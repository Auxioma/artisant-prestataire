<?php

declare(strict_types=1);

namespace App\Notification\Infrastructure\Mailer;

use App\Notification\Application\Port\EmailSender;
use App\Notification\Application\RenderedEmail;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use Symfony\Component\Mailer\Transport\TransportInterface;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;

/**
 * Symfony Mailer : Brevo en production (MAILER_DSN=brevo+api://…, secret), Mailpit en développement. Envoi direct par
 * le transport pour obtenir l'identifiant du fournisseur (rapprochement des rebonds et plaintes, BO11.2).
 */
final readonly class SymfonyMailerEmailSender implements EmailSender
{
    public function __construct(
        #[Autowire(service: 'mailer.transports')]
        private TransportInterface $transport,
        #[Autowire('%env(MAILER_SENDER)%')]
        private string $sender,
    ) {
    }

    public function send(string $to, RenderedEmail $email, string $code, string $deliveryId): ?string
    {
        $message = new Email()
            ->from(Address::create($this->sender))
            ->to($to)
            ->subject($email->subject)
            ->html($email->html)
            ->text($email->text);
        $headers = $message->getHeaders();
        $headers->addTextHeader('X-TrouveMoi-Template', $code);
        $headers->addTextHeader('X-TrouveMoi-Delivery', $deliveryId);

        try {
            $sent = $this->transport->send($message);
        } catch (TransportExceptionInterface $e) {
            throw new \RuntimeException('Envoi refusé : '.$e->getMessage(), 0, $e);
        }

        return $sent?->getMessageId();
    }
}
