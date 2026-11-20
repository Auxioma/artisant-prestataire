<?php

declare(strict_types=1);

namespace App\Tests\Integration\Shared;

use App\Kernel;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Routing\RouterInterface;

/** SECURITY.md §7 (T-905a) : en production, ni profileur, ni pages `/_error`, ni outils de développement. */
final class ProductionConfigurationTest extends TestCase
{
    public function testProductionKernelExposesNoDebugSurface(): void
    {
        $kernel = new Kernel('prod', false);
        $kernel->boot();
        try {
            $container = $kernel->getContainer();
            self::assertFalse($container->getParameter('kernel.debug'));
            self::assertFalse($container->has('profiler'));
            $router = $container->get('router');
            \assert($router instanceof RouterInterface);
            foreach ($router->getRouteCollection()->all() as $name => $route) {
                self::assertDoesNotMatchRegularExpression('#^/_(error|profiler|wdt|dev)#', $route->getPath(), $name);
                $controller = $route->getDefault('_controller');
                self::assertStringNotContainsString('DesignSystem', \is_string($controller) ? $controller : '', $name.' : démo interne réservée au développement');
            }
            self::assertArrayNotHasKey('MakerBundle', $kernel->getBundles());
        } finally {
            $cacheDir = $kernel->getCacheDir();
            $kernel->shutdown();
            new Filesystem()->remove($cacheDir);
        }
    }
}
