<?php

declare(strict_types=1);

namespace App\Tests\Functional\QuoteRequest;

use App\Catalog\Application\Questionnaire\ImportQuestionnaires;
use App\QuoteRequest\UI\Http\RequestTunnelController;
use App\Shared\Application\Storage\ScanQuarantinedFile;
use App\Shared\Domain\Storage\FileQuarantined;
use App\Shared\Infrastructure\Persistence\DbalValue;
use App\Tests\Support\ArtisanDossier;
use App\Tests\Support\StorageSamples;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/** P11 étapes 1 à 3 (T-402a) : brouillon de visiteur, validation par étape, questionnaire conditionnel, sauvegarde automatique, photos privées. */
final class RequestTunnelTest extends WebTestCase
{
    use ArtisanDossier;

    private KernelBrowser $client;
    private Connection $connection;
    private string $jobId;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->client->disableReboot();
        $connection = self::getContainer()->get(Connection::class);
        \assert($connection instanceof Connection);
        $this->connection = $connection;
        $this->prepareReferentials();
        $this->dossierDispatch(new ImportQuestionnaires(
            [['key' => 'logement', 'label' => 'Type de lieu', 'type' => 'choice', 'required' => true, 'options' => ['maison' => 'Maison', 'appartement' => 'Appartement']],
                ['key' => 'etage', 'label' => 'Étage', 'type' => 'number', 'required' => true, 'min' => 0, 'max' => 60, 'show_if' => ['field' => 'logement', 'in' => ['appartement']]]],
            ['plomberie' => [['key' => 'probleme', 'label' => 'Nature de l’intervention', 'type' => 'choice', 'required' => true, 'options' => ['fuite' => 'Fuite', 'bouchon' => 'Bouchon']]]],
            [],
        ));
        $this->jobId = $this->dossierValue("SELECT id FROM catalog.job WHERE slug = 'plombier'");
    }

    protected function tearDown(): void
    {
        StorageSamples::cleanup();
        parent::tearDown();
    }

    public function testVisitorCompletesTheFirstThreeSteps(): void
    {
        $this->client->request('GET', '/demande-de-devis');
        self::assertResponseRedirects('/demande-de-devis/1', 303);
        self::assertNotNull($this->client->getCookieJar()->get(RequestTunnelController::COOKIE, '/demande-de-devis'), 'jeton de brouillon du visiteur');
        $crawler = $this->client->followRedirect();
        self::assertSelectorTextContains('h1', 'Quel type de travaux');
        self::assertSelectorExists('meta[name="robots"][content="noindex, follow"]');

        $crawler = $this->client->request('POST', '/demande-de-devis/1', ['_token' => $this->token($crawler), 'action' => 'continue']);
        self::assertResponseStatusCodeSame(422);
        self::assertSame(['Indiquez le type de professionnel recherché.', 'Choisissez le type de projet.'], $crawler->filter('.tm-field__error')->each(static fn (Crawler $n): string => trim($n->text())));

        $this->client->request('POST', '/demande-de-devis/1', ['_token' => $this->token($crawler), 'action' => 'continue', 'job_id' => $this->jobId, 'project_type' => 'repair', 'work_nature' => 'Réparation d’une fuite']);
        self::assertResponseRedirects('/demande-de-devis/2', 303);
        $crawler = $this->client->followRedirect();
        self::assertStringContainsString('Questions spécifiques · Plomberie', $crawler->filter('.tm-request__main')->text());
        self::assertCount(1, $crawler->filter('[data-key="etage"][hidden]'), 'étage masqué tant que « Appartement » n’est pas choisi');

        $form = ['_token' => $this->token($crawler), 'action' => 'continue', 'answers' => ['logement' => 'appartement', 'probleme' => 'fuite'], 'description' => 'Trop court'];
        $crawler = $this->client->request('POST', '/demande-de-devis/2', $form);
        self::assertResponseStatusCodeSame(422);
        self::assertStringContainsString('Réponse obligatoire', $crawler->filter('[data-key="etage"]')->text(), 'question conditionnelle devenue obligatoire');
        self::assertStringContainsString('30 caractères minimum', $crawler->filter('#description_error')->text());
        self::assertSame('Trop court', $crawler->filter('#description')->text(), 'saisie réaffichée');

        $form['answers'] = ['logement' => 'maison', 'etage' => '3', 'probleme' => 'fuite'];
        $form['description'] = 'Fuite sous l’évier de la cuisine depuis ce matin (demande fictive).';
        $form['budget_range'] = '500_1000';
        $form['title'] = 'Fuite sous l’évier (fictif)';
        $form['summary'] = 'Remplacer le siphon et le joint.';
        $this->client->request('POST', '/demande-de-devis/2', $form);
        self::assertResponseRedirects('/demande-de-devis/3', 303);
        $draft = $this->draft();
        self::assertSame(3, $draft['step']);
        self::assertSame(['logement' => 'maison', 'probleme' => 'fuite'], $draft['payload']['answers'], 'étage d’une maison non conservé');
        self::assertSame(1, $draft['payload']['questionnaire_version']);
        self::assertSame(['Réparation d’une fuite', 'Fuite sous l’évier (fictif)', 'Remplacer le siphon et le joint.'], [$draft['payload']['work_nature'], $draft['payload']['title'], $draft['payload']['summary']], 'champs Figma 5:2 et 5:57');

        $crawler = $this->client->request('GET', '/demande-de-devis/3');
        $this->client->request('POST', '/demande-de-devis/3', ['_token' => $this->token($crawler), 'action' => 'continue']);
        self::assertResponseRedirects('/demande-de-devis/4', 303, 'photos facultatives');
        $crawler = $this->client->request('GET', '/demande-de-devis/2');
        self::assertSame('maison', $crawler->filter('input[name="answers[logement]"][checked]')->attr('value'), 'retour en arrière sans perte');
    }

    public function testStepsCannotBeSkippedAndAutosaveKeepsValidValues(): void
    {
        $this->client->request('GET', '/demande-de-devis/3');
        self::assertResponseRedirects('/demande-de-devis/1', 303);
        $crawler = $this->client->followRedirect();
        $this->client->request('POST', '/demande-de-devis/brouillon', ['_token' => $this->token($crawler), 'step' => '1', 'project_type' => 'renovation']);
        self::assertResponseIsSuccessful();
        self::assertSame('renovation', $this->draft()['payload']['project_type'], 'première saisie d’un nouveau visiteur enregistrée');

        $this->client->request('GET', '/demande-de-devis/plombier');
        $crawler = $this->client->followRedirect();
        self::assertSame($this->jobId, $crawler->filter('select[name="job_id"] option[selected]')->attr('value'), 'métier pré-rempli depuis l’URL');
        self::assertSame('Plomberie', $crawler->filter('input[name="category"][checked]')->attr('value'), 'catégorie correspondante cochée (Figma 5:2)');

        $this->client->request('POST', '/demande-de-devis/brouillon', ['_token' => $this->token($crawler), 'step' => '1', 'project_type' => 'inconnu']);
        self::assertResponseIsSuccessful();
        $this->client->request('POST', '/demande-de-devis/brouillon', ['_token' => $this->token($crawler), 'step' => '2']);
        self::assertResponseStatusCodeSame(409, 'étape pas encore atteinte');
        $this->client->request('POST', '/demande-de-devis/brouillon', ['step' => '1']);
        self::assertResponseStatusCodeSame(403, 'jeton CSRF obligatoire');
        $draft = $this->draft();
        self::assertSame(1, $draft['step']);
        self::assertSame($this->jobId, $draft['payload']['job_id']);
        self::assertSame('renovation', $draft['payload']['project_type'], 'valeur invalide ignorée : la précédente est conservée');
    }

    public function testPhotosArePrivateToTheDraftOwnerAndShreddedOnRemoval(): void
    {
        $this->client->request('GET', '/demande-de-devis/plombier');
        $crawler = $this->client->followRedirect();
        $this->client->request('POST', '/demande-de-devis/1', ['_token' => $this->token($crawler), 'action' => 'continue', 'job_id' => $this->jobId, 'project_type' => 'repair']);
        $this->client->request('POST', '/demande-de-devis/2', ['_token' => $this->token($crawler), 'action' => 'continue', 'answers' => ['logement' => 'maison', 'probleme' => 'bouchon'], 'description' => 'Évier bouché dans la cuisine, l’eau ne s’écoule plus (fictif).']);
        $crawler = $this->client->followRedirect();

        $this->client->request('POST', '/demande-de-devis/fichiers', ['_token' => $this->token($crawler)], ['files' => [
            new UploadedFile(StorageSamples::file(StorageSamples::png(400, 300)), 'evier.png', 'image/png', null, true),
            new UploadedFile(StorageSamples::file('pas une image'), 'faux.png', 'image/png', null, true),
        ]]);
        $crawler = $this->client->followRedirect();
        self::assertStringContainsString('« faux.png » : Ce fichier n’est pas accepté', $crawler->filter('.tm-toasts')->text());
        self::assertSelectorTextContains('.tm-request-file', 'Analyse en cours');

        $fileId = DbalValue::string($this->connection->fetchOne('SELECT file_id FROM request.draft_attachment'));
        $scan = self::getContainer()->get(ScanQuarantinedFile::class);
        \assert($scan instanceof ScanQuarantinedFile);
        $scan(new FileQuarantined($fileId, 'request_photo', new \DateTimeImmutable()));
        $crawler = $this->client->request('GET', '/demande-de-devis/3');
        $this->client->request('GET', (string) $crawler->filter('.tm-request-file img')->attr('src'));
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('no-store', (string) $this->client->getResponse()->headers->get('Cache-Control'), 'aperçu jamais mis en cache');

        $owner = $this->client->getCookieJar()->get(RequestTunnelController::COOKIE, '/demande-de-devis');
        $this->client->getCookieJar()->clear();
        $this->client->request('GET', '/demande-de-devis/fichiers/'.$fileId);
        self::assertResponseStatusCodeSame(404, 'autre visiteur');
        \assert(null !== $owner);
        $this->client->getCookieJar()->set($owner);

        $crawler = $this->client->request('GET', '/demande-de-devis/3');
        $this->client->request('POST', '/demande-de-devis/fichiers/'.$fileId.'/supprimer', ['_token' => $this->token($crawler)]);
        self::assertResponseRedirects('/demande-de-devis/3', 303);
        self::assertStringContainsString('Fichier retiré', $this->client->followRedirect()->filter('.tm-toasts')->text());
        self::assertSame('shredded', DbalValue::string($this->connection->fetchOne('SELECT status FROM shared.stored_file WHERE id = :id', ['id' => $fileId])));
    }

    /** @return array{step: int, payload: array<string, mixed>} */
    private function draft(): array
    {
        $row = $this->connection->fetchAssociative('SELECT step, payload FROM request.request_draft ORDER BY updated_at DESC LIMIT 1');
        \assert(false !== $row);
        $payload = DbalValue::json($row['payload']);
        \assert(\is_array($payload));

        /** @var array<string, mixed> $payload */
        return ['step' => DbalValue::int($row['step']), 'payload' => $payload];
    }

    private function token(Crawler $crawler): string
    {
        return (string) $crawler->filter('input[name="_token"]')->first()->attr('value');
    }
}
