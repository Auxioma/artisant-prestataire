<?php

declare(strict_types=1);

namespace App\ArtisanProfile\Application\Moderation;

use App\ArtisanProfile\Application\Port\ProfileReviews;
use App\Shared\Application\Bus\QueryHandler;
use App\Shared\Application\Moderation\PendingProfileReviews;
use App\Shared\Application\Moderation\ProfileReviewItem;

/** BO05 « Contenus de profils » — échantillon RG-031 en lignes lisibles par section. */
final readonly class ProfileReviewQueueHandler implements QueryHandler
{
    public const array SECTIONS = ['presentation' => 'Présentation', 'services' => 'Prestations', 'portfolio' => 'Réalisation', 'portfolio_photo' => 'Photo de réalisation'];
    private const int LIMIT = 100;

    public function __construct(private ProfileReviews $reviews)
    {
    }

    /** @return list<ProfileReviewItem> */
    public function __invoke(PendingProfileReviews $query): array
    {
        return array_map(static fn (array $r): ProfileReviewItem => new ProfileReviewItem(
            $r['id'],
            $r['artisan_id'],
            $r['artisan_name'],
            $r['section'],
            self::SECTIONS[$r['section']] ?? $r['section'],
            self::lines($r['section'], $r['content']),
            'portfolio_photo' === $r['section'],
            $r['created_at'],
        ), $this->reviews->pending(self::LIMIT));
    }

    /**
     * @param array<string, mixed> $content
     *
     * @return list<array{label: string, value: string}>
     */
    private static function lines(string $section, array $content): array
    {
        $text = static fn (string $key): string => \is_string($content[$key] ?? null) ? $content[$key] : '';
        $list = static fn (string $key): string => implode(' · ', array_filter(\is_array($content[$key] ?? null) ? $content[$key] : [], \is_string(...)));
        $lines = match ($section) {
            'presentation' => [['Nom commercial', $text('trade_name')], ['Slogan', $text('slogan')], ['Description', $text('description')], ['Points forts', $list('strengths')]],
            'services' => array_map(
                static fn (mixed $s): array => ['Prestation', \is_array($s) ? trim((\is_string($s['title'] ?? null) ? $s['title'] : '').(\is_string($s['price'] ?? null) && '' !== $s['price'] ? ' — '.$s['price'] : '')) : ''],
                \is_array($content['services'] ?? null) ? array_values($content['services']) : [],
            ),
            'portfolio' => [['Titre', $text('title')], ['Description', $text('description')], ['Ville', $text('city')]],
            default => [['Contenu', 'Photo ajoutée à une réalisation']],
        };

        return array_values(array_map(static fn (array $l): array => ['label' => (string) $l[0], 'value' => (string) $l[1]], array_filter($lines, static fn (array $l): bool => '' !== $l[1])));
    }
}
