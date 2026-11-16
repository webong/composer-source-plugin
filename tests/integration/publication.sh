#!/usr/bin/env bash
# Test the generic publisher on a small fixture monorepo (no network, no Packagist):
# splitting, determinism, release tags, metadata, refusal of unsafe releases, atomic push,
# and a real `composer install` from the generated repository.

set -euo pipefail

ROOT=$(CDPATH= cd -- "$(dirname -- "$0")/../.." && pwd)
TOOL="$ROOT/bin/source-publish"
WORK=$(mktemp -d "${TMPDIR:-/tmp}/core-split-test.XXXXXX")
trap 'rm -rf "$WORK"' EXIT

command -v composer >/dev/null || { echo 'composer is required.' >&2; exit 1; }
[ -f "$(git --exec-path)/git-subtree" ] || { echo 'git subtree is required.' >&2; exit 1; }

# Isolate from the caller's git configuration (signing, identity, hooks): the tool must work
# on a CI runner that has none.
export GIT_CONFIG_GLOBAL=/dev/null GIT_CONFIG_SYSTEM=/dev/null
export COMPOSER_ROOT_VERSION=1.0.0 COMPOSER_HOME="$WORK/composer-home" COMPOSER_CACHE_DIR="$WORK/composer-cache" COMPOSER_NO_INTERACTION=1

fail() { echo "FAIL: $*" >&2; exit 1; }
assert_eq() { [ "$1" = "$2" ] || fail "$3: expected [$2], got [$1]"; }
assert_contains() { case "$1" in *"$2"*) ;; *) fail "$3: [$2] not found in: $1";; esac; }
step() { echo "- $*"; }

# jget FILE 'a|b/c|1.0.0|key': read a JSON value; keys are separated by | because names hold / and .
jget() {
    php -r '$v = json_decode(file_get_contents($argv[1]), true);
        foreach (explode("|", $argv[2]) as $k) { if (!is_array($v) || !array_key_exists($k, $v)) { echo "<missing>"; exit(0); } $v = $v[$k]; }
        echo is_scalar($v) ? (string) $v : json_encode($v);' "$1" "$2"
}

# ---- fixture monorepo -------------------------------------------------------------------
MONO="$WORK/mono"
BARE="$WORK/remote.git"
git init -q "$MONO"
git -C "$MONO" checkout -q -b main
git init -q --bare "$BARE"

commit() { # commit MESSAGE
    git -C "$MONO" add -A
    GIT_AUTHOR_NAME=Fixture GIT_AUTHOR_EMAIL=fixture@example.test GIT_COMMITTER_NAME=Fixture GIT_COMMITTER_EMAIL=fixture@example.test \
        git -C "$MONO" commit -q -m "$1"
}
package() { # package DIR NAME [TYPE] [REQUIRE-JSON]
    mkdir -p "$MONO/$1/src"
    php -r '
        $require = ($argv[4] ?? "") !== "" ? json_decode($argv[4], true) : new stdClass();
        echo json_encode([
            "name" => $argv[2], "type" => $argv[3] ?: "library", "license" => "MIT",
            "autoload" => ["psr-4" => ["Acme\\".ucfirst(basename($argv[1]))."\\" => "src/"]],
            "require" => $require,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), "\n";' "$1" "$2" "${3:-}" "${4:-}" > "$MONO/$1/composer.json"
    echo "<?php // $2 v1" > "$MONO/$1/src/Marker.php"
}

cat > "$MONO/composer.json" <<'JSON'
{
  "name": "acme/catalog",
  "type": "metapackage",
  "extra": {"source-plugin": {"workspaces": ["php/*", "adapters/*"]}}
}
JSON
package adapters/laravel acme/adapter
package php/core acme/core library '{"acme/adapter": "dev-main"}'
commit 'add adapter and planner'
package php/web acme/web library '{"acme/core": "dev-main", "acme/adapter": "dev-main"}'
mkdir -p "$MONO/php/golang" && echo 'package x' > "$MONO/php/golang/x.go"     # not a Composer package
package php/host acme/host project                                           # a project, never published
commit 'add web, a Go module and a host project'
echo '<?php // adapter v2' > "$MONO/adapters/laravel/src/Marker.php"
commit 'change the adapter'
git -C "$MONO" remote add origin "$BARE"

split() { php "$TOOL" "$@" --repo="$MONO" --source-url="$BARE"; }

# ---- list ---------------------------------------------------------------------------------
step 'discovers committed workspace manifests at arbitrary configured paths'
listing=$(split list)
assert_contains "$listing" 'acme/adapter' 'list'
assert_contains "$listing" 'acme/web' 'list'
case "$listing" in *acme/host*|*golang*) fail "list included a project or a non-package: $listing";; esac
assert_eq "$(printf '%s\n' "$listing" | wc -l | tr -d ' ')" 3 'package count'

