<?php

declare(strict_types=1);

namespace App\Billing\Application\Credit;

use App\Billing\Domain\Credit\CreditGauge;
use App\Shared\Application\Bus\QueryHandler;
use App\Shared\Application\Settings\SettingKey;
use App\Shared\Application\Settings\SettingsProvider;

final readonly class CreditGaugeOfHandler implements QueryHandler
{
    public function __construct(private CreditLedger $ledger, private SettingsProvider $settings)
    {
    }

    public function __invoke(CreditGaugeOf $query): ?CreditGauge
    {
        $balance = $this->ledger->balanceOfUser($query->userId);

        return null === $balance ? null : new CreditGauge($balance->total(), $balance->freeGranted, $this->settings->intList(SettingKey::CreditsAlertThresholds));
    }
}
