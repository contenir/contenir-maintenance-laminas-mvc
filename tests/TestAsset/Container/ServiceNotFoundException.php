<?php

declare(strict_types=1);

namespace Contenir\Maintenance\Laminas\Mvc\Tests\TestAsset\Container;

use Psr\Container\NotFoundExceptionInterface;
use RuntimeException;

final class ServiceNotFoundException extends RuntimeException implements NotFoundExceptionInterface {}