# ---- split --------------------------------------------------------------------------------
step 'split gives each package its own root and history'
split split >/dev/null
assert_eq "$(git -C "$MONO" ls-tree --name-only dist/acme/adapter | tr '\n' ' ')" 'composer.json src ' 'adapter branch root'
assert_eq "$(git -C "$MONO" rev-list --count dist/acme/adapter)" 2 'adapter history is its own commits'
assert_eq "$(git -C "$MONO" rev-list --count dist/acme/web)" 1 'web history'
adapter_tip=$(git -C "$MONO" rev-parse dist/acme/adapter)

step 'split is deterministic'
git -C "$MONO" branch -q -D dist/acme/adapter dist/acme/core dist/acme/web
split split >/dev/null
assert_eq "$(git -C "$MONO" rev-parse dist/acme/adapter)" "$adapter_tip" 'adapter split commit changed between runs'

# ---- release ------------------------------------------------------------------------------
step 'release tags every package and writes consistent metadata'
split release --version=0.1.0 --out-dir="$WORK/out1" >/dev/null
for p in adapter core web; do
    git -C "$MONO" rev-parse -q --verify "refs/tags/acme/$p/v0.1.0" >/dev/null || fail "tag acme/$p/v0.1.0 missing"
done
M="$WORK/out1/composer-packages.json"
assert_eq "$(jget "$M" 'packages|acme/web|0.1.0|require|acme/core')" '^0.1.0' 'tagged requirement rewritten'
assert_eq "$(jget "$M" 'packages|acme/web|0.1.0|require|acme/adapter')" '^0.1.0' 'tagged requirement rewritten (adapter)'
assert_eq "$(jget "$M" 'packages|acme/web|dev-main|require|acme/core')" 'dev-main' 'dev-main requirement kept'
assert_eq "$(jget "$M" 'packages|acme/core|0.1.0|source|url')" "$BARE" 'source url'
assert_eq "$(jget "$M" 'packages|acme/core|0.1.0|source|reference')" "$(git -C "$MONO" rev-parse dist/acme/core)" 'source reference'
assert_eq "$(jget "$M" 'packages|acme/core|0.1.0|dist')" '<missing>' 'no zip url for a non-GitHub source'
assert_eq "$(jget "$M" 'packages|acme/host')" '<missing>' 'project must not be published'
assert_eq "$(git -C "$MONO" show dist/composer:composer-packages.json)" "$(cat "$M")" 'metadata branch equals the generated file'

step 'a repeated release at the same content is a no-op'
split release --version=0.1.0 >/dev/null
assert_eq "$(git -C "$MONO" rev-list --count dist/composer)" 1 'metadata branch gained a commit without a change'

