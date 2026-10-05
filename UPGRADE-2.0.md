# Upgrading from 0.x to 2.0

Configuration keys and behaviour are unchanged. Most sites only update the
constraint:

```bash
composer require contenir/maintenance-laminas-mvc:^2.0
```

## Requirements

| | 0.x | 2.0 |
| --- | --- | --- |
| PHP | ^8.1 | 8.3, 8.4 or 8.5 |
| `contenir/maintenance` | ^0.1.0 | ^0.1 or ^2.0 |
| `laminas/laminas-mvc` | ^3.4 | ^3.7 |
| `laminas/laminas-eventmanager` | ^3.0 | ^3.13 |
| `laminas/laminas-http` | ^2.0 | ^2.19 |
| `laminas/laminas-servicemanager` | ^3.0 | ^3.22 or ^4.0 |

## BC breaks

### `Module` is final

Extending the module class is no longer possible. Compose instead: register
your own module and call `attachListener()` from it if you need to change
how the listener is attached.

```php
// 0.x
class MyModule extends \Contenir\Maintenance\Laminas\Mvc\Module { /* ... */ }

// 2.0
final class MyModule
{
    public function onBootstrap(MvcEvent $event): void
    {
        $app = $event->getApplication();
        (new \Contenir\Maintenance\Laminas\Mvc\Module())->attachListener(
            $app->getEventManager(),
            $app->getServiceManager()->get(MaintenanceListener::class),
        );
    }
}
```

### `Module::DISPATCH_PRIORITY` is typed

`public const int DISPATCH_PRIORITY = 10000;`. Reading it is unchanged; only
code that redeclared it in a subclass is affected, and subclasses are no
longer possible.

### `MaintenanceListener` is `readonly` and `$bypass` is typed

```php
// 0.x: any value was accepted and only failed when a request arrived
new MaintenanceListener($repository, 600, $template, 'not callable');

// 2.0: the constructor takes ?callable, so this is a TypeError at construction
new MaintenanceListener($repository, 600, $template, $callable);
```

The factory already rejected non-callable `bypass` config, so sites that
build the listener through it are unaffected.

### Edge cases that no longer emit PHP warnings

| Input | 0.x | 2.0 |
| --- | --- | --- |
| `body_template_path` is a directory | Empty 503 body plus warnings | `RuntimeException` "is not readable" |
| `.phtml` template throws | Exception, output buffer left open | Exception, buffer closed |
| Array `state.message` / `body_template` | `"Array"` plus warning | `''` |
| Array `retry_after` | `0` or `1` | `0` |
| `maintenance` config is a non-array scalar | `TypeError` | Treated as empty |

Projects that must stay on PHP 8.1 or 8.2 can keep using `^0.3`, which is
maintained on the `0.x` branch.
