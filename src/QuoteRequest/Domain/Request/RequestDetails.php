<?php

declare(strict_types=1);

namespace App\QuoteRequest\Domain\Request;

/**
 * P11.2, P11.3 et P11.5 — contenu modifiable d'une demande tant qu'aucun devis n'a été reçu (RG-066). Les réponses au
 * questionnaire du métier sont validées contre son schéma par le cas d'usage (T-402) ; le domaine borne leur volume.
 */
final readonly class RequestDetails
{
    /** P11.3 — « Décrivez votre projet (30 à 3 000 caractères) ». */
    public const int DESCRIPTION_MIN = 30;
    public const int DESCRIPTION_MAX = 3000;
    public const int BUDGET_RANGE_MAX = 30;
    public const int ANSWERS_MAX = 40;
    public const int ANSWER_MAX = 500;

    public string $description;
    public ?string $budgetRange;

    /** @param array<string, string|list<string>> $answers */
    public function __construct(
        public ProjectType $projectType,
        string $description,
        public array $answers,
        ?string $budgetRange,
        public Timing $timing,
        public ?\DateTimeImmutable $desiredDate,
    ) {
        $description = trim($description);
        $length = mb_strlen($description);
        if ($length < self::DESCRIPTION_MIN || $length > self::DESCRIPTION_MAX) {
            throw new RequestRuleViolation(\sprintf('La description doit compter entre %d et %d caractères.', self::DESCRIPTION_MIN, self::DESCRIPTION_MAX));
        }
        if (\count($answers) > self::ANSWERS_MAX) {
            throw new RequestRuleViolation('Trop de réponses au questionnaire.');
        }
        foreach ($answers as $answer) {
            foreach (\is_array($answer) ? $answer : [$answer] as $value) {
                if (mb_strlen($value) > self::ANSWER_MAX) {
                    throw new RequestRuleViolation(\sprintf('Une réponse au questionnaire dépasse %d caractères.', self::ANSWER_MAX));
                }
            }
        }
        if ((Timing::Date === $timing) !== (null !== $desiredDate)) {
            throw new RequestRuleViolation('Indiquez une date précise uniquement avec le délai « Date précise ».');
        }
        $budgetRange = null === $budgetRange ? '' : trim($budgetRange);
        if (mb_strlen($budgetRange) > self::BUDGET_RANGE_MAX) {
            throw new RequestRuleViolation('Tranche de budget invalide.');
        }
        $this->description = $description;
        $this->budgetRange = '' === $budgetRange ? null : $budgetRange;
    }
}
