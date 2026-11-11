<?php

declare(strict_types=1);

namespace App\Identity\Application\Port;

use App\Shared\Application\Integration\ExternalServiceUnavailable;

/**
 * Captcha invisible (cadrage §5.8, RG-045) : inscription, connexion après échecs, dépôt de demande, contact, newsletter.
 */
interface HumanVerification
{
    /**
     * @param string      $token          jeton produit par le widget côté navigateur (usage unique, valable 300 s)
     * @param string|null $remoteIp       adresse du visiteur, transmise au fournisseur pour la seule vérification
     * @param string|null $expectedAction action déclarée par le widget (ex. « signup ») ; refus si différente
     *
     * @throws ExternalServiceUnavailable fournisseur injoignable ou clé secrète invalide
     */
    public function verify(string $token, ?string $remoteIp = null, ?string $expectedAction = null): bool;
}
