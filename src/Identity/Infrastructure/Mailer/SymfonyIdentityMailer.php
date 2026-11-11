<?php

declare(strict_types=1);

namespace App\Identity\Infrastructure\Mailer;

use App\Identity\Application\Port\IdentityMailer;
use App\Shared\Domain\ValueObject\Email;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Address;
use Symfony\Component\Routing\Exception\RouteNotFoundException;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Envoi direct (Symfony Mailer, Mailpit en développement). Liens absolus construits sur DEFAULT_URI (le worker n'a pas
 * de requête HTTP). Aucune adresse ni jeton dans les journaux : seul le type d'e-mail est tracé par le Mailer.
 */
final readonly class SymfonyIdentityMailer implements IdentityMailer
{
    public function __construct(
        private MailerInterface $mailer,
        private UrlGeneratorInterface $urls,
        #[Autowire('%env(MAILER_SENDER)%')]
        private string $sender,
    ) {
    }

    public function sendEmailVerification(Email $to, string $firstName, string $token, int $ttlHours, bool $emailChange): void
    {
        $this->send($to, $emailChange ? 'Confirmez votre nouvelle adresse e-mail' : 'Confirmez votre adresse e-mail', 'email/identity/verify_email', 'E-01', [
            'first_name' => $firstName,
            'link' => $this->urls->generate('identity_verify_email', ['token' => $token], UrlGeneratorInterface::ABSOLUTE_URL),
            'ttl_hours' => $ttlHours,
            'email_change' => $emailChange,
        ]);
    }

    public function sendEmailChangedAlert(Email $oldAddress, string $firstName, \DateTimeImmutable $at): void
    {
        $this->send($oldAddress, 'L’adresse e-mail de votre compte a été modifiée', 'email/identity/email_changed_alert', 'E-06', [
            'first_name' => $firstName,
            'changed_at' => $at,
            'help_link' => $this->absolute('content_contact'),
        ]);
    }

    public function sendNewDeviceAlert(Email $to, string $firstName, string $device, \DateTimeImmutable $at): void
    {
        $this->send($to, 'Nouvelle connexion à votre compte TrouveMoi', 'email/identity/new_device', 'E-05', [
            'first_name' => $firstName,
            'device' => $device,
            'logged_at' => $at,
            'reset_link' => $this->absolute('identity_password_forgotten'),
        ]);
    }

    public function sendExistingAccountNotice(Email $to, string $firstName): void
    {
        $this->send($to, 'Vous avez déjà un compte TrouveMoi', 'email/identity/existing_account', 'A02-existant', [
            'first_name' => $firstName,
            'login_link' => $this->absolute('identity_login'),
            'reset_link' => $this->absolute('identity_password_forgotten'),
        ]);
    }

    /** @param array<string, mixed> $context */
    private function send(Email $to, string $subject, string $template, string $code, array $context): void
    {
        $email = new TemplatedEmail()
            ->from(Address::create($this->sender))
            ->to($to->value)
            ->subject($subject)
            ->htmlTemplate($template.'.html.twig')
            ->textTemplate($template.'.txt.twig')
            ->context($context + ['subject' => $subject]);
        $email->getHeaders()->addTextHeader('X-TrouveMoi-Template', $code);
        $this->mailer->send($email);
    }

    /** Lien vers une page pas encore livrée : racine du site à défaut (aucun lien mort dans un e-mail). */
    private function absolute(string $route): string
    {
        try {
            return $this->urls->generate($route, [], UrlGeneratorInterface::ABSOLUTE_URL);
        } catch (RouteNotFoundException) {
            $context = $this->urls->getContext();
            $port = 'https' === $context->getScheme() ? $context->getHttpsPort() : $context->getHttpPort();
            $default = 'https' === $context->getScheme() ? 443 : 80;

            return \sprintf('%s://%s%s/', $context->getScheme(), $context->getHost(), $port === $default ? '' : ':'.$port);
        }
    }
}
