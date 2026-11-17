<?php

declare(strict_types=1);

namespace App\Tests\Functional\Billing;

use App\Billing\Application\Webhook\HandleStripeEvent;
use App\Shared\Infrastructure\Persistence\DbalValue;
use App\Tests\Unit\Integration\StripeWebhookVerifierTest;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;

/** ADR-0010 : POST /webhooks/stripe — signature obligatoire, enregistrement unique, traitement confié au transport dédié. */
final class StripeWebhookEndpointTest extends WebTestCase
{
    private const string PAYLOAD = '{"id":"evt_FICTIF_ENDPOINT","object":"event","type":"invoice.paid","livemode":false,"data":{"object":{"id":"in_FICTIF"}}}';

    public function testSignedEventIsRecordedOnceAndQueued(): void
    {
        $client = self::createClient();
        $client->disableReboot();
        $headers = ['CONTENT_TYPE' => 'application/json', 'HTTP_STRIPE_SIGNATURE' => StripeWebhookVerifierTest::sign(self::PAYLOAD, 'secret-webhook-fictif')];

        $client->request('POST', '/webhooks/stripe', server: $headers, content: self::PAYLOAD);
        self::assertResponseIsSuccessful();
        self::assertSame([HandleStripeEvent::class], $this->queued());
        // Stripe renvoie le même événement : accepté (200) mais ni réenregistré ni retraité.
        $client->request('POST', '/webhooks/stripe', server: $headers, content: self::PAYLOAD);
        self::assertResponseIsSuccessful();
        self::assertSame([], $this->queued());

        $connection = self::getContainer()->get(Connection::class);
        \assert($connection instanceof Connection);
        self::assertSame(1, DbalValue::int($connection->fetchOne("SELECT count(*) FROM billing.processed_stripe_event WHERE event_id = 'evt_FICTIF_ENDPOINT' AND status = 'received'")));
        self::assertNull($client->getResponse()->headers->get('Set-Cookie'), 'aucune session');
    }

    /** @return list<class-string> messages confiés au transport stripe_webhooks pendant la dernière requête */
    private function queued(): array
    {
        $transport = self::getContainer()->get('messenger.transport.stripe_webhooks');
        \assert($transport instanceof InMemoryTransport);

        return array_values(array_map(static fn ($envelope): string => $envelope->getMessage()::class, $transport->getSent()));
    }

    public function testUnsignedEventIsRejected(): void
    {
        $client = self::createClient();
        $client->request('POST', '/webhooks/stripe', server: ['CONTENT_TYPE' => 'application/json', 'HTTP_STRIPE_SIGNATURE' => 't=1,v1=faux'], content: self::PAYLOAD);
        self::assertResponseStatusCodeSame(400);
        $client->request('POST', '/webhooks/stripe', server: ['CONTENT_TYPE' => 'application/json'], content: '');
        self::assertResponseStatusCodeSame(400);
    }
}
