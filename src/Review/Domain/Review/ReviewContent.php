<?php

declare(strict_types=1);

namespace App\Review\Domain\Review;

/**
 * C06.2 — contenu d'un avis : note globale 1–5 (obligatoire), sous-notes facultatives (qualité, ponctualité, propreté,
 * rapport qualité / prix, communication), titre, texte 50–2 000 caractères, date de prestation, recommandation, photos (5).
 */
final readonly class ReviewContent
{
    public const array CRITERIA = ['quality' => 'Qualité', 'punctuality' => 'Ponctualité', 'cleanliness' => 'Propreté', 'value' => 'Rapport qualité / prix', 'communication' => 'Communication'];
    public const int TEXT_MIN = 50;
    public const int TEXT_MAX = 2000;
    public const int TITLE_MAX = 100;
    public const int PHOTOS_MAX = 5;
    private const int STARS_MAX = 5;

    /**
     * @param array<string, int> $subRatings critère => 1–5
     * @param list<string>       $photos     identifiants de fichiers (usage `review_photo`)
     */
    public function __construct(
        public int $rating,
        public array $subRatings,
        public ?string $title,
        public string $text,
        public \DateTimeImmutable $serviceDate,
        public ?bool $recommends,
        public array $photos = [],
    ) {
        if ($rating < 1 || $rating > self::STARS_MAX) {
            throw new ReviewRuleViolation('Attribuez une note de 1 à 5 étoiles.');
        }
        foreach ($subRatings as $criterion => $value) {
            if (!isset(self::CRITERIA[$criterion]) || $value < 1 || $value > self::STARS_MAX) {
                throw new ReviewRuleViolation('Les notes détaillées vont de 1 à 5 étoiles.');
            }
        }
        $length = mb_strlen(trim($text));
        if ($length < self::TEXT_MIN) {
            throw new ReviewRuleViolation(\sprintf('Votre avis doit contenir au moins %d caractères.', self::TEXT_MIN));
        }
        if ($length > self::TEXT_MAX) {
            throw new ReviewRuleViolation(\sprintf('Votre avis ne peut dépasser %d caractères.', self::TEXT_MAX));
        }
        if (null !== $title && mb_strlen($title) > self::TITLE_MAX) {
            throw new ReviewRuleViolation(\sprintf('Titre limité à %d caractères.', self::TITLE_MAX));
        }
        if (\count($photos) > self::PHOTOS_MAX) {
            throw new ReviewRuleViolation(\sprintf('%d photos au plus.', self::PHOTOS_MAX));
        }
    }
}
