<?php

declare(strict_types=1);

namespace App\Billing\Application\EventHandler;

use App\Billing\Application\Alert\CreditAlertLog;
use App\Billing\Application\Alert\CreditAlertMailer;
use App\Billing\Domain\Credit\CreditAlertPolicy;
use App\Billing\Domain\Credit\CreditBucket;
use App\Billing\Domain\Credit\Event\CreditsDebited;
use App\Shared\Application\Bus\EventHandler;
use App\Shared\Application\Bus\QueryBus;
use App\Shared\Application\Identity\UserContact;
use App\Shared\Application\Identity\UserContactDetails;
use App\Shared\Application\Settings\SettingKey;
use App\Shared\Application\Settings\SettingsProvider;

/**
 * RG-105 / RG-106 — après un débit du crédit gratuit : E-42 pour chaque seuil de consommation franchi
 * (`credits.alert_thresholds`), E-42b quand le crédit gratuit est épuisé. Chaque alerte n'est envoyée qu'une fois
 * (journal à clé unique) ; les alertes du forfait (`credits.plan_alert_pct`) viendront avec les forfaits (lot 6).
 */
final readonly class NotifyCreditThresholds implements EventHandler
{
    public const string KIND_FREE = 'free';

    public function __construct(
        private CreditAlertPolicy $policy,
        private CreditAlertLog $log,
        private CreditAlertMailer $mailer,
        private QueryBus $queries,
        private SettingsProvider $settings,
    ) {
    }

    public function __invoke(CreditsDebited $event): void
    {
        if (CreditBucket::Free->value !== $event->bucket) {
            return;
        }
        $crossed = $this->policy->crossed($event->freeGranted, $event->bucketBefore, $event->bucketAfter, $this->settings->intList(SettingKey::CreditsAlertThresholds));
        $exhausted = 0 === $event->bucketAfter && $event->bucketBefore > 0;
        if ($exhausted && !\in_array(CreditAlertPolicy::EXHAUSTED, $crossed, true)) {
            $crossed[] = CreditAlertPolicy::EXHAUSTED;
        }
        if ([] === $crossed) {
            return;
        }
        $contact = $this->queries->ask(new UserContact($event->userId));
        if (!$contact instanceof UserContactDetails) {
            return;
        }
        // Le seuil le plus élevé suffit : un débit ne franchit qu'exceptionnellement deux seuils (dotation très faible).
        $threshold = max($crossed);
        if (!$this->log->record($event->artisanId, self::KIND_FREE, '', $threshold, $event->at)) {
            return;
        }
        if (CreditAlertPolicy::EXHAUSTED === $threshold) {
            $this->mailer->freeCreditExhausted($contact->email, $contact->firstName, $event->userId, $event->freeGranted, $event->totalAfter);

            return;
        }
        $this->mailer->thresholdReached($contact->email, $contact->firstName, $event->userId, $threshold, $event->bucketAfter, $event->freeGranted);
    }
}
