<?php

declare(strict_types=1);

namespace App\Search\UI\Http;

use App\Catalog\Application\Query\CategorySummary;
use App\Catalog\Application\Query\JobIndexEntries;
use App\Catalog\Application\Query\JobIndexEntry;
use App\Catalog\Application\Query\MainCategories;
use App\Search\Application\Offer\ActiveOffers;
use App\Search\Application\Offer\OfferCard;
use App\Shared\Application\Bus\QueryBus;
use App\Shared\Application\Settings\SettingKey;
use App\Shared\Application\Settings\SettingsProvider;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * P09 « Offres & promotions » (maquette Kerry « Offres et promotions — Desktop 1440 · V2 ») : bannière d'opération
 * plateforme, filtres en pastilles (catégorie, lieu · rayon, type de réduction), cartes offre avec « J'en profite »
 * (demande directe avec l'offre rattachée). RG-026 : seules les offres publiées et en cours sont affichées.
 */
final class OffersController extends AbstractController
{
    public const array TYPES = [OfferCard::PERCENT => 'Remise %', OfferCard::AMOUNT => 'Remise €', OfferCard::FREE => 'Prestation offerte'];
    private const int CATEGORY_CHIPS = 6;

    public function __construct(private readonly QueryBus $queries, private readonly SettingsProvider $settings, private readonly PlaceResolver $places)
    {
    }

    #[Route('/offres-promotions', name: 'search_offers', methods: ['GET'])]
    public function __invoke(Request $request): Response
    {
        $categories = $this->queries->ask(new MainCategories(1));
        $categories = array_values(array_filter(\is_array($categories) ? $categories : [], static fn (mixed $c): bool => $c instanceof CategorySummary));
        $slug = $request->query->getString('categorie');
        $category = array_values(array_filter($categories, static fn (CategorySummary $c): bool => $c->slug === $slug))[0] ?? null;
        $jobIds = [];
        if (null !== $category) {
            $jobs = $this->queries->ask(new JobIndexEntries());
            $jobIds = array_values(array_map(
                static fn (JobIndexEntry $j): string => $j->id,
                array_filter(\is_array($jobs) ? $jobs : [], static fn (mixed $j): bool => $j instanceof JobIndexEntry && $j->categorySlug === $category->slug),
            ));
        }
        $place = $this->places->resolve($request->query->getString('lieu'), trim($request->query->getString('lieu_libelle')));
        $radius = $this->settings->int(SettingKey::SearchDefaultRadiusKm);
        $type = $request->query->getString('type');
        $type = \array_key_exists($type, self::TYPES) ? $type : null;

        $offers = null === $category || [] !== $jobIds
            ? $this->queries->ask(new ActiveOffers($jobIds, $place?->insee, $radius, $type))
            : [];
        $params = array_filter(['categorie' => $category?->slug, 'lieu' => $place?->insee, 'type' => $type], static fn (?string $v): bool => null !== $v);

        $response = $this->render('search/offers.html.twig', [
            'offers' => \is_array($offers) ? $offers : [],
            'categories' => \array_slice($categories, 0, self::CATEGORY_CHIPS),
            'category' => $category,
            'place' => $place,
            'radius' => $radius,
            'type' => $type,
            'types' => self::TYPES,
            'params' => $params,
        ]);
        if ([] !== $params) {
            $response->headers->set('X-Robots-Tag', 'noindex, follow');
        }

        return $response;
    }
}
