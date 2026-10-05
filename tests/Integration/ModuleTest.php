<?php

declare(strict_types=1);

namespace Contenir\Maintenance\Laminas\Mvc\Tests\Integration;

use Contenir\Maintenance\Laminas\Mvc\ConfigProvider;
use Contenir\Maintenance\Laminas\Mvc\Module;
use Laminas\EventManager\EventManager;
use Laminas\Mvc\ApplicationInterface;
use Laminas\Mvc\MvcEvent;
use Laminas\ServiceManager\ServiceManager;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Wires the module into a real service manager and event manager, the way
 * laminas-mvc does on bootstrap.
 */
#[Group('integration')]
final class ModuleTest extends TestCase
{
    #[Test]
    public function letsDispatchContinueWhenMaintenanceIsInactive(): void
    {
        $dispatch = $this->dispatch(new EventManager(), ['state' => ['active' => false]]);

        static::assertNull($dispatch->getResponse());
    }

    #[Test]
    public function serves503BeforeDefaultPriorityDispatchListenersRun(): void
    {
        $events        = new EventManager();
        $controllerRan = false;
        $events->attach(MvcEvent::EVENT_DISPATCH, static function () use (&$controllerRan): void {
            $controllerRan = true;
        });

        $dispatch = $this->dispatch($events, ['state' => ['active' => true, 'message' => 'Back at 5pm']]);

        static::assertSame(503, $dispatch->getResponse()?->getStatusCode());
        static::assertFalse($controllerRan);
    }

    /**
     * @param array<string, mixed> $maintenance
     */
    private function dispatch(EventManager $events, array $maintenance): MvcEvent
    {
        $services = new ServiceManager((new ConfigProvider())->getDependencies());
        $services->setService('config', ['maintenance' => ['body_template' => '%s', ...$maintenance]]);
        $application = $this->createStub(ApplicationInterface::class);
        $application->method('getServiceManager')->willReturn($services);
        $application->method('getEventManager')->willReturn($events);
        $bootstrap = new MvcEvent();
        $bootstrap->setApplication($application);

        (new Module())->onBootstrap($bootstrap);

        $dispatch = new MvcEvent(MvcEvent::EVENT_DISPATCH);
        $dispatch->setApplication($application);
        $events->triggerEvent($dispatch);

        return $dispatch;
    }
}
