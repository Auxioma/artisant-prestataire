<?php

declare(strict_types=1);

namespace App\Content\UI\Admin;

use App\Administration\Application\Command\ChangeSetting;
use App\Content\Application\Port\NotFoundLog;
use App\Content\Application\Port\RedirectStore;
use App\Content\Application\Port\SeoMeta;
use App\Content\Application\Seo\DeleteRedirect;
use App\Content\Application\Seo\DeleteSeoOverride;
use App\Content\Application\Seo\DismissNotFound;
use App\Content\Application\Seo\ImportRedirects;
use App\Content\Application\Seo\SaveRedirect;
use App\Content\Application\Seo\SaveSeoOverride;
use App\Content\Application\Seo\SaveSeoTemplate;
use App\Content\Application\Seo\SeoPageTypes;
use App\Content\Application\Seo\SeoRuleViolation;
use App\Content\UI\Http\SitemapController;
use App\Shared\Application\Bus\Command;
use App\Shared\Application\Bus\CommandBus;
use App\Shared\Application\Security\CurrentAdministrator;
use App\Shared\Application\Settings\InvalidSettingValue;
use App\Shared\Application\Settings\SettingKey;
use App\Shared\Application\Settings\SettingsProvider;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * BO12 « Référencement » (/admin/seo, Figma 186:5301) — Meta et gabarits : gabarit title / description par type de page
 * avec compteurs, variables et aperçu du résultat, surcharges page par page (BO12.1) ; Redirections : 301 / 302 et
 * 404 dans une même liste filtrable, suggestion, import et export CSV (BO12.2) ; Sitemap et robots : nombre d'adresses
 * par sitemap, régénération, robots.txt et règles d'indexation RG-023 (BO12.3) ; Données structurées (BO12.4). Écarts : P-138.
 */
#[Route('/admin/seo')]
final class SeoAdminController extends AbstractController
{
    public const array TABS = ['gabarits' => 'Meta & gabarits', 'redirections' => 'Redirections', 'sitemap' => 'Sitemap & robots', 'donnees' => 'Données structurées'];
    /** Anciennes adresses d'onglets (T-308b) : surcharges et 404 ont rejoint les onglets de la maquette. */
    private const array LEGACY_TABS = ['surcharges' => 'gabarits', '404' => 'redirections', 'robots' => 'sitemap'];
    private const string DEFAULT_TYPE = 'job_city';
    /** BO12.3 — règles d'indexation (RG-023) éditées avec le robots.txt. */
    private const array INDEXING = [SettingKey::SeoMinProsIndex, SettingKey::SeoCityPagesMinPopulation, SettingKey::SeoMinSample];
    private const array REDIRECT_TYPES = ['301', '302', '404'];
    private const array SCHEMAS = [
        'organization' => [SettingKey::SeoSchemaOrganization, 'Organization', 'Accueil : nom, logo, réseaux sociaux (WebSite et recherche interne)'],
        'local_business' => [SettingKey::SeoSchemaLocalBusiness, 'LocalBusiness + AggregateRating (avis plateforme)', 'Fiches artisans : adresse, zone, note moyenne des avis certifiés'],
        'faq' => [SettingKey::SeoSchemaFaq, 'FAQPage', 'Aide et pages métier qui affichent des questions'],
        'breadcrumb' => [SettingKey::SeoSchemaBreadcrumb, 'BreadcrumbList', 'Fil d’Ariane de toutes les pages publiques'],
        'article' => [SettingKey::SeoSchemaArticle, 'Article', 'Articles du blog (V2)'],
    ];
    private const int PER_PAGE = 50;
    private const int NOT_FOUND_ROWS = 100;
    private const int SUGGESTION_MAX_DISTANCE = 20;
    private const int CSV_MAX_BYTES = 1_048_576;
    private const int EXPORT_BATCH = 500;

    public function __construct(private readonly CommandBus $commands, private readonly CurrentAdministrator $admin)
    {
    }

