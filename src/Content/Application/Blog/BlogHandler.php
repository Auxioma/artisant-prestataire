<?php

declare(strict_types=1);

namespace App\Content\Application\Blog;

use App\Content\Application\Media\MediaLibrary;
use App\Content\Application\Page\RichText;
use App\Content\Application\Slug;
use App\Shared\Application\Audit\AuditTrail;
use App\Shared\Application\Bus\CommandHandler;
use App\Shared\Domain\Audit\AuditRecord;
use App\Shared\Domain\Identifier\IdGenerator;
use App\Shared\Domain\Time\Clock;

/**
 * BO10.3 — blog : création et édition d'un article (texte riche assaini comme les pages CMS, images de la médiathèque),
 * publication immédiate, programmée ou retrait ; catégories. Chaque action est tracée dans le journal d'audit.
 */
final readonly class BlogHandler implements CommandHandler
{
    private const int TITLE_MAX = 120;
    private const int SHORT_TITLE_MAX = 40;
    private const int SLUG_MAX = 100;
    private const int EXCERPT_MAX = 300;
    private const int AUTHOR_MAX = 80;
    private const int HTML_MAX = 50000;
    private const int META_TITLE_MAX = 70;
    private const int META_DESCRIPTION_MAX = 170;
    private const int JOBS_MAX = 6;
    private const int CATEGORY_MAX = 60;
    private const int CATEGORY_SLUG_MAX = 80;
    private const int POSITION_MAX = 999;
    private const string UUID = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/';

    public function __construct(
        private BlogStore $blog,
        private MediaLibrary $media,
        private RichText $richText,
        private AuditTrail $audit,
        private IdGenerator $ids,
        private Clock $clock,
    ) {
    }

    public function __invoke(BlogCommand $command): void
    {
        $now = $this->clock->now();
        match ($command->action) {
            'create' => $this->create($command, $now),
            'save' => $this->save($this->article($command->id), $command, $now),
            'publish' => $this->publish($this->article($command->id), $command, $now),
            'schedule' => $this->blog->setPublishedAt($this->article($command->id)->id, $this->scheduleAt($command->at, $now), $command->adminId, $now),
            'unpublish' => $this->blog->setPublishedAt($this->article($command->id)->id, null, $command->adminId, $now),
            'delete' => $this->blog->delete($this->article($command->id)->id),
            'create_category' => $this->saveCategory($command, null, $now),
            'save_category' => $this->saveCategory($command, $this->blog->category($command->id) ?? throw new BlogRejected('category', 'Catégorie introuvable.'), $now),
            'delete_category' => $this->blog->deleteCategory(($this->blog->category($command->id) ?? throw new BlogRejected('category', 'Catégorie introuvable.'))->id),
            default => throw new BlogRejected('action', 'Action inconnue.'),
        };
        $this->audit->record(new AuditRecord(
            id: $this->ids->next(),
            occurredAt: $now,
            actorType: 'admin',
            actorId: $command->adminId,
            action: 'content.blog_'.$command->action,
            subjectType: str_ends_with($command->action, '_category') ? 'blog_category' : 'blog_article',
            subjectId: $command->id,
        ));
    }

    private function create(BlogCommand $command, \DateTimeImmutable $now): void
    {
        $title = $this->title($command->data['title'] ?? '');
        $this->blog->create($command->id, $this->slug($title, null), $title, $command->adminId, $now);
    }

    private function save(BlogArticle $article, BlogCommand $command, \DateTimeImmutable $now): void
    {
        $d = $command->data;
        $title = $this->title($d['title'] ?? '');
        $html = $this->richText->sanitize(\is_string($d['content_html'] ?? null) ? $d['content_html'] : '');
        if (mb_strlen($html) > self::HTML_MAX) {
            throw new BlogRejected('content_html', \sprintf('Texte de l’article : %d caractères au maximum.', self::HTML_MAX));
        }
        $categoryId = $this->optionalId($d['category_id'] ?? null);
        if (null !== $categoryId && null === $this->blog->category($categoryId)) {
            throw new BlogRejected('category_id', 'Catégorie introuvable.');
        }
        $jobIds = array_values(array_unique(array_filter(\is_array($d['job_ids'] ?? null) ? $d['job_ids'] : [], static fn (mixed $id): bool => \is_string($id) && 1 === preg_match(self::UUID, $id))));
        if (\count($jobIds) > self::JOBS_MAX) {
            throw new BlogRejected('job_ids', \sprintf('%d métiers liés au maximum.', self::JOBS_MAX));
        }
        $slugInput = \is_string($d['slug'] ?? null) && '' !== trim($d['slug']) ? $d['slug'] : $title;

        $this->blog->save($article->id, [
            'slug' => $this->slug($slugInput, $article->id),
            'title' => $title,
            'short_title' => $this->optional($d['short_title'] ?? null, self::SHORT_TITLE_MAX, 'short_title', 'Titre court (fil d’Ariane)'),
            'excerpt' => $this->optional($d['excerpt'] ?? null, self::EXCERPT_MAX, 'excerpt', 'Résumé'),
            'category_id' => $categoryId,
            'author_name' => $this->optional($d['author_name'] ?? null, self::AUTHOR_MAX, 'author_name', 'Auteur'),
            'author_media_id' => $this->media($d['author_media_id'] ?? null, 'author_media_id'),
            'cover_media_id' => $this->media($d['cover_media_id'] ?? null, 'cover_media_id'),
            'content_html' => $html,
            'job_ids' => $jobIds,
            'featured' => true === ($d['featured'] ?? false),
            'meta_title' => $this->optional($d['meta_title'] ?? null, self::META_TITLE_MAX, 'meta_title', 'Titre SEO'),
            'meta_description' => $this->optional($d['meta_description'] ?? null, self::META_DESCRIPTION_MAX, 'meta_description', 'Description SEO'),
        ], $command->adminId, $now);
    }

    /** Un article déjà en ligne garde sa date de publication ; un brouillon ou un article programmé paraît maintenant. */
    private function publish(BlogArticle $article, BlogCommand $command, \DateTimeImmutable $now): void
    {
        if ('' === trim(strip_tags($article->html))) {
            throw new BlogRejected('content_html', 'Rédigez le texte de l’article avant de le publier.');
        }
        if ($article->isVisible($now)) {
            return;
        }
        $this->blog->setPublishedAt($article->id, $now, $command->adminId, $now);
    }

    private function saveCategory(BlogCommand $command, ?BlogCategory $category, \DateTimeImmutable $now): void
    {
        $name = \is_string($command->data['name'] ?? null) ? trim((string) preg_replace('/\s+/u', ' ', $command->data['name'])) : '';
        if ('' === $name || mb_strlen($name) > self::CATEGORY_MAX) {
            throw new BlogRejected('name', \sprintf('Nom de la catégorie obligatoire (%d caractères au maximum).', self::CATEGORY_MAX));
        }
        $slug = Slug::make(\is_string($command->data['slug'] ?? null) && '' !== trim($command->data['slug']) ? $command->data['slug'] : $name, self::CATEGORY_SLUG_MAX);
        if ('' === $slug) {
            throw new BlogRejected('slug', 'Adresse de la catégorie invalide.');
        }
        if ($this->blog->categorySlugTaken($slug, $category?->id)) {
            throw new BlogRejected('slug', 'Cette adresse est déjà utilisée par une autre catégorie.');
        }
        $position = $command->data['position'] ?? null;
        $position = is_numeric($position) ? max(0, min(self::POSITION_MAX, (int) $position)) : ($category->position ?? \count($this->blog->categories($now)));
        $this->blog->saveCategory($command->id, $slug, $name, $position, $now);
    }

    private function article(string $id): BlogArticle
    {
        return $this->blog->find($id) ?? throw new BlogRejected('article', 'Article introuvable.');
    }

    private function title(mixed $value): string
    {
        $title = \is_string($value) ? trim((string) preg_replace('/\s+/u', ' ', $value)) : '';
        if ('' === $title || mb_strlen($title) > self::TITLE_MAX) {
            throw new BlogRejected('title', \sprintf('Titre obligatoire (%d caractères au maximum).', self::TITLE_MAX));
        }

        return $title;
    }

    private function slug(string $value, ?string $exceptId): string
    {
        $slug = Slug::make($value, self::SLUG_MAX);
        if ('' === $slug) {
            throw new BlogRejected('slug', 'Adresse de l’article invalide.');
        }
        if ($this->blog->slugTaken($slug, $exceptId)) {
            throw new BlogRejected('slug', 'Cette adresse est déjà utilisée par un autre article.');
        }

        return $slug;
    }

    private function optional(mixed $value, int $max, string $field, string $label): ?string
    {
        $value = \is_string($value) ? trim($value) : '';
        if (mb_strlen($value) > $max) {
            throw new BlogRejected($field, \sprintf('%s : %d caractères au maximum.', $label, $max));
        }

        return '' === $value ? null : $value;
    }

    private function optionalId(mixed $value): ?string
    {
        return \is_string($value) && 1 === preg_match(self::UUID, $value) ? $value : null;
    }

    private function media(mixed $value, string $field): ?string
    {
        $id = $this->optionalId($value);
        if (null !== $id && null === $this->media->find($id)) {
            throw new BlogRejected($field, 'Image introuvable dans la médiathèque.');
        }

        return $id;
    }

    private function scheduleAt(?string $at, \DateTimeImmutable $now): \DateTimeImmutable
    {
        try {
            $date = null === $at || '' === $at ? null : new \DateTimeImmutable($at, new \DateTimeZone('Europe/Paris'));
        } catch (\Exception) {
            $date = null;
        }
        if (null === $date || $date <= $now) {
            throw new BlogRejected('at', 'Choisissez une date de publication à venir.');
        }

        return $date;
    }
}
