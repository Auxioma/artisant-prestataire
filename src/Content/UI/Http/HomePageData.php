<?php

declare(strict_types=1);

namespace App\Content\UI\Http;

use App\Catalog\Application\Query\CategorySummary;
use App\Catalog\Application\Query\JobIndexEntries;
use App\Catalog\Application\Query\JobIndexEntry;
use App\Catalog\Application\Query\MainCategories;
use App\Content\Application\Home\HomeBlockSchema;
use App\Content\Application\Home\HomeVersion;
use App\Content\Domain\Home\HomeBlockType;
use App\Search\Application\Artisan\ArtisanHit;
use App\Search\Application\Directory\CountArtisans;
use App\Search\Application\Directory\FeaturedArtisans;
use App\Shared\Application\Bus\QueryBus;
use App\Shared\Application\Settings\SettingKey;
use App\Shared\Application\Settings\SettingsProvider;
use Symfony\Component\Routing\RouterInterface;

/**
 * Données de rendu des blocs de l'accueil P01 (page publique et aperçu BO10.1) : liens résolus et masqués si la page
 * cible n'existe pas, variables {max_devis} / {free_credits} lues dans les paramètres (RG-014), catégories « à la une »,
 * professionnels éligibles RG-011.
 *
 * @phpstan-import-type Block from HomeVersion
 */