    #[Route('', name: 'admin_seo', methods: ['GET'])]
    #[IsGranted('admin.seo.view')]
    public function index(Request $request, SeoMeta $meta, RedirectStore $redirects, NotFoundLog $notFound, SettingsProvider $settings, SitemapController $sitemaps): Response
    {
        $tab = $request->query->getString('onglet', 'gabarits');
        $tab = self::LEGACY_TABS[$tab] ?? $tab;
        $tab = \array_key_exists($tab, self::TABS) ? $tab : 'gabarits';
        $search = trim($request->query->getString('q'));
        $page = max(1, $request->query->getInt('page', 1));
        $data = [
            'tab' => $tab, 'tabs' => self::TABS, 'search' => $search, 'page' => $page, 'per_page' => self::PER_PAGE,
            'sitemaps' => $sitemaps->counts(), 'min_pros' => $settings->int(SettingKey::SeoMinProsIndex),
            'schemas' => array_map(static fn (array $s): array => ['label' => $s[1], 'help' => $s[2], 'enabled' => $settings->bool($s[0])], self::SCHEMAS),
        ];

        $data += match ($tab) {
            'gabarits' => $this->templatesTab($request, $meta),
            'redirections' => $this->redirectsTab($request, $redirects, $notFound, $sitemaps, $search, $page),
            'sitemap' => [
                'robots_extra' => implode("\n", $settings->stringList(SettingKey::SeoRobotsExtra)),
                'indexing' => array_map(static fn (SettingKey $k): array => ['key' => $k->value, 'label' => $k->definition()->label, 'value' => $settings->int($k), 'min' => $k->definition()->min, 'max' => $k->definition()->max], self::INDEXING),
            ],
            default => [],
        };

        return $this->render('admin/seo/index.html.twig', $data);
    }

    #[Route('/redirections.csv', name: 'admin_seo_redirect_export', methods: ['GET'])]
    #[IsGranted('admin.seo.view')]
    public function export(RedirectStore $redirects): StreamedResponse
    {
        $response = new StreamedResponse(static function () use ($redirects): void {
            $out = fopen('php://output', 'w');
            \assert(\is_resource($out));
            fputcsv($out, ['source', 'cible', 'code'], ';', '"', '');
            for ($offset = 0;; $offset += self::EXPORT_BATCH) {
                $rows = $redirects->list('', $offset, self::EXPORT_BATCH)['items'];
                foreach ($rows as $r) {
                    fputcsv($out, [$r['source'], $r['target'], (string) $r['status']], ';', '"', '');
                }
                if (\count($rows) < self::EXPORT_BATCH) {
                    break;
                }
            }
            fclose($out);
        });
        $response->headers->set('Content-Type', 'text/csv; charset=UTF-8');
        $response->headers->set('Content-Disposition', 'attachment; filename="redirections.csv"');

        return $response;
    }

    #[Route('/gabarits/{type}', name: 'admin_seo_template', requirements: ['type' => '[a-z_]+'], methods: ['POST'])]
    #[IsGranted('admin.seo.edit')]
    public function template(Request $request, string $type): Response
    {
        $this->assertCsrf($request, 'admin_seo_template_'.$type);

        return $this->run(new SaveSeoTemplate($this->adminId(), $type, $request->request->getString('title'), $request->request->getString('description')), 'Gabarit enregistré.', 'gabarits', ['type' => $type]);
    }

    #[Route('/surcharges', name: 'admin_seo_override', methods: ['POST'])]
    #[IsGranted('admin.seo.edit')]
    public function override(Request $request): Response
    {
        $this->assertCsrf($request, 'admin_seo_override');
        $path = $request->request->getString('path');
        $command = 'delete' === $request->request->getString('action')
            ? new DeleteSeoOverride($this->adminId(), $path)
            : new SaveSeoOverride($this->adminId(), $path, $request->request->getString('title'), $request->request->getString('description'), $request->request->getBoolean('noindex'));

        return $this->run($command, $command instanceof DeleteSeoOverride ? 'Surcharge retirée.' : 'Surcharge enregistrée.', 'gabarits', ['_fragment' => 'surcharges']);
    }

