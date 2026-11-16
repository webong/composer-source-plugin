#!/usr/bin/env bash
# Regression seam: actual Composer command and error handler, isolated publication refs.
set -euo pipefail
ROOT=$(CDPATH= cd -- "$(dirname -- "$0")/../.." && pwd)
LAB=$(mktemp -d)
trap 'rm -rf "$LAB"' EXIT
export GIT_CONFIG_GLOBAL=/dev/null GIT_CONFIG_SYSTEM=/dev/null
export GIT_AUTHOR_NAME=Fixture GIT_COMMITTER_NAME=Fixture GIT_AUTHOR_EMAIL=fixture@example.test GIT_COMMITTER_EMAIL=fixture@example.test
export COMPOSER_HOME="$LAB/home" COMPOSER_CACHE_DIR="$LAB/cache" COMPOSER_ROOT_VERSION=1.0.0 COMPOSER_NO_INTERACTION=1
SCENARIO=${1:-all}
mkdir "$LAB/tool"
php -r 'echo json_encode(["name"=>"fixture/tool","require"=>["webong/composer-source-plugin"=>"@dev"],"repositories"=>[["type"=>"path","url"=>$argv[1],"options"=>["symlink"=>true]],["packagist.org"=>false]],"config"=>["allow-plugins"=>["webong/composer-source-plugin"=>true]]],JSON_UNESCAPED_SLASHES);' "$ROOT" > "$LAB/tool/composer.json"
(cd "$LAB/tool" && composer install --no-scripts --no-progress -q)
run() { (cd "$LAB/tool" && composer source:publish "$@" --no-interaction); }
commit() { git -C "$1" add .; git -C "$1" commit -qm "$2"; }
init() {
 mkdir -p "$1/pkg/core/src"
 git -C "$1" init -q -b main
 printf '%s\n' '{"name":"fixture/catalog","extra":{"source-plugin":{"workspaces":["pkg/*"]}}}' > "$1/composer.json"
 printf '%s\n' '{"name":"fixture/core","type":"library","autoload":{"psr-4":{"Fixture\\Core\\":"src/"}}}' > "$1/pkg/core/composer.json"
 printf '%s\n' '<?php namespace Fixture\Core; class Thing {}' > "$1/pkg/core/src/Thing.php"
}
if [ "$SCENARIO" = all ] || [ "$SCENARIO" = nested ]; then
 echo '- nested project manifests do not trigger an existing-directory warning'
 REPO="$LAB/nested"; init "$REPO"
 mkdir -p "$REPO/pkg/core/companion" "$REPO/pkg/core/tools"
 printf '%s\n' '{"name":"fixture/companion","type":"project"}' > "$REPO/pkg/core/companion/composer.json"
 printf '%s\n' '{"name":"fixture/tools","type":"project"}' > "$REPO/pkg/core/tools/composer.json"
 commit "$REPO" nested
 run list --repo="$REPO" > "$LAB/nested.log"
 grep -q fixture/core "$LAB/nested.log"
 if grep -Eq 'fixture/(companion|tools)' "$LAB/nested.log"; then exit 1; fi
fi
if [ "$SCENARIO" = all ] || [ "$SCENARIO" = plugin ]; then
 echo '- Composer-plugin workspaces retain plugin metadata and install through Composer'
 REPO="$LAB/plugin"; init "$REPO"
 mkdir -p "$REPO/pkg/builder/src" "$REPO/pkg/builder/bin"
 cat > "$REPO/pkg/builder/composer.json" <<'JSON'
{"name":"fixture/builder","type":"composer-plugin","require":{"php":">=8.1","composer-plugin-api":"^2.3"},"autoload":{"psr-4":{"Fixture\\Builder\\":"src/"}},"extra":{"class":"Fixture\\Builder\\Plugin"},"bin":["bin/build"]}
JSON
 cat > "$REPO/pkg/builder/src/Plugin.php" <<'PHP'
<?php namespace Fixture\Builder;
final class Plugin implements \Composer\Plugin\PluginInterface {
 public function activate(\Composer\Composer $composer, \Composer\IO\IOInterface $io): void {}
 public function deactivate(\Composer\Composer $composer, \Composer\IO\IOInterface $io): void {}
 public function uninstall(\Composer\Composer $composer, \Composer\IO\IOInterface $io): void {}
}
PHP
 printf '%s\n' '#!/usr/bin/env php' '<?php echo "builder-ok\n";' > "$REPO/pkg/builder/bin/build"
 commit "$REPO" plugin
 run release --release-version=0.3.0 --repo="$REPO" --source-url="$REPO" --out-dir="$LAB/plugin-index" > "$LAB/plugin.log"
 mkdir "$LAB/plugin-consumer"
 php -r 'echo json_encode(["name"=>"fixture/plugin-consumer","repositories"=>[["type"=>"composer","url"=>"file://".$argv[1]."/composer-packages.json"],["packagist.org"=>false]],"require"=>["fixture/builder"=>"0.3.0"],"config"=>["allow-plugins"=>["fixture/builder"=>true]]],JSON_UNESCAPED_SLASHES);' "$LAB/plugin-index" > "$LAB/plugin-consumer/composer.json"
 (cd "$LAB/plugin-consumer" && composer install --no-progress --no-scripts --prefer-source -q && php vendor/bin/build | grep -q builder-ok)
 php -r '$p=json_decode(file_get_contents($argv[1]),true)["packages"]["fixture/builder"]["0.3.0"];if($p["type"]!=="composer-plugin" || $p["extra"]["class"]!=="Fixture\\Builder\\Plugin" || $p["bin"]!==["bin/build"])exit(1);' "$LAB/plugin-index/composer-packages.json"
