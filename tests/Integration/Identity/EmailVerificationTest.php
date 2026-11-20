<?php

declare(strict_types=1);

namespace App\Tests\Integration\Identity;

use App\Identity\Application\Command\InvalidCurrentPassword;
use App\Identity\Application\Command\RegisterUser;
use App\Identity\Application\EmailVerification\ConfirmEmail;
use App\Identity\Application\EmailVerification\EmailTokenState;
use App\Identity\Application\EmailVerification\EmailTokenStatus;
use App\Identity\Application\EmailVerification\InvalidEmailToken;
use App\Identity\Application\EmailVerification\RequestEmailChange;
use App\Identity\Application\EmailVerification\ResendEmailVerification;
use App\Identity\Application\EventHandler\NotifyExistingAccountOnRegistrationAttempt;
use App\Identity\Application\EventHandler\SendEmailVerificationOnRegistration;
use App\Identity\Domain\User\Event\RegistrationAttemptedOnExistingAccount;
use App\Identity\Domain\User\Event\UserRegistered;
use App\Shared\Application\Bus\CommandBus;
use App\Shared\Application\Bus\QueryBus;
use App\Shared\Infrastructure\Persistence\DbalValue;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Mime\Email;

/** A06 / E-01 / E-06 : jetons hachés à usage unique, expiration, renvoi, changement d'adresse, anti-énumération. */
final class EmailVerificationTest extends KernelTestCase
{
    private const string ID = '0190f5e4-0000-7000-8000-000000000701';
    private const string OTHER = '0190f5e4-0000-7000-8000-000000000702';
    private const string PASSWORD = 'cheval agrafe batterie';

    private CommandBus $commands;
    private QueryBus $queries;
    private Connection $connection;

    protected function setUp(): void
    {
        self::bootKernel();
        $c = self::getContainer();
        $commands = $c->get(CommandBus::class);
        $queries = $c->get(QueryBus::class);
        $connection = $c->get(Connection::class);
        \assert($commands instanceof CommandBus && $queries instanceof QueryBus && $connection instanceof Connection);
        [$this->commands, $this->queries, $this->connection] = [$commands, $queries, $connection];
        $this->connection->executeStatement("DELETE FROM admin.setting WHERE key LIKE 'auth.%'");
        $this->commands->dispatch(new RegisterUser(self::ID, 'camille@exemple.test', self::PASSWORD, 'client', 'Camille', 'Exemple'));
    }

    private function registeredEventHandled(string $userId = self::ID): void
    {
        $handler = self::getContainer()->get(SendEmailVerificationOnRegistration::class);
        \assert($handler instanceof SendEmailVerificationOnRegistration);
        $handler(new UserRegistered($userId, 'client', new \DateTimeImmutable()));
    }

    /** @return list<Email> */
    private function mails(): array
    {
        return array_values(array_filter(self::getMailerMessages(), static fn (object $m): bool => $m instanceof Email));
    }

    private function lastToken(): string
    {
        $mails = $this->mails();
        $last = end($mails);
        self::assertInstanceOf(Email::class, $last);
        self::assertSame(1, preg_match('#/verification-email/([A-Za-z0-9_-]{43})#', (string) $last->getTextBody(), $m));

        return $m[1];
    }

    private function state(string $token): EmailTokenState
    {
        $state = $this->queries->ask(new EmailTokenStatus($token));
        self::assertInstanceOf(EmailTokenState::class, $state);

        return $state;
    }

    private function scalar(string $sql): string
    {
        $value = $this->connection->fetchOne($sql);

        return \is_int($value) || \is_bool($value) ? var_export($value, true) : DbalValue::string($value);
    }

    public function testRegistrationSendsAHashedSingleUseLink(): void
    {
        $this->registeredEventHandled();

        $mails = $this->mails();
        self::assertCount(1, $mails);
        self::assertSame('camille@exemple.test', $mails[0]->getTo()[0]->getAddress());
        self::assertSame('Confirmez votre adresse e-mail', $mails[0]->getSubject());
        self::assertSame('E-01', $mails[0]->getHeaders()->getHeaderBody('X-TrouveMoi-Template'));
        $token = $this->lastToken();
        self::assertStringContainsString('/verification-email/'.$token, (string) $mails[0]->getHtmlBody());
        self::assertSame(hash('sha256', $token), $this->scalar('SELECT token_hash FROM identity.email_verification_token'));
        self::assertSame('0', $this->scalar("SELECT count(*) FROM identity.email_verification_token WHERE token_hash = '".$token."'"), 'jeton en clair jamais stocké');
        self::assertSame(EmailTokenState::Valid, $this->state($token));

        $this->commands->dispatch(new ConfirmEmail($token, '203.0.113.7'));

        self::assertSame('active', $this->scalar("SELECT status FROM identity.user_account WHERE id = '".self::ID."'"));
        self::assertSame(EmailTokenState::AlreadyVerified, $this->state($token));
        self::assertSame('1', $this->scalar("SELECT count(*) FROM admin.audit_log WHERE action = 'identity.email_verified'"));
        $this->expectException(InvalidEmailToken::class);
        $this->commands->dispatch(new ConfirmEmail($token));
    }

