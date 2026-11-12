<?php

declare(strict_types=1);

namespace App\Tests\Integration\Notification;

use App\Notification\Application\Notification;
use App\Notification\Application\Notifier;
use App\Notification\Infrastructure\Template\TemplateCatalogSync;
use App\Notification\UI\Twig\NotificationExtension;
use App\Shared\Infrastructure\Persistence\DbalValue;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Mime\Email;

/** CDC §6, annexe F, BO11 : modèles en base, bac à sable, canaux, journal d'envoi sans adresse en clair, in-app. */
final class NotificationDispatcherTest extends KernelTestCase
{
    private const string USER = '0190f5e4-0000-7000-8000-000000000b01';

    private Notifier $notifier;
    private Connection $connection;

    protected function setUp(): void
    {
        self::bootKernel();
        $notifier = self::getContainer()->get(Notifier::class);
        $connection = self::getContainer()->get(Connection::class);
        $sync = self::getContainer()->get(TemplateCatalogSync::class);
        \assert($notifier instanceof Notifier && $connection instanceof Connection && $sync instanceof TemplateCatalogSync);
        [$this->notifier, $this->connection] = [$notifier, $connection];
        $sync->sync();
    }

    /** @param array<string, scalar|\DateTimeInterface|null> $variables */
    private function e04(array $variables = []): void
    {
        $this->notifier->notify(new Notification('E-04', 'camille@exemple.test', self::USER, $variables + [
            'prenom' => 'Camille', 'date' => new \DateTimeImmutable('2026-11-12 10:00:00 UTC'),
            'lien_reinitialisation' => 'https://trouvemoi.test/mot-de-passe-oublie', 'lien_aide' => 'https://trouvemoi.test/contact',
        ]));
    }

    private function scalar(string $sql): string
    {
        $value = $this->connection->fetchOne($sql);

        return \is_int($value) || \is_bool($value) ? var_export($value, true) : DbalValue::string($value);
    }

    public function testCatalogHoldsTheWholeAnnexF(): void
    {
        self::assertSame('53', $this->scalar('SELECT count(*) FROM notification.template'), 'annexe F (52) + I-02');
        self::assertSame('E-01,E-03,E-04,E-05,E-06,E-20,E-21,E-22,E-23,E-24,E-25,I-01,I-02', $this->scalar("SELECT string_agg(code, ',' ORDER BY code) FROM notification.template WHERE active"));
        self::assertSame('{email,in_app,sms}', $this->scalar("SELECT channels FROM notification.template WHERE code = 'E-12'"));
    }

    public function testEmailAndInAppAreSentAndLoggedWithoutClearAddress(): void
    {
        $this->e04();

        $mail = self::getMailerMessage();
        self::assertInstanceOf(Email::class, $mail);
        self::assertSame('Votre mot de passe TrouveMoi a été modifié', $mail->getSubject());
        self::assertStringContainsString('le 12/11/2026 à 11:00', (string) $mail->getHtmlBody(), 'date au fuseau de Paris');
        self::assertStringContainsString('Toutes les autres sessions ont été fermées.', (string) $mail->getHtmlBody(), 'pré-en-tête');
        self::assertStringContainsString('— TrouveMoi.com', (string) $mail->getTextBody());

        self::assertSame('email:sent|in_app:sent', $this->scalar("SELECT string_agg(channel || ':' || status, '|' ORDER BY channel) FROM notification.delivery"));
        self::assertSame('c***@exemple.test', $this->scalar("SELECT recipient_masked FROM notification.delivery WHERE channel = 'email'"));
        self::assertSame('0', $this->scalar("SELECT count(*) FROM notification.delivery WHERE recipient_masked LIKE 'camille%'"));
        self::assertSame('1', $this->scalar("SELECT count(*) FROM notification.in_app WHERE user_id = '".self::USER."' AND body LIKE 'Votre mot de passe a été modifié le 12/11/2026%'"));

        $bell = self::getContainer()->get(NotificationExtension::class);
        \assert($bell instanceof NotificationExtension);
        self::assertSame(1, $bell->unread(self::USER));
        self::assertSame(0, $bell->unread(null));
    }

    public function testVariablesAreEscaped(): void
    {
        $this->e04(['prenom' => '<script>alert(1)</script>']);

        $mail = self::getMailerMessage();
        self::assertInstanceOf(Email::class, $mail);
        self::assertStringNotContainsString('<script>alert(1)</script>', (string) $mail->getHtmlBody());
        self::assertStringContainsString('&lt;script&gt;', (string) $mail->getHtmlBody());
    }

    public function testDisabledTemplateSendsNothing(): void
    {
        $this->connection->executeStatement("UPDATE notification.template SET active = false WHERE code = 'E-04'");

        $this->e04();

        self::assertEmailCount(0);
        self::assertSame('skipped', $this->scalar('SELECT status FROM notification.delivery'));
    }

    public function testUnsafeTemplatesAreRefusedBySandboxWithoutBreakingTheCaller(): void
    {
        // Variables globales de l'application inexistantes dans le bac à sable : `{{ app.request }}` y est vide.
        $this->connection->executeStatement("UPDATE notification.template SET body_html = '<p>{{ app.request }}x</p>', customized = true WHERE code = 'E-04'");
        $this->e04();
        $harmless = self::getMailerMessage();
        self::assertInstanceOf(Email::class, $harmless);
        self::assertStringContainsString('<p>x</p>', (string) $harmless->getHtmlBody());

        $this->connection->executeStatement('DELETE FROM notification.delivery');
        foreach (["{{ include('base.html.twig') }}", "{{ date.format('Y') }}", '{{ prenom|raw }}', '{% extends "base.html.twig" %}', '{{ source("x") }}'] as $i => $attack) {
            $this->connection->executeStatement("UPDATE notification.template SET body_html = :b WHERE code = 'E-04'", ['b' => $attack]);
            $this->e04();
            self::assertSame((string) ($i + 1), $this->scalar("SELECT count(*) FROM notification.delivery WHERE channel = 'email' AND status = 'failed'"), $attack);
        }
    }

    public function testSyncNeverOverwritesACustomizedTemplate(): void
    {
        $this->connection->executeStatement("UPDATE notification.template SET subject = 'Objet du BO', customized = true WHERE code = 'E-05'");
        $sync = self::getContainer()->get(TemplateCatalogSync::class);
        \assert($sync instanceof TemplateCatalogSync);

        $counts = $sync->sync();

        self::assertSame(1, $counts['customized']);
        self::assertSame('Objet du BO', $this->scalar("SELECT subject FROM notification.template WHERE code = 'E-05'"));
        self::assertSame('1', $this->scalar("SELECT count(*) FROM notification.template_version WHERE code = 'E-05'"));
    }
}
