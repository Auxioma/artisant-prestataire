<?php

declare(strict_types=1);

namespace App\Identity\Domain\User;

use App\Shared\Domain\ValueObject\Email;

interface UserRepository
{
    /** @throws IdentityRuleViolation */
    public function get(UserId $id): User;

    public function find(UserId $id): ?User;

    /** Compte non anonymisé portant cet e-mail (forme canonique). */
    public function findByEmail(Email $email): ?User;

    public function add(User $user): void;

    /**
     * Date de dernière connexion, écrite sans contrôle de version : deux connexions simultanées du même compte (double
     * clic, plusieurs appareils) sont légitimes et ne doivent pas échouer ; la date la plus récente est conservée.
     */
    public function recordLogin(UserId $id, \DateTimeImmutable $at): void;

    /**
     * RG-090 : comptes dont la suppression a été demandée avant `$before` (délai de rétractation écoulé).
     *
     * @return list<UserId>
     */
    public function dueForErasure(\DateTimeImmutable $before): array;

    /**
     * CDC §11 — comptes actifs sans connexion (ni création) depuis $before, pas encore relancés ; les artisans dont
     * l'abonnement est en cours en sont exclus (la relation continue).
     *
     * @return list<UserId>
     */
    public function inactiveSince(\DateTimeImmutable $before): array;

    /**
     * Comptes relancés avant $before et restés sans connexion depuis.
     *
     * @return list<UserId>
     */
    public function unansweredInactivityNotices(\DateTimeImmutable $before): array;
}
