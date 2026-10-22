#!/usr/bin/env bash
# End-to-end harness for package surfaces / projections.
#
# Builds a throwaway monolith upstream package plus a consumer, then runs real
# Composer commands in the container. The complete archive is always
# downloaded; the plugin prunes the installed copy to the selected surfaces.
#
#   docker run --rm \
#     -v "$PWD:/plugin:ro" \
#     -e COMPOSER_ALLOW_SUPERUSER=1 -e COMPOSER_HOME=/tmp/composer \
#     composer:2 bash /plugin/tests/integration/projection.sh
#
set -uo pipefail

LAB=$(mktemp -d)
UPSTREAM="$LAB/upstream"
ARTIFACTS="$LAB/artifacts"
CONSUMER="$LAB/consumer"
LOCK_CONSUMER="$LAB/lock-consumer"
SOURCE_CONSUMER="$LAB/source-consumer"
PLUGIN=${PLUGIN:-/plugin}
PACKAGE="$CONSUMER/vendor/acme/monolith"

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

run_composer() {
  ( cd "$CONSUMER" && COLUMNS=200 composer "$@" --no-interaction --no-progress 2>&1 )
}

run_in() {
  local dir="$1"
  shift
  ( cd "$dir" && COLUMNS=200 composer "$@" --no-interaction --no-progress 2>&1 )
}

tree_of() {
  ( cd "$1" && find . -path './.git' -prune -o -type f -print -o -type l -print | sort )
}

upstream_commit() {
  local msg="$1"
  ( cd "$UPSTREAM" && git add -A && git -c user.email=t@t -c user.name=t commit -q -m "$msg" ) >/dev/null 2>&1
}

archive_version() {
  local version="$1"
  ( cd "$UPSTREAM" && git archive --format=zip --prefix=acme-monolith/ -o "$ARTIFACTS/acme-monolith-$version.zip" HEAD ) >/dev/null 2>&1
}

set_surfaces() {
  php -r '
    $f = $argv[1];
    $surfaces = array_slice($argv, 2);
    $j = json_decode(file_get_contents($f), true);
    $j["extra"]["source-plugin"]["projections"]["acme/monolith"]["surfaces"] = array_values($surfaces);
    file_put_contents($f, json_encode($j, JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES));
  ' "$1" "${@:2}"
}

echo "### building monolith upstream"
mkdir -p "$ARTIFACTS" "$UPSTREAM/src/Core" "$UPSTREAM/src/Core/Legacy" "$UPSTREAM/src/Core/Nested" \
         "$UPSTREAM/src/JavaScript" "$UPSTREAM/src/Lua" "$UPSTREAM/resources/javascript" \
         "$UPSTREAM/resources/lua" "$UPSTREAM/docs/lua" "$UPSTREAM/tests"
cat > "$UPSTREAM/composer.json" <<'JSON'
{
  "name": "acme/monolith",
  "version": "1.0.0",
  "type": "library",
  "require": { "php": ">=8.1" },
  "autoload": {
    "psr-4": { "Acme\\Monolith\\": "src/" },
    "classmap": ["src/Core/Legacy/"],
    "files": ["src/Core/bootstrap.php"],
    "exclude-from-classmap": ["src/Lua/Tests/"]
  },
  "extra": {
    "source-plugin": {
      "surfaces": {
        "core": {
          "include": ["composer.json", "LICENSE", "src/Core/**", "src/shared/**"]
        },
        "javascript": {
          "include": ["src/JavaScript/**", "resources/javascript/**"]
        },
        "lua": {
          "include": ["src/Lua/**", "resources/lua/**", "docs/lua/**"]
        },
        "docs": {
          "include": ["docs/**"]
        }
      }
    }
  }
}
JSON
printf 'MIT\n' > "$UPSTREAM/LICENSE"
echo '# acme/monolith' > "$UPSTREAM/README.md"
printf '<?php\n\nnamespace Acme\\Monolith\\Core;\n\nclass Thing\n{\n    public const SURFACE = "core";\n}\n' > "$UPSTREAM/src/Core/Thing.php"
printf '<?php\n\nnamespace Acme\\Monolith\\Core\\Nested;\n\nclass Deep\n{\n}\n' > "$UPSTREAM/src/Core/Nested/Deep.php"
printf '<?php\n\ndefine("MONOLITH_CORE_BOOT", true);\n' > "$UPSTREAM/src/Core/bootstrap.php"
printf '<?php\n\nnamespace Acme\\Monolith\\Core\\Legacy;\n\nclass OldThing\n{\n}\n' > "$UPSTREAM/src/Core/Legacy/OldThing.php"
printf '<?php\n\nnamespace Acme\\Monolith\\JavaScript;\n\nclass Widget\n{\n    public const SURFACE = "javascript";\n}\n' > "$UPSTREAM/src/JavaScript/Widget.php"
printf 'console.log("monolith");\n' > "$UPSTREAM/resources/javascript/app.js"
printf '<?php\n\nnamespace Acme\\Monolith\\Lua;\n\nclass Thing\n{\n    public const SURFACE = "lua";\n}\n' > "$UPSTREAM/src/Lua/Thing.php"
printf 'print("monolith")\n' > "$UPSTREAM/resources/lua/init.lua"
printf '# Lua guide\n' > "$UPSTREAM/docs/lua/guide.md"
printf '# docs\n' > "$UPSTREAM/docs/index.md"
printf '<?php\n' > "$UPSTREAM/tests/MonolithTest.php"
mkdir -p "$UPSTREAM/src/Lua/Tests"
printf '<?php\n' > "$UPSTREAM/src/Lua/Tests/LuaTest.php"
( cd "$UPSTREAM" && git init -q -b main && git config user.email t@t && git config user.name t && git add -A && git commit -q -m "v1" && git tag -a v1.0.0 -m "v1" )
archive_version 1.0.0

