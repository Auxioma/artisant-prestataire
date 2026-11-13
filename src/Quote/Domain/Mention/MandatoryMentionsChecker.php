<?php

declare(strict_types=1);

namespace App\Quote\Domain\Mention;

use App\Quote\Domain\Quote\LineType;
use App\Quote\Domain\Quote\QuoteContent;
use App\Quote\Domain\Quote\QuoteRuleViolation;

/**
 * RG-120 — contrôle des mentions obligatoires avant envoi. Liste contrôlée administrable (`quotes.mandatory_mentions`) ;
 * manquant → envoi bloqué avec la liste des éléments.
 */
final readonly class MandatoryMentionsChecker
{
    public const string MISSING_MESSAGE = 'Il manque des informations obligatoires : %s.';

    /**
     * @param list<MandatoryMention> $enabled
     *
     * @return list<MentionCheck>
     */
    public function check(QuoteContent $content, IssuerFacts $issuer, array $enabled): array
    {
        $checks = [];
        foreach ($enabled as $mention) {
            $checks[] = match ($mention) {
                MandatoryMention::CompanyIdentity => null !== $issuer->legalName && null !== $issuer->siret && null !== $issuer->address
                    ? new MentionCheck($mention, true, $mention->label())
                    : new MentionCheck($mention, false, $mention->label(), 'identification de l’entreprise (raison sociale, SIRET, adresse du siège)'),
                MandatoryMention::DateAndValidity => new MentionCheck($mention, true, \sprintf('Date et durée de validité (%d jours)', $content->conditions->validityDays)),
                MandatoryMention::DetailedBreakdown => $this->priced($content)
                    ? new MentionCheck($mention, true, $content->vatExempt ? 'Décompte détaillé, prix HT, « TVA non applicable, art. 293 B du CGI »' : $mention->label())
                    : new MentionCheck($mention, false, $mention->label(), 'décompte détaillé (au moins une ligne chiffrée)'),
                MandatoryMention::Insurance => $this->insurance($mention, $issuer),
                MandatoryMention::TravelCosts => $this->travel($content)
                    ? new MentionCheck($mention, true, $mention->label())
                    : new MentionCheck($mention, false, $mention->label(), 'frais de déplacement (ligne « Déplacement », même gratuite, ou mention dans les conditions)'),
                MandatoryMention::FreeQuote => new MentionCheck($mention, true, $mention->label()),
            };
        }

        return $checks;
    }

    /** @param list<MentionCheck> $checks */
    public function assertComplete(array $checks): void
    {
        $missing = array_values(array_filter(array_map(static fn (MentionCheck $c): ?string => $c->ok ? null : $c->missing, $checks)));
        if ([] !== $missing) {
            throw new QuoteRuleViolation(\sprintf(self::MISSING_MESSAGE, implode(', ', $missing)));
        }
    }

    private function priced(QuoteContent $content): bool
    {
        foreach ($content->lines as $line) {
            if ($line->type->isPriced()) {
                return true;
            }
        }

        return false;
    }

    private function travel(QuoteContent $content): bool
    {
        foreach ($content->lines as $line) {
            if (LineType::Travel === $line->type) {
                return true;
            }
        }
        foreach ([$content->conditions->specialConditions, $content->conditions->paymentTerms, $content->conditions->clientNote] as $text) {
            if (null !== $text && 1 === preg_match('/d[ée]placement/iu', $text)) {
                return true;
            }
        }

        return false;
    }

    private function insurance(MandatoryMention $mention, IssuerFacts $issuer): MentionCheck
    {
        if ($issuer->decennialRequired && !$issuer->decennialInsured) {
            return new MentionCheck($mention, false, 'Assurance décennale (métier soumis) : attestation à jour requise', 'assurance décennale');
        }
        if ($issuer->insuranceRequired && !$issuer->liabilityInsured && !$issuer->decennialInsured) {
            return new MentionCheck($mention, false, 'Assurance professionnelle : attestation à jour requise', 'assurance professionnelle');
        }

        return new MentionCheck($mention, true, $issuer->decennialRequired ? 'Assurance décennale (métier soumis)' : $mention->label());
    }
}
