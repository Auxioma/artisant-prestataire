<?php

declare(strict_types=1);

namespace App\Identity\UI\Twig;

use App\Shared\Application\Security\ImpersonationContext;
use App\Shared\Application\Security\ImpersonationSession;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/** Bandeau de connexion assistée (ADR-0013) : `tm_impersonation()` renvoie la session en cours ou null. */
final class ImpersonationExtension extends AbstractExtension
{
    public function __construct(private readonly ImpersonationContext $impersonation)
    {
    }

    public function getFunctions(): array
    {
        return [new TwigFunction('tm_impersonation', $this->current(...))];
    }

    public function current(): ?ImpersonationSession
    {
        return $this->impersonation->current();
    }
}
