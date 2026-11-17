<?php

declare(strict_types=1);

namespace App\Administration\Application\Port;

use App\Shared\Application\Settings\SettingKey;

interface SettingStore
{
    /**
     * Valeur stockée brute (JSON décodé) ou null si la clé n'a jamais été enregistrée.
     */
    public function find(SettingKey $key): mixed;

    /**
     * Enregistre la nouvelle valeur et ajoute une ligne d'historique (avant/après), dans la transaction courante.
     *
     * @return bool false si la valeur est identique (aucune écriture)
     */
    public function save(SettingKey $key, mixed $value, ?string $adminId, string $reason, \DateTimeImmutable $changedAt): bool;
}
