<?php

declare(strict_types=1);

namespace Contenir\Maintenance\Laminas\Mvc\Tests\Integration\Factory;

use Contenir\Maintenance\Laminas\Mvc\Factory\MaintenanceListenerFactory;
use Contenir\Maintenance\Laminas\Mvc\Tests\TestAsset\Container\InMemoryContainer;
use Contenir\Maintenance\Laminas\Mvc\Tests\TestAsset\Stream\UnopenableFileStreamWrapper;
use Contenir\Maintenance\Laminas\Mvc\Tests\Trait\TemporaryDirectoryTrait;
use Contenir\Maintenance\MaintenanceRepositoryInterface;
use Contenir\Maintenance\MaintenanceState;
use Contenir\Maintenance\Repository\InMemoryRepository;
use DomainException;
use Laminas\Http\Response;
use Laminas\Mvc\MvcEvent;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;

use function chmod;
use function file_put_contents;
use function mkdir;
use function ob_get_level;
use function sprintf;

/**
 * Template loading reads real files from a temp directory and the package's
 * bundled view; the rest of the factory is covered by the unit suite.
 */
#[Group('integration')]
#[Group('factory')]
final class MaintenanceListenerFactoryTest extends TestCase
{
    use TemporaryDirectoryTrait;

    /**
     * @return array<string, array{array<string, mixed>}>
     */
    public static function missingOrMalformedConfigProvider(): array
    {
        return [
            'no config service'           => [[]],
            'config not an array'         => [['config' => 'nope']],
            'maintenance key missing'     => [['config' => []]],
            'maintenance not an array'    => [['config' => ['maintenance' => 'on']]],
            'maintenance explicitly null' => [['config' => ['maintenance' => null]]],
        ];
    }

    /**
     * @return array<string, array{string}>
     */
    public static function phpTemplateNameProvider(): array
    {
        return [
            'phtml'            => ['template.phtml'],
            'php'              => ['template.php'],
            'upper-case PHTML' => ['template.PHTML'],
        ];
    }

    #[Test]
    public function closesTheOutputBufferWhenAPhpTemplateThrows(): void
    {
        file_put_contents(
            $this->path('template.phtml'),
            data: '<p>partial</p><?php throw new DomainException("template failed");',
        );
        $level = ob_get_level();

        try {
            $this->respond(['body_template_path' => $this->path('template.phtml')]);
            static::fail('Expected the template exception to propagate.');
        } catch (DomainException $e) {
            static::assertSame('template failed', $e->getMessage());
            static::assertSame($level, ob_get_level());
        }
    }

    #[Test]
    #[DataProvider('phpTemplateNameProvider')]
    public function evaluatesAPhpTemplateOnceAtBuildTime(string $name): void
    {
        file_put_contents($this->path($name), data: '<p><?= strtoupper("hello") ?>: %s</p>');

        $response = $this->respond(['body_template_path' => $this->path($name)]);

        static::assertSame('<p>HELLO: down</p>', $response?->getContent());
    }

    #[Test]
    public function phpTemplateSeesNoFactoryVariables(): void
    {
        file_put_contents(
            $this->path('template.phtml'),
            data: '<?= isset($container) || isset($userConfig) || isset($maintenance) ? "leaked" : "isolated" ?> %s',
        );

        $response = $this->respond(['body_template_path' => $this->path('template.phtml')]);

        static::assertSame('isolated down', $response?->getContent());
    }

    #[Test]
    public function readsANonPhpTemplateVerbatim(): void
    {
        file_put_contents($this->path('template.html'), data: '<p>HTML wrapper <?= "raw" ?>: %s</p>');

        $response = $this->respond(['body_template_path' => $this->path('template.html')]);

        static::assertSame('<p>HTML wrapper <?= "raw" ?>: down</p>', $response?->getContent());
    }

    #[Test]
    public function rejectsADirectoryAsTheTemplatePath(): void
    {
        mkdir($this->path('views.html'));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage(
            sprintf(
                'contenir/maintenance-laminas-mvc: body_template_path "%s" is not readable.',
                $this->path('views.html'),
            ),
        );

        $this->respond(['body_template_path' => $this->path('views.html')]);
    }

    #[Test]
    public function rejectsAMissingTemplatePath(): void
    {
        $path = $this->path('missing.phtml');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage(
            sprintf('contenir/maintenance-laminas-mvc: body_template_path "%s" is not readable.', $path),
        );

        $this->respond(['body_template_path' => $path]);
    }

    #[Test]
    public function rejectsAnUnreadableTemplateFile(): void
    {
        $this->skipWhenRunningAsRoot();
        file_put_contents($this->path('locked.html'), data: '%s');
        chmod($this->path('locked.html'), permissions: 0o000);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('is not readable.');

        $this->respond(['body_template_path' => $this->path('locked.html')]);
    }

    #[Test]
    public function rendersTheBundledTemplateByDefault(): void
    {
        $response = $this->respond([]);

        $body = (string) $response?->getContent();
        static::assertStringStartsWith('<!doctype html>', $body);
        static::assertStringContainsString('<div class="maintenance__body">down</div>', $body);
    }

    #[Test]
    public function reportsATemplateThatCannotBeOpenedAfterPassingTheReadabilityChecks(): void
    {
        $path = UnopenableFileStreamWrapper::PROTOCOL . '://template.html';
        UnopenableFileStreamWrapper::register();

        try {
            $this->expectException(RuntimeException::class);
            $this->expectExceptionMessage(
                sprintf('contenir/maintenance-laminas-mvc: failed reading body_template_path "%s".', $path),
            );

            $this->respond(['body_template_path' => $path]);
        } finally {
            UnopenableFileStreamWrapper::unregister();
        }
    }

    /**
     * @param array<string, mixed> $services
     */
    #[Test]
    #[DataProvider('missingOrMalformedConfigProvider')]
    public function treatsMissingOrMalformedConfigAsEmpty(array $services): void
    {
        $listener = (new MaintenanceListenerFactory())(new InMemoryContainer($services));

        static::assertNull($listener(new MvcEvent()));
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpTemporaryDirectory();
    }

    protected function tearDown(): void
    {
        $this->tearDownTemporaryDirectory();
        parent::tearDown();
    }

    /**
     * @param array<string, mixed> $maintenance
     */
    private function respond(array $maintenance): ?Response
    {
        $listener = (new MaintenanceListenerFactory())(new InMemoryContainer([
            'config'                              => ['maintenance' => $maintenance],
            MaintenanceRepositoryInterface::class => new InMemoryRepository(MaintenanceState::active('down')),
        ]));

        return $listener(new MvcEvent());
    }
}