final readonly class HomePageData
{
    /** La requête ne retient que les catégories ayant au moins un métier actif ; les métiers ne sont pas affichés ici. */
    private const int ONE_JOB = 1;

    public function __construct(private QueryBus $queries, private SettingsProvider $settings, private RouterInterface $router)
    {
    }

    /**
     * @param list<Block> $blocks blocs actifs, dans l'ordre
     *
     * @return array<string, mixed>
     */
    public function build(array $blocks, string $audience): array
    {
        $variables = [
            '{max_devis}' => (string) $this->settings->int(SettingKey::QuotesMaxPerRequest),
            '{free_credits}' => (string) $this->settings->int(SettingKey::CreditsFreeOnSignup),
        ];
        $rendered = [];
        $jobs = null;
        foreach ($blocks as $block) {
            $type = HomeBlockType::tryFrom($block['type']);
            if (null === $type || $type->isGlobal()) {
                continue; // en-tête et pied de page : gabarit de base
            }
            $s = $this->interpolate($block['settings'], $variables);
            $data = [];
            if (HomeBlockType::Reassurance === $type || HomeBlockType::QuickLinks === $type) {
                $s['items'] = $this->links($s['items'] ?? [], HomeBlockType::QuickLinks === $type, $audience);
                if ([] === $s['items']) {
                    continue;
                }
            } elseif (HomeBlockType::Categories === $type) {
                $jobs ??= $this->jobs();
                $data['categories'] = $this->categories(self::int($s['max'] ?? null), true === ($s['show_counts'] ?? false), $jobs);
                if ([] === $data['categories']) {
                    continue; // aucune catégorie à la une : bloc masqué
                }
            } elseif (HomeBlockType::Pros === $type) {
                $jobs ??= $this->jobs();
                $data['hits'] = $this->featured(self::int($s['count'] ?? null));
                $data['job_names'] = array_map(static fn (JobIndexEntry $j): string => $j->name, $jobs);
            } elseif (HomeBlockType::Cta === $type) {
                $data['href'] = $this->path('request') ?? $this->path('search');
            } elseif (HomeBlockType::HowItWorks === $type) {
                $data['href'] = $this->path('how_it_works');
            }
            $rendered[] = ['type' => $type->value, 'settings' => $s, 'data' => $data];
        }

        return ['blocks' => $rendered];
    }

    /**
     * Blocs renvoyés par PublishedHome, vérifiés.
     *
     * @return list<Block>
     */
    public static function blocks(mixed $result): array
    {
        $blocks = [];
        foreach (\is_array($result) ? $result : [] as $block) {
            if (\is_array($block) && \is_string($block['type'] ?? null) && \is_array($block['settings'] ?? null)) {
                /** @var array<string, mixed> $settings */
                $settings = $block['settings'];
                $blocks[] = ['type' => $block['type'], 'enabled' => true === ($block['enabled'] ?? false), 'settings' => $settings];
            }
        }

        return $blocks;
    }

    /** @return list<ArtisanHit> RG-011 : professionnels éligibles, mieux notés d'abord (sans lieu connu) */
    public function featured(int $count): array
    {
        $hits = $this->queries->ask(new FeaturedArtisans([], max(1, $count)));

        return array_values(array_filter(\is_array($hits) ? $hits : [], static fn (mixed $h): bool => $h instanceof ArtisanHit));
    }

    /** @return array<string, JobIndexEntry> */
    public function jobs(): array
    {
        $entries = $this->queries->ask(new JobIndexEntries());
        $jobs = [];
        foreach (\is_array($entries) ? $entries : [] as $entry) {
            if ($entry instanceof JobIndexEntry) {
                $jobs[$entry->id] = $entry;
            }
        }

        return $jobs;
    }

    /**
     * @param array<string, JobIndexEntry> $jobs
     *
     * @return list<array{summary: CategorySummary, count: ?int}>
     */
    private function categories(int $max, bool $counts, array $jobs): array
    {
        $summaries = $this->queries->ask(new MainCategories(self::ONE_JOB, featuredOnly: true));
        $categories = [];
        foreach (\is_array($summaries) ? \array_slice($summaries, 0, max(1, $max)) : [] as $summary) {
            if (!$summary instanceof CategorySummary) {
                continue;
            }
            $count = null;
            if ($counts) {
                $ids = array_keys(array_filter($jobs, static fn (JobIndexEntry $j): bool => $j->searchable && $j->categorySlug === $summary->slug));
                $n = $this->queries->ask(new CountArtisans(array_map('strval', $ids)));
                $count = \is_int($n) ? $n : null;
            }
            $categories[] = ['summary' => $summary, 'count' => $count];
        }

        return $categories;
    }

    /**
     * Liens résolus ; masqués si la page n'existe pas encore (P01.4) ou si le profil n'est pas visé. Pour un artisan
     * connecté, « Créer mon compte pro » devient « Mon espace pro ».
     *
     * @return list<array<array-key, mixed>>
     */
    private function links(mixed $items, bool $quick, string $audience): array
    {
        $links = [];
        foreach (\is_array($items) ? $items : [] as $item) {
            if (!\is_array($item)) {
                continue;
            }
            $target = \is_string($item['target'] ?? null) ? $item['target'] : '';
            if ($quick) {
                $wanted = \is_string($item['audience'] ?? null) ? $item['audience'] : 'all';
                if ('all' !== $wanted && $wanted !== $audience) {
                    continue;
                }
                if ('pro_signup' === $target && 'artisan' === $audience) {
                    $item['label'] = 'Mon espace pro';
                    $target = 'pro_space';
                }
            }
            $item['href'] = '' === $target ? null : $this->path($target);
            if ($quick && null === $item['href']) {
                continue;
            }
            $links[] = $item;
        }

        return $links;
    }

    private function path(string $target): ?string
    {
        $route = 'pro_space' === $target ? 'pro_dashboard' : (HomeBlockSchema::TARGETS[$target]['route'] ?? null);

        return null !== $route && null !== $this->router->getRouteCollection()->get($route) ? $this->router->generate($route) : null;
    }

    /**
     * @param array<string, mixed>  $settings
     * @param array<string, string> $variables
     *
     * @return array<string, mixed>
     */
    private function interpolate(array $settings, array $variables): array
    {
        array_walk_recursive($settings, static function (mixed &$value) use ($variables): void {
            if (\is_string($value)) {
                $value = strtr($value, $variables);
            }
        });

        return $settings;
    }

    private static function int(mixed $value): int
    {
        return \is_int($value) ? $value : 0;
    }
}
