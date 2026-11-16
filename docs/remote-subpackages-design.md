# Remote subpackages from one source repository

Status: historical proposal, 2026-11-16. The implementation developed afterward uses same-repository Git subtree splits and a Packagist-discovered catalog. See [publication.md](publication.md) for the implemented 0.5.0 contract; unimplemented alternatives below remain design notes.

## Decision proposed

Add an **author-side remote package publisher** to Composer Source Plugin. It reads explicitly declared subdirectory manifests at an immutable Git commit, validates them, builds component-root archives, and emits a standard Composer repository. Host the metadata and archives using the **same source repository's** Pages/release facilities. No distribution Git repositories are needed. Consumers explicitly register the generated Composer repository and use ordinary Composer resolution and installation; the plugin is not required in their production graph.

For Proxy this means `php/composer.json` describes `webong/proxy`, and `adapters/laravel/composer.json` describes `webong/proxy-laravel`. The latter requires core `^0.4`; only it owns Laravel persistence, migrations and Spatie integration. `go/` and `go/v0.4.0` are outside this feature and remain unchanged. PHP namespaces and registry contracts remain unchanged.

This satisfies one source repository and separately named/dependency-resolved libraries. It does require a root consumer repository entry. **Plain `composer require` using only public Packagist cannot discover both nested manifests from the current monorepo layout.** If Packagist-only discovery is mandatory, the proposed solution is insufficient; that is a decision gate, not a feature a consumer plugin can promise.

## Evidence and limits of verification

Inspected plugin checkout: `/Users/webong/Documents/Workspace/composer-source-plugin`, clean `main`, HEAD `818ec8ee702ae8945dfebe351c4869af2e7bdac9`. A remote `ls-remote` failed on GitHub DNS in this session; current remote equality is not independently verified here. No runtime implementation or installation experiment was performed for this proposal.

The present code has no remote-subpackage repository, publisher or downloader:

- [WorkspaceRepository](../src/WorkspaceRepository.php) prepends **local** `PathRepository` instances; it supplies no remote manifest discovery.
- [ComposerSourcePlugin](../src/ComposerSourcePlugin.php) activates workspaces, selects sources before pool creation, projects installed packages, syncs mirrors and generates aliases.
- [SourcePluginConfig](../src/SourcePluginConfig.php) validates existing workspace/loader/mirror/projection conflicts.
- [SourceLock](../src/SourceLock.php) stores mirror/projection state in schema version 1. It must not be silently repurposed as a remote package index.
- [CommandProvider](../src/Command/CommandProvider.php) currently exposes only the workspace command.
- [Projection documentation](projections.md) explicitly says pruning does not introduce or remove solver requirements.

Current Proxy manifests were read at `php/composer.json` and `adapters/laravel/composer.json`: core requires PHP/Guzzle; adapter requires core and Laravel/Spatie, and advertises its Laravel provider. Both currently declare version `0.4.0`. Both share a namespace prefix across separate directories, which requires class collision checking rather than renaming namespaces.

Release evidence was supplied in `/private/tmp/proxy-v040-public/verification.md` and `release-refs.json` (temporary files, not durable publication records):

| Existing identity | Recorded commit |
| --- | --- |
| Monorepo release | `5793b331cee9a2782cf5d7fdc0bcfcce2d035557` |
| Published core distribution | `64108c8071d41ebbd35b0d88e008d09281a57a97` |
| Published adapter distribution | `863916ccdfb8899ee26e47b3bee62a2843f00106` |

The evidence reports successful public PHP clean/locked installations and a real 0.3.1 to 0.4.0 data upgrade. It also records six unchanged historical tags copied to `proxy-php`, and Packagist serving distribution-repository URLs. Treat these as supplied verification history, not new tests by this design task. Go direct download/race/vet passed in that evidence; standard Go proxy/checksum-database verification remained blocked. Do not extend that claim.

## Why this architecture

