# Releasing and Packagist

This page is for maintainers of `webong/composer-source-plugin`. Feature
documentation starts at the [documentation index](./README.md).

## Installing from Packagist

After the package has been released, consumers can install a stable version
without declaring a VCS repository:

```sh
composer require --dev webong/composer-source-plugin:^1.0
```

## One-time Packagist setup

1. Sign in to [Packagist](https://packagist.org/) with the GitHub account that
   can administer `webong/composer-source-plugin`.
2. Submit `https://github.com/webong/composer-source-plugin` from the Packagist
   package submission page.
3. Enable Packagist's GitHub webhook integration for the repository. Packagist
   then receives every pushed tag and updates package metadata automatically.

The package is public, and its root `composer.json` carries the package name,
license, and supported PHP range that Packagist reads. No Packagist credential
is stored in this repository.

Release archives exclude CI, test, and local container files. Source,
`composer.json`, the README, the docs, and the license remain available to
consumers.

## Creating a release

Run the **Release** workflow from `main` and supply a semantic version such as
`1.0.0`. The workflow:

1. validates the tag format and that the tag does not already exist;
2. runs package validation, unit tests, and Composer integration tests;
3. creates an annotated `v1.0.0` tag;
4. creates the corresponding GitHub release.

Composer derives the stable package version from that tag, and Packagist
usually makes it visible within a minute after receiving the webhook.

The workflow only releases the commit checked out from `main`. A failed test or
an existing tag stops before anything is published.

## Release checklist

- [ ] `composer test` passes.
- [ ] `composer test:integration` passes (requires Docker).
- [ ] The working tree is clean and `main` is up to date.
- [ ] The version follows semantic versioning.
- [ ] The release notes describe user-visible changes.
