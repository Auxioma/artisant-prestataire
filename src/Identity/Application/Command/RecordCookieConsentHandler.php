<?php

declare(strict_types=1);

namespace App\Identity\Application\Command;

use App\Identity\Application\Port\ConsentLog;
use App\Identity\Domain\Consent\ConsentSource;
use App\Identity\Domain\Consent\CookieConsent;
use App\Shared\Application\Bus\CommandHandler;
use App\Shared\Application\Settings\SettingKey;
use App\Shared\Application\Settings\SettingsProvider;
use App\Shared\Domain\Exception\InvalidValue;
use App\Shared\Domain\Identifier\IdGenerator;
use App\Shared\Domain\Time\Clock;

/**
 * CDC §3.2 : enregistre la preuve du choix ; durée de validité = cookies.consent_months ;
 * seule la version courante de la politique (cookies.policy_version) est acceptée.
 */
final readonly class RecordCookieConsentHandler implements CommandHandler
{
    public function __construct(
        private ConsentLog $log,
        private SettingsProvider $settings,
        private IdGenerator $ids,
        private Clock $clock,
    ) {
    }

    public function __invoke(RecordCookieConsent $command): void
    {
        $currentVersion = $this->settings->int(SettingKey::CookiesPolicyVersion);
        if ($command->policyVersion !== $currentVersion) {
            throw InvalidValue::for('Consentement', 'version de politique obsolète');
        }
        $source = ConsentSource::tryFrom($command->source) ?? throw InvalidValue::for('Consentement', 'origine inconnue');
        $now = $this->clock->now();
        $months = $this->settings->int(SettingKey::CookiesConsentMonths);

        $consent = new CookieConsent($command->choices, $currentVersion, $source, $now, $now->modify(\sprintf('+%d months', $months)));
        $this->log->record($this->ids->next(), $command->visitorId, $command->userId, $consent, $command->ipHash);
    }
}
