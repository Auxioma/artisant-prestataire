<?php

declare(strict_types=1);

namespace App\Tests\Integration\Demo;

use App\Shared\Infrastructure\Persistence\DbalValue;
use App\Tests\Support\ArtisanDossier;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;

/** T-901 — jeu [DEMO] : artisans fictifs vérifiés et trouvables, clients et demandes marqués, aucun avis, idempotent. */
final class SeedDemoCommandTest extends KernelTestCase
{
    use ArtisanDossier;

    private Connection $connection;

    public function testDemoDataIsLoadedOnceAndClearlyMarked(): void
    {
        $kernel = self::bootKernel();
        $connection = self::getContainer()->get(Connection::class);
        \assert($connection instanceof Connection);
        $this->connection = $connection;
        $this->prepareReferentials();
        foreach (['electricien' => 'Électricien', 'peintre' => 'Peintre', 'chauffagiste' => 'Chauffagiste', 'carreleur' => 'Carreleur', 'macon' => 'Maçon', 'paysagiste' => 'Paysagiste'] as $slug => $name) {
            if (false === $connection->fetchOne('SELECT 1 FROM catalog.job WHERE slug = :s', ['s' => $slug])) {
                $connection->executeStatement("INSERT INTO catalog.job (id, category_id, name, slug, position, synonyms, required_documents, insurance_required, decennial_required, active, created_at, updated_at) SELECT gen_random_uuid(), category_id, :n, :s, 99, '[]', '[]', false, false, true, now(), now() FROM catalog.job WHERE slug = 'plombier'", ['n' => $name, 's' => $slug]);
            }
        }

        // Référentiel de test réduit : les six villes pilotes, actives (Évreux, Cherbourg et Alençon copiées du Havre si absentes, fictif).
        foreach (['27229' => ['Évreux', '27000'], '50129' => ['Cherbourg-en-Cotentin', '50100'], '61001' => ['Alençon', '61000']] as $insee => [$name, $cp]) {
            $connection->executeStatement(
                "INSERT INTO catalog.commune (insee, name, slug, postal_codes, department_code, population, centroid, active, seo_page_enabled, created_at, updated_at)
                 SELECT :i, :n, :slug, ARRAY[:cp], department_code, population, centroid, true, false, now(), now() FROM catalog.commune WHERE insee = '76351' ON CONFLICT (insee) DO NOTHING",
                ['i' => (string) $insee, 'n' => $name, 'slug' => $insee.'-demo', 'cp' => $cp],
            );
        }
        $connection->executeStatement("UPDATE catalog.commune SET active = true WHERE insee IN ('76351', '76540', '14118', '27229', '50129', '61001')");

        $tester = new CommandTester(new Application($kernel)->find('app:demo:seed'));
        self::assertSame(0, $tester->execute([]));
        self::assertStringContainsString('12 artisans, 3 clients et 4 demandes', $tester->getDisplay());
        self::assertMatchesRegularExpression('/Mot de passe commun .* : [a-z0-9]{16}/', $tester->getDisplay());

        self::assertSame(12, $this->rows("SELECT count(*) FROM artisan.artisan_profile WHERE status = 'verified' AND company_name LIKE '[DEMO] %' AND siret LIKE '999999%'"));
        self::assertSame(12, $this->rows("SELECT count(*) FROM search.artisan_search_view WHERE name LIKE '[DEMO] %'"), 'trouvables dans la recherche');
        self::assertSame(15, $this->rows("SELECT count(*) FROM identity.user_account WHERE email_canonical LIKE 'demo.%@exemple.test' AND last_name LIKE '[DEMO] %'"));
        self::assertSame(4, $this->rows("SELECT count(*) FROM request.quote_request WHERE title LIKE '[DEMO] %' AND description LIKE '[DEMO] %'"));
        self::assertSame(0, $this->rows('SELECT count(*) FROM review.review'), 'aucun avis fictif');

        $tester->execute([]);
        self::assertStringContainsString('déjà chargé', $tester->getDisplay());
        self::assertSame(12, $this->rows("SELECT count(*) FROM artisan.artisan_profile WHERE company_name LIKE '[DEMO] %'"));
    }

    private function rows(string $sql): int
    {
        return DbalValue::int($this->connection->fetchOne($sql));
    }
}
