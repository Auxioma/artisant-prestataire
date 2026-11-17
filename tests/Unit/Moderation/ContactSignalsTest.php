<?php

declare(strict_types=1);

namespace App\Tests\Unit\Moderation;

use App\Messaging\Application\Conversation\MessageRiskScorer;
use App\Shared\Application\Moderation\ContactSignals;
use App\Shared\Application\Settings\SettingKey;
use App\Shared\Domain\Text\ContactDetector;
use App\Tests\Support\InMemorySettingsProvider;
use PHPUnit\Framework\TestCase;

/** T-706b — BO05.3 : expressions de contournement (sans accent ni casse, mots entiers) et score de risque des messages. */
final class ContactSignalsTest extends TestCase
{
    private function signals(): ContactSignals
    {
        return new ContactSignals(new InMemorySettingsProvider()->set(SettingKey::ModerationContactKeywords, ['WhatsApp', 'mon numéro', 'hors plateforme']));
    }

    public function testKeywordsAreMatchedAsWholeWordsWithoutAccentsOrCase(): void
    {
        self::assertSame(['mon numéro'], $this->signals()->keywords('Je vous donne MON NUMERO demain'));
        self::assertSame(['WhatsApp'], $this->signals()->keywords('écrivez sur whatsapp.'));
        self::assertSame([], $this->signals()->keywords('Plateforme fiable, numéro de devis 12'));
        self::assertSame([ContactDetector::PHONE, ContactSignals::KEYWORD], $this->signals()->find('Payons hors plateforme : 06 12 34 56 78'));
    }

    public function testMessageScoreCountsContactsOnlyBeforeAcceptance(): void
    {
        $settings = new InMemorySettingsProvider()->set(SettingKey::ModerationContactKeywords, ['whatsapp'])->set(SettingKey::ModerationForbiddenTerms, ['arnaque']);
        $scorer = new MessageRiskScorer($settings, new ContactSignals($settings));

        self::assertSame(60, $scorer->score('Sur WhatsApp : 06 12 34 56 78', true));
        self::assertSame(0, $scorer->score('Sur WhatsApp : 06 12 34 56 78', false), 'coordonnées libres après acceptation (RG-080)');
        self::assertSame(50, $scorer->score('Ce devis est une arnaque', false));
        self::assertSame(15, $scorer->score('RÉPONDEZ-MOI VITE SVP MAINTENANT', false));
        self::assertTrue($scorer->exceedsThreshold(60));
        self::assertFalse($scorer->exceedsThreshold(30));
        self::assertSame('Détection automatique (score 60/100)', MessageRiskScorer::label(MessageRiskScorer::reason(60)));
        self::assertNull(MessageRiskScorer::label('spam'));
    }
}
