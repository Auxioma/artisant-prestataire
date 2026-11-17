<?php

declare(strict_types=1);

namespace App\Quote\Domain\Quote;

/** Contenu d'une version de devis (PR04.1 à PR04.3) : objet, lignes, remise globale, acompte, conditions, régime de TVA. */
final readonly class QuoteContent
{
    public const int SUBJECT_MAX = 200;
    public const int LINES_MAX = 200;

    public string $subject;
    public QuoteTotals $totals;

    /** @param list<QuoteLine> $lines */
    public function __construct(
        string $subject,
        public array $lines,
        public QuoteConditions $conditions,
        public int $globalDiscountBp = 0,
        public ?Deposit $deposit = null,
        /** Artisan en franchise en base de TVA : TVA masquée, mention « TVA non applicable, art. 293 B du CGI ». */
        public bool $vatExempt = false,
    ) {
        $subject = trim($subject);
        if ('' === $subject || mb_strlen($subject) > self::SUBJECT_MAX) {
            throw new QuoteRuleViolation('Indiquez l’objet du devis.');
        }
        if (\count($lines) > self::LINES_MAX) {
            throw new QuoteRuleViolation(\sprintf('Un devis compte au plus %d lignes.', self::LINES_MAX));
        }
        $this->subject = $subject;
        $this->totals = QuoteTotals::of($lines, $globalDiscountBp, $vatExempt, $deposit);
    }

    /** Contrôles d'envoi propres au contenu (RG-120 : mentions, au lot suivant) : au moins une ligne chiffrée, total non nul. */
    public function assertSendable(): void
    {
        if ($this->totals->ttc->amount <= 0) {
            throw new QuoteRuleViolation('Le montant du devis ne peut pas être nul.');
        }
    }

    /** Taux réduits présents : rappel de l'attestation du client (informatif). */
    public function needsClientCertificate(): bool
    {
        if ($this->vatExempt) {
            return false;
        }
        foreach ($this->lines as $line) {
            if ($line->type->isPriced() && $line->vatRate->needsClientCertificate()) {
                return true;
            }
        }

        return false;
    }
}
