#!/usr/bin/env bash
# Build virabot-update.zip and the unsigned SHA-256 sidecar.
# The checksum is not a publisher signature.
set -euo pipefail
root="$(cd "$(dirname "$0")/.." && pwd)"
out="${1:-$root/dist}"
mkdir -p "$out"
stage="$(mktemp -d)"
trap 'rm -rf "$stage"' EXIT
# Client package. No live config, no env files.
tar -C "$root" \
  --exclude='./.git' \
  --exclude='./dist' \
  --exclude='./storage' \
  --exclude='./config.php' \
  --exclude='./*.env' \
  --exclude='./.env' \
  -cf - . | tar -C "$stage" -xf -
rm -f "$stage/config.php" "$stage/.env"
if grep -R -n -E 'ghp_[A-Za-z0-9]{20,}|github_pat_[A-Za-z0-9_]{20,}' "$stage" --include='*.php' --include='*.sh' --include='*.json' --include='*.md' >/dev/null 2>&1; then
  echo "refusing to pack: a GitHub token-like string is in the tree" >&2
  exit 1
fi
zipfile="$out/virabot-update.zip"
rm -f "$zipfile" "$out/virabot-update.zip.sha256"
(cd "$stage" && zip -qr "$zipfile" .)
sum="$(sha256sum "$zipfile" | awk '{print $1}')"
printf '%s  virabot-update.zip\n' "$sum" > "$out/virabot-update.zip.sha256"
echo "wrote $zipfile"
echo "wrote $out/virabot-update.zip.sha256"