echo "### building consumer"
mkdir -p "$CONSUMER"
cat > "$CONSUMER/composer.json" <<JSON
{
  "name": "lab/projection-consumer",
  "require": {
    "acme/monolith": "^1.0",
    "webong/composer-source-plugin": "@dev"
  },
  "repositories": [
    { "type": "path", "url": "$PLUGIN", "options": { "symlink": true } },
    { "type": "artifact", "url": "$ARTIFACTS" },
    { "packagist.org": false }
  ],
  "extra": {
    "source-plugin": {
      "projections": {
        "acme/monolith": { "surfaces": ["core", "javascript"] }
      }
    }
  },
  "config": { "allow-plugins": { "webong/composer-source-plugin": true } }
}
JSON

section "1. first update installs the plugin, second update projects"
# A Composer plugin cannot act on the run that first installs it.
run_composer update >/dev/null 2>&1
OUT=$(run_composer update)
echo "$OUT" | grep -E "Projected" | sed 's/^/    /'
assert_file   "$PACKAGE/src/Core/Thing.php"          "core class retained"
assert_file   "$PACKAGE/src/Core/Nested/Deep.php"    "nested core class retained"
assert_file   "$PACKAGE/src/Core/Legacy/OldThing.php" "classmap source retained"
assert_file   "$PACKAGE/src/JavaScript/Widget.php"   "javascript class retained"
assert_file   "$PACKAGE/resources/javascript/app.js" "javascript resource retained"
assert_file   "$PACKAGE/composer.json"               "root composer.json retained"
assert_file   "$PACKAGE/LICENSE"                     "LICENSE retained by core surface"
assert_no_file "$PACKAGE/src/Lua/Thing.php"          "lua class removed"
assert_no_file "$PACKAGE/resources/lua/init.lua"     "lua resource removed"
assert_no_file "$PACKAGE/docs/lua/guide.md"          "lua docs removed"
assert_no_file "$PACKAGE/README.md"                  "unselected root README removed"
assert_no_file "$PACKAGE/tests/MonolithTest.php"     "unselected tests removed"
assert_file   "$PACKAGE/.source-plugin/projection.json" "projection marker written"
assert_dir    "$CONSUMER/vendor/acme/monolith"       "full archive was installed under vendor"
assert_file   "$CONSUMER/composer-source.lock"       "projection state recorded in composer-source.lock"
assert_contains "$CONSUMER/composer-source.lock" '"projections"' "lock carries projections"
assert_contains "$CONSUMER/composer-source.lock" '"patterns_digest"' "lock carries a stable digest"
assert_contains "$CONSUMER/composer-source.lock" '"definition_digest"' "lock carries a surface-definition digest"
assert_missing "$CONSUMER/composer-source.lock" "$LAB" "lock contains no absolute lab path"

