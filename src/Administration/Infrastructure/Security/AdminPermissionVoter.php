<?php

declare(strict_types=1);

namespace App\Administration\Infrastructure\Security;

use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Vote;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

/**
 * ADR-0013 : les contrôles d'accès du back-office portent sur des permissions `admin.<module>.<action>`, accordées
 * par le rôle de l'administrateur (BO14.2) — jamais sur des rôles.
 *
 * @extends Voter<string, mixed>
 */
final class AdminPermissionVoter extends Voter
{
    protected function supports(string $attribute, mixed $subject): bool
    {
        return 1 === preg_match('/^admin\.[a-z]+\.(view|create|edit|delete|export)$/', $attribute);
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token, ?Vote $vote = null): bool
    {
        $user = $token->getUser();

        return $user instanceof AdminSecurityUser && $user->can($attribute);
    }
}
