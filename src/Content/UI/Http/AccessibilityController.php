<?php

declare(strict_types=1);

namespace App\Content\UI\Http;

use App\Shared\Application\Settings\SettingKey;
use App\Shared\Application\Settings\SettingsProvider;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * CDC §9 (T-903b) — déclaration d'accessibilité RGAA 4.1 : état de conformité, résultats, contenus non accessibles,
 * environnement de test, retour d'information, voies de recours et schéma pluriannuel. Mise en page des documents
 * légaux (P17). L'état « non conforme » reste affiché tant qu'aucun audit complet n'a été réalisé (P-153).
 */
final class AccessibilityController extends AbstractController
{
    /** Motif du formulaire de contact pré-sélectionné quand il figure dans la liste administrable. */
    public const string CONTACT_REASON = 'Accessibilité';

    #[Route('/accessibilite', name: 'content_accessibility', methods: ['GET'])]
    public function __invoke(SettingsProvider $settings): Response
    {
        $reasons = $settings->stringList(SettingKey::ContactReasons);

        return $this->render('content/accessibility.html.twig', [
            'contact_reason' => \in_array(self::CONTACT_REASON, $reasons, true) ? self::CONTACT_REASON : null,
            'reply_hours' => $settings->int(SettingKey::ContactReplyHours),
        ]);
    }
}
