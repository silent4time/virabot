#!/usr/bin/env bash
# Virabot (ویرابات) — one-line / local installer
# ------------------------------------------------------------
#   curl -fsSL https://raw.githubusercontent.com/silent4time/virabot/main/install.sh | sudo bash
#
# Local zip:
#   sudo bash install.sh /root/virabot-latest.zip
#
# Any manage.sh command can follow (install, update, migrate, status, ...):
#   sudo bash install.sh /root/virabot-latest.zip migrate --dry-run
#
# An old "mirza_vali Pro" install (/home/mirza_vali_pro) is found automatically;
# menu option 10 (or the "migrate" command) moves it to /home/virabot.
# ------------------------------------------------------------
set -euo pipefail

REPO_OWNER="${REPO_OWNER:-silent4time}"
REPO_NAME="${REPO_NAME:-virabot}"
ZIP_NAME="virabot-latest.zip"
REPO_ZIP_RAW="https://raw.githubusercontent.com/${REPO_OWNER}/${REPO_NAME}/main/${ZIP_NAME}"
REPO_ZIP_GITHUB="https://github.com/${REPO_OWNER}/${REPO_NAME}/raw/main/${ZIP_NAME}"
REPO_BRANCH="${REPO_BRANCH:-main}"
REPO_CODELOAD="https://codeload.github.com/${REPO_OWNER}/${REPO_NAME}/zip/refs/heads/${REPO_BRANCH}"
SRC_DIR="/opt/virabot-src"
WORK="/tmp/virabot_install_$$"

LOCAL_ZIP=""
if [[ "${1:-}" == *.zip ]]; then
  LOCAL_ZIP="$1"
  shift
fi

if [[ ${EUID:-$(id -u)} -ne 0 ]]; then
  echo "Please run as root (sudo)."
  exit 1
fi

export DEBIAN_FRONTEND=noninteractive
if [[ "${VIRABOT_UPDATED:-0}" != "1" ]]; then
  echo "[*] Updating the server (apt update && apt upgrade)"
  apt-get update -y
  apt-get upgrade -y
  export VIRABOT_UPDATED=1
fi
command -v curl >/dev/null 2>&1 || { apt-get update -y >/dev/null 2>&1; apt-get install -y curl >/dev/null 2>&1; }
command -v unzip >/dev/null 2>&1 || { apt-get update -y >/dev/null 2>&1; apt-get install -y unzip >/dev/null 2>&1; }

mkdir -p "$WORK"
ZIP_FILE="$WORK/${ZIP_NAME}"

pick_local_zip() {
  local c
  for c in "$LOCAL_ZIP" "/root/${ZIP_NAME}" "/home/${ZIP_NAME}"; do
    if [[ -n "$c" && -f "$c" && -s "$c" ]]; then
      echo "$c"
      return 0
    fi
  done
  return 1
}

echo "[*] Virabot installer"
echo "    Install path (default): /home/virabot"
echo ""

verify_checksum() {
  local zip="$1" sumf="" line hex
  if [[ -f "${zip}.sha256" ]]; then
    sumf="${zip}.sha256"
  elif [[ -f "$(dirname "$zip")/virabot-update.zip.sha256" ]]; then
    sumf="$(dirname "$zip")/virabot-update.zip.sha256"
  else
    echo "[x] Checksum file is missing. Refusing to install without virabot-update.zip.sha256."
    echo "    That file is an unsigned SHA-256, not a publisher signature."
    return 1
  fi
  line="$(tr -d '\r' < "$sumf" | head -1)"
  hex="$(printf '%s' "$line" | awk '{print $1}')"
  [[ "$hex" =~ ^[a-fA-F0-9]{64}$ ]] || { echo "[x] Checksum file is not a single SHA-256."; return 1; }
  if command -v sha256sum >/dev/null 2>&1; then
    printf '%s  %s\n' "$hex" "$(basename "$zip")" | sha256sum -c - >/dev/null 2>&1 && return 0
    # sha256sum -c matches the filename in the file; compare the digest directly
    [[ "$(sha256sum "$zip" | awk '{print $1}')" == "${hex,,}" ]]
    return
  fi
  echo "[x] sha256sum is not available."
  return 1
}

if [[ "${SKIP_LOCAL:-0}" != "1" ]] && LOCAL_FOUND="$(pick_local_zip)"; then
  echo "[*] Using local package: $LOCAL_FOUND"
  if ! verify_checksum "$LOCAL_FOUND"; then
    rm -rf "$WORK"
    exit 1
  fi
  cp -f "$LOCAL_FOUND" "$ZIP_FILE"
else
  echo "[*] Downloading the public stable release (no GitHub token)..."
  if [[ -n "${GITHUB_TOKEN:-${GH_TOKEN:-}}" ]]; then
    echo "[!] A GitHub token was present and was ignored."
  fi
  if ! command -v python3 >/dev/null 2>&1; then
    echo "[x] python3 is required to download the public release, or place the zip and checksum locally."
    rm -rf "$WORK"
    exit 1
  fi
  if ! python3 - "$ZIP_FILE" << 'PY'
