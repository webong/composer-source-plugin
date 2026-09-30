#!/usr/bin/env bash
# End-to-end harness for the local source mirror feature.
#
# Builds a throwaway upstream git repository and a consumer project, then runs
# real composer commands against them inside a container. Nothing here touches
# a real application.
#
#   docker run --rm \
#     -v "$(pwd)/tests/integration:/lab" \
#     -v "$PWD:/plugin" \
#     -w /lab -e COMPOSER_ALLOW_SUPERUSER=1 -e COMPOSER_HOME=/lab/.composer \
#     composer:2 bash /lab/mirror.sh
#
set -uo pipefail

LAB=$(mktemp -d)
UPSTREAM="$LAB/upstream"
CONSUMER="$LAB/consumer"
MIRROR="$CONSUMER/ext/fluent"
PLUGIN=${PLUGIN:-/plugin}

PASS=0
FAIL=0

pass() { echo "  PASS  $1"; PASS=$((PASS+1)); }
fail() { echo "  FAIL  $1"; FAIL=$((FAIL+1)); }
section() { echo; echo "== $1 =="; }

assert_file()      { [ -f "$1" ] && pass "$2" || fail "$2 (missing $1)"; }
assert_no_file()   { [ ! -f "$1" ] && pass "$2" || fail "$2 (unexpectedly present $1)"; }
assert_dir()       { [ -d "$1" ] && pass "$2" || fail "$2 (missing dir $1)"; }
assert_contains()  { grep -qF "$2" "$1" 2>/dev/null && pass "$3" || fail "$3 ('$2' not in $1)"; }
assert_missing()   { grep -qF "$2" "$1" 2>/dev/null && fail "$3 ('$2' unexpectedly in $1)" || pass "$3"; }
assert_eq()        { [ "$1" = "$2" ] && pass "$3" || fail "$3 (expected '$2', got '$1')"; }
stat_of()          { stat -c %Y "$1"; }

upstream_commit() {
  local msg="$1"
  ( cd "$UPSTREAM" && git add -A && git -c user.email=t@t -c user.name=t commit -q -m "$msg" ) >/dev/null 2>&1
}

run_composer() {
  ( cd "$CONSUMER" && composer "$@" --no-interaction --no-progress 2>&1 )
}

echo "### building upstream package"
rm -rf "$LAB/upstream" "$CONSUMER"
mkdir -p "$UPSTREAM/src" "$CONSUMER"
cat > "$UPSTREAM/composer.json" <<'JSON'
{
  "name": "acme/fluent",
  "type": "library",
  "require": { "php": ">=8.1" },
  "autoload": { "psr-4": { "Acme\\Fluent\\": "src/" } }
}
JSON
printf '<?php\n\nnamespace Acme\\Fluent;\n\nclass Thing\n{\n    public const ORIGIN = "upstream-v1";\n}\n' > "$UPSTREAM/src/Thing.php"
printf '<?php\n\nnamespace Acme\\Fluent;\n\ntrait Helper\n{\n}\n' > "$UPSTREAM/src/Helper.php"
echo "# acme/fluent" > "$UPSTREAM/README.md"
( cd "$UPSTREAM" && git init -q -b main && git config user.email t@t && git config user.name t && git add -A && git commit -q -m "v1" )

echo "### building consumer project"
cat > "$CONSUMER/composer.json" <<JSON
{
  "name": "lab/consumer",
  "require": { "acme/fluent": "@dev", "webong/composer-source-plugin": "@dev" },
  "repositories": [
    { "type": "path", "url": "$PLUGIN", "options": { "symlink": true } },
    { "type": "vcs", "url": "$UPSTREAM" },
    { "packagist.org": false }
  ],
  "extra": {
    "source-plugin": {
      "mirrors": { "acme/fluent": { "path": "ext/fluent", "origin": "remote" } },
      "aliases": { "acme/fluent": { "Acme\\\\Fluent\\\\": "Local\\\\Fluent\\\\", "type": "rebase", "destination": "mirror" } }
    }
  },
  "config": { "allow-plugins": { "webong/composer-source-plugin": true } }
}
JSON

section "1. composer update syncs the remote into the mirror"
# A Composer plugin cannot act on the run that first installs it.
run_composer update >/dev/null 2>&1
OUT=$(run_composer update)
echo "$OUT" | grep -E "Mirrored|mirror" | sed 's/^/    /'
assert_file "$MIRROR/src/Thing.php"        "mirror contains src/Thing.php"
assert_file "$MIRROR/composer.json"        "mirror contains composer.json"
assert_file "$MIRROR/.source-plugin/sync.json" "snapshot written"
assert_dir  "$CONSUMER/vendor/acme/fluent" "remote still installed in vendor"
assert_contains "$MIRROR/src/Thing.php" "namespace Local\\Fluent" "mirror itself is rebased"

