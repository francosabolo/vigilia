export type LatLng = [number, number];

export interface CameraPlacement {
    id: number;
    position: LatLng | null;
    heading: number;
    fov: number;
    range_m: number;
    is_online: boolean;
}

const METERS_PER_DEGREE = 111_320;

/** Small-area approximation (a gated community is a few hundred meters wide). */
function toMeters(origin: LatLng, point: LatLng): [number, number] {
    const x = (point[1] - origin[1]) * METERS_PER_DEGREE * Math.cos((origin[0] * Math.PI) / 180);
    const y = (point[0] - origin[0]) * METERS_PER_DEGREE;
    return [x, y];
}

export function destination(origin: LatLng, bearingDeg: number, meters: number): LatLng {
    const rad = (bearingDeg * Math.PI) / 180;
    const dLat = (meters * Math.cos(rad)) / METERS_PER_DEGREE;
    const dLng = (meters * Math.sin(rad)) / (METERS_PER_DEGREE * Math.cos((origin[0] * Math.PI) / 180));
    return [origin[0] + dLat, origin[1] + dLng];
}

export function distanceMeters(a: LatLng, b: LatLng): number {
    const [x, y] = toMeters(a, b);
    return Math.hypot(x, y);
}

export function bearing(from: LatLng, to: LatLng): number {
    const [x, y] = toMeters(from, to);
    return ((Math.atan2(x, y) * 180) / Math.PI + 360) % 360;
}

/** Field-of-view cone as a polygon. */
export function fovPolygon(camera: CameraPlacement): LatLng[] {
    if (!camera.position) return [];
    if (camera.fov >= 360) {
        return Array.from({ length: 36 }, (_, i) => destination(camera.position!, i * 10, camera.range_m));
    }
    const start = camera.heading - camera.fov / 2;
    const steps = Math.max(4, Math.round(camera.fov / 6));
    const arc = Array.from({ length: steps + 1 }, (_, i) => destination(camera.position!, start + (camera.fov * i) / steps, camera.range_m));
    return [camera.position, ...arc];
}

export function covers(camera: CameraPlacement, point: LatLng): boolean {
    if (!camera.position || !camera.is_online) return false;
    if (distanceMeters(camera.position, point) > camera.range_m) return false;
    if (camera.fov >= 360) return true;
    const diff = Math.abs(((bearing(camera.position, point) - camera.heading + 540) % 360) - 180);
    return diff <= camera.fov / 2;
}

export interface CoverageSegment {
    points: LatLng[];
    covered: boolean;
}

/**
 * Walks the perimeter every `step` meters and marks which stretches fall inside an online camera's view.
 */
export function perimeterCoverage(perimeter: LatLng[], cameras: CameraPlacement[], step = 2): { segments: CoverageSegment[]; coveredRatio: number; lengthM: number } {
    if (perimeter.length < 3) return { segments: [], coveredRatio: 0, lengthM: 0 };

    const ring = [...perimeter, perimeter[0]];
    const samples: { point: LatLng; covered: boolean }[] = [];
    let lengthM = 0;

    for (let i = 0; i < ring.length - 1; i++) {
        const [a, b] = [ring[i], ring[i + 1]];
        const length = distanceMeters(a, b);
        lengthM += length;
        const count = Math.max(1, Math.ceil(length / step));
        for (let j = 0; j < count; j++) {
            const t = j / count;
            const point: LatLng = [a[0] + (b[0] - a[0]) * t, a[1] + (b[1] - a[1]) * t];
            samples.push({ point, covered: cameras.some((camera) => covers(camera, point)) });
        }
    }
    samples.push({ point: ring[ring.length - 1], covered: samples[0].covered });

    const segments: CoverageSegment[] = [];
    for (const sample of samples) {
        const last = segments[segments.length - 1];
        if (last && last.covered === sample.covered) {
            last.points.push(sample.point);
        } else {
            if (last) last.points.push(sample.point);
            segments.push({ points: [sample.point], covered: sample.covered });
        }
    }

    const coveredRatio = samples.filter((sample) => sample.covered).length / samples.length;
    return { segments, coveredRatio, lengthM };
}

