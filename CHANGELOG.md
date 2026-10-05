# Changelog

All notable changes to this project are documented here. The format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and the project
adheres to [Semantic Versioning](https://semver.org/).

## [2.1.0] - Unreleased

### Added

- Infection mutation testing in CI, MSI 100%.
- The maintenance state's `since` time reaches the response body: the body
  template receives it as a second `sprintf` argument, `%2$s`, formatted as
  ISO 8601, or an empty string when the state has no `since`. Templates that
  only use `%s` render exactly as before.

## [2.0.0] - Unreleased

The major version marks the move to PHP 8.3+ and the php-db QA toolchain
shared by all Contenir 2.x packages. Configuration is unchanged. See
[UPGRADE-2.0.md](UPGRADE-2.0.md) for the few signature changes.

### Changed

- `LICENSE` names Contenir as the copyright holder, in line with the other
  Contenir packages, and uses the standard MIT wording.
- Requires PHP 8.3, 8.4 or 8.5. PHP 8.1 and 8.2 are no longer supported.
- Requires `contenir/maintenance` ^0.1 or ^2.0, `laminas/laminas-mvc` ^3.7,
  `laminas/laminas-eventmanager` ^3.13, `laminas/laminas-http` ^2.19,
  `laminas/laminas-servicemanager` ^3.22 or ^4.0, and declares the
  `psr/container` dependency it already used.
- `Module` is `final`, and `Module::DISPATCH_PRIORITY` is typed `int`.
- `Listener\MaintenanceListener` is `final readonly`, and its `$bypass`
  constructor parameter is typed `?callable`.
- Public constants are typed.

### Fixed

- A `.phtml`/`.php` body template that throws no longer leaves an output
  buffer open; the buffer is closed and the exception propagates.
- A `body_template_path` that is a directory now throws the "is not readable"
  `RuntimeException` instead of producing an empty page with PHP warnings.
- A non-scalar `state.message`, `body_template` or `retry_after` no longer
  raises "Array to string conversion"; they read as `''`, `''` and `0`.
- A `maintenance` config key that is not an array is treated as empty instead
  of failing with a `TypeError`.
- The README described a `maintenance.file` key that the factory has not read
  since 0.1.1; it now documents `maintenance.state`.

### Added

- Continuous integration on PHP 8.3, 8.4 and 8.5 against lowest, locked and
  latest dependencies, with coverage reported to Codecov.
- Separate unit (no I/O) and integration (template files, real service and
  event managers) test suites, with 100% line and branch coverage.

### Removed

- `squizlabs/php_codesniffer` and `phpcs.xml`, replaced by Mago via
  `php-db/phpdb-qa-tools`.
- The `../contenir-maintenance` path and VCS repositories from `composer.json`.

## [0.3.1]

- `ConfigProvider` no longer seeds `maintenance` defaults into the merged
  config, so a site's `body_template_path` is no longer shadowed by the
  default `body_template`.

## [0.3.0]

- `body_template_path` option: `.phtml`/`.php` files are included once at
  build time, other files read verbatim. A styled default page is bundled.

## [0.2.0]

- Trigger `pagecache.disable` before serving the 503.

## [0.1.1]

- `MaintenanceListenerFactory` reads the state from the merged config.
- Added the MIT `LICENSE` file.

## [0.1.0]

- Initial release: module, listener and factory.