section "2. no path repository is required"
grep -q '"url": "ext/fluent"' "$CONSUMER/composer.json" && fail "consumer should not declare a path repo for the mirror" || pass "consumer declares no path repo for ext/fluent"
[ -L "$CONSUMER/vendor/acme/fluent" ] && fail "vendor copy must not be a symlink to the mirror" || pass "vendor copy is a real installed copy"

section "3. rebase is generated from the mirror, not vendor"
assert_dir "$CONSUMER/vendor/composer/rebased/acme--fluent/src" "rebased tree exists"
assert_contains "$CONSUMER/vendor/composer/namespace_rebases.php" "rebased/acme--fluent/src" "rebase maps to the mirror path"
assert_missing  "$CONSUMER/vendor/composer/namespace_rebases.php" "rebased/acme-fluent/src" "rebase does not point at the vendor path"

section "4. second sync is a no-op (no mtime churn)"
BEFORE=$(stat -c %Y "$MIRROR/src/Thing.php")
run_composer dump-autoload >/dev/null
AFTER=$(stat -c %Y "$MIRROR/src/Thing.php")
assert_eq "$AFTER" "$BEFORE" "repeated sync left mtime untouched"

section "5. a local edit to a file upstream did not touch survives an update"
printf '<?php\n\nnamespace Acme\\Fluent;\n\nclass Thing\n{\n    public const ORIGIN = "MY-LOCAL-EDIT";\n}\n' > "$MIRROR/src/Thing.php"
printf '<?php\n\nnamespace Acme\\Fluent;\n\nclass Other\n{\n}\n' > "$UPSTREAM/src/Other.php"
upstream_commit "add Other"
run_composer update >/dev/null
assert_contains "$MIRROR/src/Thing.php" "MY-LOCAL-EDIT" "local edit preserved"
assert_file "$MIRROR/src/Other.php" "new upstream file still arrives"

section "6. a file changed on both sides is reported, never overwritten"
printf '<?php\n\nnamespace Acme\\Fluent;\n\nclass Other\n{\n    public const WHO = "LOCAL";\n}\n' > "$MIRROR/src/Other.php"
printf '<?php\n\nnamespace Acme\\Fluent;\n\nclass Other\n{\n    public const WHO = "UPSTREAM";\n}\n' > "$UPSTREAM/src/Other.php"
upstream_commit "change Other"
OUT=$(run_composer update)
echo "$OUT" | grep -E "conflicts with upstream" | sed 's/^/    /'
assert_contains "$MIRROR/src/Other.php" "LOCAL" "local version kept"
assert_file "$MIRROR/src/Other.php.upstream" "upstream version parked as sidecar"
assert_contains "$MIRROR/src/Other.php.upstream" "UPSTREAM" "sidecar holds upstream content"

section "7. conflict survives until resolved, then clears"
run_composer update >/dev/null
assert_file "$MIRROR/src/Other.php.upstream" "still conflicting on a second pass"
cp "$MIRROR/src/Other.php.upstream" "$MIRROR/src/Other.php"
OUT=$(run_composer update)
run_composer update >/dev/null
assert_contains "$MIRROR/src/Other.php" "UPSTREAM" "accepted upstream version in place"