step 'a release is refused when its version was already published from other content'
echo '<?php // web v2' > "$MONO/php/web/src/Marker.php"
commit 'change web'
web_tip=$(git -C "$MONO" rev-parse dist/acme/web)
if out=$(split release --version=0.1.0 2>&1); then fail 'a conflicting release was accepted'; fi
assert_contains "$out" 'already released from different content' 'refusal message'
assert_eq "$(git -C "$MONO" rev-parse dist/acme/web)" "$web_tip" 'a refused release moved a branch'

step 'releases move all packages together'
split release --version=0.2.0 --out-dir="$WORK/out2" >/dev/null
assert_eq "$(git -C "$MONO" rev-parse refs/tags/acme/adapter/v0.2.0^{commit})" "$(git -C "$MONO" rev-parse refs/tags/acme/adapter/v0.1.0^{commit})" 'unchanged package keeps its commit under the new tag'
assert_eq "$(jget "$WORK/out2/composer-packages.json" 'packages|acme/web|0.2.0|require|acme/core')" '^0.2.0' 'second release requirement'
assert_eq "$(jget "$WORK/out2/composer-packages.json" 'packages|acme/web|0.1.0|require|acme/core')" '^0.1.0' 'earlier release kept'
split metadata --out-dir="$WORK/out2b" >/dev/null
cmp -s "$WORK/out2/composer-packages.json" "$WORK/out2b/composer-packages.json" || fail 'metadata is not deterministic'

step 'bad input is rejected'
if split release --version=1.0 >/dev/null 2>&1; then fail 'an invalid version was accepted'; fi
if split release >/dev/null 2>&1; then fail 'a release without a version was accepted'; fi
if split list --pus >/dev/null 2>&1; then fail 'an unknown option was accepted'; fi

step 'dry run changes nothing'
before=$(git -C "$MONO" for-each-ref | md5 2>/dev/null || git -C "$MONO" for-each-ref | md5sum)
split release --version=0.3.0 --dry-run >/dev/null
after=$(git -C "$MONO" for-each-ref | md5 2>/dev/null || git -C "$MONO" for-each-ref | md5sum)
assert_eq "$after" "$before" 'dry run changed refs'

step 'branches that would not fast-forward are refused unless forced'
git -C "$MONO" branch -q -f dist/acme/core "$(git -C "$MONO" rev-parse main)"      # unrelated history
if split split >/dev/null 2>&1; then fail 'a non-fast-forward move was accepted'; fi
split split --force-branches >/dev/null

# ---- push ---------------------------------------------------------------------------------
step 'push sends branches, tags and the metadata branch atomically'
split release --version=0.2.0 --push --remote=origin >/dev/null
remote_refs=$(git ls-remote "$BARE" | awk '{print $2}')
for ref in refs/heads/dist/composer refs/heads/dist/acme/core refs/heads/dist/acme/web refs/heads/dist/acme/adapter \
    refs/tags/acme/core/v0.2.0 refs/tags/acme/web/v0.2.0 refs/tags/acme/adapter/v0.2.0; do
    case "$remote_refs" in *"$ref"*) ;; *) fail "remote is missing $ref";; esac
done
assert_eq "$(git ls-remote "$BARE" refs/heads/dist/composer | awk '{print $1}')" "$(git -C "$MONO" rev-parse dist/composer)" 'remote metadata branch'

# ---- Composer consumes the published repository -----------------------------------------
step 'composer installs a release from the generated repository'
git clone -q --branch dist/composer "$BARE" "$WORK/composer-repo"
mkdir "$WORK/consumer"
cat > "$WORK/consumer/composer.json" <<JSON
{
  "name": "acme/consumer",
  "repositories": [ { "type": "composer", "url": "file://$WORK/composer-repo/composer-packages.json" }, { "packagist.org": false } ],
  "require": { "acme/web": "^0.2.0" }
}
JSON
(cd "$WORK/consumer" && composer install --no-plugins --no-scripts --no-progress --prefer-source -q 2>&1) || fail 'composer install failed'
for p in core adapter web; do
    [ -f "$WORK/consumer/vendor/acme/$p/src/Marker.php" ] || fail "acme/$p was not installed with its own files"
    [ ! -d "$WORK/consumer/vendor/acme/$p/mod" ] || fail "acme/$p was installed with monorepo paths"