fi
if [ "$SCENARIO" = all ] || [ "$SCENARIO" = legacy ]; then
 echo '- opt-in short refs retain historical records, tags and old index URLs'
 REPO="$LAB/legacy"; init "$REPO"; commit "$REPO" initial
 SPLIT=$(git -C "$REPO" subtree split --prefix=pkg/core HEAD 2>/dev/null)
 git -C "$REPO" branch dist/core "$SPLIT"
 git -C "$REPO" tag core/v0.2.0 "$SPLIT"
 git -C "$REPO" archive --format=zip --output="$LAB/core-0.2.0.zip" "$SPLIT"
 php -r 'echo json_encode(["packages"=>["fixture/core"=>["0.2.0"=>["name"=>"fixture/core","version"=>"0.2.0","type"=>"library","description"=>"historical exact record","autoload"=>["psr-4"=>["Fixture\\Core\\"=>"src/"]],"require-dev"=>["old/dev-dependency"=>"*"],"source"=>["type"=>"git","url"=>$argv[1],"reference"=>$argv[2]],"dist"=>["type"=>"zip","url"=>"file://".$argv[3],"reference"=>$argv[2],"shasum"=>sha1_file($argv[3])]]]]],JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES);' "$REPO" "$SPLIT" "$LAB/core-0.2.0.zip" > "$LAB/original.json"
 mkdir "$LAB/metadata-tree"
 cp "$LAB/original.json" "$LAB/metadata-tree/packages.json"
 printf '%s\n' '# Existing index' > "$LAB/metadata-tree/README.md"
 INDEX="$LAB/metadata.index"
 GIT_INDEX_FILE="$INDEX" git -C "$REPO" read-tree --empty
 BLOB=$(git -C "$REPO" hash-object -w "$LAB/metadata-tree/packages.json")
 README=$(git -C "$REPO" hash-object -w "$LAB/metadata-tree/README.md")
 TREE=$(printf '100644 blob %s\tREADME.md\n100644 blob %s\tpackages.json\n' "$README" "$BLOB" | git -C "$REPO" mktree)
 META=$(printf 'old index\n' | git -C "$REPO" commit-tree "$TREE")
 git -C "$REPO" update-ref refs/heads/dist/composer "$META"
 run split --repo="$REPO" --ref-names=short > "$LAB/legacy-split.log"
 run metadata --repo="$REPO" --source-url="$REPO" --ref-names=short --metadata-alias=packages.json --out-dir="$LAB/legacy-index" > "$LAB/legacy-index.log"
 git -C "$REPO" show dist/composer:packages.json > "$LAB/old-url.json"
 git -C "$REPO" show dist/composer:composer-packages.json > "$LAB/new-url.json"
 cmp "$LAB/old-url.json" "$LAB/new-url.json"
 cmp "$LAB/legacy-index/packages.json" "$LAB/legacy-index/composer-packages.json"
 php -r '$old=json_decode(file_get_contents($argv[1]),true);$new=json_decode(file_get_contents($argv[2]),true);if($old["packages"]["fixture/core"]["0.2.0"]!==$new["packages"]["fixture/core"]["0.2.0"])exit(1);' "$LAB/original.json" "$LAB/new-url.json"
 test "$(git -C "$REPO" rev-parse core/v0.2.0)" = "$SPLIT"
 if git -C "$REPO" show-ref --verify --quiet refs/heads/dist/fixture/core; then exit 1; fi
 # Repeated generation is idempotent and preserves an existing alias without restating it.
 META=$(git -C "$REPO" rev-parse dist/composer)
 run metadata --repo="$REPO" --source-url="$REPO" --ref-names=short >/dev/null
 test "$(git -C "$REPO" rev-parse dist/composer)" = "$META"
 mkdir "$LAB/legacy-consumer"
 php -r 'echo json_encode(["name"=>"fixture/legacy-consumer","repositories"=>[["type"=>"composer","url"=>"file://".$argv[1]."/packages.json"],["packagist.org"=>false]],"require"=>["fixture/core"=>"0.2.0"]],JSON_UNESCAPED_SLASHES);' "$LAB/legacy-index" > "$LAB/legacy-consumer/composer.json"
 (cd "$LAB/legacy-consumer" && composer install --no-plugins --no-scripts --no-progress --prefer-dist -q && cp composer.lock expected.lock && rm -rf vendor && composer install --no-plugins --no-scripts --no-progress --prefer-source -q && cmp composer.lock expected.lock)
 # A known published index cannot be silently ignored merely because its local branch is absent.
 git -C "$REPO" update-ref refs/remotes/origin/dist/composer "$META"
 git -C "$REPO" update-ref -d refs/heads/dist/composer
 BEFORE=$(git -C "$REPO" for-each-ref)
 if run metadata --repo="$REPO" --ref-names=short --source-url="$REPO" > "$LAB/baseline.log" 2>&1; then exit 1; fi
 grep -q 'baseline.*missing' "$LAB/baseline.log"
 test "$(git -C "$REPO" for-each-ref)" = "$BEFORE"
 git -C "$REPO" update-ref refs/heads/dist/composer "$META"
 # Short-name collisions must fail before any refs can change.
 mkdir -p "$REPO/pkg/other"
 printf '%s\n' '{"name":"other/core","type":"library"}' > "$REPO/pkg/other/composer.json"
 commit "$REPO" collision
 BEFORE=$(git -C "$REPO" for-each-ref)
 if run split --repo="$REPO" --ref-names=short > "$LAB/collision.log" 2>&1; then exit 1; fi
 grep -qi collision "$LAB/collision.log"
 test "$(git -C "$REPO" for-each-ref)" = "$BEFORE"
fi
echo "Publication migration $SCENARIO passed."