    #[Route('/redirections', name: 'admin_seo_redirect', methods: ['POST'])]
    #[IsGranted('admin.seo.edit')]
    public function redirections(Request $request): Response
    {
        $this->assertCsrf($request, 'admin_seo_redirect');
        $command = match ($request->request->getString('action')) {
            'delete' => new DeleteRedirect($this->adminId(), $request->request->getInt('id')),
            'import' => new ImportRedirects($this->adminId(), $this->csv($request)),
            default => new SaveRedirect($this->adminId(), $request->request->getString('source'), $request->request->getString('target'), $request->request->getInt('status', 301)),
        };

        return $this->run($command, match (true) {
            $command instanceof DeleteRedirect => 'Redirection supprimée.',
            $command instanceof ImportRedirects => 'Redirections importées.',
            default => 'Redirection enregistrée.',
        }, 'redirections');
    }

    #[Route('/404', name: 'admin_seo_not_found', methods: ['POST'])]
    #[IsGranted('admin.seo.edit')]
    public function dismiss(Request $request): Response
    {
        $this->assertCsrf($request, 'admin_seo_not_found');

        return $this->run(new DismissNotFound($this->adminId(), $request->request->getString('path')), 'Adresse retirée du rapport.', 'redirections');
    }

    #[Route('/robots', name: 'admin_seo_robots', methods: ['POST'])]
    #[IsGranted('admin.seo.edit')]
    public function robots(Request $request): Response
    {
        $this->assertCsrf($request, 'admin_seo_robots');
        $lines = array_values(array_filter(array_map('trim', preg_split('/\R/', $request->request->getString('robots_extra')) ?: []), static fn (string $l): bool => '' !== $l));
        $changes = [SettingKey::SeoRobotsExtra->value => $lines];
        foreach (self::INDEXING as $key) {
            $changes[$key->value] = $request->request->getString($key->value);
        }

        return $this->settings($changes, 'BO12 : sitemap et robots', 'sitemap', $request);
    }

    #[Route('/donnees-structurees', name: 'admin_seo_schemas', methods: ['POST'])]
    #[IsGranted('admin.seo.edit')]
    public function schemas(Request $request): Response
    {
        $this->assertCsrf($request, 'admin_seo_schemas');
        $changes = [];
        foreach (self::SCHEMAS as $name => [$key]) {
            $changes[$key->value] = $request->request->getBoolean('schema_'.$name);
        }

        return $this->settings($changes, 'BO12 : données structurées', 'donnees' === $request->request->getString('back') ? 'donnees' : 'gabarits', $request);
    }

    #[Route('/sitemaps/regenerer', name: 'admin_seo_sitemaps', methods: ['POST'])]
    #[IsGranted('admin.seo.edit')]
    public function regenerate(Request $request, SitemapController $sitemaps): Response
    {
        $this->assertCsrf($request, 'admin_seo_sitemaps');
        $sitemaps->refresh();
        $this->addFlash('success', 'Sitemaps régénérés.');

        return $this->redirectToRoute('admin_seo', ['onglet' => 'sitemap'], Response::HTTP_SEE_OTHER);
    }

    /**
     * Valide toutes les valeurs avant d'en écrire une seule (ChangeSetting journalise avant / après).
     *
     * @param array<string, mixed> $changes
     */
    private function settings(array $changes, string $reason, string $tab, Request $request): Response
    {
        $errors = [];
        foreach ($changes as $key => $value) {
            $setting = SettingKey::from($key);
            try {
                $setting->definition()->normalize($setting, $value);
            } catch (InvalidSettingValue $e) {
                $errors[] = $e->getMessage();
            }
        }
        if ([] === $errors) {
            foreach ($changes as $key => $value) {
                $this->commands->dispatch(new ChangeSetting($key, $value, $this->adminId(), $reason, $request->getClientIp()));
            }
            $this->addFlash('success', 'Réglages enregistrés : ils s’appliquent sous quelques minutes (cache des paramètres).');
        }
        foreach ($errors as $error) {
            $this->addFlash('error', $error);
        }

        return $this->redirectToRoute('admin_seo', ['onglet' => $tab], Response::HTTP_SEE_OTHER);
    }

