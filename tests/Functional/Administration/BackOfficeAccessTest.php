<?php

declare(strict_types=1);

namespace App\Tests\Functional\Administration;

use App\Administration\Application\Team\DisableAdmin;
use App\Administration\Application\Team\InvitationToken;
use App\Administration\Application\Team\InviteAdmin;
use App\Administration\Application\Team\TeamRejected;
use App\Shared\Application\Bus\CommandBus;
use App\Shared\Infrastructure\Persistence\DbalValue;
use Doctrine\DBAL\Connection;
use OTPHP\TOTP;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Mime\Email;

/** ADR-0013 / BO14 : pare-feu distinct, invitation, 2FA TOTP obligatoire, codes de secours, permissions, ré-authentification. */
final class BackOfficeAccessTest extends WebTestCase
{
    private const string PASSWORD = 'phrase secrète du back-office fictive';
    private const string SUPER = '0190f5e4-0000-7000-8000-0000000a0001';

    private KernelBrowser $client;
    private Connection $connection;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->client->disableReboot();
        $this->client->setServerParameter('HTTP_ORIGIN', 'http://localhost');
        $connection = self::getContainer()->get(Connection::class);
        \assert($connection instanceof Connection);
        $this->connection = $connection;
        $this->connection->executeStatement('DELETE FROM admin.setting WHERE key = :k', ['k' => 'admin.ip_allowlist']);
        $this->clearSettingsCache();
    }

    private function clearSettingsCache(): void
    {
        $cache = self::getContainer()->get('cache.settings');
        \assert($cache instanceof \Psr\Cache\CacheItemPoolInterface);
        $cache->clear();
    }

    private function dispatch(object $command): void
    {
        $bus = self::getContainer()->get(CommandBus::class);
        \assert($bus instanceof CommandBus && $command instanceof \App\Shared\Application\Bus\Command);
        $bus->dispatch($command);
    }

    private function value(string $sql): string
    {
        $value = $this->connection->fetchOne($sql);

        return \is_int($value) || \is_bool($value) ? var_export($value, true) : DbalValue::string($value);
    }

    /**
     * Invite puis active un compte ; renvoie [secret TOTP, codes de secours].
     *
     * @return array{non-empty-string, list<string>}
     */
    private function activate(string $id, string $email, string $role): array
    {
        $token = InvitationToken::generate();
        $this->dispatch(new InviteAdmin($id, $email, 'Admin '.$role, $role, $token, null));
        $crawler = $this->client->request('GET', '/admin/invitation/'.$token);
        self::assertResponseIsSuccessful();
        $secret = str_replace(' ', '', $crawler->filter('.tm-admin-secret')->text());
        \assert('' !== $secret);
        $form = ['_token' => (string) $crawler->filter('input[name="_token"]')->attr('value'), 'password' => self::PASSWORD, 'password_confirm' => self::PASSWORD];

        $crawler = $this->client->request('POST', '/admin/invitation/'.$token, $form + ['code' => TOTP::createFromSecret($secret)->now()]);
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('no-store', (string) $this->client->getResponse()->headers->get('Cache-Control'), 'codes de secours jamais mis en cache');
        $codes = $crawler->filter('.tm-admin-codes code')->each(static fn ($n): string => $n->text());
        self::assertCount(10, $codes);
        $this->client->getCookieJar()->clear();

        return [$secret, $codes];
    }

    private function login(string $email, string $code): void
    {
        $crawler = $this->client->request('GET', '/admin/connexion');
        $this->client->request('POST', '/admin/connexion', ['_username' => $email, '_password' => self::PASSWORD, '_csrf_token' => (string) $crawler->filter('input[name="_csrf_token"]')->attr('value')]);
        $this->client->request('GET', '/admin');
        self::assertResponseRedirects('/admin/2fa', null, 'mot de passe seul : code exigé');
        $crawler = $this->client->request('GET', '/admin/2fa');
        $this->client->request('POST', '/admin/2fa/verification', ['_auth_code' => $code, '_csrf_token' => (string) $crawler->filter('input[name="_csrf_token"]')->attr('value')]);
    }

    public function testBackOfficeIsClosedToVisitorsAndStoresNoSecretInClear(): void
    {
        $this->client->request('GET', '/admin');
        self::assertResponseRedirects('/admin/connexion');
        $this->client->request('GET', '/admin/equipe');
        self::assertResponseRedirects('/admin/connexion');
        $this->client->request('GET', '/admin/invitation/'.InvitationToken::generate());
        self::assertResponseStatusCodeSame(410);

        [$secret] = $this->activate(self::SUPER, 'super@exemple.test', 'super_admin');
        self::assertSame('active|false|10', $this->value("SELECT status || '|' || (totp_secret LIKE '%".((string) $secret)."%')::text || '|' || jsonb_array_length(backup_codes) FROM admin.admin_user"));
        self::assertStringStartsWith('$argon2id$', $this->value('SELECT password_hash FROM admin.admin_user'));
    }

    public function testInvitationEmailAndActivationRules(): void
    {
        $token = InvitationToken::generate();
        $this->dispatch(new InviteAdmin(self::SUPER, 'Nouvel.Admin@Exemple.test', 'Nouvel Admin', 'support', $token, null));
        $mail = self::getMailerMessage();
        self::assertInstanceOf(Email::class, $mail);
        self::assertSame('I-02', $mail->getHeaders()->getHeaderBody('X-TrouveMoi-Template'));
        self::assertStringContainsString('/admin/invitation/'.$token, (string) $mail->getTextBody());
        self::assertSame(InvitationToken::hash($token), $this->value('SELECT invitation_token_hash FROM admin.admin_user'), 'jeton conservé haché');

        $crawler = $this->client->request('GET', '/admin/invitation/'.$token);
        $base = ['_token' => (string) $crawler->filter('input[name="_token"]')->attr('value')];
        $this->client->request('POST', '/admin/invitation/'.$token, $base + ['password' => 'court', 'password_confirm' => 'court', 'code' => '123456']);
        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('#password_error', '12 à 128 caractères');
        $this->client->request('POST', '/admin/invitation/'.$token, $base + ['password' => self::PASSWORD, 'password_confirm' => self::PASSWORD, 'code' => '000000']);
        self::assertSelectorTextContains('#code_error', 'Code incorrect');
        self::assertSame('invited', $this->value('SELECT status FROM admin.admin_user'));

        try {
            $this->dispatch(new InviteAdmin('0190f5e4-0000-7000-8000-0000000a0009', 'nouvel.admin@exemple.test', 'Doublon', 'support', InvitationToken::generate(), null));
            self::fail('Adresse en double acceptée');
        } catch (TeamRejected $e) {
            self::assertSame(TeamRejected::EMAIL_TAKEN, $e->reason);
        }
    }

    public function testLoginNeedsPasswordThenTotpOrSingleUseBackupCode(): void
    {
        [$secret, $codes] = $this->activate(self::SUPER, 'super@exemple.test', 'super_admin');

        $this->login('super@exemple.test', '000000');
        self::assertResponseRedirects('/admin/2fa', null, 'mauvais code : on reste à l’étape du code');
        $this->client->request('GET', '/admin');
        self::assertResponseRedirects('/admin/2fa', null, 'pas d’accès avec le seul mot de passe');
        $this->client->getCookieJar()->clear();

        $this->login('super@exemple.test', TOTP::createFromSecret($secret)->now());
        self::assertResponseRedirects('/admin');
        $crawler = $this->client->request('GET', '/admin');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Tableau de bord');
        self::assertCount(1, $crawler->filter('.tm-admin__nav a[href="/admin/equipe"]'));
        self::assertSame('admin.2fa_failed,admin.login', $this->value("SELECT string_agg(action, ',' ORDER BY seq) FROM admin.audit_log WHERE action IN ('admin.login', 'admin.2fa_failed')"));
        $this->client->request('GET', '/inscription/pro/entreprise');
        self::assertResponseRedirects('/connexion', null, 'la session admin ne vaut pas compte client ou artisan');

        $this->client->getCookieJar()->clear();
        $this->login('super@exemple.test', $codes[0]);
        self::assertResponseRedirects('/admin', null, 'code de secours accepté');
        self::assertSame('9', $this->value('SELECT jsonb_array_length(backup_codes) FROM admin.admin_user'));
        $this->client->getCookieJar()->clear();
        $this->login('super@exemple.test', $codes[0]);
        self::assertResponseRedirects('/admin/2fa', null, 'code de secours à usage unique');
    }

    public function testPermissionsComeFromTheRole(): void
    {
        [$secret] = $this->activate('0190f5e4-0000-7000-8000-0000000a0003', 'moderateur@exemple.test', 'moderator');
        $this->login('moderateur@exemple.test', TOTP::createFromSecret($secret)->now());

        $crawler = $this->client->request('GET', '/admin');
        self::assertResponseIsSuccessful();
        self::assertCount(0, $crawler->filter('.tm-admin__nav a[href="/admin/equipe"]'), 'Équipe absente de la navigation');
        self::assertStringNotContainsString('Forfaits', $crawler->filter('.tm-admin__sidebar')->text());
        $this->client->request('GET', '/admin/equipe');
        self::assertResponseStatusCodeSame(403);
    }

    public function testTeamActionsReauthenticationAndLastSuperAdmin(): void
    {
        [$secret] = $this->activate(self::SUPER, 'super@exemple.test', 'super_admin');
        $this->login('super@exemple.test', TOTP::createFromSecret($secret)->now());

        $crawler = $this->client->request('GET', '/admin/equipe');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.tm-admin-matrix caption', 'Modérateur');
        $this->client->request('POST', '/admin/equipe/inviter', ['_token' => (string) $crawler->filter('form[action="/admin/equipe/inviter"] input[name="_token"]')->attr('value'), 'name' => 'Nora Exemple', 'email' => 'nora@exemple.test', 'role' => 'finance']);
        self::assertResponseRedirects('/admin/equipe', 303, 'connexion récente : pas de nouvelle confirmation');
        self::assertSame('2|finance', $this->value("SELECT count(*) || '|' || max(role) FILTER (WHERE email = 'nora@exemple.test') FROM admin.admin_user"));

        $crawler = $this->client->request('GET', '/admin/reauthentification?cible=/admin/equipe');
        $this->client->request('POST', '/admin/reauthentification?cible=/admin/equipe', ['_token' => (string) $crawler->filter('input[name="_token"]')->attr('value'), 'password' => self::PASSWORD, 'code' => '000000']);
        self::assertResponseStatusCodeSame(422);
        $this->client->request('POST', '/admin/reauthentification?cible=https://pirate.example/x', ['_token' => (string) $crawler->filter('input[name="_token"]')->attr('value'), 'password' => self::PASSWORD, 'code' => TOTP::createFromSecret($secret)->now()]);
        self::assertResponseRedirects('/admin', 303, 'cible externe ignorée');

        try {
            $this->dispatch(new DisableAdmin(self::SUPER, '0190f5e4-0000-7000-8000-0000000a0099'));
            self::fail('Dernier super-admin désactivé');
        } catch (TeamRejected $e) {
            self::assertSame(TeamRejected::LAST_SUPER_ADMIN, $e->reason);
        }
    }

    public function testIpAllowlistClosesTheBackOffice(): void
    {
        $this->connection->executeStatement("INSERT INTO admin.setting (key, value, type, updated_at) VALUES ('admin.ip_allowlist', '[\"10.0.0.0/8\"]', 'string_list', now()) ON CONFLICT (key) DO UPDATE SET value = EXCLUDED.value");
        $this->clearSettingsCache();

        $this->client->request('GET', '/admin/connexion', server: ['REMOTE_ADDR' => '203.0.113.7']);
        self::assertResponseStatusCodeSame(403);
        $this->client->request('GET', '/admin/connexion', server: ['REMOTE_ADDR' => '10.1.2.3']);
        self::assertResponseIsSuccessful();
        $this->client->request('GET', '/', server: ['REMOTE_ADDR' => '203.0.113.7']);
        self::assertResponseIsSuccessful('le site public n’est pas concerné');
    }
}
