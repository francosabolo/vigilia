"""seguria-bridge: forwards Frigate events (MQTT) to the Vigilia cloud platform.

- Listens to `frigate/events`: sends each event as soon as someone enters a zone (real time) and, when it
  ends, completes it with the clip and frames downloaded
  from the local Frigate API and POSTs them to /api/edge/events.
- If the cloud is unreachable, persists the event to QUEUE_DIR and retries later.
- Sends a heartbeat with camera status every HEARTBEAT_SECONDS.
- Polls /api/edge/commands every COMMAND_POLL_SECONDS: live frames requested by the platform
  (resident reports), preview frames while someone watches the camera wall, zone updates, and a
  periodic health frame per camera (to detect covered or turned cameras).
"""

import json
import logging
import os
import re
import shutil
import socket
import subprocess
import threading
import time
from pathlib import Path

import paho.mqtt.client as mqtt
import requests
from ruamel.yaml import YAML

VERSION = "0.6.0"
API_URL = os.environ["SEGURIA_API_URL"].rstrip("/")
TOKEN = os.environ["SEGURIA_TOKEN"]
MQTT_HOST = os.environ.get("MQTT_HOST", "localhost")
MQTT_TOPIC = os.environ.get("MQTT_TOPIC", "frigate/events")
FRIGATE_URL = os.environ.get("FRIGATE_URL", "http://localhost:5000").rstrip("/")
QUEUE_DIR = Path(os.environ.get("QUEUE_DIR", "./queue"))
HEARTBEAT_SECONDS = int(os.environ.get("HEARTBEAT_SECONDS", "60"))
RETRY_SECONDS = int(os.environ.get("RETRY_SECONDS", "30"))
CLIP_WAIT_SECONDS = int(os.environ.get("CLIP_WAIT_SECONDS", "5"))
COMMAND_POLL_SECONDS = float(os.environ.get("COMMAND_POLL_SECONDS", "3"))
FRIGATE_CONFIG = Path(os.environ.get("FRIGATE_CONFIG", "./frigate-config/config.yml"))
ZONES_VERSION_FILE = QUEUE_DIR.parent / "zones_version"

logging.basicConfig(level=logging.INFO, format="%(asctime)s %(levelname)s %(message)s")
log = logging.getLogger("seguria-bridge")
session = requests.Session()
session.headers.update({"Authorization": f"Bearer {TOKEN}", "Accept": "application/json"})
QUEUE_DIR.mkdir(parents=True, exist_ok=True)
queue_lock = threading.Lock()


def to_payload(event: dict) -> dict:
    """Map a Frigate event ("after" object) to the platform payload."""
    return {
        "id": event["id"],
        "camera": event["camera"],
        "label": event["label"],
        "score": event.get("top_score") or event.get("score"),
        "zones": event.get("entered_zones") or [],
        "start_time": event["start_time"],
        "end_time": event.get("end_time"),
    }


def fetch_media(event_id: str, target: Path) -> None:
    """Download snapshot and clip from Frigate into target/ (best effort)."""
    target.mkdir(parents=True, exist_ok=True)
    sources = {
        "snapshot.jpg": f"{FRIGATE_URL}/api/events/{event_id}/snapshot.jpg?bbox=1",
        "clip.mp4": f"{FRIGATE_URL}/api/events/{event_id}/clip.mp4",
    }
    for name, url in sources.items():
        for attempt in range(3):  # the clip is finalized a few seconds after the event ends
            try:
                r = requests.get(url, timeout=30)
                if r.ok and r.content:
                    (target / name).write_bytes(r.content)
                    break
            except requests.RequestException as exc:
                log.warning("fetch %s failed: %s", name, exc)
            time.sleep(CLIP_WAIT_SECONDS)


FRAME_COUNT = int(os.environ.get("SEGURIA_FRAMES", "4"))
FRAME_WIDTH = 768  # enough to see a person at the fence, and ~3x fewer tokens than 1280px


