<?php

declare(strict_types=1);

namespace App\Tests\Functional\QuoteRequest;

use App\ArtisanProfile\Application\Verification\ApproveArtisan;
use App\Identity\Application\Command\RegisterUser;
use App\QuoteRequest\Application\Dispatch\DispatchQuoteRequest;
use App\QuoteRequest\Application\Dispatch\SendRequestDigests;
use App\QuoteRequest\Application\EventHandler\DispatchOnPublication;
use App\QuoteRequest\Application\Submission\SubmitQuoteRequest;
use App\QuoteRequest\Domain\Request\Event\QuoteRequestStatusChanged;
use App\QuoteRequest\Domain\Request\Event\QuoteRequestSubmitted;
use App\Shared\Infrastructure\Persistence\DbalValue;
use App\Tests\Support\AdminSession;
use App\Tests\Support\ArtisanDossier;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Mime\Email;

/**
 * RG-062 : diffusion aux artisans éligibles (métier, zone, vérifié et visible, compte actif, non en pause, non bloqué),
 * demande directe (E-31), alertes immédiates ou en digest (E-30), rejeu sans double alerte, signalement BO04.
 */
final class RequestDispatchTest extends WebTestCase
{
    use AdminSession;
    use ArtisanDossier;

    private const string CLIENT = '0190f5e4-0000-7000-8000-0000000e7001';
    private const string ADMIN = '0190f5e4-0000-7000-8000-0000000a7001';
    private const string FIRST = '0190f5e4-0000-7000-8000-0000000e7101';
    private const string SECOND = '0190f5e4-0000-7000-8000-0000000e7102';
    private const string THIRD = '0190f5e4-0000-7000-8000-0000000e7103';

