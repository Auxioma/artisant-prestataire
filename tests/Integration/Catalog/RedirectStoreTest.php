<?php

declare(strict_types=1);

namespace App\Tests\Integration\Catalog;

use App\Content\Application\Port\RedirectStore;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/** BO12.2 : redirections 301, aplatissement des chaînes, suppression des boucles, préfixes métier × ville. */
final class RedirectStoreTest extends KernelTestCase
{
    private RedirectStore $store;

    protected function setUp(): void
    {
        self::bootKernel();
        $store = self::getContainer()->get(RedirectStore::class);
        \assert($store instanceof RedirectStore);
        $this->store = $store;
    }

    public function testChainsAreFlattened(): void
    {
        $this->store->redirect('/metiers/a', '/metiers/b', false, 'test');
        $this->store->redirect('/metiers/b', '/metiers/c', false, 'test');

        self::assertSame(['target' => '/metiers/c', 'status' => 301], $this->store->resolve('/metiers/a'), 'A → C directement (pas de double saut)');
        self::assertSame('/metiers/c', $this->store->resolve('/metiers/b')['target'] ?? null);
    }

    public function testRenamingBackRemovesTheLoop(): void
    {
        $this->store->redirect('/categories/a', '/categories/b', false, 'test');
        $this->store->redirect('/categories/b', '/categories/a', false, 'test');

        self::assertNull($this->store->resolve('/categories/a'), 'La page a retrouvé son adresse : plus de redirection');
        self::assertSame('/categories/a', $this->store->resolve('/categories/b')['target'] ?? null);
    }

    public function testPrefixRedirectKeepsTheRestOfThePath(): void
    {
        $this->store->redirect('/plombier/', '/plombier-chauffagiste/', true, 'test');

        self::assertSame('/plombier-chauffagiste/le-havre-76600', $this->store->resolve('/plombier/le-havre-76600')['target'] ?? null);
        self::assertNull($this->store->resolve('/plombiers/le-havre-76600'), 'Pas de correspondance partielle de segment');
    }

    public function testExactMatchWinsOverPrefix(): void
    {
        $this->store->redirect('/x/', '/y/', true, 'test');
        $this->store->redirect('/x/special', '/z', false, 'test');

        self::assertSame('/z', $this->store->resolve('/x/special')['target'] ?? null);
    }

    public function testLikeWildcardsInStoredPathAreLiteral(): void
    {
        $this->store->redirect('/a_b/', '/ok/', true, 'test');

        self::assertNull($this->store->resolve('/axb/test'), '« _ » n’est pas un joker');
        self::assertSame('/ok/test', $this->store->resolve('/a_b/test')['target'] ?? null);
    }
}