def extract_frames(directory: Path) -> list[Path]:
    """Evenly spaced frames from the clip (best effort): frame_0.jpg … frame_N.jpg, in time order."""
    clip = directory / "clip.mp4"
    if FRAME_COUNT < 2 or not clip.exists() or not shutil.which("ffmpeg"):
        return []
    try:
        probe = subprocess.run(
            ["ffprobe", "-v", "error", "-show_entries", "format=duration", "-of", "csv=p=0", str(clip)],
            capture_output=True, text=True, timeout=20, check=True,
        )
        duration = float(probe.stdout.strip() or 0)
    except (subprocess.SubprocessError, ValueError) as exc:
        log.warning("ffprobe failed on %s: %s", clip, exc)
        return []
    if duration <= 0:
        return []

    frames = []
    for i in range(FRAME_COUNT):
        at = duration * (i + 0.5) / FRAME_COUNT
        target = directory / f"frame_{i}.jpg"
        try:
            subprocess.run(
                ["ffmpeg", "-v", "error", "-y", "-ss", f"{at:.2f}", "-i", str(clip), "-frames:v", "1",
                 "-vf", f"scale={FRAME_WIDTH}:-2", "-q:v", "4", str(target)],
                capture_output=True, timeout=20, check=True,
            )
            frames.append(target)
        except subprocess.SubprocessError as exc:
            log.warning("frame %s of %s failed: %s", i, clip, exc)
    return frames


def upload(directory: Path) -> bool:
    """POST a queued event to the cloud. Returns True when accepted (or permanently rejected)."""
    payload = json.loads((directory / "event.json").read_text())
    data = [(k, str(v)) for k, v in payload.items() if k != "zones" and v is not None]
    data += [("zones[]", z) for z in payload["zones"]]

    files = {}
    try:
        for field, name, mime in (("snapshot", "snapshot.jpg", "image/jpeg"), ("clip", "clip.mp4", "video/mp4")):
            path = directory / name
            if path.exists():
                files[field] = (name, path.open("rb"), mime)
        for i, path in enumerate(sorted(directory.glob("frame_*.jpg"))):
            files[f"frames[{i}]"] = (path.name, path.open("rb"), "image/jpeg")
        r = session.post(f"{API_URL}/api/edge/events", data=data, files=files, timeout=60)
    except requests.RequestException as exc:
        log.warning("upload %s failed, will retry: %s", payload["id"], exc)
        return False
    finally:
        for _, fh, _ in files.values():
            fh.close()

    if r.status_code in (200, 201):
        log.info("uploaded event %s (%s on %s)", payload["id"], payload["label"], payload["camera"])
        return True
    if r.status_code in (401, 422):
        log.error("event %s rejected (%s): %s", payload["id"], r.status_code, r.text[:300])
        return r.status_code == 422  # drop invalid payloads; keep on auth errors
    log.warning("upload %s got %s, will retry", payload["id"], r.status_code)
    return False


EARLY_SENT: dict[str, float] = {}


def handle_early(event: dict) -> None:
    """Real time: send the event as soon as someone enters a zone, with the snapshot of that moment. The alert must
    not wait for the person to leave; the clip and the frames complete the same event when it ends."""
    directory = QUEUE_DIR / (event["id"].replace("/", "_") + "-early")
    directory.mkdir(parents=True, exist_ok=True)
    (directory / "event.json").write_text(json.dumps(to_payload(event) | {"end_time": None}))
    for url in (f"{FRIGATE_URL}/api/events/{event['id']}/snapshot.jpg", f"{FRIGATE_URL}/api/{event['camera']}/latest.jpg"):
        try:
            r = requests.get(url, timeout=5)
            if r.ok and r.content:
                (directory / "snapshot.jpg").write_bytes(r.content)
                break
        except requests.RequestException:
            continue
    with queue_lock:
        if upload(directory):
            shutil.rmtree(directory, ignore_errors=True)


def handle_event(event: dict) -> None:
    if event.get("false_positive"):
        return
    directory = QUEUE_DIR / event["id"].replace("/", "_")
    directory.mkdir(parents=True, exist_ok=True)
    (directory / "event.json").write_text(json.dumps(to_payload(event)))
    fetch_media(event["id"], directory)
    extract_frames(directory)
    with queue_lock:
        if upload(directory):
            shutil.rmtree(directory, ignore_errors=True)


def retry_loop() -> None:
    while True:
        time.sleep(RETRY_SECONDS)
        with queue_lock:
            for directory in sorted(QUEUE_DIR.iterdir()):
                if (directory / "event.json").exists() and upload(directory):
                    shutil.rmtree(directory, ignore_errors=True)


