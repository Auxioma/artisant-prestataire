<?php

declare(strict_types=1);

namespace App\Content\UI\Http;

use App\Catalog\Application\Query\JobIndexEntry;
use App\Content\Application\Faq\FaqQuery;
use App\Content\Application\Page\PageQuery;
use App\Content\Application\Page\PageView;
use App\Shared\Application\Bus\QueryBus;
use App\Shared\Domain\Time\Clock;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;

/** BO10.2 — page éditoriale publiée (`/pages/{adresse}`) ; l'aperçu du back-office rend le brouillon avec le même gabarit. */
final class PageController extends AbstractController
{
    public function __construct(private readonly QueryBus $queries, private readonly HomePageData $data, private readonly Clock $clock)
    {
    }

    #[Route('/pages/{slug}', name: 'content_page', requirements: ['slug' => '[a-z0-9]+(?:-[a-z0-9]+)*'], methods: ['GET'])]
    public function show(string $slug): Response
    {
        $page = $this->queries->ask(new PageQuery(slug: $slug));
        $blocks = $page instanceof PageView ? $page->visible($this->clock->now()) : null;
        if (!$page instanceof PageView || null === $blocks) {
            throw new NotFoundHttpException();
        }

        return $this->renderPage($page, $blocks, false);
    }

    /** @param list<array{id: string, type: string, data: array<string, mixed>}> $blocks */
    public function renderPage(PageView $page, array $blocks, bool $preview): Response
    {
        $context = ['faq' => [], 'artisans' => []];
        foreach ($blocks as $block) {
            if ('faq' === $block['type'] && \is_string($block['data']['topic'] ?? null)) {
                $questions = $this->queries->ask(FaqQuery::topic($block['data']['topic']));
                $context['faq'][$block['id']] = \is_array($questions) ? $questions : [];
            }
            if ('artisans' === $block['type']) {
                $count = $block['data']['count'] ?? 6;
                $context['artisans'][$block['id']] = $this->data->featured(\is_int($count) ? $count : 6);
            }
        }

        return $this->render('content/page.html.twig', [
            'page' => $page,
            'blocks' => $blocks,
            'context' => $context,
            'job_names' => [] === $context['artisans'] ? [] : array_map(static fn (JobIndexEntry $j): string => $j->name, $this->data->jobs()),
            'preview' => $preview,
        ]);
    }
}
