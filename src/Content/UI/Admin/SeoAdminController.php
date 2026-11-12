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
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * BO12 (/admin/seo) — gabarits title / description par type de page et surcharges page par page (BO12.1),
 * redirections 301 / 302 et import CSV, rapport des 404 avec suggestion (BO12.2), lignes du robots.txt (BO12.3),
 * activation des données structurées par type (BO12.4).
 */
#[Route('/admin/seo')]
final class SeoAdminController extends AbstractController
{
    public const array TABS = ['gabarits' => 'Meta et gabarits', 'surcharges' => 'Surcharges', 'redirections' => 'Redirections', '404' => 'Rapport des 404', 'robots' => 'Robots et données structurées'];
    private const array SCHEMAS = [
        'organization' => [SettingKey::SeoSchemaOrganization, 'Organization / WebSite (accueil)'],
        'local_business' => [SettingKey::SeoSchemaLocalBusiness, 'LocalBusiness (fiches artisans)'],
        'faq' => [SettingKey::SeoSchemaFaq, 'FAQPage (aide, pages métier)'],
        'breadcrumb' => [SettingKey::SeoSchemaBreadcrumb, 'BreadcrumbList (fil d’Ariane)'],
        'article' => [SettingKey::SeoSchemaArticle, 'Article (blog, V2)'],
    ];
    private const int PER_PAGE = 50;
    private const int NOT_FOUND_ROWS = 100;
    private const int SUGGESTION_MAX_DISTANCE = 20;
    private const int CSV_MAX_BYTES = 1_048_576;

    public function __construct(private readonly CommandBus $commands, private readonly CurrentAdministrator $admin)
    {
    }

    #[Route('', name: 'admin_seo', methods: ['GET'])]
    #[IsGranted('admin.seo.view')]
    public function index(Request $request, SeoMeta $meta, RedirectStore $redirects, NotFoundLog $notFound, SettingsProvider $settings, SitemapController $sitemaps): Response
    {
        $tab = $request->query->getString('onglet', 'gabarits');
        $tab = \array_key_exists($tab, self::TABS) ? $tab : 'gabarits';
        $search = trim($request->query->getString('q'));
        $page = max(1, $request->query->getInt('page', 1));
        $data = ['tab' => $tab, 'tabs' => self::TABS, 'search' => $search, 'page' => $page, 'per_page' => self::PER_PAGE];

        $data += match ($tab) {
            'gabarits' => ['types' => SeoPageTypes::TYPES, 'templates' => $meta->templates()],
            'surcharges' => ['overrides' => $meta->overrides()],
            'redirections' => ['redirects' => $redirects->list($search, ($page - 1) * self::PER_PAGE, self::PER_PAGE), 'prefill' => ['source' => $request->query->getString('source'), 'target' => $request->query->getString('cible')]],
            '404' => ['not_found' => $this->withSuggestions($notFound->top(self::NOT_FOUND_ROWS), $sitemaps->knownPaths())],
            default => [
                'robots_extra' => implode("\n", $settings->stringList(SettingKey::SeoRobotsExtra)),
                'schemas' => array_map(static fn (array $s): array => ['label' => $s[1], 'enabled' => $settings->bool($s[0])], self::SCHEMAS),
            ],
        };

        return $this->render('admin/seo/index.html.twig', $data);
    }

    #[Route('/gabarits/{type}', name: 'admin_seo_template', requirements: ['type' => '[a-z_]+'], methods: ['POST'])]
    #[IsGranted('admin.seo.edit')]
    public function template(Request $request, string $type): Response
    {
        $this->assertCsrf($request, 'admin_seo_template_'.$type);

        return $this->run(new SaveSeoTemplate($this->adminId(), $type, $request->request->getString('title'), $request->request->getString('description')), 'Gabarit enregistré.', 'gabarits');
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

        return $this->run($command, $command instanceof DeleteSeoOverride ? 'Surcharge retirée.' : 'Surcharge enregistrée.', 'surcharges');
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

        return $this->run(new DismissNotFound($this->adminId(), $request->request->getString('path')), 'Adresse retirée du rapport.', '404');
    }

    #[Route('/robots', name: 'admin_seo_robots', methods: ['POST'])]
    #[IsGranted('admin.seo.edit')]
    public function robots(Request $request): Response
    {
        $this->assertCsrf($request, 'admin_seo_robots');
        $reason = 'BO12 : référencement';
        try {
            $lines = array_values(array_filter(array_map('trim', preg_split('/\R/', $request->request->getString('robots_extra')) ?: []), static fn (string $l): bool => '' !== $l));
            $this->commands->dispatch(new ChangeSetting(SettingKey::SeoRobotsExtra->value, $lines, $this->adminId(), $reason));
            foreach (self::SCHEMAS as $name => [$key]) {
                $this->commands->dispatch(new ChangeSetting($key->value, $request->request->getBoolean('schema_'.$name), $this->adminId(), $reason));
            }
            $this->addFlash('success', 'Réglages enregistrés : ils s’appliquent sous quelques minutes (cache des paramètres).');
        } catch (InvalidSettingValue $e) {
            $this->addFlash('error', $e->getMessage());
        }

        return $this->redirectToRoute('admin_seo', ['onglet' => 'robots'], Response::HTTP_SEE_OTHER);
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

    private function run(Command $command, string $success, string $tab): Response
    {
        try {
            $this->commands->dispatch($command);
            $this->addFlash('success', $success);
        } catch (SeoRuleViolation $e) {
            $this->addFlash('error', $e->getMessage());
        }

        return $this->redirectToRoute('admin_seo', ['onglet' => $tab], Response::HTTP_SEE_OTHER);
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