    /** @return array<string, mixed> */
    private function templatesTab(Request $request, SeoMeta $meta): array
    {
        $type = $request->query->getString('type', self::DEFAULT_TYPE);
        $type = \array_key_exists($type, SeoPageTypes::TYPES) ? $type : self::DEFAULT_TYPE;
        $templates = $meta->templates();
        $current = $templates[$type] ?? ['title' => null, 'description' => null];
        $sample = SeoPageTypes::SAMPLES[$type];

        return [
            'types' => SeoPageTypes::TYPES, 'type' => $type, 'template' => $current, 'overrides' => $meta->overrides(),
            'advised' => ['title' => SeoPageTypes::TITLE_ADVISED, 'description' => SeoPageTypes::DESCRIPTION_ADVISED],
            'preview' => [
                'path' => $sample['path'],
                'title' => SeoPageTypes::render($current['title'], $sample['values']),
                'description' => SeoPageTypes::render($current['description'], $sample['values']),
            ],
        ];
    }

    /** @return array<string, mixed> */
    private function redirectsTab(Request $request, RedirectStore $redirects, NotFoundLog $notFound, SitemapController $sitemaps, string $search, int $page): array
    {
        $type = $request->query->getString('type');
        $type = \in_array($type, self::REDIRECT_TYPES, true) ? $type : '';
        $list = '404' === $type ? ['total' => 0, 'items' => []] : $redirects->list($search, ($page - 1) * self::PER_PAGE, self::PER_PAGE, '' === $type ? null : (int) $type);
        $missing = [];
        if (\in_array($type, ['', '404'], true) && 1 === $page) {
            $rows = array_values(array_filter($notFound->top(self::NOT_FOUND_ROWS), static fn (array $r): bool => '' === $search || str_contains(mb_strtolower($r['path']), mb_strtolower($search))));
            $missing = $this->withSuggestions($rows, $sitemaps->knownPaths());
        }

        return [
            'type' => $type, 'redirects' => $list, 'not_found' => $missing,
            'prefill' => ['source' => $request->query->getString('source'), 'target' => $request->query->getString('cible'), 'status' => $request->query->getInt('code', 301)],
        ];
    }

    /**
     * Suggestion de redirection : l'adresse indexable connue la plus proche (distance d'édition), si elle est assez proche.
     *
     * @param list<array{path: string, hits: int, last_referer: ?string, last_seen_at: string}> $rows
     * @param list<string>                                                                      $known
     *
     * @return list<array{path: string, hits: int, last_referer: ?string, last_seen_at: string, suggestion: ?string}>
     */
    private function withSuggestions(array $rows, array $known): array
    {
        return array_map(static function (array $row) use ($known): array {
            $best = null;
            $distance = self::SUGGESTION_MAX_DISTANCE + 1;
            $path = mb_strtolower(substr($row['path'], 0, 255));
            foreach ($known as $candidate) {
                $d = levenshtein($path, substr($candidate, 0, 255));
                if ($d < $distance) {
                    [$best, $distance] = [$candidate, $d];
                }
            }

            return $row + ['suggestion' => $best];
        }, $rows);
    }

    private function csv(Request $request): string
    {
        $file = $request->files->get('csv');
        if ($file instanceof UploadedFile && $file->isValid() && $file->getSize() <= self::CSV_MAX_BYTES) {
            $content = file_get_contents($file->getPathname());

            return \is_string($content) ? $content : '';
        }

        return $request->request->getString('csv_text');
    }

    /** @param array<string, string> $params */
    private function run(Command $command, string $success, string $tab, array $params = []): Response
    {
        try {
            $this->commands->dispatch($command);
            $this->addFlash('success', $success);
        } catch (SeoRuleViolation $e) {
            $this->addFlash('error', $e->getMessage());
        }

        return $this->redirectToRoute('admin_seo', ['onglet' => $tab] + $params, Response::HTTP_SEE_OTHER);
    }

    private function adminId(): string
    {
        return $this->admin->id() ?? throw new AccessDeniedHttpException();
    }

    private function assertCsrf(Request $request, string $id): void
    {
        if (!$this->isCsrfTokenValid($id, $request->request->getString('_token'))) {
            throw new AccessDeniedHttpException('Jeton CSRF invalide.');
        }
    }
}