    private KernelBrowser $client;
    private Connection $connection;
    private string $artisan;
    private string $job;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->client->disableReboot();
        $connection = self::getContainer()->get(Connection::class);
        \assert($connection instanceof Connection);
        $this->connection = $connection;
        $this->prepareReferentials();
        $this->artisan = $this->submittedDossier(200);
        $this->uploadPiece('kbis');
        $this->uploadPiece('insurance_rc');
        $this->dossierDispatch(new ApproveArtisan($this->artisan, self::ADMIN));
        $this->connection->executeStatement("UPDATE identity.user_account SET status = 'active', email_verified_at = date_trunc('second', now()) WHERE id = :id", ['id' => self::DOSSIER_USER]);
        self::getContainer()->get(EntityManagerInterface::class)->clear();
        $this->dossierDispatch(new RegisterUser(self::CLIENT, 'camille.diffusion@exemple.test', 'cheval agrafe batterie', 'client', 'Camille', 'Diffusion'));
        $this->job = $this->dossierValue("SELECT id FROM catalog.job WHERE slug = 'plombier'");
    }

    public function testEligibleArtisanIsAlertedOnceAndPausedOrBlockedArtisansAreSkipped(): void
    {
        self::assertSame('true', $this->dossierValue("SELECT EXISTS (SELECT 1 FROM artisan.service_area_commune WHERE insee = '76351')"), 'Le Havre dans la zone (30 km autour de 76341)');
        $this->submit(self::FIRST, '1 rue Fictive', 'Fuite sous l’évier de la cuisine depuis ce matin (fictif).', title: 'Fuite sous l’évier');
        $this->publish(new QuoteRequestSubmitted(self::FIRST, self::CLIENT, $this->job, '76351', 'open', 3, null, true, new \DateTimeImmutable()));

        self::assertSame(['reason' => 'eligible', 'notified' => true], $this->dispatch(self::FIRST), 'artisan éligible, alerte immédiate (fréquence par défaut)');
        $mail = $this->lastEmail();
        self::assertSame('Nouvelle demande : Plombier à Le Havre (76600)', $mail->getSubject());
        self::assertStringContainsString('Fuite sous l’évier', (string) $mail->getTextBody());
        self::assertStringContainsString('Sous 1 semaine', (string) $mail->getTextBody());
        self::assertStringNotContainsString('1 rue Fictive', (string) $mail->getHtmlBody(), 'jamais l’adresse (RG-022bis)');
        self::assertStringNotContainsString('Camille', (string) $mail->getHtmlBody(), 'jamais l’identité du client (RG-112)');
        self::assertSame(1, $this->deliveries('E-30', 'in_app'));

        $this->dossierDispatch(new DispatchQuoteRequest(self::FIRST));
        self::assertSame(1, $this->deliveries('E-30', 'email'), 'rejouée : pas de double alerte');
        self::assertSame(2, DbalValue::int($this->connection->fetchOne("SELECT count(*) FROM admin.audit_log WHERE action = 'request.dispatched' AND subject_id = :id", ['id' => self::FIRST])), 'chaque diffusion est journalisée');

        // Réception mise en pause : la demande suivante ne trouve personne, l'équipe est alertée.
        $this->connection->executeStatement('INSERT INTO artisan.request_preference (artisan_id, paused_at, updated_at) VALUES (:a, now(), now())', ['a' => $this->artisan]);
        $this->submit(self::SECOND, '2 rue Fictive', 'Remplacement du chauffe-eau de la salle de bain (fictif).');
        $this->dossierDispatch(new DispatchQuoteRequest(self::SECOND));
        self::assertNull($this->dispatch(self::SECOND), 'artisan en pause');
        self::assertSame(1, DbalValue::int($this->connection->fetchOne("SELECT count(*) FROM admin.audit_log WHERE action = 'request.dispatch_empty' AND subject_id = :id", ['id' => self::SECOND])));

        // Pause levée mais artisan bloqué par ce client.
        $this->connection->executeStatement('UPDATE artisan.request_preference SET paused_at = NULL WHERE artisan_id = :a', ['a' => $this->artisan]);
        $this->connection->executeStatement('INSERT INTO request.client_artisan_block (client_id, artisan_id, created_at) VALUES (:c, :a, now())', ['c' => self::CLIENT, 'a' => $this->artisan]);
        $this->dossierDispatch(new DispatchQuoteRequest(self::SECOND));
        self::assertNull($this->dispatch(self::SECOND), 'artisan bloqué par le client');

        $this->connection->executeStatement('DELETE FROM request.client_artisan_block');
        $this->connection->executeStatement("UPDATE artisan.artisan_profile SET hidden_at = now(), hidden_reason = 'insurance_expired'");
        $this->dossierDispatch(new DispatchQuoteRequest(self::SECOND));
        self::assertNull($this->dispatch(self::SECOND), 'profil masqué (RG-130)');

        // BO04 : nombre d'artisans notifiés, demande publiée sans destinataire signalée.
        $this->adminSession($this->client, self::ADMIN, 'moderator');
        $crawler = $this->client->request('GET', '/admin/demandes');
        self::assertResponseIsSuccessful();
        $cell = fn (string $id): string => trim($crawler->filter('tbody tr')->reduce(fn ($row): bool => str_contains($row->text(), $this->dossierValue("SELECT number FROM request.quote_request WHERE id = '".$id."'")))->filter('td')->eq(3)->text());
        self::assertSame('Aucun artisan', $cell(self::SECOND), 'publiée, personne ne la reçoit');
        self::assertSame('1', $cell(self::FIRST));
    }

    public function testDirectRequestAndDigest(): void
    {
        // Demande directe sans diffusion élargie : seul l'artisan choisi la reçoit, en priorité (E-31).
        $this->submit(self::FIRST, '1 rue Fictive', 'Fuite sous l’évier de la cuisine depuis ce matin (fictif).', target: $this->artisan, broadcast: false);
        $this->dossierDispatch(new DispatchQuoteRequest(self::FIRST));
        self::assertSame(['reason' => 'direct', 'notified' => true], $this->dispatch(self::FIRST));
        self::assertSame(1, $this->deliveries('E-31', 'email'));
        self::assertSame(0, $this->deliveries('E-30', 'email'));

        // Alertes regroupées 2 fois par jour : rien tout de suite, tout au prochain digest.
        $this->connection->executeStatement("INSERT INTO artisan.request_preference (artisan_id, alert_frequency, updated_at) VALUES (:a, 'twice_daily', now())", ['a' => $this->artisan]);
        $this->submit(self::SECOND, '2 rue Fictive', 'Remplacement du chauffe-eau de la salle de bain (fictif).');
        $this->submit(self::THIRD, '3 rue Fictive', 'Robinet de la cuisine qui goutte en permanence (fictif).', title: 'Robinet qui goutte');
        $this->dossierDispatch(new DispatchQuoteRequest(self::SECOND));
        $this->dossierDispatch(new DispatchQuoteRequest(self::THIRD));
        self::assertSame(['reason' => 'eligible', 'notified' => false], $this->dispatch(self::THIRD), 'en attente du digest');
        self::assertSame(0, $this->deliveries('E-30', 'email'));

        $this->dossierDispatch(new SendRequestDigests(false));
        self::assertSame(1, $this->deliveries('E-30', 'email'), 'un seul e-mail pour les deux demandes');
        $mail = $this->lastEmail();
        self::assertSame('2 nouvelles demandes dans votre zone', $mail->getSubject());
        self::assertStringContainsString('• Robinet qui goutte — Plombier · Le Havre (76600) · Sous 1 semaine', (string) $mail->getTextBody());
        self::assertSame(['reason' => 'eligible', 'notified' => true], $this->dispatch(self::SECOND));

        $this->dossierDispatch(new SendRequestDigests(true));
        self::assertSame(1, $this->deliveries('E-30', 'email'), 'digest déjà envoyé');

        // Fréquence quotidienne : seul le passage du matin l'envoie.
        $this->connection->executeStatement("UPDATE artisan.request_preference SET alert_frequency = 'daily'");
        $this->connection->executeStatement('UPDATE request.request_dispatch SET notified_at = NULL WHERE request_id = :r', ['r' => self::SECOND]);
        $this->dossierDispatch(new SendRequestDigests(false));
        self::assertSame(1, $this->deliveries('E-30', 'email'));
        $this->dossierDispatch(new SendRequestDigests(true));
        self::assertSame(2, $this->deliveries('E-30', 'email'));
    }

    public function testModerationKeepsTheRequestUntilApproval(): void
    {
        $this->submit(self::FIRST, '1 rue Fictive', 'Fuite sous l’évier de la cuisine depuis ce matin (fictif).');
        $this->connection->executeStatement("UPDATE request.quote_request SET status = 'pending_moderation', published_at = NULL, expires_at = NULL WHERE id = :id", ['id' => self::FIRST]);
        self::getContainer()->get(EntityManagerInterface::class)->clear();
        $this->publish(new QuoteRequestSubmitted(self::FIRST, self::CLIENT, $this->job, '76351', 'pending_moderation', 3, null, true, new \DateTimeImmutable()));
        $this->dossierDispatch(new DispatchQuoteRequest(self::FIRST));
        self::assertNull($this->dispatch(self::FIRST), 'pas de diffusion avant la modération (RG-063)');

        $this->connection->executeStatement("UPDATE request.quote_request SET status = 'open', published_at = date_trunc('second', now()), expires_at = date_trunc('second', now()) + interval '30 days' WHERE id = :id", ['id' => self::FIRST]);
        self::getContainer()->get(EntityManagerInterface::class)->clear();
        $this->publish(new QuoteRequestStatusChanged(self::FIRST, self::CLIENT, 'pending_moderation', 'open', 'moderation_approved', new \DateTimeImmutable()));
        self::assertSame(['reason' => 'eligible', 'notified' => true], $this->dispatch(self::FIRST), 'diffusée à la validation');
    }

    public function testTeamRedispatchesAndExtendsTheArea(): void
    {
        // Caen : hors de la zone de l'artisan (30 km autour d'Harfleur), à 55 km de sa commune de départ.
        $this->dossierDispatch(new SubmitQuoteRequest(self::FIRST, self::CLIENT, $this->job, '14118', '14000', '1 rue Fictive', 'repair', 'Fuite sous l’évier de la cuisine depuis ce matin (fictif).', [], null, 'week', null));
        $this->dossierDispatch(new DispatchQuoteRequest(self::FIRST));
        self::assertNull($this->dispatch(self::FIRST), 'hors zone');

        $this->adminSession($this->client, self::ADMIN, 'moderator');
        $crawler = $this->client->request('GET', '/admin/demandes?demande='.self::FIRST);
        self::assertResponseIsSuccessful();
        $form = $crawler->filter('.tm-admin-dispatch');
        self::assertStringContainsString('Étendre la zone (20 km)', $form->text());
        $token = (string) $form->filter('input[name="_token"]')->attr('value');

        $this->client->request('POST', '/admin/demandes/'.self::FIRST.'/diffusion', ['_token' => $token, 'action' => 'redispatch']);
        self::assertStringContainsString('Aucun nouvel artisan éligible', $this->client->followRedirect()->filter('.tm-toasts')->text());
        $this->client->request('POST', '/admin/demandes/'.self::FIRST.'/diffusion', ['_token' => $token, 'action' => 'extend']);
        self::assertStringContainsString('Aucun autre artisan du métier à moins de 20 km', $this->client->followRedirect()->filter('.tm-toasts')->text());
        self::assertSame(1, DbalValue::int($this->connection->fetchOne("SELECT count(*) FROM admin.audit_log WHERE action = 'request.dispatch_extended' AND actor_type = 'admin'")));

        $this->dossierDispatch(new DispatchQuoteRequest(self::FIRST, self::ADMIN, 60));
        self::assertSame(['reason' => 'extended', 'notified' => true], $this->dispatch(self::FIRST), 'artisan du métier à moins de 60 km');
        self::assertSame('Nouvelle demande : Plombier à Caen (14000)', $this->lastEmail()->getSubject());

        $this->connection->executeStatement("UPDATE request.quote_request SET status = 'cancelled', status_reason = 'admin_closed' WHERE id = :id", ['id' => self::FIRST]);
        self::getContainer()->get(EntityManagerInterface::class)->clear();
        $this->client->request('POST', '/admin/demandes/'.self::FIRST.'/diffusion', ['_token' => $token, 'action' => 'redispatch']);
        self::assertStringContainsString('Seule une demande ouverte peut être diffusée', $this->client->followRedirect()->filter('.tm-toasts')->text());
    }

    private function submit(string $id, string $address, string $description, ?string $target = null, ?bool $broadcast = null, ?string $title = null): void
    {
        $this->dossierDispatch(new SubmitQuoteRequest($id, self::CLIENT, $this->job, '76351', '76600', $address, 'repair', $description, [], null, 'week', null, targetArtisanId: $target, broadcastToOthers: $broadcast, title: $title));
    }

    private function publish(QuoteRequestSubmitted|QuoteRequestStatusChanged $event): void
    {
        $handler = self::getContainer()->get(DispatchOnPublication::class);
        \assert($handler instanceof DispatchOnPublication);
        $handler($event);
    }

    /** @return array{reason: string, notified: bool}|null */
    private function dispatch(string $requestId): ?array
    {
        $row = $this->connection->fetchAssociative('SELECT reason, notified_at IS NOT NULL AS notified FROM request.request_dispatch WHERE request_id = :r AND artisan_id = :a', ['r' => $requestId, 'a' => $this->artisan]);

        return false === $row ? null : ['reason' => DbalValue::string($row['reason']), 'notified' => true === $row['notified']];
    }

    private function deliveries(string $code, string $channel): int
    {
        return DbalValue::int($this->connection->fetchOne('SELECT count(*) FROM notification.delivery WHERE code = :c AND channel = :ch', ['c' => $code, 'ch' => $channel]));
    }

    private function lastEmail(): Email
    {
        $mails = array_values(array_filter(self::getMailerMessages(), static fn (object $m): bool => $m instanceof Email));
        $mail = end($mails);
        self::assertInstanceOf(Email::class, $mail);

        return $mail;
    }
}
