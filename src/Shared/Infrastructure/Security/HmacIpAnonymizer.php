<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Security;

use App\Shared\Application\Security\IpAnonymizer;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/** HMAC-SHA256 avec un secret serveur : non réversible sans le secret, mais stable pour détecter les abus. */
final readonly class HmacIpAnonymizer implements IpAnonymizer
{
    public function __construct(#[Autowire('%kernel.secret%')] private string $secret)
    {
    }

    public function hash(?string $ip): ?string
    {
        if (null === $ip || '' === $ip || false === filter_var($ip, \FILTER_VALIDATE_IP)) {
            return null;
        }

        return hash_hmac('sha256', $ip, 'ip-anonymizer|'.$this->secret);
    }
}
