#!/usr/bin/env bash
# Real Composer projection and lock replay with directory-owned surface manifests.
set -euo pipefail
ROOT=$(CDPATH= cd -- "$(dirname -- "$0")/../.." && pwd)
LAB=$(mktemp -d)
trap 'rm -rf "$LAB"' EXIT
export COMPOSER_HOME="$LAB/home" COMPOSER_CACHE_DIR="$LAB/cache" COMPOSER_NO_INTERACTION=1 COMPOSER_ROOT_VERSION=1.0.0
MODULE="$LAB/module"
CONSUMER="$LAB/consumer"
mkdir -p "$MODULE/core/src" "$MODULE/ext/Laravel/src" "$MODULE/ext/Laravel/tests" "$MODULE/ext/Temporal/src" "$CONSUMER"
cat > "$MODULE/composer.json" <<'JSON'
{
 "name":"acme/discovery-demo","version":"1.0.0","type":"library",
 "autoload":{"psr-4":{"Acme\\Core\\":"core/src/","Acme\\Laravel\\":"ext/Laravel/src/","Acme\\Temporal\\":"ext/Temporal/src/"}},
 "extra":{"source-plugin":{"surfaces":["core/composer.json","ext/*/composer.json"]}}
}
JSON
printf '%s\n' '{"extra":{"source-plugin":{"surface":true}}}' > "$MODULE/core/composer.json"
printf '%s\n' '{"require":{"does-not-exist/never-merge":"*"},"extra":{"source-plugin":{"surface":{"extends":"core","exclude":["tests"]}}}}' > "$MODULE/ext/Laravel/composer.json"
printf '%s\n' '{"extra":{"source-plugin":{"surface":{"extends":"core"}}}}' > "$MODULE/ext/Temporal/composer.json"
printf '%s\n' '<?php namespace Acme\Core; class Thing { public const VALUE = "core"; }' > "$MODULE/core/src/Thing.php"
printf '%s\n' '<?php namespace Acme\Laravel; class Bridge { public const VALUE = \Acme\Core\Thing::VALUE; }' > "$MODULE/ext/Laravel/src/Bridge.php"
printf '%s\n' '<?php namespace Acme\Temporal; class Driver {}' > "$MODULE/ext/Temporal/src/Driver.php"
printf '%s\n' '<?php // development-only' > "$MODULE/ext/Laravel/tests/Test.php"
php -r 'echo json_encode([
 "name"=>"acme/discovery-consumer",
 "require"=>["webong/composer-source-plugin"=>"@dev","acme/discovery-demo"=>"1.0.0"],
 "repositories"=>[
  ["type"=>"path","url"=>$argv[1],"options"=>["symlink"=>true]],
  ["type"=>"path","url"=>$argv[2],"options"=>["symlink"=>false]],
  ["packagist.org"=>false]
 ],
 "config"=>["allow-plugins"=>["webong/composer-source-plugin"=>true]]
],JSON_UNESCAPED_SLASHES|JSON_PRETTY_PRINT);' "$ROOT" "$MODULE" > "$CONSUMER/composer.json"
cd "$CONSUMER"
composer update --no-scripts --no-progress -q
php -r '$p=json_decode(file_get_contents("composer.json"),true);$p["extra"]["source-plugin"]["projections"]["acme/discovery-demo"]=["surfaces"=>["laravel"]];file_put_contents("composer.json",json_encode($p,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES));'
composer update --no-scripts --no-progress -q
PACKAGE="$CONSUMER/vendor/acme/discovery-demo"
test -f "$PACKAGE/core/src/Thing.php"
test -f "$PACKAGE/ext/Laravel/src/Bridge.php"
test ! -f "$PACKAGE/ext/Laravel/tests/Test.php"
test ! -f "$PACKAGE/ext/Temporal/src/Driver.php"
test -f "$PACKAGE/ext/Temporal/composer.json"
php -r 'require "vendor/autoload.php"; if (Acme\Laravel\Bridge::VALUE !== "core") exit(1);'
cp composer-source.lock expected-source.lock
composer install --no-scripts --no-progress -q
composer dump-autoload --classmap-authoritative --no-scripts -q
cmp -s composer-source.lock expected-source.lock
# Fresh lock-only installation first bootstraps without the plugin, then reconciles.
rm -rf vendor
composer install --no-plugins --no-dev --no-scripts --no-progress -q
composer install --no-dev --no-scripts --no-progress -q
cmp -s composer-source.lock expected-source.lock
test ! -f "$PACKAGE/ext/Temporal/src/Driver.php"
test -f "$PACKAGE/ext/Temporal/composer.json"
# Tampering with a descriptor invalidates recorded definitions before pruning.
php -r '$f=$argv[1];$p=json_decode(file_get_contents($f),true);$p["extra"]["source-plugin"]["surface"]["exclude"]=["tests","src"];file_put_contents($f,json_encode($p));' "$PACKAGE/ext/Laravel/composer.json"
if composer install --no-scripts --no-progress -q > "$LAB/tamper.log" 2>&1; then
 echo 'Changed surface descriptor was accepted against a locked projection' >&2
 exit 1
fi
test -f "$PACKAGE/ext/Laravel/src/Bridge.php"
echo 'Surface discovery passed: inherited directory selection, exclusions, runtime autoloading, descriptor retention, locked replay and tamper rejection.'
