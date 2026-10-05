<?php

declare(strict_types=1);

namespace Contenir\Maintenance\Laminas\Mvc\Tests\Unit;

use Contenir\Maintenance\Laminas\Mvc\ConfigProvider;
use Contenir\Maintenance\Laminas\Mvc\Listener\MaintenanceListener;
use Contenir\Maintenance\Laminas\Mvc\Module;
use Contenir\Maintenance\Repository\InMemoryRepository;
use Laminas\EventManager\EventManagerInterface;
use Laminas\Mvc\ApplicationInterface;
use Laminas\Mvc\MvcEvent;
use Laminas\ServiceManager\ServiceLocatorInterface;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[Group('unit')]
final class ModuleTest extends TestCase
{
    #[Test]
    public function attachesTheListenerToDispatchAtHighPriority(): void
    {
        $listener = new MaintenanceListener(new InMemoryRepository());
        $events   = $this->createMock(EventManagerInterface::class);
        $events->expects($this->once())
            ->method('attach')
            ->with(MvcEvent::EVENT_DISPATCH, $listener, 10_000);

        (new Module())->attachListener($events, $listener);
    }

    #[Test]
    public function bootstrapAttachesTheListenerFromTheServiceManager(): void
    {
        $listener = new MaintenanceListener(new InMemoryRepository());
        $services = $this->createStub(ServiceLocatorInterface::class);
        $services->method('get')->willReturnMap([[MaintenanceListener::class, $listener]]);
        $events = $this->createMock(EventManagerInterface::class);
        $events->expects($this->once())
            ->method('attach')
            ->with(MvcEvent::EVENT_DISPATCH, $listener, Module::DISPATCH_PRIORITY);
        $application = $this->createStub(ApplicationInterface::class);
        $application->method('getServiceManager')->willReturn($services);
        $application->method('getEventManager')->willReturn($events);
        $event = new MvcEvent();
        $event->setApplication($application);

        (new Module())->onBootstrap($event);
    }

    #[Test]
    public function configIsTheConfigProviderOutput(): void
    {
        static::assertSame((new ConfigProvider())(), (new Module())->getConfig());
    }
}
