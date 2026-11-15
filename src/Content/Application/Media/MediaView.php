<?php

declare(strict_types=1);

namespace App\Content\Application\Media;

/** Image de la médiathèque (BO10.7) ; `variants` : format (webp, avif) → largeur → fichier. */
final readonly class MediaView
{
    public const array FOCALS = [
        'tl' => 'En haut à gauche', 'tc' => 'En haut', 'tr' => 'En haut à droite',
        'cl' => 'À gauche', 'cc' => 'Au centre', 'cr' => 'À droite',
        'bl' => 'En bas à gauche', 'bc' => 'En bas', 'br' => 'En bas à droite',
    ];

    /** @param array<string, array<int, string>> $variants */
    public function __construct(
        public string $id,
        public string $fileId,
        public string $title,
        public string $alt,
        public string $focal,
        public ?int $width,
        public ?int $height,
        public array $variants,
        public string $status,
        public \DateTimeImmutable $createdAt,
    ) {
    }

    public function ready(): bool
    {
        return 'ready' === $this->status;
    }

    /** @return list<int> largeurs disponibles pour un format, croissantes */
    public function widths(string $format): array
    {
        $widths = array_map('intval', array_keys($this->variants[$format] ?? []));
        sort($widths);

        return $widths;
    }

    public function variant(string $format, int $width): ?string
    {
        return $this->variants[$format][$width] ?? null;
    }
}