def heartbeat_loop() -> None:
    while True:
        cameras = []
        try:
            stats = requests.get(f"{FRIGATE_URL}/api/stats", timeout=10).json()
            for name, cam in stats.get("cameras", {}).items():
                cameras.append({"name": name, "online": bool(cam.get("camera_fps", 0) > 0)})
        except (requests.RequestException, ValueError) as exc:
            log.warning("could not read Frigate stats: %s", exc)
        try:
            session.post(
                f"{API_URL}/api/edge/heartbeat",
                json={"version": VERSION, "cameras": cameras, "zones_version": applied_zones_version()},
                timeout=15,
            )
        except requests.RequestException as exc:
            log.warning("heartbeat failed: %s", exc)
        time.sleep(HEARTBEAT_SECONDS)


def applied_zones_version() -> str | None:
    return ZONES_VERSION_FILE.read_text().strip() if ZONES_VERSION_FILE.exists() else None


def apply_zones(payload: dict) -> None:
    """Write the zones drawn in the console into Frigate's config and restart Frigate."""
    yaml = YAML()
    yaml.preserve_quotes = True
    config = yaml.load(FRIGATE_CONFIG.read_text())
    changed = False

    for camera, zones in payload.get("cameras", {}).items():
        if camera not in config.get("cameras", {}):
            log.warning("zones for unknown camera %s ignored", camera)
            continue
        config["cameras"][camera]["zones"] = {
            name: {"coordinates": zone["coordinates"], "objects": zone["objects"]} for name, zone in zones.items()
        }
        changed = True

    # Privacy areas (the street, a neighbour's window): Frigate ignores motion and objects there.
    for camera, masks in payload.get("masks", {}).items():
        if camera not in config.get("cameras", {}):
            continue
        cam = config["cameras"][camera]
        cam.setdefault("motion", {})["mask"] = list(masks)
        cam.setdefault("objects", {})["mask"] = list(masks)
        changed = True

    if changed:
        backup = FRIGATE_CONFIG.with_suffix(".yml.bak")
        backup.write_text(FRIGATE_CONFIG.read_text())
        with FRIGATE_CONFIG.open("w") as fh:
            yaml.dump(config, fh)
        try:
            requests.post(f"{FRIGATE_URL}/api/restart", timeout=10)
        except requests.RequestException:
            pass  # Frigate drops the connection while restarting
        log.info("applied zones version %s and restarted Frigate", payload["version"])

    ZONES_VERSION_FILE.write_text(payload["version"])


WS_DISCOVERY_PROBE = """<?xml version="1.0" encoding="UTF-8"?>
<e:Envelope xmlns:e="http://www.w3.org/2003/05/soap-envelope" xmlns:w="http://schemas.xmlsoap.org/ws/2004/08/addressing"
 xmlns:d="http://schemas.xmlsoap.org/ws/2005/04/discovery" xmlns:dn="http://www.onvif.org/ver10/network/wsdl">
<e:Header><w:MessageID>uuid:{uid}</w:MessageID><w:To>urn:schemas-xmlsoap-org:ws:2005:04:discovery</w:To>
<w:Action>http://schemas.xmlsoap.org/ws/2005/04/discovery/Probe</w:Action></e:Header>
<e:Body><d:Probe><d:Types>dn:NetworkVideoTransmitter</d:Types></d:Probe></e:Body></e:Envelope>"""


def discover_cameras(timeout: float = 4.0) -> list[dict]:
    """K8 — ONVIF WS-Discovery: every camera on the local network answers the multicast probe with its address."""
    sock = socket.socket(socket.AF_INET, socket.SOCK_DGRAM, socket.IPPROTO_UDP)
    sock.setsockopt(socket.IPPROTO_IP, socket.IP_MULTICAST_TTL, 2)
    sock.settimeout(timeout)
    sock.sendto(WS_DISCOVERY_PROBE.format(uid=os.urandom(8).hex()).encode(), ("239.255.255.250", 3702))
    found: dict[str, dict] = {}
    deadline = time.time() + timeout
    while time.time() < deadline:
        try:
            data, (address, _) = sock.recvfrom(65535)
        except socket.timeout:
            break
        text = data.decode(errors="ignore")
        names = re.findall(r"onvif://www\.onvif\.org/(?:name|hardware)/([^\s<]+)", text)
        found[address] = {"address": address, "name": " ".join(requests.utils.unquote(n) for n in names)[:200] or None}
    sock.close()
    return list(found.values())


