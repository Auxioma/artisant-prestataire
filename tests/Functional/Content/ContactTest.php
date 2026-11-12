<?php

declare(strict_types=1);

namespace App\Tests\Functional\Content;

use App\Shared\Infrastructure\Persistence\DbalValue;
use App\Tests\Support\Fake\AcceptingHumanVerification;
use App\Tests\Support\StorageSamples;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\Mime\Email;

/** P16 : contrôles, pièce jointe chiffrée, accusé E-90 et alerte au support I-03, limite de 3 envois par heure ; P12. */
final class ContactTest extends WebTestCase
{
    private const string MESSAGE = 'Bonjour, je souhaite modifier l’adresse d’intervention de ma demande (message fictif de test).';

    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->client->disableReboot();
        $this->client->setServerParameter('HTTP_ORIGIN', 'http://localhost');
    }

    protected function tearDown(): void
    {
        StorageSamples::cleanup();
        parent::tearDown();
    }

    public function testMessageIsStoredAcknowledgedAndForwarded(): void
    {
        $crawler = $this->client->request('GET', '/contact?motif=Partenariat');
        self::assertResponseIsSuccessful();
        self::assertSame('Partenariat', $crawler->filter('#reason option[selected]')->text(), 'motif pré-sélectionné depuis le lien');
        self::assertCount(1, $crawler->filter('.cf-turnstile[data-action="contact"]'));

        $path = StorageSamples::file(StorageSamples::pdf());
        $this->client->request('POST', '/contact', $this->form(['request_reference' => 'TM-2026-0001']), ['attachment' => new UploadedFile($path, 'capture.pdf', 'application/pdf', null, true)]);
        self::assertResponseRedirects('/contact?envoye=1', 303);
        self::assertEmailCount(2);
        $ack = self::getMailerMessage(0);
        $alert = self::getMailerMessage(1);
        self::assertInstanceOf(Email::class, $ack);
        self::assertInstanceOf(Email::class, $alert);
        self::assertSame('E-90', $ack->getHeaders()->getHeaderBody('X-TrouveMoi-Template'));
        self::assertSame('camille.contact@exemple.test', $ack->getTo()[0]->getAddress());
        self::assertSame('I-03', $alert->getHeaders()->getHeaderBody('X-TrouveMoi-Template'));
        self::assertStringContainsString('TM-2026-0001', (string) $alert->getTextBody());
        self::assertStringContainsString('Pièce jointe : oui', (string) $alert->getTextBody());

        $crawler = $this->client->followRedirect();
        self::assertSelectorTextContains('.tm-contact__success', 'Message envoyé.');
        self::assertSame('contact_submit', $crawler->filter('.tm-contact__success')->attr('data-analytics-auto-event-value'));
        $connection = self::getContainer()->get(Connection::class);
        \assert($connection instanceof Connection);
        self::assertSame('contact_attachment', DbalValue::string($connection->fetchOne('SELECT f.purpose FROM content.contact_message m JOIN shared.stored_file f ON f.id = m.attachment_file_id')));
        self::assertMatchesRegularExpression('/^CT-[0-9A-F]{8}$/', DbalValue::string($connection->fetchOne('SELECT reference FROM content.contact_message')));
    }

    public function testValidationAndRateLimit(): void
    {
        $crawler = $this->client->request('POST', '/contact', $this->form(['message' => 'Trop court']));
        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('#message_error', 'Votre message doit contenir au moins 20 caractères.');
        self::assertSame('camille.contact@exemple.test', $crawler->filter('#email')->attr('value'), 'saisie conservée');

        $this->client->request('POST', '/contact', $this->form(['consent' => '']));
        self::assertSelectorTextContains('#consent_error', 'Merci d’accepter');
        $this->client->request('POST', '/contact', $this->form(['reason' => 'Motif inventé']));
        self::assertSelectorTextContains('#reason_error', 'Choisissez un motif.');

        $this->client->request('POST', '/contact', $this->form());
        self::assertResponseStatusCodeSame(429, 'quatrième envoi dans l’heure (3 par IP)');
        self::assertSelectorTextContains('.tm-contact__form', 'réessayer dans une heure');
    }

    public function testHowItWorksExplainsTheRanking(): void
    {
        $crawler = $this->client->request('GET', '/comment-ca-marche');

        self::assertResponseIsSuccessful();
        self::assertCount(2, $crawler->filter('[role="tabpanel"]'));
        self::assertStringContainsString('jusqu’à 3 devis', $crawler->filter('#particuliers')->text());
        self::assertStringContainsString('100 devis offerts', $crawler->filter('#professionnels')->text());
        self::assertStringContainsString('métier recherché exercé à titre principal : 30 %', $crawler->filter('#classement')->text());
    }

    /**
     * @param array<string, string> $override
     *
     * @return array<string, string>
     */
    private function form(array $override = []): array
    {
        return $override + [
            '_token' => 'csrf-token',
            'reason' => 'Question sur une demande ou un devis',
            'name' => 'Camille Exemple',
            'email' => 'camille.contact@exemple.test',
            'phone' => '06 11 22 33 44',
            'message' => self::MESSAGE,
            'consent' => '1',
            'cf-turnstile-response' => AcceptingHumanVerification::HUMAN,
        ];
    }
}
