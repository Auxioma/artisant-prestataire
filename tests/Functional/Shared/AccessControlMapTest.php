<?php

declare(strict_types=1);

namespace App\Tests\Functional\Shared;

use App\Identity\Application\Command\RegisterUser;
use App\Identity\Infrastructure\Security\UserProvider;
use App\Shared\Application\Bus\CommandBus;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Routing\Route;
use Symfony\Component\Routing\RouterInterface;

/**
 * SECURITY.md §6 / ASVS 4.1.1, 4.1.3 (T-905a) : carte du contrôle d'accès, générée depuis toutes les routes déclarées.
 * Chaque route des espaces privés, pour chacune de ses méthodes, refuse le visiteur anonyme et les rôles d'un autre
 * espace : jamais de 2xx (ni de 5xx). Une nouvelle route est couverte sans modifier ce test.
 */
final class AccessControlMapTest extends WebTestCase
{
    /** Espace (motif de `access_control`) → rôles qui n'y ont pas accès, en plus de l'anonyme. */
    private const array SPACES = [
        '#^/admin(/|$)#' => ['client', 'artisan'],
        '#^/espace-pro(/|$)#' => ['client'],
        '#^/espace-client(/|$)#' => ['artisan'],
        '#^/inscription/pro/#' => ['client'],
    ];

    /** Pages publiques à l'intérieur de ces préfixes (connexion et invitation du back-office). */
    private const array PUBLIC = ['admin_login', 'admin_invitation'];

    /** Valeurs d'essai pour les paramètres de route, la première conforme à l'exigence est retenue. */
    private const array SAMPLES = ['0190f5e4-0000-7000-8000-00000000dead', '1', 'abc', 'a-b', '2026-11', '2026-11-20', 'x.csv'];

    public function testAnonymousVisitorsReachNoPrivateRoute(): void
    {
        $client = self::createClient();
        $checked = $this->assertDenied($client, null);
        self::assertGreaterThan(150, $checked, 'carte complète des routes privées');
    }

    public function testClientsStayOutOfProAndAdminSpaces(): void
    {
        $client = $this->signedIn('client');
        $this->assertDenied($client, 'client');
    }

    public function testArtisansStayOutOfClientAndAdminSpaces(): void
    {
        $client = $this->signedIn('artisan');
        $this->assertDenied($client, 'artisan');
    }

    private function signedIn(string $role): KernelBrowser
    {
        $client = self::createClient();
        $bus = self::getContainer()->get(CommandBus::class);
        $users = self::getContainer()->get(UserProvider::class);
        \assert($bus instanceof CommandBus && $users instanceof UserProvider);
        $email = $role.'.acces@exemple.test';
        $bus->dispatch(new RegisterUser('client' === $role ? '0190f5e4-0000-7000-8000-00000000a001' : '0190f5e4-0000-7000-8000-00000000a002', $email, 'cheval agrafe batterie', $role, 'Camille', 'Exemple'));
        $client->loginUser($users->loadUserByIdentifier($email));
        $client->request('GET', 'client' === $role ? '/espace-client' : '/espace-pro');
        self::assertStringNotContainsString('/connexion', (string) $client->getResponse()->headers->get('Location'), 'session conservée entre les requêtes');
        self::assertLessThan(400, $client->getResponse()->getStatusCode(), $role.' accède à son propre espace');

        return $client;
    }

    /** @return int nombre de couples route / méthode vérifiés */
    private function assertDenied(KernelBrowser $client, ?string $role): int
    {
        $router = self::getContainer()->get(RouterInterface::class);
        \assert($router instanceof RouterInterface);
        $checked = 0;
        foreach ($router->getRouteCollection()->all() as $name => $route) {
            $space = $this->spaceOf($route->getPath());
            if (null === $space || \in_array($name, self::PUBLIC, true) || (null !== $role && !\in_array($role, self::SPACES[$space], true))) {
                continue;
            }
            $path = $this->samplePath($route);
            foreach ($route->getMethods() ?: ['GET'] as $method) {
                $client->request($method, $path);
                $status = $client->getResponse()->getStatusCode();
                self::assertTrue($status >= 300 && $status < 500, \sprintf('%s %s (%s) → %d pour %s', $method, $path, $name, $status, $role ?? 'anonyme'));
                ++$checked;
            }
            gc_collect_cycles();
        }

        return $checked;
    }

    private function spaceOf(string $path): ?string
    {
        foreach (array_keys(self::SPACES) as $pattern) {
            if (1 === preg_match($pattern, $path)) {
                return $pattern;
            }
        }

        return null;
    }

    private function samplePath(Route $route): string
    {
        return (string) preg_replace_callback('/\{(\w+)\}/', static function (array $m) use ($route): string {
            $requirement = $route->getRequirement($m[1]);
            foreach (self::SAMPLES as $sample) {
                if (null === $requirement || 1 === preg_match('#^(?:'.$requirement.')$#', $sample)) {
                    return $sample;
                }
            }

            return 'x';
        }, $route->getPath());
    }
}
