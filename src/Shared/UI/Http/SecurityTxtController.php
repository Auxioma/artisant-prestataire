<?php

declare(strict_types=1);

namespace App\Shared\UI\Http;

use Psr\Clock\ClockInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * SECURITY.md §7 (T-905a) — `security.txt` (RFC 9116) : contact pour signaler une vulnérabilité, date d'expiration
 * glissante (moins d'un an, recalculée à chaque appel), langues, adresse canonique. Adresse : `SECURITY_CONTACT`.
 */
final readonly class SecurityTxtController
{
    /** RFC 9116 §2.5.5 : expiration recommandée à moins d'un an. */
    private const string VALIDITY = '+180 days';

    public function __construct(
        private ClockInterface $clock,
        private UrlGeneratorInterface $urls,
        #[Autowire(env: 'SECURITY_CONTACT')]
        private string $contact,
    ) {
    }

    #[Route('/.well-known/security.txt', name: 'security_txt', methods: ['GET'])]
    public function __invoke(): Response
    {
        $expires = $this->clock->now()->modify(self::VALIDITY)->setTime(0, 0)->setTimezone(new \DateTimeZone('UTC'));
        $body = implode("\n", [
            'Contact: mailto:'.$this->contact,
            'Expires: '.$expires->format('Y-m-d\TH:i:s\Z'),
            'Preferred-Languages: fr, en',
            'Canonical: '.$this->urls->generate('security_txt', [], UrlGeneratorInterface::ABSOLUTE_URL),
        ])."\n";

        return new Response($body, Response::HTTP_OK, ['Content-Type' => 'text/plain; charset=utf-8']);
    }
}
