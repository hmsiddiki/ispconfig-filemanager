#!/usr/bin/env bash
# Runs the PHP tests. PHP=/path/to/php ./tests/run.sh   (extra PHP flags: PHP_FLAGS="-d extension=curl")
set -u
cd "$(dirname "${BASH_SOURCE[0]}")"
PHP="${PHP:-php}"; FLAGS="${PHP_FLAGS:-}"
failed=0
for t in sso-test.php urlfetch-test.php urljobs-test.php; do
    echo "== $t"
    out="$($PHP $FLAGS "$t" 2>&1 | grep -vE 'already loaded|^$')" || true
    echo "$out" | grep -E "FAIL|passed|Fatal|Uncaught" || echo "$out" | tail -3
    echo "$out" | grep -q "0 failed" || failed=1
done
[ "$failed" -eq 0 ] && echo && echo "ALL TESTS PASSED" || { echo && echo "SOME TESTS FAILED"; exit 1; }
