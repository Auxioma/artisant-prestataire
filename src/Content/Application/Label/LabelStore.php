<?php

declare(strict_types=1);

namespace App\Content\Application\Label;

/** Dictionnaire des libellés (`content.ui_label`). */
interface LabelStore
{
    public const string LOCALE = 'fr';

    /** @return list<UiLabel> par clé */
    public function all(string $locale): array;

    /** @return array<string, string> clé → texte modifié (seulement les libellés modifiés) */
    public function overrides(string $locale): array;

    public function find(string $key, string $locale): ?UiLabel;

    public function setText(string $key, string $locale, ?string $text, ?string $adminId, \DateTimeImmutable $at): void;

    /**
     * Enregistre les clés relevées dans les gabarits (texte d'origine mis à jour, texte modifié conservé).
     *
     * @param array<string, string> $defaults clé → texte d'origine
     *
     * @return int nombre de clés ajoutées
     */
    public function sync(array $defaults, string $locale, \DateTimeImmutable $at): int;
}
