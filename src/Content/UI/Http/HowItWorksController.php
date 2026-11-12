<?php

declare(strict_types=1);

namespace App\Content\UI\Http;

use App\Content\Application\Faq\FaqQuery;
use App\Content\Application\Faq\FaqQuestion;
use App\Shared\Application\Bus\QueryBus;
use App\Shared\Application\Settings\SettingKey;
use App\Shared\Application\Settings\SettingsProvider;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * P12 (Figma 162:1927, 237:14825) — « Comment ça marche ? » : onglets particuliers / professionnels reflétés dans l'URL
 * (#particuliers, #professionnels), étapes détaillées, engagements de confiance dont les critères de classement
 * (#classement, L111-7 : mêmes pondérations que la recherche), appels à l'action selon l'onglet.
 */
final class HowItWorksController extends AbstractController
{
    /** P12.5 : 6 à 10 questions par public, reprises de l'aide (P14). */
    private const int FAQ_PER_AUDIENCE = 10;

    /** @return list<FaqQuestion> */
    private static function questions(mixed $result): array
    {
        return array_values(array_filter(\is_array($result) ? $result : [], static fn (mixed $q): bool => $q instanceof FaqQuestion));
    }

    #[Route('/comment-ca-marche', name: 'content_how_it_works', methods: ['GET'])]
    public function __invoke(SettingsProvider $settings, QueryBus $queries): Response
    {
        return $this->render('content/how_it_works.html.twig', [
            'faq_client' => self::questions($queries->ask(FaqQuery::audience('client', self::FAQ_PER_AUDIENCE))),
            'faq_artisan' => self::questions($queries->ask(FaqQuery::audience('artisan', self::FAQ_PER_AUDIENCE))),
            'max_quotes' => $settings->int(SettingKey::QuotesMaxPerRequest),
            'free_credits' => $settings->int(SettingKey::CreditsFreeOnSignup),
            'weights' => $settings->weights(SettingKey::SearchWeights),
            'verification_hours' => $settings->int(SettingKey::ProVerificationDelayHours),
        ]);
    }
}
