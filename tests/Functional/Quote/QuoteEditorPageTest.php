<?php

declare(strict_types=1);

namespace App\Tests\Functional\Quote;

use App\ArtisanProfile\Application\Verification\ApproveArtisan;
use App\Billing\Application\Credit\CreditLedger;
use App\Identity\Application\Command\RegisterUser;
use App\QuoteRequest\Application\Dispatch\DispatchQuoteRequest;
use App\QuoteRequest\Application\Submission\SubmitQuoteRequest;
use App\Tests\Support\ArtisanDossier;
use App\Tests\Support\StorageSamples;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * PR04 (Figma 22:229) : éditeur, enregistrement automatique (totaux et RG-120), saisie refusée, droits, lien depuis PR03 ;
 * envoi confirmé dans la modale (Figma 74:121), refus sans crédit, devis envoyé en lecture seule.
 */
final class QuoteEditorPageTest extends WebTestCase
{
    use ArtisanDossier;

    private const string CLIENT = '0190f5e4-0000-7000-8000-0000000f2001';
    private const string ADMIN = '0190f5e4-0000-7000-8000-0000000a2001';
    private const string REQUEST = '0190f5e4-0000-7000-8000-0000000f2101';
    private const string OUTSIDE = '0190f5e4-0000-7000-8000-0000000f2102';

    private KernelBrowser $client;
    private string $artisan;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->client->disableReboot();
        $connection = self::getContainer()->get(Connection::class);
        \assert($connection instanceof Connection);
        $this->prepareReferentials();
        $artisan = $this->artisan = $this->submittedDossier(200);
        $this->uploadPiece('kbis');
        $this->uploadPiece('insurance_rc');
        $this->dossierDispatch(new ApproveArtisan($artisan, self::ADMIN));
        $connection->executeStatement("UPDATE identity.user_account SET status = 'active', email_verified_at = date_trunc('second', now()) WHERE id = :id", ['id' => self::DOSSIER_USER]);
        self::getContainer()->get(EntityManagerInterface::class)->clear();
        $this->dossierDispatch(new RegisterUser(self::CLIENT, 'camille.pr04@exemple.test', 'cheval agrafe batterie', 'client', 'Camille', 'Devis'));
        $job = $this->dossierValue("SELECT id FROM catalog.job WHERE slug = 'plombier'");
        $this->dossierDispatch(new SubmitQuoteRequest(self::REQUEST, self::CLIENT, $job, '76351', '76600', '1 rue Fictive', 'repair', 'Fuite sous l’évier de la cuisine depuis ce matin (fictif).', [], null, 'week', null, title: 'Fuite sous l’évier'));
        $this->dossierDispatch(new SubmitQuoteRequest(self::OUTSIDE, self::CLIENT, $job, '14118', '14000', '2 rue Fictive', 'repair', 'Robinet de la cuisine qui goutte en permanence (fictif).', [], null, 'week', null));
        $this->dossierDispatch(new DispatchQuoteRequest(self::REQUEST));

