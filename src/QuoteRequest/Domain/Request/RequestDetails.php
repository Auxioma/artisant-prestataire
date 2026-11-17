<?php

declare(strict_types=1);

namespace App\QuoteRequest\Domain\Request;

use App\Shared\Domain\Text\ContactDetector;

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
    /** Figma P11 (5:2, 5:57) : « Nature des travaux », « Titre de votre demande », « Décrivez votre besoin ». */
    public const int NATURE_MAX = 120;
    public const int TITLE_MAX = 100;
    public const int SUMMARY_MAX = 300;
    /** Messages d'erreur du CDC (P11, « Messages d'erreur et validations »). */
    public const string DESCRIPTION_MESSAGE = 'Décrivez votre projet en quelques phrases (30 caractères minimum).';
    public const string CONTACT_MESSAGE = 'Pour votre sécurité, n’indiquez pas vos coordonnées dans la description.';

    public string $description;
    public ?string $budgetRange;
    public ?string $title;
    public ?string $summary;
    public ?string $workNature;

    /**
     * @param array<string, string|list<string>> $answers
     * @param list<PreferredSlot>                $preferredSlots
     */
    public function __construct(
        public ProjectType $projectType,
        string $description,
        public array $answers,
        ?string $budgetRange,
        public Timing $timing,
        public ?\DateTimeImmutable $desiredDate,
        public array $preferredSlots = [],
        public ?OccupantStatus $occupantStatus = null,
        ?string $title = null,
        ?string $summary = null,
        ?string $workNature = null,
    ) {
        $this->title = self::optional($title, self::TITLE_MAX, 'Le titre');
        $this->summary = self::optional($summary, self::SUMMARY_MAX, 'Le besoin');
        $this->workNature = self::optional($workNature, self::NATURE_MAX, 'La nature des travaux');
        $description = trim($description);
        $length = mb_strlen($description);
        if ($length < self::DESCRIPTION_MIN || $length > self::DESCRIPTION_MAX) {
            throw new RequestRuleViolation(self::DESCRIPTION_MESSAGE);
        }
        if ([] !== ContactDetector::find($description)) {
            throw new RequestRuleViolation(self::CONTACT_MESSAGE);
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

    /** Texte facultatif : borné et sans coordonnées (même règle que la description). */
    private static function optional(?string $value, int $max, string $label): ?string
    {
        $value = null === $value ? '' : trim($value);
        if (mb_strlen($value) > $max) {
            throw new RequestRuleViolation(\sprintf('%s ne doit pas dépasser %d caractères.', $label, $max));
        }
        if ([] !== ContactDetector::find($value)) {
            throw new RequestRuleViolation(self::CONTACT_MESSAGE);
        }

        return '' === $value ? null : $value;
    }
}
