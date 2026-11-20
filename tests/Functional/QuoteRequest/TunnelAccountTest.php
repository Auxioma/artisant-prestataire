<?php

declare(strict_types=1);

namespace App\Tests\Functional\QuoteRequest;

use App\Identity\Application\Command\RegisterUser;
use App\Identity\Application\EventHandler\SendEmailVerificationOnRegistration;
use App\Identity\Domain\User\Event\UserRegistered;
use App\QuoteRequest\Application\EventHandler\SubmitDraftOnEmailVerified;
use App\QuoteRequest\Application\Submission\SubmitQuoteRequest;
use App\Shared\Domain\Event\Integration\AccountEmailVerified;
use App\Shared\Infrastructure\Persistence\DbalValue;
use App\Tests\Support\ArtisanDossier;
use App\Tests\Support\Fake\AcceptingHumanVerification;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\Mime\Email;

/** P11.6 (Figma 5:162, T-402d) : compte créé dans le tunnel, E-11, envoi automatique à l'activation (RG-040), anti-énumération. */
final class TunnelAccountTest extends WebTestCase
{
    use ArtisanDossier;

    private const string PASSWORD = 'cheval agrafe batterie';

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
        $this->jobId = $this->dossierValue("SELECT id FROM catalog.job WHERE slug = 'plombier'");
        $this->client->setServerParameter('HTTP_ORIGIN', 'http://localhost');
    }

    public function testAccountCreatedInTheTunnelSendsTheRequestOnActivation(): void
    {
        $crawler = $this->walkToContact();
        self::assertSelectorExists('#first_name');
        self::assertSelectorTextContains('.tm-request__main', 'J’ai déjà un compte : Se connecter');

        $crawler = $this->client->request('POST', '/demande-de-devis/5', ['_token' => $this->token($crawler), 'action' => 'continue', 'contact_preference' => 'messaging_only', 'first_name' => 'Camille']);
        self::assertResponseStatusCodeSame(422);
        self::assertSame('Acceptez les conditions d’utilisation pour créer votre compte.', trim($crawler->filter('#terms_error')->text()));
        self::assertCount(1, $crawler->filter('#email_error'));

        $form = ['_token' => $this->token($crawler), 'action' => 'continue', 'contact_preference' => 'messaging_only', 'first_name' => 'Camille', 'last_name' => 'Tunnel',
            'email' => 'camille.nouvelle@exemple.test', 'phone' => '06 12 34 56 78', 'password' => 'court', 'terms' => '1', 'cf-turnstile-response' => AcceptingHumanVerification::HUMAN];
        $crawler = $this->client->request('POST', '/demande-de-devis/5', $form);
        self::assertResponseStatusCodeSame(422);
        self::assertStringContainsString('au moins 12 caractères', $crawler->filter('#password_error')->text());

        $form['password'] = self::PASSWORD;
        $this->client->request('POST', '/demande-de-devis/5', $form);
        self::assertResponseRedirects('/demande-de-devis/6', 303);
        $userId = DbalValue::string($this->connection->fetchOne("SELECT id FROM identity.user_account WHERE email = 'camille.nouvelle@exemple.test'"));
        self::assertSame('pending_email', DbalValue::string($this->connection->fetchOne('SELECT status FROM identity.user_account WHERE id = :id', ['id' => $userId])));
        self::assertSame($userId, DbalValue::string($this->connection->fetchOne('SELECT pending_user_id FROM request.request_draft')));

        $crawler = $this->client->followRedirect();
        $this->client->request('POST', '/demande-de-devis/6', ['_token' => $this->token($crawler), 'action' => 'send', 'confirm' => '1', 'cf-turnstile-response' => AcceptingHumanVerification::HUMAN]);
        self::assertResponseRedirects('/demande-de-devis/6', 303);
        $crawler = $this->client->followRedirect();
        self::assertStringContainsString('Plus qu’une étape : activez votre compte', $crawler->filter('.tm-request__main')->text());
        self::assertSame(0, DbalValue::int($this->connection->fetchOne('SELECT count(*) FROM request.quote_request')), 'RG-040 : rien n’est envoyé avant l’activation');

        // E-11 (et non E-01) pour un compte créé dans le tunnel ; le lien d'activation valide l'adresse.
        $sender = self::getContainer()->get(SendEmailVerificationOnRegistration::class);
        \assert($sender instanceof SendEmailVerificationOnRegistration);
        $sender(new UserRegistered($userId, 'client', new \DateTimeImmutable(), SendEmailVerificationOnRegistration::REQUEST));
        self::assertSame(1, DbalValue::int($this->connection->fetchOne("SELECT count(*) FROM notification.delivery WHERE code = 'E-11'")));
        $mail = self::getMailerMessages()[0] ?? null;
        \assert($mail instanceof Email);
        self::assertStringContainsString('Activez votre compte pour envoyer votre demande', (string) $mail->getSubject());
        self::assertSame(1, preg_match('~/verification-email/([A-Za-z0-9_-]+)~', (string) $mail->getTextBody(), $m));
        $this->client->request('GET', '/verification-email/'.$m[1]);
        self::assertSame(1, DbalValue::int($this->connection->fetchOne("SELECT count(*) FROM shared.outbox_message WHERE message_type LIKE '%AccountEmailVerified'")), 'événement d’intégration publié');

        $submit = self::getContainer()->get(SubmitDraftOnEmailVerified::class);
        \assert($submit instanceof SubmitDraftOnEmailVerified);
        $submit(new AccountEmailVerified($userId, new \DateTimeImmutable()));
        self::assertSame($userId, DbalValue::string($this->connection->fetchOne("SELECT client_id FROM request.quote_request WHERE status = 'open'")), 'demande envoyée à l’activation');
        self::assertSame(0, DbalValue::int($this->connection->fetchOne('SELECT count(*) FROM request.request_draft')));
    }

    public function testExistingEmailGivesTheSameOutcome(): void
    {
        $this->dossierDispatch(new RegisterUser('0190f5e4-0000-7000-8000-0000000f2001', 'deja.inscrit@exemple.test', self::PASSWORD, 'client', 'Déjà', 'Inscrit'));
        $crawler = $this->walkToContact();
        $this->client->request('POST', '/demande-de-devis/5', ['_token' => $this->token($crawler), 'action' => 'continue', 'contact_preference' => 'messaging_only', 'first_name' => 'Autre', 'last_name' => 'Personne',
            'email' => 'deja.inscrit@exemple.test', 'phone' => '06 12 34 56 78', 'password' => 'une toute autre phrase secrète', 'terms' => '1', 'cf-turnstile-response' => AcceptingHumanVerification::HUMAN]);
        self::assertResponseRedirects('/demande-de-devis/6', 303, 'anti-énumération (A02) : même issue qu’une adresse nouvelle');
        self::assertSame(1, DbalValue::int($this->connection->fetchOne("SELECT count(*) FROM identity.user_account WHERE email = 'deja.inscrit@exemple.test'")), 'aucun compte créé');
        self::assertSame(1, DbalValue::int($this->connection->fetchOne("SELECT count(*) FROM shared.outbox_message WHERE message_type LIKE '%RegistrationAttemptedOnExistingAccount'")), 'titulaire prévenu (I-01)');
    }

    public function testCreateAccountLaterLogsInThroughTheMagicLink(): void
    {
        $crawler = $this->walkToContact();
        $this->client->request('POST', '/demande-de-devis/5', ['_token' => $this->token($crawler), 'action' => 'magic', 'contact_preference' => 'messaging_only', 'first_name' => 'Dominique', 'last_name' => 'Plus-Tard',
            'email' => 'plus.tard@exemple.test', 'phone' => '06 11 22 33 44', 'terms' => '1', 'cf-turnstile-response' => AcceptingHumanVerification::HUMAN]);
        self::assertResponseRedirects('/demande-de-devis/6', 303, 'aucun mot de passe exigé (Figma 5:162 « Créer mon compte plus tard »)');
        $userId = DbalValue::string($this->connection->fetchOne("SELECT id FROM identity.user_account WHERE email = 'plus.tard@exemple.test'"));
        self::assertTrue((bool) $this->connection->fetchOne('SELECT passwordless FROM identity.user_account WHERE id = :id', ['id' => $userId]));

        $crawler = $this->client->followRedirect();
        $this->client->request('POST', '/demande-de-devis/6', ['_token' => $this->token($crawler), 'action' => 'send', 'confirm' => '1', 'cf-turnstile-response' => AcceptingHumanVerification::HUMAN]);
        $sender = self::getContainer()->get(SendEmailVerificationOnRegistration::class);
        \assert($sender instanceof SendEmailVerificationOnRegistration);
        $sender(new UserRegistered($userId, 'client', new \DateTimeImmutable(), SendEmailVerificationOnRegistration::REQUEST));
        $mail = self::getMailerMessages()[0] ?? null;
        \assert($mail instanceof Email);
        self::assertStringContainsString('ce lien vous connecte directement', (string) $mail->getTextBody());
        self::assertSame(1, preg_match('~/verification-email/([A-Za-z0-9_-]+)~', (string) $mail->getTextBody(), $m));

        $this->client->getCookieJar()->clear();
        $this->client->request('GET', '/verification-email/'.$m[1]);
        self::assertResponseRedirects('/', 303);
        $crawler = $this->client->followRedirect();
        self::assertStringContainsString('vous êtes connecté', $crawler->filter('.tm-toasts')->text());
        self::assertCount(1, $crawler->filter('.tm-dropdown__title'), 'connecté par le lien magique');

        $this->client->getCookieJar()->clear();
        $this->client->request('GET', '/verification-email/'.$m[1]);
        self::assertResponseIsSuccessful();
        self::assertCount(0, $this->client->getCrawler()->filter('.tm-dropdown__title'), 'lien à usage unique : pas de seconde connexion');
    }

    public function testRefusedAutomaticSubmissionKeepsTheReasonOnTheDraft(): void
    {
        $other = '0190f5e4-0000-7000-8000-0000000f2101';
        $this->dossierDispatch(new RegisterUser($other, 'voisin.doublon@exemple.test', self::PASSWORD, 'client', 'Voisin', 'Doublon'));
        $this->dossierDispatch(new SubmitQuoteRequest('0190f5e4-0000-7000-8000-0000000f2102', $other, $this->jobId, '76351', '76600', '12 Rue Fictive 76600 Le Havre', 'repair', 'Fuite d’eau déjà signalée par le voisin (demande fictive).', [], null, 'week', null));

        $crawler = $this->walkToContact();
        $this->client->request('POST', '/demande-de-devis/5', ['_token' => $this->token($crawler), 'action' => 'magic', 'contact_preference' => 'messaging_only', 'first_name' => 'Alix', 'last_name' => 'Doublon',
            'email' => 'alix.doublon@exemple.test', 'phone' => '06 11 22 33 55', 'terms' => '1', 'cf-turnstile-response' => AcceptingHumanVerification::HUMAN]);
        $crawler = $this->client->followRedirect();
        $this->client->request('POST', '/demande-de-devis/6', ['_token' => $this->token($crawler), 'action' => 'send', 'confirm' => '1', 'cf-turnstile-response' => AcceptingHumanVerification::HUMAN]);
        $userId = DbalValue::string($this->connection->fetchOne("SELECT id FROM identity.user_account WHERE email = 'alix.doublon@exemple.test'"));
        $this->connection->executeStatement("UPDATE identity.user_account SET status = 'active', email_verified_at = date_trunc('second', now()) WHERE id = :id", ['id' => $userId]);

        $submit = self::getContainer()->get(SubmitDraftOnEmailVerified::class);
        \assert($submit instanceof SubmitDraftOnEmailVerified);
        $submit(new AccountEmailVerified($userId, new \DateTimeImmutable()));
        self::assertSame(1, DbalValue::int($this->connection->fetchOne('SELECT count(*) FROM request.quote_request')), 'RG-065 : doublon refusé');
        $row = $this->connection->fetchAssociative("SELECT owner_user_id, submit_requested_at, payload->>'submit_error' AS error FROM request.request_draft");
        \assert(false !== $row);
        self::assertSame($userId, $row['owner_user_id'], 'brouillon rattaché au compte');
        self::assertNull($row['submit_requested_at'], 'plus d’envoi en attente');
        self::assertStringContainsString('déjà envoyé une demande pour ce métier à cette adresse', DbalValue::string($row['error']));
    }

    /** Étapes 1 à 4 en visiteur ; renvoie la page de l'étape 5. */
    private function walkToContact(): Crawler
    {
        $crawler = $this->client->request('GET', '/demande-de-devis/1');
        $this->client->request('POST', '/demande-de-devis/1', ['_token' => $this->token($crawler), 'action' => 'continue', 'job_id' => $this->jobId, 'project_type' => 'repair']);
        $this->client->request('POST', '/demande-de-devis/2', ['_token' => $this->token($crawler), 'action' => 'continue', 'description' => 'Fuite sous l’évier de la cuisine depuis ce matin (demande fictive).']);
        $this->client->request('POST', '/demande-de-devis/3', ['_token' => $this->token($crawler), 'action' => 'continue']);
        $this->client->request('POST', '/demande-de-devis/4', ['_token' => $this->token($crawler), 'action' => 'continue', 'address' => '12 rue fictive', 'timing' => 'week', 'occupant_status' => 'owner']);
        self::assertResponseRedirects('/demande-de-devis/5', 303);

        return $this->client->followRedirect();
    }

    private function token(Crawler $crawler): string
    {
        return (string) $crawler->filter('input[name="_token"]')->first()->attr('value');
    }
}