        $this->client->setServerParameter('HTTP_ORIGIN', 'http://localhost');
        $crawler = $this->client->request('GET', '/connexion');
        $this->client->request('POST', '/connexion', ['_username' => 'dominique.verif@exemple.test', '_password' => 'cheval agrafe batterie', '_csrf_token' => (string) $crawler->filter('input[name="_csrf_token"]')->attr('value')]);
    }

    protected function tearDown(): void
    {
        StorageSamples::cleanup();
        parent::tearDown();
    }

    public function testEditorAutosavesAndRecomputes(): void
    {
        $crawler = $this->client->request('GET', '/espace-pro/demandes/'.self::REQUEST);
        self::assertSame('/espace-pro/devis/nouveau?demande='.self::REQUEST, $crawler->filter('.tm-pro-detail__decide a.tm-button')->first()->attr('href'), 'PR03 : « Créer un devis » actif');

        $crawler = $this->client->request('GET', '/espace-pro/devis/nouveau?demande='.self::REQUEST);
        self::assertResponseIsSuccessful();
        self::assertSame('Créer un devis', $crawler->filter('h1')->text());
        self::assertSame('Fuite sous l’évier', $crawler->filter('#subject')->attr('value'), 'objet pré-rempli');
        self::assertSame('30', $crawler->filter('#validity_days')->attr('value'));
        self::assertStringContainsString('Attribué à l’envoi', $crawler->filter('.tm-qedit__box')->first()->text());
        $token = (string) $crawler->filter('input[name="_token"]')->attr('value');

        $this->client->request('POST', '/espace-pro/devis/brouillon?demande='.self::REQUEST, [
            '_token' => $token, 'subject' => 'Réparation de la fuite', 'validity_days' => '30',
            'lines' => [
                ['type' => 'section', 'label' => 'Intervention'],
                ['type' => 'labour', 'label' => 'Recherche de fuite', 'quantity' => '1', 'unit' => 'forfait', 'unit_price' => '90,00', 'vat' => '1000'],
                ['type' => 'supply', 'label' => 'Siphon', 'quantity' => '2', 'unit' => 'u', 'unit_price' => '12,50', 'discount' => '10', 'vat' => '1000'],
            ],
        ]);
        self::assertResponseIsSuccessful();
        $data = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertIsArray($data);
        $totals = $data['totals'] ?? null;
        self::assertIsArray($totals);
        self::assertSame("112,50\u{a0}€", $totals['ht'] ?? null);
        self::assertSame("123,75\u{a0}€", $totals['ttc'] ?? null);
        self::assertSame(['', "90,00\u{a0}€", "22,50\u{a0}€"], $data['lines']);
        self::assertFalse($data['complete'], 'frais de déplacement absents');
        self::assertTrue($data['certificate'], 'TVA à 10 % : attestation');

        $this->client->request('POST', '/espace-pro/devis/brouillon?demande='.self::REQUEST, ['_token' => $token, 'subject' => 'X', 'lines' => [['type' => 'labour', 'label' => 'Pose', 'quantity' => '1', 'unit_price' => '12,345']]]);
        self::assertResponseStatusCodeSame(422);
        self::assertStringContainsString('Prix unitaire invalide', (string) $this->client->getResponse()->getContent());

        $crawler = $this->client->request('GET', '/espace-pro/devis/nouveau?demande='.self::REQUEST);
        self::assertSame('Réparation de la fuite', $crawler->filter('#subject')->attr('value'), 'brouillon rechargé');
        self::assertCount(3, $crawler->filter('tbody .tm-qlines__row'));
        self::assertSame("123,75\u{a0}€", $crawler->filter('[data-quote-editor-target="ttc"]')->text());
        self::assertStringContainsString('Frais de déplacement', $crawler->filter('.tm-qedit__mentions .is-missing')->text());

        $this->client->request('GET', '/espace-pro/devis/apercu?demande='.self::REQUEST);
        self::assertResponseIsSuccessful();
        self::assertResponseHeaderSame('Content-Type', 'application/pdf');
        self::assertStringStartsWith('%PDF-', (string) $this->client->getResponse()->getContent());

        $form = $crawler->filter('form.tm-qedit')->form();
        $this->client->submit($form, ['lines[2][unit_price]' => '15,00']);
        self::assertResponseRedirects('/espace-pro/devis/nouveau?demande='.self::REQUEST);
        self::assertStringContainsString('Brouillon enregistré', $this->client->followRedirect()->filter('.tm-toasts')->text());
    }

    public function testSendConfirmedInModal(): void
    {
        $crawler = $this->client->request('GET', '/espace-pro/devis/nouveau?demande='.self::REQUEST);
        $lines = [
            'subject' => 'Réparation de la fuite', 'validity_days' => '30',
            'lines' => [
                ['type' => 'labour', 'label' => 'Recherche de fuite', 'quantity' => '1', 'unit' => 'forfait', 'unit_price' => '90,00', 'vat' => '1000'],
                ['type' => 'travel', 'label' => 'Déplacement', 'quantity' => '1', 'unit' => 'forfait', 'unit_price' => '0,00', 'vat' => '2000'],
            ],
        ];
        $token = (string) $crawler->filter('input[name="_token"]')->attr('value');

        // (f) sans crédit : brouillon enregistré, rien n'est débité, renvoi vers les forfaits.
        $this->client->request('POST', '/espace-pro/devis/envoyer?demande='.self::REQUEST, ['_token' => $token] + $lines);
        self::assertResponseRedirects('/tarifs-professionnels');
        self::assertSame('draft', $this->dossierValue("SELECT status FROM quote.quote WHERE request_id = '".self::REQUEST."'"));

        $ledger = self::getContainer()->get(CreditLedger::class);
        \assert($ledger instanceof CreditLedger);
        $ledger->grantFree('0190f5e4-0000-7000-8000-0000000f2301', $this->artisan, self::DOSSIER_USER, 1, null, new \DateTimeImmutable());

        $crawler = $this->client->request('GET', '/espace-pro/devis/nouveau?demande='.self::REQUEST);
        $opener = $crawler->filter('.tm-qedit__actions button[data-modal-opener-dialog-value="envoi-devis"]');
        self::assertSame('Envoyer le devis', trim($opener->text()));
        self::assertNull($opener->attr('disabled'), 'mentions complètes : envoi possible');
        self::assertSame('Envoyer ce devis ?', $crawler->filter('#envoi-devis_title')->text());
        self::assertStringContainsString('Cet envoi consommera 1 devis de votre crédit. Il vous restera 0 devis.', $crawler->filter('#envoi-devis')->text());

        $this->client->submit($crawler->filter('#envoi-devis button[type="submit"]')->form());
        self::assertResponseRedirects('/espace-pro/devis/nouveau?demande='.self::REQUEST);
        $crawler = $this->client->followRedirect();
        $number = 'DEV-'.new \DateTimeImmutable('now', new \DateTimeZone('Europe/Paris'))->format('Y').'-0001';
        self::assertStringContainsString('Devis '.$number.' envoyé au client.', $crawler->filter('.tm-toasts')->text());
        self::assertSame('Devis '.$number, $crawler->filter('h1')->text());
        self::assertNotNull($crawler->filter('fieldset.tm-qedit__frame')->attr('disabled'), 'lecture seule');
        self::assertCount(0, $crawler->filter('#envoi-devis'));
        self::assertStringEndsWith('/pdf/1', (string) $crawler->filter('.tm-qedit__sent a')->attr('href'));
        self::assertSame('1', $this->dossierValue("SELECT count(*)::text FROM billing.credit_ledger_entry WHERE type = 'quote_debit'"));

        $crawler = $this->client->request('GET', '/espace-pro/demandes/'.self::REQUEST);
        self::assertStringContainsString('Devis '.$number.' envoyé au client.', $crawler->filter('.tm-pro-detail__decide')->text());
        self::assertSame('Voir mon devis', trim($crawler->filter('.tm-pro-detail__decide a.tm-button')->first()->text()));
        self::assertStringContainsString('Crédit épuisé : souscrivez un forfait', $crawler->filter('.tm-pro-detail__credits [role="alert"]')->text(), 'RG-106');
        self::assertSame('exhausted', str_replace('tm-credit-pill tm-credit-pill--', '', (string) $crawler->filter('.tm-credit-pill')->attr('class')), 'jauge de l’en-tête');
    }

    public function testEditorIsReservedToReceivedRequests(): void
    {
        $this->client->request('GET', '/espace-pro/devis/nouveau?demande='.self::OUTSIDE);
        self::assertResponseStatusCodeSame(404);
        $this->client->request('GET', '/espace-pro/devis/nouveau?demande=pas-un-id');
        self::assertResponseStatusCodeSame(404);
    }
}
