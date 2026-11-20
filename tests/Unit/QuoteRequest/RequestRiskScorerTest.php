<?php

declare(strict_types=1);

namespace App\Tests\Unit\QuoteRequest;

use App\QuoteRequest\Application\Port\RequestRiskInput;
use App\QuoteRequest\Application\Port\RequestRiskSignals;
use App\QuoteRequest\Application\Risk\RequestRiskScorer;
use App\Shared\Application\Moderation\ContactSignals;
use App\Shared\Application\Settings\SettingKey;
use App\Tests\Support\InMemorySettingsProvider;
use PHPUnit\Framework\TestCase;

/** RG-063 : signaux (termes interdits, liens, coordonnées, texte identique, rafale), points paramétrables, plafond 100. */
final class RequestRiskScorerTest extends TestCase
{
    private const string CLIENT = '0190f5e4-0000-7000-8000-0000000e5001';
    private const string JOB = '0190f5e4-0000-7000-8000-0000000e5002';

    public function testCleanRequestScoresZero(): void
    {
        $risk = $this->scorer()->assess($this->input('Fuite sous l’évier de la cuisine depuis ce matin (fictif).'));
        self::assertSame(0, $risk->score);
        self::assertSame([], $risk->signals);
    }

    public function testSignalsAddUpAndAreCapped(): void
    {
        $settings = new InMemorySettingsProvider()->set(SettingKey::ModerationForbiddenTerms, ['arnaque']);
        $risk = $this->scorer($settings, sameText: true, recent: 5)->assess($this->input('Ce n’est pas une arnaque, voir www.exemple-fictif.fr', ['precision' => 'Écrivez à test@exemple.test']));
        self::assertSame(['forbidden_term', 'link', 'contact', 'duplicate_text', 'velocity'], $risk->signals);
        self::assertSame(100, $risk->score, '70 + 40 + 40 + 40 + 40, plafonné');

        $risk = $this->scorer($settings)->assess($this->input('Je cherche un artisan pour une arnaqueuse de serrure (fictif).'));
        self::assertSame([], $risk->signals, 'mot entier seulement');
    }

    public function testPointsAndVelocityLimitAreSettings(): void
    {
        $settings = new InMemorySettingsProvider()->set(SettingKey::ModerationRequestPointsVelocity, 15)->set(SettingKey::ModerationRequestVelocityPerDay, 10);
        self::assertSame(0, $this->scorer($settings, recent: 5)->assess($this->input('Peinture du salon et du couloir (demande fictive).'))->score, 'sous la limite de rafale');
        self::assertSame(15, $this->scorer($settings, recent: 10)->assess($this->input('Peinture du salon et du couloir (demande fictive).'))->score);
    }

    private function scorer(?InMemorySettingsProvider $settings = null, bool $sameText = false, int $recent = 0): RequestRiskScorer
    {
        $history = new class($sameText, $recent) implements RequestRiskSignals {
            public function __construct(private bool $sameText, private int $recent)
            {
            }

            public function countByClientSince(string $clientId, \DateTimeImmutable $since): int
            {
                return $this->recent;
            }

            public function countByIpSince(string $ipHash, \DateTimeImmutable $since): int
            {
                return 0;
            }

            public function hasSameDescriptionSince(string $description, \DateTimeImmutable $since): bool
            {
                return $this->sameText;
            }
        };

        $settings ??= new InMemorySettingsProvider();

        return new RequestRiskScorer($history, $settings, new ContactSignals($settings));
    }

    /** @param array<string, string|list<string>> $answers */
    private function input(string $description, array $answers = []): RequestRiskInput
    {
        return new RequestRiskInput(self::CLIENT, self::JOB, $description, $answers, 'empreinte-ip', new \DateTimeImmutable('2026-11-18 10:00'));
    }
}
