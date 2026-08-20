#!/usr/bin/env bash
#
# Regression guard for the WooExports date logic.
#
#   ./guard.sh before    capture baselines, before touching the date code
#   ./guard.sh after     capture again, diff against "before", verify the cutoff
#
# Runs the unit suite, then the baseline in BOTH order-storage modes, then the
# end-to-end cutoff checks. HPOS is restored to whatever it was found as.
#
# Requires a booted WordPress with fixtures seeded:
#   php tests/integration/fixtures.php seed
#
# PHP binary: set WOOEX_PHP if `php` is not on PATH. On a Mac running Local with
# no system PHP, that is usually a wrapper around Local's bundled binary.
set -euo pipefail

HERE="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PLUGIN="$(cd "$HERE/../.." && pwd)"
OUT="${WOOEX_IT_OUT:-$HERE/.results}"
PHP="${WOOEX_PHP:-php}"
PHASE="${1:-after}"

if [ "$PHASE" != "before" ] && [ "$PHASE" != "after" ]; then
	echo "Usage: $0 [before|after]" >&2
	exit 2
fi

command -v "$PHP" >/dev/null 2>&1 || { echo "PHP not found. Set WOOEX_PHP to your binary." >&2; exit 2; }
mkdir -p "$OUT"

fail=0

echo "== unit suite =="
if [ -x "$PLUGIN/vendor/bin/phpunit" ]; then
	( cd "$PLUGIN" && vendor/bin/phpunit --colors=never | tail -4 ) || fail=1
else
	echo "  vendor/bin/phpunit missing; run composer install. Skipping."
fi

# Remember how HPOS was found, so it can be put back rather than left on.
was_hpos="$("$PHP" "$HERE/hpos.php" status | grep -c '"hpos_in_use": true' || true)"
restore_hpos() {
	if [ "$was_hpos" = "1" ]; then
		"$PHP" "$HERE/hpos.php" on >/dev/null
	else
		"$PHP" "$HERE/hpos.php" off >/dev/null
	fi
}
trap restore_hpos EXIT

echo
echo "== legacy storage =="
"$PHP" "$HERE/hpos.php" off >/dev/null
"$PHP" "$HERE/baseline.php" > "$OUT/baseline-$PHASE.json"
"$PHP" "$HERE/verify-cutoff.php" > "$OUT/verify-$PHASE.json" || fail=1
echo "  cutoff failures: $(grep -o '"failures": [0-9]*' "$OUT/verify-$PHASE.json" | head -1 | awk '{print $2}')"

echo
echo "== hpos storage =="
"$PHP" "$HERE/hpos.php" on >/dev/null
"$PHP" "$HERE/baseline.php" > "$OUT/baseline-$PHASE-hpos.json"
"$PHP" "$HERE/verify-cutoff.php" > "$OUT/verify-$PHASE-hpos.json" || fail=1
echo "  cutoff failures: $(grep -o '"failures": [0-9]*' "$OUT/verify-$PHASE-hpos.json" | head -1 | awk '{print $2}')"

if [ "$PHASE" = "before" ]; then
	echo
	echo "Baselines captured in $OUT. Make your change, then run: $0 after"
	exit $fail
fi

echo
echo "== diff vs before =="
for suffix in "" "-hpos"; do
	label="legacy"; [ -n "$suffix" ] && label="hpos"
	if [ ! -f "$OUT/baseline-before$suffix.json" ]; then
		echo "  no before snapshot for $label; run '$0 before' first" >&2
		fail=1
		continue
	fi
	echo "  [$label]"
	"$PHP" "$HERE/compare.php" "$OUT/baseline-before$suffix.json" "$OUT/baseline-after$suffix.json" || fail=1
done

echo
if [ "$fail" = "0" ]; then
	echo "PASS. Ranges unchanged at day_start 00:00, and the cutoff resolves to the second."
else
	echo "FAIL. See above." >&2
fi
exit $fail
