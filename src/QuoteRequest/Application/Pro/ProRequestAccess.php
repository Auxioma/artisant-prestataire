<?php

declare(strict_types=1);

namespace App\QuoteRequest\Application\Pro;

use App\QuoteRequest\Application\Port\ProRequestReader;
use App\Shared\Application\Settings\SettingKey;
use App\Shared\Application\Settings\SettingsProvider;

/**
 * Droits de l'artisan sur une demande (Voter, requêtes) : la consulter (reçue, ou aperçu Q15 si `pro.preview_before_verified`),
 * y répondre (vérifié, demande reçue et ouverte, places restantes — RG-111).
 */
final readonly class ProRequestAccess
{
    /** Attributs du Voter (sujet : identifiant de la demande). */
    public const string VIEW = 'QUOTE_REQUEST_VIEW';
    public const string ANSWER = 'QUOTE_REQUEST_ANSWER';

    public function __construct(private ProRequestReader $reader, private SettingsProvider $settings)
    {
    }

    /** Artisan pouvant voir l'espace « Demandes » : vérifié, ou en attente avec l'aperçu activé. */
    public function artisan(string $userId): ?ProArtisan
    {
        $artisan = $this->reader->artisanOf($userId);

        return null !== $artisan && ($artisan->verified || $this->settings->bool(SettingKey::ProPreviewBeforeVerified)) ? $artisan : null;
    }

    public function card(string $userId, string $requestId): ?ProRequestCard
    {
        $artisan = $this->artisan($userId);

        return null === $artisan ? null : $this->reader->card($artisan, $requestId);
    }

    public function canView(string $userId, string $requestId): bool
    {
        return null !== $this->card($userId, $requestId);
    }

    public function canAnswer(string $userId, string $requestId): bool
    {
        return true === $this->card($userId, $requestId)?->answerable();
    }

    /**
     * Rédiger un devis vaut consultation : la demande reste visible de l'artisan une fois complète ou close (la liste
     * PR02 ne montre une demande non ouverte qu'à ceux qui l'ont consultée).
     */
    public function recordConsultation(string $artisanId, string $requestId, \DateTimeImmutable $at): void
    {
        $this->reader->markViewed($artisanId, $requestId, $at);
    }
}
