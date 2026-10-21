#!/usr/bin/env bash
# Signs the folder staged by scripts/package.sh with occ integrity:sign-app, packs the archive
# again and writes its App Store signature to build/artifacts/workoutcalendar.tar.gz.sig.
# Usage: scripts/sign.sh <private key> <certificate> <nextcloud server checkout>
set -euo pipefail

if (( $# != 3 )); then
    echo "Usage: $0 <private key> <certificate> <nextcloud server checkout>" >&2
    exit 1
fi

APP_ID=workoutcalendar
ROOT=$(cd "$(dirname "$0")/.." && pwd)
ARTIFACTS="$ROOT/build/artifacts"
STAGE="$ARTIFACTS/$APP_ID"
KEY=$(realpath "$1")
CRT=$(realpath "$2")
SERVER=$(realpath "$3")

if [[ ! -f "$STAGE/appinfo/info.xml" ]]; then
    echo "$STAGE is missing, run scripts/package.sh first" >&2
    exit 1
fi

php "$SERVER/occ" integrity:sign-app --privateKey="$KEY" --certificate="$CRT" --path="$STAGE"

if [[ ! -f "$STAGE/appinfo/signature.json" ]]; then
    echo "occ integrity:sign-app wrote no appinfo/signature.json" >&2
    exit 1
fi

tar --owner=0 --group=0 -czf "$ARTIFACTS/$APP_ID.tar.gz" -C "$ARTIFACTS" "$APP_ID"
openssl dgst -sha512 -sign "$KEY" "$ARTIFACTS/$APP_ID.tar.gz" | openssl base64 -A > "$ARTIFACTS/$APP_ID.tar.gz.sig"

echo "$ARTIFACTS/$APP_ID.tar.gz signed"
