"""seguria-bridge: forwards Frigate events (MQTT) to the Seguria cloud platform.

- Listens to `frigate/events`; on each finished event, downloads snapshot + clip
  from the local Frigate API and POSTs them to /api/edge/events.
- If the cloud is unreachable, persists the event to QUEUE_DIR and retries later.
- Sends a heartbeat with camera status every HEARTBEAT_SECONDS.
- Polls /api/edge/commands every COMMAND_POLL_SECONDS: live frames requested by the platform
  (resident reports) and, while someone watches the camera wall, a preview frame per camera.
"""

import json
import logging
import os
import shutil
import threading
import time
from pathlib import Path

import paho.mqtt.client as mqtt
import requests
from ruamel.yaml import YAML

VERSION = "0.2.0"
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


def handle_event(event: dict) -> None:
    if event.get("false_positive"):
        return
    directory = QUEUE_DIR / event["id"].replace("/", "_")
    directory.mkdir(parents=True, exist_ok=True)
    (directory / "event.json").write_text(json.dumps(to_payload(event)))
    fetch_media(event["id"], directory)
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


def latest_frame(camera: str) -> bytes | None:
    """Current frame of a camera from Frigate (720p JPEG keeps uploads small)."""
    try:
        r = requests.get(f"{FRIGATE_URL}/api/{camera}/latest.jpg?h=720&quality=70", timeout=10)
        return r.content if r.ok and r.content else None
    except requests.RequestException as exc:
        log.warning("latest frame for %s failed: %s", camera, exc)
        return None


def command_loop() -> None:
    last_preview = 0.0
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

        time.sleep(COMMAND_POLL_SECONDS)


def on_connect(client, _userdata, _flags, reason_code, _properties=None):
    log.info("connected to MQTT %s (%s), subscribing to %s", MQTT_HOST, reason_code, MQTT_TOPIC)
    client.subscribe(MQTT_TOPIC)


def on_message(_client, _userdata, msg):
    try:
        message = json.loads(msg.payload)
    except ValueError:
        return
    if message.get("type") == "end":
        threading.Thread(target=handle_event, args=(message["after"],), daemon=True).start()


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
