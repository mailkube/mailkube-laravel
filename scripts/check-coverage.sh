#!/usr/bin/env bash
# Fail if line coverage is below the threshold, parsed from a Clover report.
#
# PHPUnit/pcov report line coverage (covered vs total statements) but no reliable branch metric,
# so this gate is line coverage only (see .rules/SOLID_DRY_KISS.md).
set -euo pipefail

THRESHOLD=90
CLOVER="${1:-coverage.clover}"

if [[ ! -f "$CLOVER" ]]; then
  echo "clover report '$CLOVER' not found; run phpunit with --coverage-clover=$CLOVER first" >&2
  exit 1
fi

pct="$(php -r '
    $xml = simplexml_load_file($argv[1]);
    if ($xml === false) { fwrite(STDERR, "could not parse clover XML\n"); exit(2); }
    $m = $xml->xpath("/coverage/project/metrics")[0] ?? null;
    if ($m === null) { fwrite(STDERR, "no project metrics in clover XML\n"); exit(2); }
    $total = (int) $m["statements"];
    $covered = (int) $m["coveredstatements"];
    echo $total > 0 ? ($covered / $total) * 100 : 100;
' "$CLOVER")"

if awk -v p="$pct" -v thr="$THRESHOLD" 'BEGIN { exit (p + 0 >= thr) ? 0 : 1 }'; then
  printf 'coverage %.2f%% meets the %d%% threshold\n' "$pct" "$THRESHOLD"
else
  printf 'coverage %.2f%% is below the required %d%%\n' "$pct" "$THRESHOLD" >&2
  exit 1
fi
