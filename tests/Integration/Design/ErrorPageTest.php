<?php

declare(strict_types=1);

namespace App\Tests\Integration\Design;

use App\Catalog\Application\Command\CreateCategory;
use App\Catalog\Application\Command\CreateJob;
use App\Shared\Application\Bus\CommandBus;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use Twig\Environment;

/** P18 (Figma 162:2385) — page 404 : picto, « Erreur 404 », titre, recherche d'un service, catégories en pastilles, accueil. */
final class ErrorPageTest extends KernelTestCase
{
    public function testNotFoundPageFollowsTheMockup(): void
    {
        self::bootKernel();
        $bus = self::getContainer()->get(CommandBus::class);
        \assert($bus instanceof CommandBus);
        $bus->dispatch(new CreateCategory('Plomberie', 'wrench'));
        $bus->dispatch(new CreateJob('plomberie', 'Plombier'));
        $request = Request::create('/une-adresse-disparue');
        $request->setSession(new Session(new MockArraySessionStorage()));
        $stack = self::getContainer()->get(RequestStack::class);
        $twig = self::getContainer()->get(Environment::class);
        \assert($stack instanceof RequestStack && $twig instanceof Environment);
        $stack->push($request);

        $page = new Crawler($twig->render('bundles/TwigBundle/Exception/error404.html.twig', ['status_code' => 404, 'status_text' => 'Not Found']));

        self::assertSame('Erreur 404', $page->filter('.tm-error-page__code')->text());
        self::assertSame('Cette page est introuvable', $page->filter('.tm-error-page h1')->text());
        self::assertStringContainsString('n’existe plus. Lancez une recherche ou explorez nos catégories.', $page->filter('.tm-error-page__text')->text());
        self::assertSame('/nos-professionnels', $page->filter('form.tm-error-page__search')->attr('action'), 'recherche d’un service sans lieu : annuaire filtré');
        self::assertSame('Quel service recherchez-vous ?', $page->filter('#error_job')->attr('placeholder'));
        self::assertCount(1, $page->filter('label[for="error_job"]'), 'champ étiqueté (RGAA 11.1)');
        self::assertSame('Catégories populaires :', $page->filter('#error-popular')->text());
        self::assertSame('Plomberie', $page->filter('.tm-error-page__chip')->first()->text());
        self::assertLessThanOrEqual(4, $page->filter('.tm-error-page__chip')->count());
        self::assertSame('Retour à l’accueil', trim($page->filter('.tm-error-page a.tm-button')->last()->text()));
        self::assertSame('noindex', $page->filter('meta[name="robots"]')->attr('content'));
    }
}
