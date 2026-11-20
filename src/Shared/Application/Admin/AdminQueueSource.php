<?php

declare(strict_types=1);

namespace App\Shared\Application\Admin;

/**
 * BO01.2 — files d'attente fournies par chaque module : nombre d'éléments à traiter par clé de file (le tableau de bord
 * fixe le libellé, l'icône, le droit d'accès et la liste filtrée ouverte au clic).
 */
interface AdminQueueSource
{
    public const string TAG = 'app.admin_queue_source';

    /** @return array<string, int> */
    public function queues(): array;
}
