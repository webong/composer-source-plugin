#!/usr/bin/env bash
# End-to-end harness for the workspaces feature.
#
# Builds a throwaway upstream VCS repository, two local workspace packages and
# a consumer that only declares `extra.source-plugin.workspaces`. Nothing here
# touches a real application.
#
#   bash tests/integration/workspaces.sh
#   PLUGIN=/path/to/plugin bash tests/integration/workspaces.sh
#
set -uo pipefail

PLUGIN=${PLUGIN:-}
if [ -z "$PLUGIN" ]; then
  if [ -d /plugin/src ]; then PLUGIN=/plugin; else PLUGIN=$(pwd); fi
fi
LAB=$(mktemp -d)
UPSTREAM="$LAB/upstream"
CONSUMER="$LAB/consumer"
PACKAGES="$CONSUMER/packages"
export COMPOSER_HOME="$LAB/composer-home"
export COMPOSER_ALLOW_SUPERUSER=${COMPOSER_ALLOW_SUPERUSER:-1}
export COMPOSER_ROOT_VERSION=${COMPOSER_ROOT_VERSION:-1.0.0}
mkdir -p "$COMPOSER_HOME"

PASS=0
FAIL=0

pass() { echo "  PASS  $1"; PASS=$((PASS+1)); }
fail() { echo "  FAIL  $1"; FAIL=$((FAIL+1)); }
section() { echo; echo "== $1 =="; }

assert_file()    { [ -f "$1" ] && pass "$2" || fail "$2 (missing $1)"; }
assert_no_file() { [ ! -f "$1" ] && pass "$2" || fail "$2 (unexpectedly present $1)"; }
assert_symlink() { [ -L "$1" ] && pass "$2" || fail "$2 (not a symlink: $1)"; }
assert_dir()     { [ -d "$1" ] && pass "$2" || fail "$2 (missing directory $1)"; }
assert_not_symlink() { [ -e "$1" ] && [ ! -L "$1" ] && pass "$2" || fail "$2 (expected a real directory)"; }
assert_contains() { grep -qF "$2" "$1" 2>/dev/null && pass "$3" || fail "$3 ('$2' not in $1)"; }
assert_eq()      { [ "$1" = "$2" ] && pass "$3" || fail "$3 (expected '$2', got '$1')"; }

run_composer() {
  ( cd "$CONSUMER" && composer "$@" --no-interaction --no-progress 2>&1 )
}

# The diagnostic command does not inherit Composer's --no-progress option.
run_source() {
  ( cd "$CONSUMER" && composer source:workspaces "$@" --no-interaction 2>&1 )
}

