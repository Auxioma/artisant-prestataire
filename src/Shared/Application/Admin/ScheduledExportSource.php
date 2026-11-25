<?php

declare(strict_types=1);

namespace App\Shared\Application\Admin;

/**
 * BO15.2 — export planifié fourni par un module : clé stable, libellé, en-tête et lignes du CSV au moment donné.
 * Le back-office le produit selon le calendrier de sa tâche, le chiffre, le conserve un temps limité et journalise
 * chaque téléchargement.
 */
interface ScheduledExportSource
{
    public const string TAG = 'app.scheduled_export_source';

    public function key(): string;

    public function label(): string;

    /** Rythme affiché (« Hebdomadaire · lundi 06:00 »). */
    public function schedule(): string;

    /** Période couverte, pour le nom du fichier et la liste (« semaine du 16/11/2026 »). */
    public function periodLabel(\DateTimeImmutable $now): string;

    /** @return list<string> */
    public function header(): array;

    /** @return iterable<list<string>> */
    public function rows(\DateTimeImmutable $now): iterable;
}
