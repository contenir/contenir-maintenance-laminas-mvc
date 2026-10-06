<?php

declare(strict_types=1);

namespace Contenir\Maintenance\Laminas\Mvc\Factory;

use Contenir\Maintenance\Laminas\Mvc\ConfigProvider;
use Contenir\Maintenance\Laminas\Mvc\Listener\MaintenanceListener;
use Contenir\Maintenance\MaintenanceRepositoryInterface;
use Contenir\Maintenance\MaintenanceState;
use Contenir\Maintenance\Repository\InMemoryRepository;
use DateTimeImmutable;
use Psr\Container\ContainerExceptionInterface;
use Psr\Container\ContainerInterface;
use RuntimeException;
use Throwable;

use function array_key_exists;
use function file_get_contents;
use function in_array;
use function is_array;
use function is_callable;
use function is_file;
use function is_readable;
use function is_scalar;
use function is_string;
use function ob_end_clean;
use function ob_get_contents;
use function ob_start;
use function pathinfo;
use function restore_error_handler;
use function set_error_handler;
use function sprintf;
use function strtolower;

use const PATHINFO_EXTENSION;

/**
 * Builds the MaintenanceListener from `config[maintenance]`, falling back to
 * ConfigProvider::getMaintenanceDefaults() for every key the site omits.
 *
 * @api
 *
 * @mago-expect lint:cyclomatic-complexity Config parsing and template loading share this class; splitting them is a suggested follow-up.
 */
final class MaintenanceListenerFactory
{
    /**
     * @throws RuntimeException When the path is not a readable file.
     */
    private static function loadBodyTemplateFromPath(string $path): string
    {
        if (! is_file($path) || ! is_readable($path)) {
            throw new RuntimeException(sprintf(
                'contenir/contenir-maintenance-laminas-mvc: body_template_path "%s" is not readable.',
                $path,
            ));
        }

        $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        if (in_array($extension, ['phtml', 'php'], strict: true)) {
            return self::renderTemplate($path);
        }

        return self::readTemplate($path);
    }

    private static function parseSince(mixed $raw): ?DateTimeImmutable
    {
        if (! is_string($raw) || '' === $raw) {
            return null;
        }

        try {
            return new DateTimeImmutable($raw);
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * @throws RuntimeException When the file cannot be read.
     */
    private static function readTemplate(string $path): string
    {
        set_error_handler(static fn(): bool => true);

        try {
            $content = file_get_contents($path);
        } finally {
            restore_error_handler();
        }

        if (false === $content) {
            throw new RuntimeException(sprintf(
                'contenir/contenir-maintenance-laminas-mvc: failed reading body_template_path "%s".',
                $path,
            ));
        }

        return $content;
    }

    /**
     * Include a PHP template under output buffering, inside a closure so it
     * sees no factory-scope variables. The buffer is closed even when the
     * template throws.
     */
    private static function renderTemplate(string $path): string
    {
        ob_start();

        try {
            (static function () use ($path): void {
                include $path;
            })();

            return (string) ob_get_contents();
        } finally {
            ob_end_clean();
        }
    }

    /**
     * Resolve the sprintf body template the listener feeds the 503 response.
     *
     * Precedence:
     *   1. An explicit `body_template` in user config wins (legacy inline-string
     *      contract, untouched).
     *   2. Otherwise `body_template_path` is loaded — user-set or the package
     *      default (bundled view/contenir/maintenance/index.phtml). For .phtml
     *      and .php paths the file is `include`d under output buffering inside
     *      an isolated closure, so PHP runs once at config-load time without
     *      leaking factory-scope variables; for any other extension the file
     *      contents are read raw via file_get_contents.
     *   3. Final fallback is the inline default body_template string.
     *
     * Either way the result is a sprintf template — exactly one %s for the
     * admin message, no other unescaped percent signs.
     *
     * @param array<array-key, mixed> $userConfig
     *
     * @throws RuntimeException When the template path cannot be read.
     *
     * @mago-expect analysis:mixed-assignment The site's config is untyped; narrowed here.
     */
    private static function resolveBodyTemplate(array $userConfig, mixed $path): string
    {
        if (array_key_exists('body_template', $userConfig)) {
            $template = $userConfig['body_template'];

            return is_scalar($template) ? (string) $template : '';
        }

        if (is_string($path) && '' !== $path) {
            return self::loadBodyTemplateFromPath($path);
        }

        return ConfigProvider::DEFAULT_BODY_TEMPLATE;
    }

    /**
     * Build an in-memory repository from `config[maintenance][state]` (the
     * merged Laminas config). The data file written by admin lives in
     * config/autoload/*.local.php and is auto-merged into `$config` at boot,
     * so there's no need to re-read it from disk on every request.
     *
     * If a service is registered for MaintenanceRepositoryInterface, that wins.
     *
     * @throws ContainerExceptionInterface
     */
    private static function resolveRepository(
        ContainerInterface $container,
        mixed $stateData,
    ): MaintenanceRepositoryInterface {
        if ($container->has(MaintenanceRepositoryInterface::class)) {
            return $container->get(MaintenanceRepositoryInterface::class);
        }

        return new InMemoryRepository(self::stateFromConfig($stateData));
    }

    /**
     * @mago-expect analysis:mixed-assignment,mixed-operand The site's config is untyped; narrowed here.
     */
    private static function stateFromConfig(mixed $stateData): ?MaintenanceState
    {
        if (! is_array($stateData)) {
            return null;
        }

        if (! (bool) ($stateData['active'] ?? false)) {
            return MaintenanceState::inactive();
        }

        $message = $stateData['message'] ?? '';

        return new MaintenanceState(
            active: true,
            message: is_scalar($message) ? (string) $message : '',
            since: self::parseSince($stateData['since'] ?? null),
        );
    }

    /**
     * The site's `config[maintenance]` array, or an empty array when the
     * container has no config or the key is missing or not an array.
     *
     * @throws ContainerExceptionInterface
     *
     * @return array<array-key, mixed>
     *
     * @mago-expect analysis:mixed-assignment The container's config service is untyped; narrowed here.
     */
    private static function userConfig(ContainerInterface $container): array
    {
        $config      = $container->has('config') ? $container->get('config') : [];
        $maintenance = is_array($config) ? $config['maintenance'] ?? [] : [];

        return is_array($maintenance) ? $maintenance : [];
    }

    /**
     * @throws ContainerExceptionInterface
     * @throws RuntimeException When `bypass` is not callable, or `body_template_path` cannot be read.
     *
     * @mago-expect analysis:mixed-assignment The site's config is untyped; narrowed here.
     */
    public function __invoke(ContainerInterface $container): MaintenanceListener
    {
        $userConfig  = self::userConfig($container);
        $maintenance = $userConfig + (new ConfigProvider())->getMaintenanceDefaults();
        $bypass      = $maintenance['bypass'] ?? null;
        $retryAfter  = $maintenance['retry_after'] ?? null;

        if (null !== $bypass && ! is_callable($bypass)) {
            throw new RuntimeException(
                'contenir/contenir-maintenance-laminas-mvc: config[maintenance][bypass] must be callable or null.',
            );
        }

        return new MaintenanceListener(
            repository: self::resolveRepository($container, $maintenance['state'] ?? null),
            retryAfter: is_scalar($retryAfter) ? (int) $retryAfter : 0,
            bodyTemplate: self::resolveBodyTemplate($userConfig, $maintenance['body_template_path'] ?? null),
            bypass: $bypass,
        );
    }
}