# Small JSON mutator so scenarios stay readable: set <path> <json> | del <path>
cat > "$LAB/tweak.php" <<'PHP'
<?php
$file = $argv[1];
$data = json_decode(file_get_contents($file), true, 512, JSON_THROW_ON_ERROR);
$path = explode('.', $argv[2]);
$last = array_pop($path);
$ref =& $data;
foreach ($path as $segment) {
    $ref =& $ref[$segment];
}
if ($argv[3] === 'set') {
    $ref[$last] = json_decode($argv[4], true, 512, JSON_THROW_ON_ERROR);
} else {
    unset($ref[$last]);
}
file_put_contents($file, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
PHP

tweak() { php "$LAB/tweak.php" "$CONSUMER/composer.json" "$@"; }

echo "### building upstream acme/core 1.0.0"
mkdir -p "$UPSTREAM/src"
cat > "$UPSTREAM/composer.json" <<'JSON'
{
  "name": "acme/core",
  "type": "library",
  "require": { "php": ">=8.1" },
  "autoload": { "psr-4": { "Acme\\Core\\": "src/" } }
}
JSON
printf '<?php\n\nnamespace Acme\\Core;\n\nclass Thing\n{\n    public const ORIGIN = "vcs-1.0.0";\n}\n' > "$UPSTREAM/src/Thing.php"
( cd "$UPSTREAM" && git init -q -b main && git config user.email t@t && git config user.name t \
  && git add -A && git commit -q -m v1 && git tag 1.0.0 )

echo "### building local workspaces"
mkdir -p "$PACKAGES/core/src" "$PACKAGES/util/src"
cat > "$PACKAGES/core/composer.json" <<'JSON'
{
  "name": "acme/core",
  "type": "library",
  "version": "2.0.0",
  "require": { "php": ">=8.1", "acme/util": "^1.0" },
  "autoload": { "psr-4": { "Acme\\Core\\": "src/" } }
}
JSON
printf '<?php\n\nnamespace Acme\\Core;\n\nclass Thing\n{\n    public const ORIGIN = "workspace-2.0.0";\n}\n' > "$PACKAGES/core/src/Thing.php"
cat > "$PACKAGES/util/composer.json" <<'JSON'
{
  "name": "acme/util",
  "type": "library",
  "version": "1.2.0",
  "require": { "php": ">=8.1" },
  "autoload": { "psr-4": { "Acme\\Util\\": "src/" } }
}
JSON
printf '<?php\n\nnamespace Acme\\Util;\n\nfinal class Func {}\n' > "$PACKAGES/util/src/Func.php"

echo "### bootstrap: install the plugin alone (a plugin cannot act on the run that installs it)"
mkdir -p "$CONSUMER"
cat > "$CONSUMER/composer.json" <<JSON
{
  "name": "lab/consumer",
  "require": { "webong/composer-source-plugin": "@dev" },
  "repositories": [
    { "type": "path", "url": "$PLUGIN", "options": { "symlink": true } },
    { "packagist.org": false }
  ],
  "config": { "allow-plugins": { "webong/composer-source-plugin": true } }
}
JSON
run_composer update >/dev/null 2>&1
assert_dir "$CONSUMER/vendor/webong/composer-source-plugin" "plugin installed by bootstrap run"

section "1. enabling workspaces links local packages"
cat > "$CONSUMER/composer.json" <<JSON
{
  "name": "lab/consumer",
  "require": {
    "webong/composer-source-plugin": "@dev",
    "acme/core": "@dev"
  },
  "repositories": [
    { "type": "path", "url": "$PLUGIN", "options": { "symlink": true } },
    { "type": "vcs", "url": "$UPSTREAM" },
    { "packagist.org": false }
  ],
  "extra": {
    "source-plugin": { "workspaces": ["packages/*"] }
  },
  "config": { "allow-plugins": { "webong/composer-source-plugin": true } }
}
JSON
OUT=$(run_composer update)
echo "$OUT" | grep -E "Installing acme|Symlinking" | sed 's/^/    /'
assert_symlink "$CONSUMER/vendor/acme/core" "local acme/core is symlinked into vendor"
assert_contains "$CONSUMER/vendor/acme/core/src/Thing.php" 'ORIGIN = "workspace-2.0.0"' "local source wins over the VCS tag"
assert_symlink "$CONSUMER/vendor/acme/util" "sibling workspace dependency resolved locally"
assert_contains "$CONSUMER/vendor/acme/util/src/Func.php" 'class Func' "sibling source present"

section "2. composer.lock records a portable, relative path"
DIST=$(php -r '$l=json_decode(file_get_contents($argv[1]),true); foreach($l["packages"] as $p){ if($p["name"]==="acme/core"){ echo $p["dist"]["type"],"|",$p["dist"]["url"]; }}' "$CONSUMER/composer.lock")
assert_eq "path|packages/core" "$DIST" "lock stores a relative path dist"

section "3. editing a workspace is live without reinstalling"
printf '<?php\n\nnamespace Acme\\Core;\n\nclass Thing\n{\n    public const ORIGIN = "live-edit";\n}\n' > "$PACKAGES/core/src/Thing.php"
if ( cd "$CONSUMER" && php -r 'require "vendor/autoload.php"; exit(\Acme\Core\Thing::ORIGIN === "live-edit" ? 0 : 1);' ); then
  pass "runtime sees the edit through the symlink"
else
  fail "runtime does not see the live edit"
fi
if ( cd "$CONSUMER" && php -r 'require "vendor/autoload.php"; exit(class_exists("Acme\\Util\\Func") ? 0 : 1);' ); then
  pass "sibling classes autoload"
else
  fail "sibling classes do not autoload"
fi
printf '<?php\n\nnamespace Acme\\Core;\n\nclass Thing\n{\n    public const ORIGIN = "workspace-2.0.0";\n}\n' > "$PACKAGES/core/src/Thing.php"

section "3a. composer source:workspaces reports the graph"
OUT=$(run_source)
echo "$OUT" | sed 's/^/    /'
echo "$OUT" | grep -qF "2 workspace(s): 2 linked, 0 outline." && pass "summary counts linked workspaces" || fail "expected the workspace summary"
echo "$OUT" | grep -qF "packages/core" && pass "summary lists the workspace path" || fail "expected the workspace path"
JSON=$(run_source --json)
echo "$JSON" | grep -qF '"package": "acme/core"' && pass "json lists acme/core" || fail "json missing acme/core"
echo "$JSON" | grep -qF '"mode": "linked"' && pass "json reports linked mode" || fail "json missing linked mode"
echo "$JSON" | grep -qF '"requires": {' && pass "json includes sibling requirements" || fail "json missing requirements"

section "4. a mismatched root constraint fails before the solver"
tweak 'require.acme/core' set '"^9.0"'
OUT=$(run_composer update)
echo "$OUT" | grep -F "but the root requires ^9.0" | head -1 | sed 's/^/    /'
echo "$OUT" | grep -qF "Workspace [acme/core] is 2.0.0" && pass "actionable version mismatch" || fail "expected an actionable version mismatch"
OUT=$(run_source); STATUS=$?
echo "$OUT" | grep -F "but the root requires ^9.0" | head -1 | sed 's/^/    /'
assert_eq "1" "$STATUS" "the diagnostic command exits non-zero on a broken configuration"
echo "$OUT" | grep -qF "but the root requires ^9.0" && pass "the diagnostic command explains the mismatch" || fail "diagnostic command missing the mismatch"
tweak 'require.acme/core' set '"@dev"'

section "5. a semver requirement without a workspace version fails"
php -r '$f=$argv[1]; $j=json_decode(file_get_contents($f),true); unset($j["version"]); file_put_contents($f,json_encode($j,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES));' "$PACKAGES/core/composer.json"
tweak 'require.acme/core' set '"^2.0"'
OUT=$(run_composer update)
echo "$OUT" | grep -F "does not declare a version" | head -1 | sed 's/^/    /'
echo "$OUT" | grep -qF "does not declare a version" && pass "missing version is reported" || fail "expected a missing version error"
tweak 'require.acme/core' set '"@dev"'
php -r '$f=$argv[1]; $j=json_decode(file_get_contents($f),true); $j["version"]="2.0.0"; file_put_contents($f,json_encode($j,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES));' "$PACKAGES/core/composer.json"

section "6. an explicit outline loader opts back into the published package"
tweak 'require.acme/core' set '"^1.0"'
tweak 'extra.source-plugin.loaders' set '{"acme/core":{"type":"outline"}}'
run_composer update >/dev/null
assert_not_symlink "$CONSUMER/vendor/acme/core" "outline override installs a real vendor copy"
assert_contains "$CONSUMER/vendor/acme/core/src/Thing.php" 'ORIGIN = "vcs-1.0.0"' "outline override picks the VCS package"
JSON=$(run_source --json)
echo "$JSON" | grep -qF '"mode": "outline"' && pass "diagnostic reports the outline override" || fail "expected outline mode in the diagnostic output"

section "7. a workspace cannot also be mirrored"
tweak 'extra.source-plugin.loaders' del
tweak 'require.acme/core' set '"@dev"'
tweak 'extra.source-plugin.mirrors' set '{"acme/core":{"path":"ext/core"}}'
OUT=$(run_composer update)
echo "$OUT" | grep -F "cannot be both mirrored and loadable" | head -1 | sed 's/^/    /'
echo "$OUT" | grep -qF "cannot be both mirrored and loadable" && pass "mirror conflict rejected at activation" || fail "expected a mirror conflict"
tweak 'extra.source-plugin.mirrors' del

section "8. --no-plugins falls back to the registry (documented caveat)"
run_composer update --no-plugins >/dev/null 2>&1
assert_not_symlink "$CONSUMER/vendor/acme/core" "--no-plugins installs a real vendor copy"
assert_contains "$CONSUMER/vendor/acme/core/src/Thing.php" 'ORIGIN = "vcs-1.0.0"' "--no-plugins loses the local override"
run_composer update >/dev/null 2>&1
assert_symlink "$CONSUMER/vendor/acme/core" "a normal update restores the workspace link"

section "9. a committed lock bootstraps a fresh clone without repositories"
CLONE="$LAB/clone"
mkdir -p "$CLONE"
cp "$CONSUMER/composer.lock" "$CLONE/composer.lock"
cp -R "$PACKAGES" "$CLONE/packages"
php -r '$j=json_decode(file_get_contents($argv[1]),true); unset($j["repositories"]); file_put_contents($argv[2],json_encode($j,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES));' "$CONSUMER/composer.json" "$CLONE/composer.json"
( cd "$CLONE" && composer install --no-interaction --no-progress --no-plugins 2>&1 | tail -4 | sed 's/^/    /' )
assert_symlink "$CLONE/vendor/acme/core" "lock-only install links the workspace without the plugin active"
assert_contains "$CLONE/vendor/acme/core/src/Thing.php" 'ORIGIN = "workspace-2.0.0"' "lock-only install keeps the workspace source"
if ( cd "$CLONE" && php -r 'require "vendor/autoload.php"; exit(class_exists("Acme\\Core\\Thing") ? 0 : 1);' ); then
  pass "lock-only install autoloads"
else
  fail "lock-only install autoload failure"
fi

section "10. no workspace configuration leaves a project untouched"
mkdir -p "$LAB/plain"
cat > "$LAB/plain/composer.json" <<JSON
{
  "name": "lab/plain",
  "require": { "acme/core": "^1.0" },
  "repositories": [
    { "type": "vcs", "url": "$UPSTREAM" },
    { "packagist.org": false }
  ]
}
JSON
( cd "$LAB/plain" && composer update --no-interaction --no-progress >/dev/null 2>&1 )
assert_not_symlink "$LAB/plain/vendor/acme/core" "projects without workspaces are unaffected"

echo
echo "================ $PASS passed, $FAIL failed ================"
[ "$FAIL" -eq 0 ]