import hashlib, json, sys, urllib.request
from urllib.parse import urlparse
dest = sys.argv[1]
api = "https://api.github.com/repos/silent4time/virabot/releases?per_page=20"
pins = {"api.github.com", "release-assets.githubusercontent.com", "objects.githubusercontent.com", "github-releases.githubusercontent.com"}

class Pin(urllib.request.HTTPRedirectHandler):
    def redirect_request(self, req, fp, code, msg, headers, newurl):
        host = urlparse(newurl).hostname
        if host not in pins:
            raise RuntimeError("redirect rejected")
        return urllib.request.Request(newurl, headers={"User-Agent": "VirabotInstall", "Accept": "application/octet-stream"})

opener = urllib.request.build_opener(Pin)
def get(url, accept):
    req = urllib.request.Request(url, headers={"User-Agent": "VirabotInstall", "Accept": accept})
    with opener.open(req, timeout=60) as r:
        return r.read()

rels = json.loads(get(api, "application/vnd.github+json").decode())
def ver(tag):
    tag = tag[1:] if tag.startswith("v") else tag
    p = tag.split(".")
    if len(p) != 3 or not all(x.isdigit() for x in p):
        return None
    return tuple(int(x) for x in p)
best = None
for rel in rels:
    if rel.get("draft") or rel.get("prerelease"):
        continue
    v = ver(rel.get("tag_name") or "")
    if not v:
        continue
    if best is None or v > best[0]:
        best = (v, {a.get("name"): a for a in rel.get("assets") or []})
if not best:
    sys.exit(2)
if "virabot-update.zip" not in best[1] or "virabot-update.zip.sha256" not in best[1]:
    sys.exit(2)
def asset(aid):
    return get(f"https://api.github.com/repos/silent4time/virabot/releases/assets/{aid}", "application/octet-stream")
raw = asset(best[1]["virabot-update.zip.sha256"]["id"]).decode().replace("\r\n", "\n").strip().split("\n")
if len([ln for ln in raw if ln.strip()]) != 1:
    sys.exit(3)
hexpart = raw[0].split()[0]
blob = asset(best[1]["virabot-update.zip"]["id"])
if hashlib.sha256(blob).hexdigest() != hexpart.lower():
    sys.exit(4)
open(dest, "wb").write(blob)
PY
  then
    echo "[x] Public release download failed or the checksum did not match."
    rm -rf "$WORK"
    exit 1
  fi
fi

if [[ ! -s "$ZIP_FILE" ]] || ! unzip -t "$ZIP_FILE" >/dev/null 2>&1; then
  echo "[x] Package is missing or not a valid zip."
  rm -rf "$WORK"
  exit 1
fi

echo "[*] Checking archive entries..."
python3 - "$ZIP_FILE" << 'PY'
import sys, zipfile
z = zipfile.ZipFile(sys.argv[1])
for i in z.infolist():
    name = i.filename.replace("\\", "/")
    if name.startswith("/") or name.startswith("../") or "/../" in name or name == ".." or "\x00" in name:
        sys.exit(2)
    mode = (i.external_attr >> 16) & 0o170000
    if mode == 0o120000:
        sys.exit(3)
PY
echo "[*] Extracting..."
mkdir -p "$WORK/out"
unzip -qo "$ZIP_FILE" -d "$WORK/out"
rm -rf "$WORK/out/update-server"

FOUND=""
if [[ -f "$WORK/out/manage.sh" && -d "$WORK/out/patch" ]]; then
  FOUND="$WORK/out"
else
  FOUND="$(find "$WORK/out" -type f -name manage.sh 2>/dev/null | head -1 || true)"
  [[ -n "$FOUND" ]] && FOUND="$(dirname "$FOUND")"
fi

if [[ -z "$FOUND" || ! -f "$FOUND/manage.sh" || ! -f "$FOUND/patch/botapi.php" ]]; then
  echo "[x] This zip is not a Virabot package (manage.sh / patch/ missing)."
  rm -rf "$WORK"
  exit 1
fi
if ! grep -q 'PROJECT_NAME="virabot"' "$FOUND/manage.sh"; then
  echo "[x] This package is not Virabot."
  rm -rf "$WORK"
  exit 1
fi

rm -rf "$FOUND/update-server"
rm -f "$FOUND/config.php" "$FOUND/.env"
echo "[*] Installing source to $SRC_DIR ..."
rm -rf "$SRC_DIR"
mkdir -p "$SRC_DIR"
cp -a "$FOUND"/. "$SRC_DIR/"
chmod +x "$SRC_DIR/manage.sh" "$SRC_DIR/install.sh" 2>/dev/null || true
ln -sf "$SRC_DIR/manage.sh" /usr/local/bin/virabot
rm -rf "$WORK"

echo "[*] Source OK — command: sudo virabot"
cd "$SRC_DIR"
if [[ $# -eq 0 && -e /dev/tty ]]; then
  exec bash ./manage.sh < /dev/tty
else
  exec bash ./manage.sh "$@"
fi
