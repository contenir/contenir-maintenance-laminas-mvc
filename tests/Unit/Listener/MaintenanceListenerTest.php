<?php

declare(strict_types=1);

namespace Contenir\Maintenance\Laminas\Mvc\Tests\Unit\Listener;

use Contenir\Maintenance\Laminas\Mvc\Listener\MaintenanceListener;
use Contenir\Maintenance\MaintenanceState;
use Contenir\Maintenance\Repository\InMemoryRepository;
use Laminas\EventManager\EventManagerInterface;
use Laminas\Http\Response;
use Laminas\Mvc\ApplicationInterface;
use Laminas\Mvc\MvcEvent;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[Group('unit')]
#[Group('listener')]
final class MaintenanceListenerTest extends TestCase
{
    /**
     * @return array<string, array{mixed}>
     */
    public static function nonTrueBypassResultProvider(): array
    {
        return [
            'false'          => [false],
            'truthy integer' => [1],
            'truthy string'  => ['yes'],
            'null'           => [null],
        ];
    }

    #[Test]
    public function attachesA503ToTheEventAndStopsPropagation(): void
    {
        $event = new MvcEvent();

        $response = (new MaintenanceListener($this->activeRepository()))($event);

        static::assertInstanceOf(Response::class, $response);
        static::assertSame(503, $response->getStatusCode());
        static::assertSame($response, $event->getResponse());
        static::assertTrue($event->propagationIsStopped());
    }

    #[Test]
    public function defaultsRetryAfterToTenMinutes(): void
    {
        $response = (new MaintenanceListener($this->activeRepository()))(new MvcEvent());

        static::assertSame('Retry-After: 600', $response?->getHeaders()->get('Retry-After')->toString());
    }

    #[Test]
    public function escapesTheMessageIntoTheTemplate(): void
    {
        $listener = new MaintenanceListener(
            repository: $this->activeRepository("<script>alert('x')</script> & \"more\""),
            bodyTemplate: '<p>%s</p>',
        );

        static::assertSame(
            '<p>&lt;script&gt;alert(&#039;x&#039;)&lt;/script&gt; &amp; &quot;more&quot;</p>',
            $listener(new MvcEvent())?->getContent(),
        );
    }

    #[Test]
    public function leavesThePageCacheAloneWhenMaintenanceIsInactive(): void
    {
        $events = $this->createMock(EventManagerInterface::class);
        $events->expects($this->never())->method('trigger');
        $event = $this->eventWithApplicationEvents($events);

        (new MaintenanceListener(new InMemoryRepository()))($event);
    }

    #[Test]
    public function letsTheRequestThroughWhenMaintenanceIsInactive(): void
    {
        $event = new MvcEvent();

        $result = (new MaintenanceListener(new InMemoryRepository()))($event);

        static::assertNull($result);
        static::assertNull($event->getResponse());
        static::assertFalse($event->propagationIsStopped());
    }

    #[Test]
    public function letsTheRequestThroughWhenTheBypassReturnsTrue(): void
    {
        $event    = new MvcEvent();
        $listener = new MaintenanceListener(
            repository: $this->activeRepository(),
            bypass: static fn(MvcEvent $e): bool => true,
        );

        static::assertNull($listener($event));
        static::assertFalse($event->propagationIsStopped());
    }

    #[Test]
    public function passesTheEventToTheBypass(): void
    {
        $event    = new MvcEvent();
        $received = null;
        $listener = new MaintenanceListener(
            repository: $this->activeRepository(),
            bypass: static function (MvcEvent $e) use (&$received): bool {
                $received = $e;

                return false;
            },
        );

        $listener($event);

        static::assertSame($event, $received);
    }

    #[Test]
    public function rendersTheDefaultTemplateWithTheMessage(): void
    {
        $response = (new MaintenanceListener($this->activeRepository('Back soon')))(new MvcEvent());

        static::assertSame(
            '<!doctype html><title>503</title><h1>Service Unavailable</h1><p>Back soon</p>',
            $response?->getContent(),
        );
    }

    #[Test]
    public function sendsAnHtmlContentType(): void
    {
        $response = (new MaintenanceListener($this->activeRepository()))(new MvcEvent());

        static::assertSame(
            'Content-Type: text/html; charset=utf-8',
            $response?->getHeaders()->get('Content-Type')->toString(),
        );
    }

    #[Test]
    public function sendsTheConfiguredRetryAfter(): void
    {
        $listener = new MaintenanceListener(
            repository: $this->activeRepository(),
            retryAfter: 1800,
        );

        static::assertSame(
            'Retry-After: 1800',
            $listener(new MvcEvent())?->getHeaders()->get('Retry-After')->toString(),
        );
    }

    #[Test]
    #[DataProvider('nonTrueBypassResultProvider')]
    public function servesTheMaintenancePageUnlessTheBypassReturnsExactlyTrue(mixed $bypassResult): void
    {
        $listener = new MaintenanceListener(
            repository: $this->activeRepository(),
            bypass: static fn(MvcEvent $e): mixed => $bypassResult,
        );

        static::assertSame(503, $listener(new MvcEvent())?->getStatusCode());
    }

    #[Test]
    public function tellsThePageCacheNotToStoreThe503(): void
    {
        $events = $this->createMock(EventManagerInterface::class);
        $events->expects($this->once())->method('trigger')->with('pagecache.disable');
        $event = $this->eventWithApplicationEvents($events);

        (new MaintenanceListener($this->activeRepository()))($event);
    }

    private function activeRepository(string $message = 'Down'): InMemoryRepository
    {
        return new InMemoryRepository(MaintenanceState::active($message));
    }

    private function eventWithApplicationEvents(EventManagerInterface $events): MvcEvent
    {
        $application = $this->createStub(ApplicationInterface::class);
        $application->method('getEventManager')->willReturn($events);
        $event = new MvcEvent();
        $event->setApplication($application);

        return $event;
    }
}