Composer resolves package metadata separately from package files. A standard Composer repository can list both package names and their full requirements before solving. Root projects must opt into that repository; repository entries inside dependencies are ignored. See [Composer repositories](https://getcomposer.org/doc/05-repositories.md).

Packagist discovers the manifest at a repository root and processes its tags; it does not run an installed consumer plugin to discover nested manifests. See [Packagist submission requirements](https://packagist.org/about#how-to-submit-packages) and [Composer GitDriver](https://github.com/composer/composer/blob/main/src/Composer/Repository/Vcs/GitDriver.php). Retargeting both current Packagist package names to the ordinary monorepo URL does not create two independent packages.

Options considered:

| Option | Result | Disposition |
| --- | --- | --- |
| Existing local workspaces | Local development links only | Keep for author development |
| Existing projections | File selection after dependency resolution | Not dependency isolation |
| Consumer plugin scans remote tags and supplies packages | Possible future custom repository/downloader, but bootstrap, credentials and lock replay become runtime requirements | Defer |
| Standard repository plus component-root release archives | Stock resolver/downloader; one source repo; no runtime plugin | Recommended |
| One monorepo archive with prefixed autoload paths | Installer root, binaries and provider-relative resources differ; duplicates whole repo per package | Reject for first version |
| Root package plus metapackage aliases | Does not give independent contents/dependency graphs | Reject |
| Separate distribution repositories | Already published; rejected architecture for new releases | Compatibility hosting only |

## Proposed author interface

The following keys and commands are **new proposals**, not supported syntax today:

```json
{
  "extra": {
    "source-plugin": {
      "workspaces": ["php", "adapters/laravel"],
      "publication": {
        "schema": 1,
        "repository": "https://github.com/webong/proxy",
        "packages": {
          "webong/proxy": {"path": "php", "tag-pattern": "php/v{version}"},
          "webong/proxy-laravel": {"path": "adapters/laravel", "tag-pattern": "php/v{version}"}
        }
      }
    }
  }
}
```

Proposed author workflow:

```sh
composer source:publish-plan --ref php/v0.4.1 --output build/composer
composer source:publish-verify --input build/composer
```

These commands prepare and verify local artifacts only. Uploading assets, creating tags/releases, updating Pages or Packagist is a distinct, explicitly authorized release step. No embedded tokens, shell hooks or remote code execution from manifests. Publication runs from a pinned allowed plugin version already installed in the author's development/CI environment. It must not bootstrap by resolving the remote packages it is trying to publish.

The publisher's interface returns a validated release plan: package metadata, archives and a provenance inventory. Internal seams should separate manifest validation, version mapping, deterministic archive construction and metadata rendering. A host-specific uploader is outside the initial module; avoid adding a custom runtime installer where standard archives suffice.

### Discovery, identity and versions

1. Resolve the approved ref to a full immutable commit. Read configuration and declared manifests from that commit, never a dirty working tree. Validate any local overrides separately and reject publication with them.
2. Only explicitly listed paths are discovered. No arbitrary remote glob crawling. Normalize paths and reject root/empty paths, traversal, absolute paths, duplicate or overlapping roots and submodules in a package root in the initial implementation.
3. Manifest name must exactly match its configured identity. Validate Composer schema, package type, requirements and path-bearing metadata; initial support is `library` packages. Reject package-level embedded repositories and custom installer/plugin types until deliberately supported. Do not run scripts.
4. Shared PHP tag `php/v0.4.1` emits both packages at `0.4.1`; it must not interpret `go/v0.4.0` or historic unprefixed tags as current PHP publications. This new ref is illustrative, not an authorized tag creation.
5. Independent mode uses disjoint patterns, e.g. `php/core/v{version}` and `php/laravel/v{version}`. Each tag emits only its mapped package at that tag's commit. Adapter resolution uses its declared core constraint, not an automatic same-commit substitution.
6. Release versions are stable SemVer initially. Reject ambiguous tag matches and duplicate name/version entries with differing bytes or provenance. Reject mutable branch/dev versions in the first release.
7. Derive metadata version from the tag; if a manifest contains `version`, require an exact normalized match. Before a 0.4.1 release, remove redundant versions or update them in a new commit. Never rewrite the existing 0.4.0 commit/manifests.
8. Record repository URL, commit, original tag object/ref, subdirectory, Git tree identity, original manifest SHA-256 and archive SHA-256. Verify signed tags under an explicit trusted maintainer-key policy in release CI. Archive digests authenticate bytes only relative to trusted metadata; signatures/trusted HTTPS are separate concerns.

### Package roots and archive contract

Build each ZIP so extracting it produces precisely that package's root (optionally one conventional enclosing directory). Thus `src/`, `config/`, `database/`, `bin/` and `composer.json` retain their ordinary package-relative meanings. Do not prepend `php/` to autoload rules or patch PHP resource lookups.

Preserve the component manifest's `autoload` (PSR-4/PSR-0/classmap/files), `bin`, requirements, conflicts, suggestions and `extra`, including Laravel provider discovery. Normalize path syntax for validation, not by changing public behavior. Root-only development/configuration fields stay non-operative for consumers. Apply declared archive exclusions with a tested policy; always include the package manifest/license and required runtime files. If resources outside the package root are needed, move/copy them into the source package in an approved code change; never silently reach outside the root during publication.

Validate every autoload file/bin/resource path exists where required, no escaping path exists, and retained classes do not collide across the two installed packages. Both packages can share `Webong\\Proxy\\` while declaring distinct symbols. Validate optimized and authoritative classmaps plus real provider discovery and migration execution. Do not claim arbitrary `extra` strings can be universally interpreted: retain their values and require integration tests for known consumers.

Reject archive entries with traversal, absolute/drive/UNC paths, NULs, unsafe separator normalization, case-folding collisions or duplicate normalized names. Initial policy rejects symlinks, hardlinks, devices and submodules, including links pointing inside the root; add link support only with separate tests. Impose compressed/uncompressed size, file-count and nesting limits. Preserve executable modes only where intended. No extraction into live vendor trees during generation or verification. Stage artifacts in a private temporary directory, verify the entire inventory, then atomically finalize local outputs.

### Published metadata and provenance

A generated `packages.json` lists ordinary Composer package records keyed by name/version. For each record:

- `name`, `version`, `require`, `autoload`, `bin`, `extra` and other supported Composer metadata come from the validated component manifest.
- `dist.type` is `zip`; `dist.url` identifies a content-addressed component-root asset in a release of **webong/proxy**; `dist.reference` is the immutable monorepo commit.
- Include `dist.shasum` (SHA-1 in Composer 2.8.12) plus SHA-256 in the signed release inventory; see [FileDownloader 2.8.12](https://raw.githubusercontent.com/composer/composer/2.8.12/src/Composer/Downloader/FileDownloader.php). Verify exact checksum behavior across the supported Composer matrix; do not assume Composer enforces a custom SHA-256 `extra` field.
- Add `extra.source-plugin.provenance` with schema, repository, commit, path, tree, manifest hash and inventory location. This preserves the distinction between commit and subdirectory.
- **Omit `source` initially.** A stock Git source checkout would install the monorepo root, not the component root. `support.source` may link to the monorepo for browsing without offering a false install source. `--prefer-source` must fall back to the only available dist, tested explicitly. True editable remote source installs require a future custom downloader contract.

Use deterministic entry order, timestamps and modes and hash the final ZIP. Archive URLs include version and content digest. GitHub release assets are not assumed intrinsically immutable: publication policy forbids replacement, metadata pins checksums, and installation fails on altered bytes. Keep append-only version records; a corrected package needs a new version.

Proposed hosting is `https://webong.github.io/proxy/composer/` for a standard index, with assets under releases in `https://github.com/webong/proxy`. These are proposed endpoints; availability, Pages ownership/configuration and release retention remain unverified. A generated Pages branch in this same repository is permitted by this proposal; an additional distribution repository is not. A mutable catalog can add versions but must not mutate existing records. Publish all assets and immutable metadata snapshots first, verify their public availability, then atomically promote the catalog. Never expose a version with incomplete assets.

### Concrete metadata shape

Illustrative record below uses placeholders for the future commit/digests, not deployable values. The actual publisher copies all validated manifest fields; this abbreviated example only shows the remote-specific additions:

```json
{
  "packages": {
    "webong/proxy": {
      "0.4.1": {
        "name": "webong/proxy",
        "version": "0.4.1",
        "type": "library",
        "require": {"php": "^8.3", "guzzlehttp/guzzle": "^7.10"},
        "autoload": {"psr-4": {"Webong\\Proxy\\": "src/"}},
        "dist": {
          "type": "zip",
          "url": "https://github.com/webong/proxy/releases/download/php%2Fv0.4.1/proxy-0.4.1-ARCHIVE_SHA256.zip",
          "reference": "FULL_MONOREPO_COMMIT",
          "shasum": "ARCHIVE_SHA1"
        },
        "extra": {
          "source-plugin": {
            "provenance": {
              "schema": 1,
              "repository": "https://github.com/webong/proxy",
              "commit": "FULL_MONOREPO_COMMIT",
              "path": "php",
              "tree": "COMPONENT_TREE_ID",
              "manifest-sha256": "MANIFEST_SHA256",
              "archive-sha256": "ARCHIVE_SHA256"
            }
          }
        }
      }
    }
  }
}
```

The real catalog also includes `webong/proxy-laravel` with its own full requirement graph and provider metadata; no `replace`/`provide` trick maps one package onto the other. The release inventory links both records to their approved tag/commit and retained paths. URL encoding of prefixed release tags is an integration-test requirement.

## Fresh consumer interface

Illustrative configuration after a tested 0.4.1 publication:

```json
{
  "repositories": [
    {
      "type": "composer",
      "url": "https://webong.github.io/proxy/composer/",
      "canonical": true,
      "only": ["webong/proxy", "webong/proxy-laravel"]
    }
  ],
  "require": {"webong/proxy-laravel": "^0.4.1"}
}
```

A core-only app instead requires `webong/proxy`. Composer sees the adapter's `webong/proxy` constraint and resolves the sibling from this same catalog. No plugin install order, global plugin or `allow-plugins` entry is needed. Consumers retain their ordinary `composer.lock`. The custom catalog is authoritative only for these two names, never unrelated dependencies.

Canonical repositories can hide older Packagist versions. Before promotion, the catalog must contain validated compatibility records for the historical versions it promises to support, including 0.4.0, pointing to their **existing** artifacts and references unchanged. Do not synthesize new 0.4.0 monorepo archives or rewrite old metadata under the same version. Compare compatibility records to a captured public Packagist snapshot. If exact compatibility cannot be established, narrow rollout to consumers requiring new releases and document the limitation; do not silently relax canonical priority to make fallback unpredictable.

### Runtime and lock behavior

| Operation | Required behavior |
| --- | --- |
| Cold install/update | Metadata available before solving; stock archive installs; no plugin bootstrap |
| Locked install/reinstall | Use exact locked dist/checksum; no tag re-resolution or publisher command |
| Partial update | Only allowed dependencies change under Composer rules; no forced sibling upgrades; constraints may legitimately fail |
| `--no-dev` | Production installs work without author tooling |
| `--no-plugins` | Remote package installation remains valid because no custom installer/downloader is needed; other source-plugin features are separately disabled |
| `--prefer-source` | Dist-only fallback, not a whole-monorepo checkout |
| Offline | Works only with complete Composer metadata/archive cache or pre-seeded artifacts; missing data fails clearly |

`composer.lock` is the authoritative consumer package lock. Test that provenance `extra` survives locking and reinstall, and record supported Composer versions. No new consumer `composer-source.lock` section is needed for the recommended path. Existing mirror/projection sections remain untouched when those features are independently used. Release inventory is an author artifact, not a second solver lock.

Use Composer's normal HTTPS/auth/cache mechanisms. Keep credentials in auth.json/environment, never repository configuration examples, generated metadata, lockfiles or logs. Private hosting must be tested for redirects/auth forwarding; do not promise private GitHub Pages support. Cache only validated public artifacts in CI; key author caches by commit, package path, manifest and publisher format version. Catalog signatures require a verifier if clients must enforce them beyond Composer/HTTPS; stock Composer is not assumed to verify them.

## Compatibility with existing features

- Local workspaces retain local-development precedence for the same names. Do not project symlinked workspace installs. Publisher input comes from an immutable Git ref, not the linked installed tree.
- Loaders may select a local versus remote candidate under their existing contract; publisher mode itself creates no loader entries and never merges manifests.
- Projections may prune a **copied** component installation if it declares surfaces; dependencies stay as resolved from that component. The root-installed manifest must survive. Test against existing projection conflict rules.
- Mirrors can track an installed remote component using its normal installed package root. Provenance remains associated with that component, not the whole monorepo. Existing no-workspace/no-loader/no-projection mirror conflicts still apply.
- Aliases/rebases continue to operate on installed component source. Do not add aliases to solve already-compatible namespaces.
- Duplicate package/version definitions, foreign repository takeover and contradictory publication/workspace mappings fail before artifact writes. Never overwrite a caller's source tree, catalog or existing release as recovery.

## Deferred plugin-backed repository contract

A remote-scanning consumer plugin remains feasible, but is not the first milestone. It must read an explicit repository + package-path + tag-pattern allowlist, fetch immutable manifests and register package candidates during plugin activation before the resolver constructs its pool. `pre-pool-create` alone cannot make a plugin available to the solve that first installs it. Bootstrap therefore needs two commands: install/trust the independently discoverable plugin first, then require the subpackages in a second solve. `plugin-modifies-downloads` only addresses download ordering after resolution, not that circularity.

If this later mode merely injects generated standard metadata pointing at component ZIPs, locked installs can still work without it, while fresh updates/discovery cannot. If it introduces a custom subdirectory dist/source type, plugin presence becomes a production requirement, not `require-dev`: it must register its downloader before all package operations, explicitly handle install/update/reinstall and reject disabled-plugin installs before touching existing package trees. With `--no-plugins`, stock Composer must fail on unsupported types rather than install the whole monorepo. This cannot rely solely on an error thrown by the disabled plugin itself.

Any custom downloader must resolve tags only during update, persist commit + path + tree in locked package metadata, stage and validate extraction, reject traversal/unsafe links, cache by commit/path/format and atomically install the component root. A new `composer-source.lock` section would need explicit schema/version/merge rules and missing/mismatch failures; it cannot contain the only provenance required to replay a Composer lock. Source mode must define whether a component is an editable subtree checkout or a copied export; never advertise ordinary Git semantics for an exported directory. None of these runtime behaviors are implemented or validated here.

## Safe Proxy publication correction

### Phase 0 — freeze and preserve

Capture immutable evidence outside temporary directories: existing Packagist package/version metadata, lock samples, Git tag objects/signatures, release asset hashes, manifests and permissions. Preserve `proxy-php`, `proxy-laravel`, all six historical stable tags, both PHP 0.4.0 releases, and `go/v0.4.0`. No deletion, archive, retarget or tag recreation. Keep Gateway untouched.

### Phase 1 — build and prove the author module

Implement the publisher in the plugin on a reviewed branch after authorization. Verify deterministic archives and standard Composer fixtures from a local HTTP catalog. No live Proxy publication changes yet. Prove separate dependency graphs and original namespace/resource behavior with the actual current layout.

### Phase 2 — stage a new patch release

Prepare **new 0.4.1 package records and a new approved monorepo tag/commit**, rather than changing 0.4.0. Confirm core and adapter version policy and constraints. Stage same-repository assets and catalog, including exact compatibility records; test against staged immutable URLs. Upload only after separate release authorization. Existing public distributions remain available.

### Phase 3 — validate old and new consumers

Require empty-home/cache core and adapter installs, production `--no-dev --no-plugins`, Laravel 12/13 discovery/migrations/encrypted storage, full portable API checks, locked reinstall, optimized autoloading, partial updates and prefer-source fallback. Compare source inventories to the signed source commit. Test old 0.3.1 and 0.4.0 locks unchanged, fresh historical solves from the compatibility catalog, and real data upgrades from both 0.3.1 and 0.4.0 to 0.4.1. Do not equate package installation with migration correctness.

### Phase 4 — consumer migration; registry decision gate

Document an explicit root-repository opt-in and distribute the new catalog URL to Atmosphere consumers. Public Packagist cannot simply be retargeted to the current monorepo and discover both subpackages. Under the recommended architecture, **do not retarget it**. It continues serving existing versions from existing repositories until a separately designed, server-supported ingestion path is established. A future registry migration requires proof of both names, complete historical metadata, new versions and old lock replay before any change. Root-manifest branches or a registry accepting generated metadata are alternatives to research, not validated correction steps.

This means stock Packagist-only users remain on the compatibility publication until they opt in. If delivering new releases through Packagist-only discovery is a hard requirement, stop at this gate and obtain an architecture decision; do not quietly create distribution repositories again.

### Phase 5 — retention and rollback

Promote only after all acceptance tests pass. Roll back a bad catalog promotion by restoring its prior catalog snapshot for new solves; retain every already-advertised artifact so new locks remain installable. Fix bad published bytes/metadata through another patch version, never replacement. Preserve deployed databases and their tested migration rollback/recovery procedures; metadata rollback does not reverse migrations.

Existing locks directly reference the unwanted repositories, and public installations cannot be enumerated completely. Therefore no finite observation window proves deletion safe. Keep long-lived compatibility hosting unless every dependency has a separately proven durable replacement at the **same old URLs**; GitHub deletion/archival is not such a replacement. Archival may preserve downloads but restrict future fixes and is not authorized here. The target is no new distribution repositories/releases for the new architecture, not destruction of old provenance.

## Implementation milestones and acceptance gates

1. **Contract fixtures:** approved configuration/schema, shared/prefixed tag mapping, invalid identities/paths/version conflicts. No publication.
2. **Plan and archive engine:** immutable Git reads, deterministic ZIPs, provenance and collision/resource validation. Test malicious archives/links/traversal, filesystem collisions, size limits, no writes outside staging and safe interrupted runs.
3. **Metadata renderer:** standard repository, compatibility import, frozen-version detection and atomic catalog promotion plan. No host credentials/uploader required to test it.
4. **Composer matrix:** minimum supported Composer 2.3 and current Composer 2 on supported PHP versions. Empty caches/homes, sibling solve, mismatched constraints, no-dev/no-plugins, locked install, reinstall, partial update, archive tampering, missing dist, unavailable catalog, cached offline success/failure. No whole-repo source fallback.
5. **Proxy staging proof:** actual neutral core and Laravel fixture installs, byte inventories, duplicate-symbol checks, migration/data compatibility. Verify standalone Go layout/tag remains unchanged; do not infer unavailable checksum-database verification.
6. **Release runbook:** separately reviewed/authorized metadata hosting, signing, upload, compatibility retention and rollback. Only then propose actual publication changes.

## Open decisions requiring agreement before release

- Is a root Composer repository entry acceptable, or is public Packagist-only discovery mandatory? These requirements lead to different feasible architectures.
- Approve publisher-first scope and deferral of plugin-backed remote source installation.
- Approve same-repository Pages/release hosting, retention guarantees and trusted signing keys.
- Shared `php/v{version}` tags initially, or independent package-prefixed tags? Neither changes Go tags or old stable PHP tags.
- Minimum Composer support and the exact checksum/auth/metadata-lock compatibility matrix.
- Whether a future requirement for editable remote source installs justifies a custom downloader and a mandatory production plugin. That future design must specify cold two-stage bootstrap, plugin trust, `plugin-modifies-downloads`, lock provenance, no-plugin failure and recovery; it cannot inherit the plugin-free claims above.

## Primary references

- [Composer repository format and root-only repository configuration](https://getcomposer.org/doc/05-repositories.md)
- [Composer schema: source, dist, autoload, extra and bin](https://getcomposer.org/doc/04-schema.md)
- [Composer plugin loading and download/install ordering attributes](https://getcomposer.org/doc/articles/plugins.md)
- [Composer allow-plugins trust configuration](https://getcomposer.org/doc/06-config.md#allow-plugins)
- [Composer CLI install/update/reinstall controls](https://getcomposer.org/doc/03-cli.md)
- [Packagist package submission and release discovery](https://packagist.org/about)
- [Composer GitDriver source](https://github.com/composer/composer/blob/main/src/Composer/Repository/Vcs/GitDriver.php)

These documents establish the standard mechanism. Proposed publisher syntax, hosting deployment, signatures and acceptance results above are design requirements, not claims of implemented or tested behavior.
