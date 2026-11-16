# Changelog

## 0.5.0 — 2026-11-16

- Discover directory-owned surface groups through nested Composer manifest globs. Add surface inheritance, naming overrides, include-list shorthand and scoped exclusions while preserving existing include-only lock digests.
- Retain surface descriptors across pruning so fresh and repeated locked installations can reconstruct definitions and reject changed selections safely.
- Add `composer source:publish` to split committed workspace packages into package-root branches and version tags in the same repository, and generate `composer-packages.json`.
- Support writing the package index on the normal branch with `--no-metadata-branch --out-dir=DIR`.
- Add `composer source:catalog vendor/catalog:constraint` to register an index advertised through ordinary package metadata, including Packagist. Subsequent package installation uses standard Composer repositories and requirements.
- Add real Composer fixtures for publication, catalog registration, surface discovery, inheritance, exclusions and lock replay; run them in CI.

Packagist discovery applies to the catalog package. Nested packages still come from the advertised Composer index; this release does not change Packagist server-side discovery or merge nested surface requirements.