def run_discovery() -> None:
    try:
        devices = discover_cameras()
        session.post(f"{API_URL}/api/edge/discovered", json={"devices": devices}, timeout=10)
        log.info("discovery found %d cameras", len(devices))
    except Exception as exc:  # noqa: BLE001 — a failed scan must not stop the bridge
        log.error("discovery failed: %s", exc)


def add_cameras(setups: list[dict]) -> None:
    """K8 — add the cameras the technician picked to Frigate (detect on the main stream) and restart it once."""
    yaml = YAML()
    yaml.preserve_quotes = True
    config = yaml.load(FRIGATE_CONFIG.read_text())
    cameras = config.setdefault("cameras", {})
    results = []
    for setup in setups:
        if setup["name"] in cameras:
            results.append((setup["id"], False, "Ya existe una cámara con ese nombre"))
            continue
        cameras[setup["name"]] = {
            "ffmpeg": {"inputs": [{"path": setup["url"], "roles": ["detect", "record"]}]},
            "detect": {"width": 1280, "height": 720, "fps": 5},
        }
        results.append((setup["id"], True, "Agregada a Frigate"))
    if any(ok for _, ok, _ in results):
        FRIGATE_CONFIG.with_suffix(".yml.bak").write_text(FRIGATE_CONFIG.read_text())
        with FRIGATE_CONFIG.open("w") as fh:
            yaml.dump(config, fh)
        try:
            requests.post(f"{FRIGATE_URL}/api/restart", timeout=10)
        except requests.RequestException:
            pass
    for setup_id, ok, result in results:
        session.post(f"{API_URL}/api/edge/camera-setups/{setup_id}", json={"ok": ok, "result": result}, timeout=10)


def latest_frame(camera: str) -> bytes | None:
    """Current frame of a camera from Frigate (720p JPEG keeps uploads small)."""
    try:
        r = requests.get(f"{FRIGATE_URL}/api/{camera}/latest.jpg?h=720&quality=70", timeout=10)
        return r.content if r.ok and r.content else None
    except requests.RequestException as exc:
        log.warning("latest frame for %s failed: %s", camera, exc)
        return None


DETER_COMMAND = os.environ.get("SEGURIA_DETER_COMMAND", "").strip()
# E6 — how this box updates itself when the console asks, e.g. "cd /opt/vigilia/edge && docker compose pull && docker compose up -d".
UPDATE_COMMAND = os.environ.get("SEGURIA_UPDATE_COMMAND", "").strip()
_last_update_attempt = 0.0


def update_self() -> None:
    """Runs the site's update command at most once every 30 minutes; the new version shows in the next heartbeat."""
    global _last_update_attempt
    if not UPDATE_COMMAND or time.time() - _last_update_attempt < 1800:
        return
    _last_update_attempt = time.time()
    log.info("update requested by the console, running SEGURIA_UPDATE_COMMAND")
    try:
        subprocess.run(UPDATE_COMMAND, shell=True, timeout=900, check=False)
    except subprocess.SubprocessError as exc:
        log.error("update failed: %s", exc)


def deter(command: dict) -> None:
    """Plays the deterrence on the site's speaker / siren through a site-specific hook, and reports the result.

    SEGURIA_DETER_COMMAND is a shell command run with SEGURIA_CAMERA and SEGURIA_MESSAGE in its environment,
    e.g. a curl to the camera's two-way audio or alarm-output API (Hikvision ISAPI, Reolink siren) or a TTS script.
    """
    if not DETER_COMMAND:
        ok, result = False, "El equipo no tiene altavoz ni sirena configurados (SEGURIA_DETER_COMMAND)."
    else:
        try:
            done = subprocess.run(
                DETER_COMMAND, shell=True, capture_output=True, text=True, timeout=30,
                env={**os.environ, "SEGURIA_CAMERA": command["camera"], "SEGURIA_MESSAGE": command["message"]},
            )
            ok = done.returncode == 0
            result = "Aviso reproducido." if ok else f"Falló (código {done.returncode}): {done.stderr.strip()[:200]}"
        except subprocess.SubprocessError as exc:
            ok, result = False, f"Falló: {exc}"[:255]
    log.info("deterrence %s on %s: %s", command["id"], command["camera"], result)
    try:
        session.post(f"{API_URL}/api/edge/deterrence/{command['id']}", json={"ok": ok, "result": result}, timeout=10)
    except requests.RequestException as exc:
        log.warning("could not report deterrence %s: %s", command["id"], exc)


