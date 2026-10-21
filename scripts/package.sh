#!/usr/bin/env bash
# Builds the App Store archive build/artifacts/workoutcalendar.tar.gz from a checkout whose
# frontend is already built (npm run build). The staged folder build/artifacts/workoutcalendar
# stays in place so the release workflow can sign it and pack it again.
set -euo pipefail

APP_ID=workoutcalendar
ROOT=$(cd "$(dirname "$0")/.." && pwd)
ARTIFACTS="$ROOT/build/artifacts"
STAGE="$ARTIFACTS/$APP_ID"
# Runtime files only; vendor, node_modules and the sources in src/ are not needed by Nextcloud.
CONTENT=(appinfo lib templates img js css l10n LICENSE CHANGELOG.md README.md)

cd "$ROOT"

if [[ ! -f js/workoutcalendar-settings.mjs ]]; then
    echo "js/workoutcalendar-settings.mjs is missing, run npm run build first" >&2
    exit 1
fi

# Every CSS chunk has to be one the entry point imports, otherwise an older build is left over.
for chunk in css/*.chunk.css; do
    if ! grep -qF "'./$(basename "$chunk")'" css/workoutcalendar-settings.css; then
        echo "$chunk is not imported by css/workoutcalendar-settings.css, it is left over from an older build" >&2
        exit 1
    fi
done

rm -rf "$ARTIFACTS"
mkdir -p "$STAGE"
cp -R "${CONTENT[@]}" "$STAGE/"

tar --owner=0 --group=0 -czf "$ARTIFACTS/$APP_ID.tar.gz" -C "$ARTIFACTS" "$APP_ID"

# The App Store rejects archives above 20 MB.
size=$(wc -c < "$ARTIFACTS/$APP_ID.tar.gz")
if (( size > 20 * 1024 * 1024 )); then
    echo "Archive has $size bytes, the App Store accepts at most 20 MB" >&2
    exit 1
fi

echo "$ARTIFACTS/$APP_ID.tar.gz ($size bytes)"