export interface CameraModel {
    id: string;
    name: string;
    fov: number;
    range_m: number;
}

/** Typical night-time specs of common perimeter cameras (conservative, with IR). */
export const CAMERA_MODELS: CameraModel[] = [
    { id: 'bullet', name: 'Bullet 4 MP con IR', fov: 90, range_m: 35 },
    { id: 'varifocal', name: 'Varifocal largo alcance', fov: 40, range_m: 80 },
    { id: 'turret', name: 'Domo / turret gran angular', fov: 110, range_m: 25 },
];

export interface CoverageGap {
    points: LatLng[];
    lengthM: number;
    midpoint: LatLng;
    nearestCamera: { id: number; distanceM: number } | null;
}

export interface SuggestedCamera {
    position: LatLng;
    heading: number;
    fov: number;
    range_m: number;
    gapIndex: number;
}

function pathLength(points: LatLng[]): number {
    let length = 0;
    for (let i = 1; i < points.length; i++) length += distanceMeters(points[i - 1], points[i]);
    return length;
}

/** Point at `meters` along a polyline, and the bearing of the path there. */
function along(points: LatLng[], meters: number): { point: LatLng; heading: number } {
    let walked = 0;
    for (let i = 1; i < points.length; i++) {
        const segment = distanceMeters(points[i - 1], points[i]);
        if (walked + segment >= meters || i === points.length - 1) {
            const t = segment === 0 ? 0 : Math.min(1, (meters - walked) / segment);
            const point: LatLng = [points[i - 1][0] + (points[i][0] - points[i - 1][0]) * t, points[i - 1][1] + (points[i][1] - points[i - 1][1]) * t];
            return { point, heading: bearing(points[i - 1], points[i]) };
        }
        walked += segment;
    }
    return { point: points[0], heading: 0 };
}

/**
 * Finds the blind spots of the perimeter and proposes where to install cameras: on the fence line,
 * aimed along it (the usual perimeter layout), one every ~85% of the model's range so views overlap.
 */
export function planCoverage(perimeter: LatLng[], cameras: CameraPlacement[], model: CameraModel, minGapM = 3) {
    const current = perimeterCoverage(perimeter, cameras);
    const placed = cameras.filter((camera) => camera.position && camera.is_online);

    const gaps: CoverageGap[] = current.segments
        .filter((segment) => !segment.covered)
        .map((segment) => {
            const lengthM = pathLength(segment.points);
            const midpoint = along(segment.points, lengthM / 2).point;
            const nearest = placed
                .map((camera) => ({ id: camera.id, distanceM: distanceMeters(camera.position!, midpoint) }))
                .sort((a, b) => a.distanceM - b.distanceM)[0];
            return { points: segment.points, lengthM, midpoint, nearestCamera: nearest ?? null };
        })
        .filter((gap) => gap.lengthM >= minGapM)
        .sort((a, b) => b.lengthM - a.lengthM);

    const reach = model.range_m * 0.85;
    const suggestions: SuggestedCamera[] = gaps.flatMap((gap, gapIndex) => {
        const count = Math.max(1, Math.ceil(gap.lengthM / reach));
        const spacing = gap.lengthM / count;
        return Array.from({ length: count }, (_, i) => {
            const { point, heading } = along(gap.points, Math.max(0, i * spacing - 1));
            return { position: point, heading: Math.round(heading), fov: model.fov, range_m: model.range_m, gapIndex };
        });
    });

    const projected = perimeterCoverage(perimeter, [
        ...cameras,
        ...suggestions.map((suggestion, index) => ({ id: -1 - index, is_online: true, ...suggestion })),
    ]);

    return { current, gaps, suggestions, projectedRatio: projected.coveredRatio };
}
