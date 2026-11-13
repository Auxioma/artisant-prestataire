<?php

declare(strict_types=1);

namespace App\QuoteRequest\Infrastructure\Security;

use App\QuoteRequest\Application\Pro\ProRequestAccess;
use App\Shared\Application\Security\CurrentUser;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Vote;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

/**
 * PR02 / PR03 — `QUOTE_REQUEST_VIEW` : demande reçue par l'artisan (ou aperçu Q15) ; `QUOTE_REQUEST_ANSWER` : artisan
 * vérifié, demande reçue, ouverte, avec des places (RG-111). Sujet : identifiant de la demande.
 *
 * @extends Voter<string, string>
 */
final class QuoteRequestVoter extends Voter
{
    public const string VIEW = ProRequestAccess::VIEW;
    public const string ANSWER = ProRequestAccess::ANSWER;

    public function __construct(private readonly ProRequestAccess $access, private readonly CurrentUser $user)
    {
    }

    protected function supports(string $attribute, mixed $subject): bool
    {
        return \in_array($attribute, [self::VIEW, self::ANSWER], true) && \is_string($subject);
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token, ?Vote $vote = null): bool
    {
        $userId = $this->user->id();
        if (null === $userId || !$this->user->isArtisan() || !\is_string($subject)) {
            return false;
        }

        return self::VIEW === $attribute ? $this->access->canView($userId, $subject) : $this->access->canAnswer($userId, $subject);
    }
}