def command_loop() -> None:
    last_preview = 0.0
    last_health = 0.0
    while True:
        try:
            commands = session.get(f"{API_URL}/api/edge/commands", timeout=10).json()
        except (requests.RequestException, ValueError) as exc:
            log.warning("command poll failed: %s", exc)
            time.sleep(COMMAND_POLL_SECONDS * 3)
            continue

        zones = commands.get("zones")
        if zones and zones.get("version") != applied_zones_version():
            try:
                apply_zones(zones)
            except Exception as exc:  # never let a bad config stop the bridge
                log.error("could not apply zones: %s", exc)

        # Deterrence first: every second counts.
        for command in commands.get("deterrence", []):
            threading.Thread(target=deter, args=(command,), daemon=True).start()

        if commands.get("update"):
            threading.Thread(target=update_self, daemon=True).start()

        if commands.get("discover"):
            threading.Thread(target=run_discovery, daemon=True).start()

        if commands.get("add_cameras"):
            try:
                add_cameras(commands["add_cameras"])
            except Exception as exc:  # never let a bad config stop the bridge
                log.error("could not add cameras: %s", exc)

        for snapshot in commands.get("snapshots", []):
            frame = latest_frame(snapshot["camera"])
            if frame:
                session.post(
                    f"{API_URL}/api/edge/snapshots/{snapshot['id']}",
                    files={"image": ("live.jpg", frame, "image/jpeg")},
                    timeout=30,
                )
                log.info("sent live frame of %s (request %s)", snapshot["camera"], snapshot["id"])

        preview = commands.get("preview")
        if preview and time.time() - last_preview >= preview.get("interval", 3):
            last_preview = time.time()
            for camera in preview.get("cameras", []):
                frame = latest_frame(camera)
                if frame:
                    try:
                        session.post(
                            f"{API_URL}/api/edge/previews",
                            data={"camera": camera},
                            files={"image": ("preview.jpg", frame, "image/jpeg")},
                            timeout=15,
                        )
                    except requests.RequestException as exc:
                        log.warning("preview upload failed: %s", exc)

        health = commands.get("health_check")
        if health and time.time() - last_health >= health.get("interval", 300):
            last_health = time.time()
            for camera in health.get("cameras", []):
                frame = latest_frame(camera)
                if frame:
                    try:
                        session.post(
                            f"{API_URL}/api/edge/health-frames",
                            data={"camera": camera},
                            files={"image": ("health.jpg", frame, "image/jpeg")},
                            timeout=30,
                        )
                    except requests.RequestException as exc:
                        log.warning("health frame upload failed: %s", exc)

        time.sleep(COMMAND_POLL_SECONDS)


def on_connect(client, _userdata, _flags, reason_code, _properties=None):
    log.info("connected to MQTT %s (%s), subscribing to %s", MQTT_HOST, reason_code, MQTT_TOPIC)
    client.subscribe(MQTT_TOPIC)


def on_message(_client, _userdata, msg):
    try:
        message = json.loads(msg.payload)
    except ValueError:
        return
    after = message.get("after") or {}
    kind = message.get("type")
    if kind in ("new", "update") and after.get("id") not in EARLY_SENT and not after.get("false_positive") \
            and (after.get("entered_zones") or after.get("has_snapshot")):
        EARLY_SENT[after["id"]] = time.time()
        for old in [key for key, at in EARLY_SENT.items() if at < time.time() - 3600]:
            EARLY_SENT.pop(old, None)
        threading.Thread(target=handle_early, args=(after,), daemon=True).start()
    if kind == "end":
        threading.Thread(target=handle_event, args=(after,), daemon=True).start()


def main() -> None:
    threading.Thread(target=heartbeat_loop, daemon=True).start()
    threading.Thread(target=retry_loop, daemon=True).start()
    threading.Thread(target=command_loop, daemon=True).start()

    client = mqtt.Client(mqtt.CallbackAPIVersion.VERSION2, client_id="seguria-bridge")
    client.on_connect = on_connect
    client.on_message = on_message
    while True:
        try:
            client.connect(MQTT_HOST, 1883, keepalive=60)
            client.loop_forever(retry_first_connection=True)
        except OSError as exc:
            log.warning("MQTT unavailable (%s), retrying in 5s", exc)
            time.sleep(5)


if __name__ == "__main__":
    main()