done
installed=$(php -r '$i = json_decode(file_get_contents($argv[1]), true); $o = []; foreach ($i["packages"] as $p) { $o[] = $p["name"]."@".$p["version"]; } sort($o); echo implode(",", $o);' "$WORK/consumer/vendor/composer/installed.json")
assert_eq "$installed" 'acme/adapter@0.2.0,acme/core@0.2.0,acme/web@0.2.0' 'installed versions'

step 'composer can also resolve dev-main (which needs minimum-stability dev, as dev-main depends on dev-main)'
mkdir "$WORK/consumer-dev"
cat > "$WORK/consumer-dev/composer.json" <<JSON
{
  "name": "acme/consumer-dev",
  "repositories": [ { "type": "composer", "url": "file://$WORK/composer-repo/composer-packages.json" }, { "packagist.org": false } ],
  "require": { "acme/web": "dev-main" }
}
JSON
if (cd "$WORK/consumer-dev" && composer update --no-plugins --no-scripts --no-progress --dry-run -q >/dev/null 2>&1); then
    fail 'dev-main resolved without minimum-stability dev; the stability rule this test documents changed'
fi
(cd "$WORK/consumer-dev" && composer config minimum-stability dev && composer config prefer-stable true \
    && composer update --no-plugins --no-scripts --no-progress --dry-run 2>&1 | grep -E 'Installing acme/' | sort) > "$WORK/dev-install.txt" || fail 'dev-main did not resolve'
assert_eq "$(grep -c 'dev-main' "$WORK/dev-install.txt")" 3 'all three packages resolve at dev-main'

step 'verify agrees'
split verify --composer >/dev/null || fail 'verify --composer failed'

step 'a Packagist-shaped catalog record registers the index through the real plugin command'
mkdir "$WORK/catalog-consumer" "$WORK/catalog-discovery"
php -r '
$plugin=$argv[1]; $repo=$argv[2]; $discovery=$argv[3];
$catalog=["name"=>"acme/catalog","version"=>"1.0.0","type"=>"metapackage",
 "extra"=>["source-plugin"=>["catalog"=>["url"=>"file://".$repo."/composer-packages.json","packages"=>["acme/adapter","acme/core","acme/web"]]]]];
file_put_contents($discovery."/packages.json",json_encode(["packages"=>["acme/catalog"=>["1.0.0"=>$catalog]]]));
echo json_encode([
 "name"=>"acme/catalog-consumer",
 "require"=>["webong/composer-source-plugin"=>"@dev"],
 "repositories"=>[
  ["type"=>"path","url"=>$plugin,"options"=>["symlink"=>true]],
  ["type"=>"composer","url"=>"file://".$discovery],
  ["packagist.org"=>false]
 ],
 "config"=>["allow-plugins"=>["webong/composer-source-plugin"=>true]]
], JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES);' "$ROOT" "$WORK/composer-repo" "$WORK/catalog-discovery" > "$WORK/catalog-consumer/composer.json"
(
 cd "$WORK/catalog-consumer"
 composer install --no-scripts --no-progress -q
 composer source:publish list --repo="$MONO" --no-interaction > "$WORK/command-list.txt"
 grep -q acme/core "$WORK/command-list.txt" || fail 'publisher command did not discover core'
 composer source:publish release --release-version=0.3.0 --dry-run --repo="$MONO" --no-interaction >/dev/null || fail 'publisher release command failed'
 cp composer.json before.json
 if composer source:catalog acme/catalog:^1 --no-interaction >/dev/null 2>&1; then fail 'file catalog allowed without opt-in'; fi
 cmp -s composer.json before.json || fail 'failed registration changed manifest'
 composer source:catalog acme/catalog:^1 --allow-local --dry-run --no-interaction >/dev/null
 cmp -s composer.json before.json || fail 'dry-run changed manifest'
 composer source:catalog acme/catalog:^1 --allow-local --no-interaction
 cp composer.json registered.json
 composer source:catalog acme/catalog:^1 --allow-local --no-interaction >/dev/null
 cmp -s composer.json registered.json || fail 'repeated registration changed manifest'
 composer require acme/web:^0.2 --no-scripts --no-progress --no-interaction -q
 rm -rf vendor
 composer install --no-dev --no-plugins --no-scripts --no-progress -q
 test -f vendor/acme/web/src/Marker.php || fail 'locked no-plugin catalog install missing module'
 test ! -d vendor/acme/web/php || fail 'module installed with monorepo root'
) || fail 'catalog consumer failed'

