<?php

declare(strict_types=1);

namespace App\Content\UI\Http;

use App\Billing\Application\Plan\PlanView;
use App\Billing\Application\Plan\PublishedPlans;
use App\Content\Application\Faq\FaqQuery;
use App\Content\Application\Faq\FaqQuestion;
use App\Shared\Application\Bus\QueryBus;
use App\Shared\Application\Settings\SettingKey;
use App\Shared\Application\Settings\SettingsProvider;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * P13 (Figma 159:1364) — tarifs professionnels : devis offerts au démarrage, forfaits publiés en BO08 (cartes, mensuel /
 * annuel, comparatif ; Q5 : aucun tant que le porteur n'en a pas publié — jamais de prix d'exemple), questions fréquentes de la rubrique « Paiement &
 * abonnement » de l'aide (P13.3), appel à l'inscription.
 */
final class PricingController extends AbstractController
{
    private const string PRICING_TOPIC = 'paiement-abonnement';

    #[Route('/tarifs-professionnels', name: 'content_pricing', methods: ['GET'])]
    public function __invoke(QueryBus $queries, SettingsProvider $settings): Response
    {
        $faq = $queries->ask(FaqQuery::topic(self::PRICING_TOPIC));

        $plans = $queries->ask(new PublishedPlans());

        return $this->render('content/pricing.html.twig', [
            'plans' => \is_array($plans) ? array_values(array_filter($plans, static fn (mixed $p): bool => $p instanceof PlanView)) : [],
            'free_credits' => $settings->int(SettingKey::CreditsFreeOnSignup),
            'faq' => array_values(array_filter(\is_array($faq) ? $faq : [], static fn (mixed $q): bool => $q instanceof FaqQuestion)),
        ]);
    }
}
