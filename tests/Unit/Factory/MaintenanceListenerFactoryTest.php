<?php

declare(strict_types=1);

namespace Contenir\Maintenance\Laminas\Mvc\Tests\Unit\Factory;

use Contenir\Maintenance\Laminas\Mvc\ConfigProvider;
use Contenir\Maintenance\Laminas\Mvc\Factory\MaintenanceListenerFactory;
use Contenir\Maintenance\Laminas\Mvc\Tests\TestAsset\Container\InMemoryContainer;
use Contenir\Maintenance\MaintenanceRepositoryInterface;
use Contenir\Maintenance\MaintenanceState;
use Contenir\Maintenance\Repository\InMemoryRepository;
use Laminas\Http\Response;
use Laminas\Mvc\MvcEvent;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;

use function sprintf;

/**
 * Every case sets `body_template` or a null `body_template_path`, so the
 * factory never reads a template file; file loading is covered by the
 * integration suite.
 */
#[Group('unit')]
#[Group('factory')]
final class MaintenanceListenerFactoryTest extends TestCase
{
    /**
     * @return array<string, array{mixed}>
     */
    public static function inactiveStateProvider(): array
    {
        return [
            'state missing'       => [null],
            'state not an array'  => ['on'],
            'active flag missing' => [['message' => 'm']],
            'active flag false'   => [['active' => false, 'message' => 'lingering']],
            'active flag zero'    => [['active' => 0]],
        ];
    }

    /**
     * @return array<string, array{mixed, string}>
     */
    public static function inlineTemplateProvider(): array
    {
        return [
            'string'               => ['MAINT: %s', 'MAINT: down'],
            'null falls to empty'  => [null, ''],
            'array falls to empty' => [['MAINT: %s'], ''],
        ];
    }

    /**
     * @return array<string, array{mixed, string}>
     */
    public static function messageProvider(): array
    {
        return [
            'string'  => ['Down for upgrade', 'Down for upgrade'],
            'missing' => [null, ''],
            'integer' => [503, '503'],
            'array'   => [['nested'], ''],
        ];
    }

    /**
     * @return array<string, array{mixed, string}>
     */
    public static function retryAfterProvider(): array
    {
        return [
            'integer'        => [1234, '1234'],
            'numeric string' => ['90', '90'],
            'null'           => [null, '0'],
            'array'          => [[60], '0'],
        ];
    }

    /**
     * @return array<string, array{mixed}>
     */
    public static function unusableTemplatePathProvider(): array
    {
        return [
            'null'         => [null],
            'empty string' => [''],
            'not a string' => [42],
        ];
    }

    #[Test]
    public function appliesTheConfiguredBypass(): void
    {
        $response = $this->respond(
            ['body_template' => '%s', 'bypass' => static fn(MvcEvent $e): bool => true],
            $this->activeRepository(),
        );

        static::assertNull($response);
    }

    #[Test]
    #[DataProvider('retryAfterProvider')]
    public function appliesTheConfiguredRetryAfterAsAnInteger(mixed $retryAfter, string $expected): void
    {
        $response = $this->respond(['body_template' => '%s', 'retry_after' => $retryAfter], $this->activeRepository());

        static::assertSame("Retry-After: {$expected}", $response?->getHeaders()->get('Retry-After')->toString());
    }

    #[Test]
    public function buildsTheStateFromConfigWhenNoRepositoryIsRegistered(): void
    {
        $response = $this->respond([
            'body_template' => '%s',
            'state'         => [
                'active'  => true,
                'message' => 'Down for upgrade',
                'since'   => '2026-01-02T03:04:05+00:00',
            ],
        ]);

        static::assertSame(503, $response?->getStatusCode());
    }

    #[Test]
    public function defaultsRetryAfterToTenMinutes(): void
    {
        $response = $this->respond(['body_template' => '%s'], $this->activeRepository());

        static::assertSame('Retry-After: 600', $response?->getHeaders()->get('Retry-After')->toString());
    }

    #[Test]
    #[DataProvider('unusableTemplatePathProvider')]
    public function fallsBackToTheInlineDefaultTemplateWithoutAPath(mixed $path): void
    {
        $response = $this->respond(['body_template_path' => $path], $this->activeRepository('msg'));

        static::assertSame(sprintf(ConfigProvider::DEFAULT_BODY_TEMPLATE, 'msg'), $response?->getContent());
    }

    #[Test]
    #[DataProvider('inlineTemplateProvider')]
    public function inlineBodyTemplateWinsOverAnyPath(mixed $template, string $expected): void
    {
        $response = $this->respond(
            ['body_template' => $template, 'body_template_path' => '/does/not/exist.phtml'],
            $this->activeRepository('down'),
        );

        static::assertSame($expected, $response?->getContent());
    }

    #[Test]
    #[DataProvider('inactiveStateProvider')]
    public function letsRequestsThroughWhenConfigStateIsNotActive(mixed $state): void
    {
        static::assertNull($this->respond(['body_template' => '%s', 'state' => $state]));
    }

    #[Test]
    #[DataProvider('messageProvider')]
    public function readsTheConfigStateMessageAsAString(mixed $message, string $expected): void
    {
        $response = $this->respond(['body_template' => '%s', 'state' => ['active' => true, 'message' => $message]]);

        static::assertSame($expected, $response?->getContent());
    }

    #[Test]
    public function rejectsABypassThatIsNotCallable(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage(
            'contenir/maintenance-laminas-mvc: config[maintenance][bypass] must be callable or null.',
        );

        $this->respond(['body_template' => '%s', 'bypass' => 'not a callable string xyz']);
    }

    #[Test]
    public function toleratesAnUnparseableSince(): void
    {
        $response = $this->respond([
            'body_template' => '%s',
            'state'         => ['active' => true, 'message' => 'm', 'since' => 'not-a-date'],
        ]);

        static::assertSame(503, $response?->getStatusCode());
    }

    #[Test]
    public function usesTheRegisteredRepositoryServiceOverConfigState(): void
    {
        $response = $this->respond(
            ['body_template' => '%s', 'state' => ['active' => false]],
            new InMemoryRepository(MaintenanceState::active('from service')),
        );

        static::assertSame('from service', $response?->getContent());
    }

    private function activeRepository(string $message = 'm'): InMemoryRepository
    {
        return new InMemoryRepository(MaintenanceState::active($message));
    }

    /**
     * @param array<string, mixed> $maintenance
     */
    private function respond(array $maintenance, ?MaintenanceRepositoryInterface $repository = null): ?Response
    {
        $services = ['config' => ['maintenance' => $maintenance]];
        if (null !== $repository) {
            $services[MaintenanceRepositoryInterface::class] = $repository;
        }

        $listener = (new MaintenanceListenerFactory())(new InMemoryContainer($services));

        return $listener(new MvcEvent());
    }
}
