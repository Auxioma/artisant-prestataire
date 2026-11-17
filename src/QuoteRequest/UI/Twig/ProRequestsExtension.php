<?php

declare(strict_types=1);

namespace App\QuoteRequest\UI\Twig;

use App\QuoteRequest\Application\Port\ProRequestReader;
use App\QuoteRequest\Application\Pro\ProRequestAccess;
use App\Shared\Application\Security\CurrentUser;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/** `tm_pro_unread_requests()` : compteur « Mes demandes » du menu de l'espace pro (demandes non lues, RG-111). */
final class ProRequestsExtension extends AbstractExtension
{
    public function __construct(private readonly ProRequestAccess $access, private readonly ProRequestReader $reader, private readonly CurrentUser $user)
    {
    }

    public function getFunctions(): array
    {
        return [new TwigFunction('tm_pro_unread_requests', $this->unread(...))];
    }

    public function unread(): int
    {
        $userId = $this->user->id();
        $artisan = null === $userId || !$this->user->isArtisan() ? null : $this->access->artisan($userId);

        return null === $artisan || !$artisan->verified ? 0 : $this->reader->stats($artisan)['unread'];
    }
}
