#!/usr/bin/env bash
# Live check (NETWORK, simulate only, nothing is downloaded): runs the
# plugin's real format selectors through a current yt-dlp in a throwaway
# container and prints which format each one picks per resolution, for a
# portrait and a landscape video. Re-run when a platform changes its formats.
#
#   tests/live/selectors.sh [url ...]
set -euo pipefail
cd "$(dirname "$0")/../.."
TMP=$(mktemp -d)
php -r 'require "src/services/Downloader.php"; use arifje\craftvideodownloader\services\Downloader as D;
foreach ([1080, 720, 480] as $r) {
    echo "MP4 $r\t", D::buildToolSelector($r, "compatible"), "\n";
    echo "Best $r\t", D::buildToolSelector($r, "best"), "\n";
    echo "field-default $r\t", D::buildFormatSelector($r), "\n";
}' > "$TMP/sels.tsv"
URLS=("$@")
if [ ${#URLS[@]} -eq 0 ]; then
  URLS=("https://x.com/djsnake/status/2108270710770561269/video/1" "https://x.com/FDW_VB/status/2063925722079518814")
fi
docker run --rm -v "$TMP:/s" python:3.12-slim sh -c '
  pip install -q yt-dlp >/dev/null 2>&1; echo "yt-dlp $(yt-dlp --version)"
  for U in "$@"; do
    echo "== $U"
    while IFS="	" read -r name sel; do
      printf "  %-18s -> " "$name"
      yt-dlp --no-playlist --no-warnings --simulate -f "$sel" --print "%(format_id)s %(resolution)s" "$U" 2>&1 | tail -1
    done < /s/sels.tsv
  done' sh "${URLS[@]}"
rm -rf "$TMP"