    public function testExpiredAndMalformedLinks(): void
    {
        $this->registeredEventHandled();
        $token = $this->lastToken();
        $this->connection->executeStatement("UPDATE identity.email_verification_token SET created_at = now() - interval '2 days', expires_at = now() - interval '1 day'");

        self::assertSame(EmailTokenState::Expired, $this->state($token));
        self::assertSame(EmailTokenState::Invalid, $this->state('court'));
        self::assertSame(EmailTokenState::Invalid, $this->state(str_repeat('A', 43)));
        try {
            $this->commands->dispatch(new ConfirmEmail($token));
            self::fail('Lien expiré accepté');
        } catch (InvalidEmailToken $e) {
            self::assertSame(EmailTokenState::Expired, $e->state);
        }

        $this->commands->dispatch(new ResendEmailVerification(previousToken: $token));
        $fresh = $this->lastToken();
        self::assertNotSame($token, $fresh);
        self::assertSame(EmailTokenState::Valid, $this->state($fresh));
        self::assertSame(EmailTokenState::Invalid, $this->state($token), 'ancien lien révoqué');
    }

    public function testResendRespectsTheCooldownAndRevealsNothing(): void
    {
        $this->registeredEventHandled();
        $this->commands->dispatch(new ResendEmailVerification(email: 'camille@exemple.test'));
        self::assertCount(1, $this->mails(), 'délai de 60 s non écoulé');

        $this->connection->executeStatement("UPDATE identity.email_verification_token SET created_at = now() - interval '2 minutes'");
        $this->commands->dispatch(new ResendEmailVerification(email: 'CAMILLE@exemple.test'));
        self::assertCount(2, $this->mails());

        $this->commands->dispatch(new ResendEmailVerification(email: 'inconnu@exemple.test'));
        $this->commands->dispatch(new ResendEmailVerification(email: 'pas-une-adresse'));
        self::assertCount(2, $this->mails(), 'adresse inconnue : rien envoyé, aucune erreur');
    }

    public function testEmailChangeIsConfirmedByTheNewAddressAndAlertsTheOldOne(): void
    {
        try {
            $this->commands->dispatch(new RequestEmailChange(self::ID, 'mauvais', 'nouvelle@exemple.test'));
            self::fail('Mot de passe non vérifié');
        } catch (InvalidCurrentPassword) {
        }

        $this->commands->dispatch(new RequestEmailChange(self::ID, self::PASSWORD, 'Nouvelle@Exemple.test'));
        $mails = $this->mails();
        self::assertCount(1, $mails);
        self::assertSame('Nouvelle@exemple.test', $mails[0]->getTo()[0]->getAddress());
        self::assertSame('Confirmez votre nouvelle adresse e-mail', $mails[0]->getSubject());
        self::assertSame('camille@exemple.test', $this->scalar("SELECT email FROM identity.user_account WHERE id = '".self::ID."'"), 'inchangé avant confirmation');

        $this->commands->dispatch(new ConfirmEmail($this->lastToken()));

        self::assertSame('Nouvelle@exemple.test|active', $this->scalar("SELECT email || '|' || status FROM identity.user_account WHERE id = '".self::ID."'"));
        $alert = $this->mails()[1] ?? null;
        self::assertInstanceOf(Email::class, $alert);
        self::assertSame('camille@exemple.test', $alert->getTo()[0]->getAddress());
        self::assertSame('E-06', $alert->getHeaders()->getHeaderBody('X-TrouveMoi-Template'));
    }

    public function testEmailChangeToATakenAddressOnlyWarnsItsOwner(): void
    {
        $this->commands->dispatch(new RegisterUser(self::OTHER, 'dominique@exemple.test', self::PASSWORD, 'client', 'Dominique', 'Exemple'));

        $this->commands->dispatch(new RequestEmailChange(self::ID, self::PASSWORD, 'dominique@exemple.test'));

        $mails = $this->mails();
        self::assertCount(1, $mails);
        self::assertSame('Vous avez déjà un compte TrouveMoi', $mails[0]->getSubject());
        self::assertSame('0', $this->scalar("SELECT count(*) FROM identity.email_verification_token WHERE purpose = 'email_change'"));
    }

    public function testRegistrationAttemptOnExistingAccountWarnsTheHolder(): void
    {
        $handler = self::getContainer()->get(NotifyExistingAccountOnRegistrationAttempt::class);
        \assert($handler instanceof NotifyExistingAccountOnRegistrationAttempt);

        $handler(new RegistrationAttemptedOnExistingAccount(self::ID, new \DateTimeImmutable()));

        $mails = $this->mails();
        self::assertCount(1, $mails);
        self::assertSame('camille@exemple.test', $mails[0]->getTo()[0]->getAddress());
        self::assertStringContainsString('Vous en avez déjà un', (string) $mails[0]->getTextBody());
        self::assertStringContainsString('#0057b8', (string) $mails[0]->getHtmlBody(), 'palette du gabarit commun (EmailPalette)');
    }
}
