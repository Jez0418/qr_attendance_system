#!/bin/bash
# Runs tests/load/attendance.js against the staging preview. Usage: bash tests/load/run.sh dry|full
# Secrets come from attendance-system/.env (git-ignored): VERCEL_BYPASS_SECRET, LOADTEST_PASSWORD,
# STAGING_QR_SECRET_KEY. Results: tests/load/results/<profile>-<time>.json (summary).
set -e
cd "$(dirname "$0")"
ENVF=../../.env
get() { grep -E "^[[:space:]]*$1[[:space:]]*=" "$ENVF" | head -1 | cut -d= -f2- | tr -d '\r' | sed -E 's/^[[:space:]]+//; s/[[:space:]]+$//; s/^"//; s/"$//'; }
PROFILE=${1:-dry}
mkdir -p results
OUT="results/$PROFILE-$(date +%Y%m%d-%H%M%S).json"
k6 run --quiet --summary-export "$OUT" \
  -e PROFILE="$PROFILE" \
  -e BASE_URL="${BASE_URL:-https://qrattendancesystem-attendance-system-git-loadtest-jez8.vercel.app}" \
  -e BYPASS="$(get VERCEL_BYPASS_SECRET)" \
  -e PASSWORD="$(get LOADTEST_PASSWORD)" \
  -e QR_KEY="$(get STAGING_QR_SECRET_KEY)" \
  attendance.js
echo "Summary saved to tests/load/$OUT"