section "8. the aliased namespace resolves at runtime"
if ( cd "$CONSUMER" && php -r '
require "vendor/autoload.php";
$ok = class_exists("Local\\Fluent\\Thing");
$viaAlias = class_exists("Acme\\Fluent\\Thing");
printf("Local\\Fluent\\Thing: %s\n", $ok ? "loads" : "MISSING");
printf("Acme\\Fluent\\Thing:  %s\n", $viaAlias ? "loads" : "MISSING");
printf("same class: %s\n", ($ok && $viaAlias) && (new ReflectionClass("Local\\Fluent\\Thing"))->getName() === (new ReflectionClass("Acme\\Fluent\\Thing"))->getName() ? "yes" : "no");
exit(($ok && $viaAlias) && (new ReflectionClass("Local\\Fluent\\Thing"))->getName() === (new ReflectionClass("Acme\\Fluent\\Thing"))->getName() ? 0 : 1);
' ); then pass "both namespaces resolve to the rebased class"; else fail "runtime alias loading"; fi

section "9. mirroring and loading the same package is rejected"
cp "$CONSUMER/composer.json" "$CONSUMER/composer.json.bak"
php -r '
$f = $argv[1];
$j = json_decode(file_get_contents($f), true);
$j["extra"]["source-plugin"]["loaders"] = ["acme/fluent" => ["type" => "inline"]];
file_put_contents($f, json_encode($j, JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES));
' "$CONSUMER/composer.json"
OUT=$(run_composer update)
echo "$OUT" | grep -E "both mirrored and loadable" | head -1 | sed 's/^/    /'
echo "$OUT" | grep -q "both mirrored and loadable" && pass "mutual exclusion error surfaced" || fail "expected mutual exclusion error"
mv "$CONSUMER/composer.json.bak" "$CONSUMER/composer.json"

section "10. consumers without mirrors are unaffected"
rm -rf "$CONSUMER/ext" "$CONSUMER/vendor" "$CONSUMER/composer.lock"
# A consumer that does not use mirrors at all: drop the key entirely.
php -r '
$f = $argv[1];
$j = json_decode(file_get_contents($f), true);
unset($j["extra"]["source-plugin"]["mirrors"]);
file_put_contents($f, json_encode($j, JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES));
' "$CONSUMER/composer.json"
OUT=$(run_composer update)
echo "$OUT" | tail -6 | sed 's/^/    /'
# Bootstrap run installs the plugin; a plugin cannot act on that same run.
run_composer update >/dev/null 2>&1
assert_dir "$CONSUMER/vendor/acme/fluent" "install works with no mirror configured"
assert_contains "$CONSUMER/vendor/acme/fluent/src/Thing.php" "upstream-v1" "vendor copy intact"
assert_dir "$CONSUMER/vendor/composer/rebased/acme--fluent/src" "rebase still generated without mirrors"
assert_dir "$CONSUMER/vendor/composer" "no mirror directory created"
assert_no_file "$CONSUMER/ext/fluent/src/Thing.php" "nothing mirrored into ext"

section "11. opt-in autoload copying preserves layout and removes old metadata"
mkdir -p "$UPSTREAM/src/Tests" "$UPSTREAM/resources"
printf '<?php namespace Acme\\Fluent\\Tests; class Hidden {}\n' > "$UPSTREAM/src/Tests/Hidden.php"
printf 'runtime resource\n' > "$UPSTREAM/resources/view.txt"
upstream_commit "add resource and nested development source"
php -r '
$f = $argv[1];
$j = json_decode(file_get_contents($f), true);
$j["extra"]["source-plugin"]["aliases"]["acme/fluent"]["copy"] = "autoload";
$j["extra"]["source-plugin"]["aliases"]["acme/fluent"]["include"] = ["resources"];
$j["extra"]["source-plugin"]["aliases"]["acme/fluent"]["exclude"] = ["src/Tests"];
file_put_contents($f, json_encode($j, JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES));
' "$CONSUMER/composer.json"
if run_composer update >/dev/null; then pass "autoload policy update succeeds"; else fail "autoload policy update"; fi
assert_file "$CONSUMER/vendor/composer/rebased/acme--fluent/src/Thing.php" "original source layout retained"
assert_file "$CONSUMER/vendor/composer/rebased/acme--fluent/resources/view.txt" "explicit runtime resource copied"
assert_no_file "$CONSUMER/vendor/composer/rebased/acme--fluent/README.md" "stale root documentation removed"
assert_no_file "$CONSUMER/vendor/composer/rebased/acme--fluent/composer.json" "stale manifest removed"
assert_no_file "$CONSUMER/vendor/composer/rebased/acme--fluent/src/Tests/Hidden.php" "nested development subtree excluded"
assert_missing "$CONSUMER/vendor/composer/namespace_rebases.php" "Hidden" "excluded symbols are not registered"
if (cd "$CONSUMER" && php -r 'require "vendor/autoload.php"; exit(class_exists("Local\\Fluent\\Thing") ? 0 : 1);'); then pass "lean rebase loads at runtime"; else fail "lean runtime loading"; fi
assert_eq "$(grep -c 'class_alias.*Thing' "$CONSUMER/vendor/composer/namespace_rebases.php")" "1" "installed dev aliases generate one class alias"

echo
echo "================ $PASS passed, $FAIL failed ================"
[ "$FAIL" -eq 0 ]
