<?php

declare(strict_types=1);

namespace App\Billing\Application\Credit;

use App\Shared\Application\Audit\AuditTrail;
use App\Shared\Application\Bus\EventHandler;
use App\Shared\Application\Settings\SettingKey;
use App\Shared\Application\Settings\SettingsProvider;
use App\Shared\Domain\Audit\AuditRecord;
use App\Shared\Domain\Event\Integration\ProfessionalAccountVerified;
use App\Shared\Domain\Identifier\IdGenerator;

/**
 * RG-044 : le crédit gratuit (`credits.free_on_signup`) est attribué à la vérification du compte — jamais à la création —
 * et une seule fois par artisan (index unique : une suspension levée ou un événement rejoué n'en redonne pas).
 * Validité : `credits.free_validity_days` (vide = illimitée).
 */
final readonly class GrantFreeCredit implements EventHandler
{
    public function __construct(
        private CreditLedger $ledger,
        private SettingsProvider $settings,
        private AuditTrail $audit,
        private IdGenerator $ids,
    ) {
    }

    public function __invoke(ProfessionalAccountVerified $event): void
    {
        $amount = $this->settings->int(SettingKey::CreditsFreeOnSignup);
        if ($amount <= 0) {
            return;
        }
        $validity = $this->settings->nullableInt(SettingKey::CreditsFreeValidityDays);
        $expiresAt = null === $validity ? null : $event->at->modify(\sprintf('+%d days', $validity));
        if (!$this->ledger->grantFree($this->ids->next(), $event->profileId, $event->userId, $amount, $expiresAt, $event->at)) {
            return;
        }
        $this->audit->record(new AuditRecord(
            id: $this->ids->next(),
            occurredAt: $event->at,
            actorType: 'system',
            actorId: null,
            action: 'billing.free_credit_granted',
            subjectType: 'artisan_profile',
            subjectId: $event->profileId,
            context: ['amount' => $amount, 'expires' => $expiresAt?->format('Y-m-d') ?? 'never'],
        ));
    }
}
