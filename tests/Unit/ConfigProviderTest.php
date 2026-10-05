<?php

declare(strict_types=1);

namespace Contenir\Maintenance\Laminas\Mvc\Tests\Unit;

use Contenir\Maintenance\Laminas\Mvc\ConfigProvider;
use Contenir\Maintenance\Laminas\Mvc\Factory\MaintenanceListenerFactory;
use Contenir\Maintenance\Laminas\Mvc\Listener\MaintenanceListener;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

use function dirname;

#[Group('unit')]
final class ConfigProviderTest extends TestCase
{
    #[Test]
    public function contributesOnlyServiceManagerConfig(): void
    {
        $provider = new ConfigProvider();

        static::assertSame(['service_manager' => $provider->getDependencies()], $provider());
    }

    #[Test]
    public function defaultBodyTemplatePathIsInsideThePackageViewDirectory(): void
    {
        static::assertSame(
            dirname(__DIR__, levels: 2) . '/src/../view/contenir/maintenance/index.phtml',
            ConfigProvider::defaultBodyTemplatePath(),
        );
    }

    #[Test]
    public function defaultsPointAtTheBundledTemplateAndATenMinuteRetry(): void
    {
        static::assertSame(
            [
                'retry_after'        => 600,
                'bypass'             => null,
                'body_template'      => ConfigProvider::DEFAULT_BODY_TEMPLATE,
                'body_template_path' => ConfigProvider::defaultBodyTemplatePath(),
            ],
            (new ConfigProvider())->getMaintenanceDefaults(),
        );
    }

    #[Test]
    public function registersTheListenerFactory(): void
    {
        static::assertSame(
            ['factories' => [MaintenanceListener::class => MaintenanceListenerFactory::class]],
            (new ConfigProvider())->getDependencies(),
        );
    }
}