step 'metadata can live on the normal branch without a metadata ref'
metadata_tip=$(git -C "$MONO" rev-parse dist/composer)
normal_tip=$(git -C "$MONO" rev-parse main)
split metadata --no-metadata-branch --out-dir="$MONO/composer" >/dev/null
cmp -s "$MONO/composer/composer-packages.json" "$WORK/out2/composer-packages.json" || fail 'file-only index differs from generated metadata'
assert_eq "$(git -C "$MONO" rev-parse dist/composer)" "$metadata_tip" 'file-only generation moved the metadata ref'
assert_eq "$(git -C "$MONO" rev-parse main)" "$normal_tip" 'file-only generation committed normal branch'
if split metadata --no-metadata-branch >/dev/null 2>&1; then fail 'file-only mode accepted no output directory'; fi
if split metadata --no-metadata-branch --metadata-branch=custom --out-dir="$MONO/composer" >/dev/null 2>&1; then fail 'conflicting metadata options accepted'; fi
if split metadata --no-metadata-branch --push --out-dir="$MONO/composer" >/dev/null 2>&1; then fail 'file-only metadata silently claimed to push'; fi
# Remove only the fixture metadata ref, then prove release does not recreate it.
git -C "$MONO" update-ref -d refs/heads/dist/composer
split release --version=0.3.0 --no-metadata-branch --out-dir="$MONO/composer" >/dev/null
if git -C "$MONO" show-ref --verify --quiet refs/heads/dist/composer; then fail 'file-only release created a metadata branch'; fi
(cd "$WORK/catalog-consumer" && composer source:publish metadata --no-metadata-branch --out-dir="$MONO/composer" --repo="$MONO" --no-interaction >/dev/null) || fail 'file-only Composer command failed'
commit 'commit package index on normal branch'
assert_eq "$(git -C "$MONO" show main:composer/composer-packages.json)" "$(cat "$MONO/composer/composer-packages.json")" 'index not committed to normal branch'

step 'full package identities prevent branch collisions across vendors'
package php/other other/core
commit 'another vendor with the same short name'
split split >/dev/null
assert_eq "$(git -C "$MONO" show dist/other/core:composer.json | php -r '$p=json_decode(stream_get_contents(STDIN),true); echo $p["name"];')" 'other/core' 'cross-vendor branch identity'

step 'dirty manifests do not enter publication discovery'
package php/uncommitted acme/uncommitted
if split list | grep -q 'acme/uncommitted'; then fail 'dirty manifest entered publication'; fi
rm -rf "$MONO/php/uncommitted"

step 'package symlinks are refused before refs change'
ln -s /tmp "$MONO/php/other/src/unsafe"
commit 'unsafe link'
if split release --version=0.4.0 >/dev/null 2>&1; then fail 'published a package symlink'; fi
if git -C "$MONO" rev-parse --verify refs/tags/other/core/v0.4.0 >/dev/null 2>&1; then fail 'unsafe release created a tag'; fi

echo 'Publication works: generic workspace discovery, split branches, tags, index, catalog registration and real Composer installs.'
