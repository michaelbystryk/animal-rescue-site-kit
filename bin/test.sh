#!/usr/bin/env bash
# The single entry point for every offline test. Needs only php (8.1+), node (20+) and bash.
#
#   bin/test.sh            run everything and print one line per suite
#   bin/test.sh --verbose  also print each suite's full output
#
# Exits 0 only if every check passed. A failing suite prints its full output.
set -uo pipefail

root="$(cd "$(dirname "$0")/.." && pwd)"
cd "$root" || exit 2

verbose=0
[ "${1:-}" = "--verbose" ] && verbose=1

passed=0
failed=0
failed_names=()
log="$(mktemp)"
trap 'rm -f "$log"' EXIT

# run_suite NAME COMMAND...: run one suite, show its last line, keep its output only if it fails.
run_suite() {
    local name="$1"
    shift
    if "$@" >"$log" 2>&1; then
        passed=$((passed + 1))
        # The last output line is the suite's own "N checks passed" summary.
        printf 'ok    %-34s %s\n' "$name" "$(grep -v '^Homeward:' "$log" | tail -n 1)"
        [ "$verbose" -eq 1 ] && sed 's/^/        /' "$log"
    else
        failed=$((failed + 1))
        failed_names+=("$name")
        printf 'FAIL  %s\n' "$name"
        sed 's/^/        /' "$log"
    fi
}

lint_php() {
    local file count=0
    while IFS= read -r -d '' file; do
        php -l "$file" >/dev/null || return 1
        count=$((count + 1))
    done < <(find plugin theme tests -type f -name '*.php' -print0)
    printf '%s PHP files pass syntax lint.\n' "$count"
}

check_js() {
    local file count=0
    while IFS= read -r -d '' file; do
        node --check "$file" || return 1
        count=$((count + 1))
    done < <(find plugin theme tests -type f -name '*.js' -print0)
    printf '%s JavaScript files pass syntax check.\n' "$count"
}

run_suite "secret scanner" bash tests/sh/test-scan-secrets.sh
run_suite "php lint" lint_php
run_suite "javascript syntax" check_js

for file in tests/php/run-tests.php tests/php/test-*.php; do
    name="php: $(basename "$file" .php | sed 's/^test-//')"
    if [ "$file" = "tests/php/test-text.php" ]; then
        # Proves the mbstring fallbacks by hiding the real functions.
        run_suite "$name" php -d disable_functions=mb_strtoupper,mb_strtolower,mb_strrpos "$file"
    else
        run_suite "$name" php "$file"
    fi
done

for file in tests/js/test-*.js; do
    run_suite "js: $(basename "$file" .js | sed 's/^test-//')" node "$file"
done

total=$((passed + failed))
printf '\n%s of %s suites passed.\n' "$passed" "$total"
if [ "$failed" -gt 0 ]; then
    printf 'Failed: %s\n' "${failed_names[*]}"
    exit 1
fi
