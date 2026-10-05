# contenir/maintenance-laminas-mvc

[![Continuous Integration](https://github.com/contenir/maintenance-laminas-mvc/actions/workflows/continuous-integration.yml/badge.svg)](https://github.com/contenir/maintenance-laminas-mvc/actions/workflows/continuous-integration.yml)
[![codecov](https://codecov.io/gh/contenir/maintenance-laminas-mvc/graph/badge.svg)](https://codecov.io/gh/contenir/maintenance-laminas-mvc)

Laminas MVC adapter for [`contenir/maintenance`](https://github.com/contenir/maintenance).

When the admin (Contenir CMS) toggles maintenance mode, this module
short-circuits dispatch in the consuming Site with a `503 Service Unavailable`
response until the flag is cleared.

## Requirements

- PHP 8.3, 8.4 or 8.5
- `laminas/laminas-mvc` ^3.7
- `contenir/maintenance` ^0.1 or ^2.0

The 0.x releases, which support PHP 8.1, remain available from the `0.x`
branch and `v0.*` tags; see [UPGRADE-2.0.md](UPGRADE-2.0.md).

## Install

```bash
composer require contenir/maintenance-laminas-mvc
```

With `laminas/laminas-component-installer`, the module is registered for
you. Otherwise add it to `config/modules.config.php`:

```php
return [
    // ...
    'Contenir\Maintenance\Laminas\Mvc',
];
```

## How it works

- `Module::getConfig()` returns `ConfigProvider::__invoke()`, which registers
  `MaintenanceListenerFactory` for `Listener\MaintenanceListener`.
- `Module::onBootstrap()` fetches the listener and attaches it to
  `MvcEvent::EVENT_DISPATCH` at priority `Module::DISPATCH_PRIORITY`
  (`10000`), ahead of route-to-controller dispatch.
- When the state is active and no bypass applies, the listener triggers
  `pagecache.disable` on the application's event manager (so
  `contenir/cache-laminas-mvc` will not store the page), builds a 503 with
  `Retry-After` and `Content-Type: text/html; charset=utf-8` headers, sets it
  on the event and stops propagation.

## Configuration

All keys live under `maintenance` and are optional.

| Key | Default | Meaning |
| --- | --- | --- |
| `state` | inactive | `active`, `message`, `since`: the state written by the admin |
| `retry_after` | `600` | Seconds sent in the `Retry-After` header |
| `bypass` | `null` | `callable(MvcEvent): bool`; only a `true` return lets the request through |
| `body_template` | not set | Inline `sprintf` template; wins over `body_template_path` |
| `body_template_path` | bundled `view/contenir/maintenance/index.phtml` | Template file; `null` or `''` uses a minimal inline page |

### State

The admin writes the state with `Contenir\Maintenance\Repository\FileRepository`
into a file the Site loads as config, for example
`config/autoload/maintenance.local.php`:

```php
return [
    'maintenance' => [
        'state' => [
            'active'  => true,
            'message' => 'Back online by 5pm AEST.',
            'since'   => '2026-05-05T03:14:15+00:00',
        ],
    ],
];
```

The factory turns `maintenance.state` into an `InMemoryRepository`, so no
file is read per request. If the Site caches its merged config, the cache
must be cleared when the state changes. To read the state from another
source instead, register a service for
`Contenir\Maintenance\MaintenanceRepositoryInterface`; it always wins:

```php
'service_manager' => [
    'factories' => [
        MaintenanceRepositoryInterface::class => static fn(): MaintenanceRepositoryInterface
            => new FileRepository('/var/www/shared/maintenance.local.php'),
    ],
],
```

### Bypass for operators

```php
'maintenance' => [
    'bypass' => static function (\Laminas\Mvc\MvcEvent $event): bool {
        $auth = $event->getApplication()->getServiceManager()->get('auth');

        return $auth->hasIdentity() && $auth->getIdentity()->isSuperAdmin();
    },
],
```

A `bypass` that is neither callable nor `null` makes the factory throw a
`RuntimeException`.

### Response body

The body is a `sprintf` format. It receives two arguments, and any other `%`
must be written as `%%`:

| Placeholder | Value |
| --- | --- |
| `%s` or `%1$s` | The HTML-escaped message |
| `%2$s` | `since`, when maintenance started, as ISO 8601 (`2026-05-05T03:14:15+00:00`); an empty string when the state has no `since` or it could not be parsed |

A template that only uses `%s` is unaffected by `since`. To show it:

```php
'body_template' => '<h1>Down for maintenance</h1><p>%1$s</p>'
    . '<p>Down since <time datetime="%2$s">%2$s</time></p>',
```

```php
'maintenance' => [
    // A file: .phtml and .php are included once when the listener is built,
    // so they may contain PHP; any other extension is read verbatim.
    'body_template_path' => __DIR__ . '/../../view/maintenance.phtml',

    // Or an inline string, which wins over body_template_path:
    'body_template' => '<h1>Down for maintenance</h1><p>%s</p>',

    'retry_after' => 1800,
],
```

A `body_template_path` that is not a readable file makes the factory throw a
`RuntimeException`. The bundled template is self-contained (inline CSS, no
layout) so it renders even when the Site's assets are unavailable.

For anything more elaborate (layouts, view helpers, translation), replace the
`MaintenanceListener` service with your own factory. The listener's
constructor is
`new MaintenanceListener(MaintenanceRepositoryInterface $repository, int $retryAfter = 600, string $bodyTemplate = MaintenanceListener::DEFAULT_BODY_TEMPLATE, ?callable $bypass = null)`.

## Public API

| Symbol | Purpose |
| --- | --- |
| `Module` | `getConfig()`, `onBootstrap(MvcEvent)`, `attachListener(EventManagerInterface, MaintenanceListener)`, `DISPATCH_PRIORITY` |
| `ConfigProvider` | `__invoke()`, `getDependencies()`, `getMaintenanceDefaults()`, `defaultBodyTemplatePath()`, `DEFAULT_BODY_TEMPLATE` |
| `Factory\MaintenanceListenerFactory` | `__invoke(ContainerInterface): MaintenanceListener` |
| `Listener\MaintenanceListener` | `__invoke(MvcEvent): ?Response`, `DEFAULT_BODY_TEMPLATE` |

## Development

The QA toolchain is [php-db/phpdb-qa-tools](https://github.com/php-db/phpdb-qa-tools).
[Mago](https://mago.carthage.software/) is a standalone binary, installed
separately (`brew install mago`).

```bash
composer check             # everything below
composer cs-check          # mago format --check && mago lint
composer static-analysis   # mago analyze
composer test              # unit suite: listener, module and factory with test doubles, no I/O
composer test-integration  # integration suite: template files, bundled view, real service and event managers
composer test-coverage     # both suites, clover.xml for Codecov
composer mutation-test     # Infection mutation testing over both suites
```

## License

MIT. See [LICENSE](LICENSE).
