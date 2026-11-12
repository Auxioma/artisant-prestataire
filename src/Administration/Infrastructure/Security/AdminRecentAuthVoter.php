<?php

declare(strict_types=1);

namespace App\Administration\Infrastructure\Security;

use App\Administration\Application\Port\Reauthentication;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Vote;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

/**
 * `ADMIN_RECENT_AUTH` : administrateur ré-authentifié récemment (ADR-0013, ASVS 3.7.1).
 *
 * @extends Voter<string, mixed>
 */
final class AdminRecentAuthVoter extends Voter
{
    public function __construct(private readonly Reauthentication $reauth)
    {
    }

    protected function supports(string $attribute, mixed $subject): bool
    {
        return Reauthentication::ATTRIBUTE === $attribute;
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token, ?Vote $vote = null): bool
    {
        return $token->getUser() instanceof AdminSecurityUser && $this->reauth->isRecent();
    }
}