section "2. selected autoload forms still work, unselected classes do not"
if ( cd "$CONSUMER" && php -r '
require "vendor/autoload.php";
$core = class_exists("Acme\\Monolith\\Core\\Thing");
$nested = class_exists("Acme\\Monolith\\Core\\Nested\\Deep");
$legacy = class_exists("Acme\\Monolith\\Core\\Legacy\\OldThing");
$js = class_exists("Acme\\Monolith\\JavaScript\\Widget");
$lua = class_exists("Acme\\Monolith\\Lua\\Thing");
$boot = defined("MONOLITH_CORE_BOOT");
printf("core=%s nested=%s classmap=%s js=%s lua=%s bootstrap=%s\n",
    $core?"yes":"no", $nested?"yes":"no", $legacy?"yes":"no", $js?"yes":"no", $lua?"yes":"no", $boot?"yes":"no");
exit($core && $nested && $legacy && $js && !$lua && $boot ? 0 : 1);
' ); then pass "PSR-4, classmap and files autoload work after projection"; else fail "autoload after projection"; fi

section "3. narrowing removes javascript without a reinstall"
set_surfaces "$CONSUMER/composer.json" core
if run_composer update >/dev/null; then pass "narrowing update succeeds"; else fail "narrowing update"; fi
assert_file   "$PACKAGE/src/Core/Thing.php"        "core retained after narrowing"
assert_no_file "$PACKAGE/src/JavaScript/Widget.php" "javascript removed after narrowing"
assert_no_file "$PACKAGE/resources/javascript/app.js" "javascript resource removed after narrowing"
assert_contains "$CONSUMER/composer-source.lock" '"core"' "state advanced to core"
php -r '
$j = json_decode(file_get_contents($argv[1]), true);
exit(isset($j["projections"]["acme/monolith"]) && $j["projections"]["acme/monolith"]["surfaces"] === ["core"] ? 0 : 1);
' "$CONSUMER/composer-source.lock" && pass "narrowed surface list locked" || fail "narrowed surface list locked"

section "4. widening without reinstall is actionable; composer reinstall restores it"
set_surfaces "$CONSUMER/composer.json" core javascript
STATE_BEFORE=$(sha256sum "$CONSUMER/composer-source.lock")
OUT=$(run_composer update)
echo "$OUT" | grep -E "composer reinstall|Cannot widen" | sed 's/^/    /'
echo "$OUT" | grep -q "composer reinstall acme/monolith" && pass "widening explains how to reinstall" || fail "widening action message"
assert_eq "$(sha256sum "$CONSUMER/composer-source.lock")" "$STATE_BEFORE" "failed widening leaves state untouched"
assert_no_file "$PACKAGE/src/JavaScript/Widget.php" "widening failure does not silently leave a partial surface"
REINSTALL_OUT=$(run_composer reinstall acme/monolith)
if [ $? -eq 0 ]; then pass "composer reinstall succeeds"; else echo "$REINSTALL_OUT" | sed 's/^/    /'; fail "composer reinstall"; fi
assert_file "$PACKAGE/src/JavaScript/Widget.php" "reinstall restores the widened surface"
assert_no_file "$PACKAGE/src/Lua/Thing.php" "reinstall still prunes unselected lua"
php -r '
$j = json_decode(file_get_contents($argv[1]), true);
$s = $j["projections"]["acme/monolith"]["surfaces"] ?? [];
sort($s);
exit($s === ["core", "javascript"] ? 0 : 1);
' "$CONSUMER/composer-source.lock" && pass "reinstall advances the locked surfaces" || fail "reinstall advances the locked surfaces"

section "5. composer update to a newer fixture version projects new files"
printf '<?php\n\nnamespace Acme\\Monolith\\Core;\n\nclass Fresh\n{\n}\n' > "$UPSTREAM/src/Core/Fresh.php"
php -r '
$f = $argv[1];
$j = json_decode(file_get_contents($f), true);
$j["version"] = "1.1.0";
file_put_contents($f, json_encode($j, JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES));
' "$UPSTREAM/composer.json"
upstream_commit "add Fresh core class"
( cd "$UPSTREAM" && git tag -a v1.1.0 -m "v1.1.0" ) >/dev/null 2>&1
archive_version 1.1.0
if run_composer update acme/monolith >/dev/null; then pass "package update succeeds"; else fail "package update"; fi
assert_file   "$PACKAGE/src/Core/Fresh.php"          "new core file from the newer version retained"
assert_file   "$PACKAGE/src/JavaScript/Widget.php"   "selected javascript still retained"
assert_no_file "$PACKAGE/src/Lua/Thing.php"          "lua still pruned after update"
php -r '
$j = json_decode(file_get_contents($argv[1]), true);
exit(($j["projections"]["acme/monolith"]["reference"] ?? "") !== "" ? 0 : 1);
' "$CONSUMER/composer-source.lock" && pass "lock advanced to the new reference" || fail "lock advanced to the new reference"

section "6. lock-based fresh install reproduces the same projected tree"
mkdir -p "$LOCK_CONSUMER"
cp "$CONSUMER/composer.json" "$CONSUMER/composer.lock" "$CONSUMER/composer-source.lock" "$LOCK_CONSUMER/"
# First install has no active plugin; the second install applies the committed state.
LOCK_FIRST=$(run_in "$LOCK_CONSUMER" install)
LOCK_SECOND=$(run_in "$LOCK_CONSUMER" install)
echo "$LOCK_SECOND" | grep -E "Projected|projection|Invalid|Cannot|No projection" | sed 's/^/    /'
assert_file   "$LOCK_CONSUMER/vendor/acme/monolith/src/Core/Thing.php" "lock install retained core"
assert_file   "$LOCK_CONSUMER/vendor/acme/monolith/src/JavaScript/Widget.php" "lock install retained javascript"
assert_no_file "$LOCK_CONSUMER/vendor/acme/monolith/src/Lua/Thing.php" "lock install pruned lua"
OURS=$(tree_of "$CONSUMER/vendor/acme/monolith")
THEIRS=$(tree_of "$LOCK_CONSUMER/vendor/acme/monolith")
assert_eq "$THEIRS" "$OURS" "lock install produced the same package tree"

section "7. dry run and no-install change nothing"
STATE_BEFORE=$(sha256sum "$CONSUMER/composer-source.lock")
TREE_BEFORE=$(tree_of "$PACKAGE")
run_composer update --dry-run >/dev/null
assert_eq "$(sha256sum "$CONSUMER/composer-source.lock")" "$STATE_BEFORE" "dry run leaves projection state untouched"
assert_eq "$(tree_of "$PACKAGE")" "$TREE_BEFORE" "dry run leaves the package tree untouched"
run_composer update --no-install >/dev/null
assert_eq "$(sha256sum "$CONSUMER/composer-source.lock")" "$STATE_BEFORE" "no-install leaves projection state untouched"
assert_eq "$(tree_of "$PACKAGE")" "$TREE_BEFORE" "no-install leaves the package tree untouched"

section "8. a projected package cannot also be mirrored"
cp "$CONSUMER/composer.json" "$CONSUMER/composer.json.bak"
php -r '
$f = $argv[1];
$j = json_decode(file_get_contents($f), true);
$j["extra"]["source-plugin"]["mirrors"] = ["acme/monolith" => ["path" => "ext/monolith"]];
file_put_contents($f, json_encode($j, JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES));
' "$CONSUMER/composer.json"
OUT=$(run_composer update)
echo "$OUT" | grep -E "both projected and mirrored" | head -1 | sed 's/^/    /'
echo "$OUT" | grep -q "both projected and mirrored" && pass "projection/mirror conflict surfaced" || fail "expected projection/mirror conflict"
mv "$CONSUMER/composer.json.bak" "$CONSUMER/composer.json"

section "9. --no-plugins leaves the full ordinary package"
rm -rf "$CONSUMER/vendor"
if run_composer install --no-plugins >/dev/null; then pass "--no-plugins install succeeds"; else fail "--no-plugins install"; fi
assert_file   "$PACKAGE/src/Lua/Thing.php"            "lua present when plugins are disabled"
assert_file   "$PACKAGE/src/JavaScript/Widget.php"    "javascript present when plugins are disabled"
assert_no_file "$PACKAGE/.source-plugin/projection.json" "no projection marker with plugins disabled"
run_composer install >/dev/null
assert_no_file "$PACKAGE/src/Lua/Thing.php" "normal install projects after --no-plugins run"

section "10. prefer-source keeps VCS metadata and still projects"
mkdir -p "$SOURCE_CONSUMER"
cat > "$SOURCE_CONSUMER/composer.json" <<JSON
{
  "name": "lab/source-consumer",
  "require": {
    "acme/monolith": "^1.0",
    "webong/composer-source-plugin": "@dev"
  },
  "repositories": [
    { "type": "path", "url": "$PLUGIN", "options": { "symlink": true } },
    { "type": "vcs", "url": "$UPSTREAM" },
    { "packagist.org": false }
  ],
  "extra": {
    "source-plugin": {
      "projections": {
        "acme/monolith": { "surfaces": ["core", "javascript"] }
      }
    }
  },
  "config": { "allow-plugins": { "webong/composer-source-plugin": true } }
}
JSON
run_in "$SOURCE_CONSUMER" update >/dev/null 2>&1
if run_in "$SOURCE_CONSUMER" update --prefer-source >/dev/null; then pass "prefer-source install succeeds"; else fail "prefer-source install"; fi
SOURCE_PACKAGE="$SOURCE_CONSUMER/vendor/acme/monolith"
assert_dir  "$SOURCE_PACKAGE/.git"                   ".git metadata retained"
assert_file "$SOURCE_PACKAGE/src/Core/Thing.php"     "core retained from source install"
assert_file "$SOURCE_PACKAGE/src/JavaScript/Widget.php" "javascript retained from source install"
assert_no_file "$SOURCE_PACKAGE/src/Lua/Thing.php"   "lua pruned from source install"

echo
echo "================ $PASS passed, $FAIL failed ================"
[ "$FAIL" -eq 0 ]
