<?php

declare(strict_types=1);

namespace Contenir\Maintenance\Laminas\Mvc\Tests\Integration;

use Contenir\Maintenance\Laminas\Mvc\ConfigProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

use function ob_get_clean;
use function ob_start;
use function preg_match_all;

#[Group('integration')]
final class BundledTemplateTest extends TestCase
{
    #[Test]
    public function renderedTemplateIsASprintfFormatWithOnlyTheMessagePlaceholder(): void
    {
        $path = ConfigProvider::defaultBodyTemplatePath();
        ob_start();
        (static function () use ($path): void {
            include $path;
        })();
        $rendered = (string) ob_get_clean();

        static::assertSame(1, preg_match_all('/%./', $rendered, $matches));
        static::assertSame(['%s'], $matches[0]);
    }
}
