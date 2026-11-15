<?php

declare(strict_types=1);

namespace App\Content\Application\Page;

use App\Content\Application\Media\MediaLibrary;
use App\Content\Application\Port\FaqStore;
use App\Content\Application\Slug;
use App\Shared\Application\Audit\AuditTrail;
use App\Shared\Application\Bus\CommandHandler;
use App\Shared\Domain\Audit\AuditRecord;
use App\Shared\Domain\Identifier\IdGenerator;
use App\Shared\Domain\Time\Clock;

/**
 * BO10.2 — pages : création (slug unique), méta SEO, blocs (ajout, enregistrement validé, ordre, suppression), publication
 * immédiate ou programmée, retrait, suppression. Champs validés selon PageBlockType::fields() ; texte riche assaini ;
 * images de la médiathèque prêtes ; liens internes (/…) ou https. Journalisé (`content.page_*`).
 */
final readonly class PageHandler implements CommandHandler
{
    private const int TITLE_MAX = 120;
    private const int SLUG_MAX = 80;
    private const int META_TITLE_MAX = 70;
    private const int META_DESCRIPTION_MAX = 170;
    private const int BLOCKS_MAX = 30;

    public function __construct(
        private PageStore $pages,
        private MediaLibrary $media,
        private FaqStore $faq,
        private RichText $richText,
        private AuditTrail $audit,
        private IdGenerator $ids,
        private Clock $clock,
    ) {
    }

    public function __invoke(PageCommand $command): void
    {
        $now = $this->clock->now();
        if ('create' === $command->action) {
            $title = $this->title($command->data['title'] ?? '');
            $slug = $this->slug(\is_string($command->data['slug'] ?? null) && '' !== trim($command->data['slug']) ? $command->data['slug'] : $title, null);
            $this->pages->create($command->pageId, $slug, $title, $command->adminId, $now);
            $this->record($command, $now);

            return;
        }
        $page = $this->pages->find($command->pageId) ?? throw new PageRejected('page', 'Page introuvable.');
        match ($command->action) {
            'meta' => $this->meta($page, $command, $now),
            'add_block', 'save_block', 'move_up', 'move_down', 'remove_block' => $this->pages->saveDraft($page->id, $this->blocks($page->draft, $command), $command->adminId, $now),
            'publish' => $this->pages->publish($page->id, $now, false, $command->adminId, $now),
            'schedule' => $this->pages->publish($page->id, $this->scheduleAt($command->at, $now), true, $command->adminId, $now),
            'unpublish' => $this->pages->unpublish($page->id, $command->adminId, $now),
            'delete' => $this->pages->delete($page->id),
            default => throw new PageRejected('action', 'Action inconnue.'),
        };
        $this->record($command, $now);
    }

    private function meta(PageView $page, PageCommand $command, \DateTimeImmutable $now): void
    {
        $title = $this->title($command->data['title'] ?? '');
        $slug = $this->slug(\is_string($command->data['slug'] ?? null) ? $command->data['slug'] : '', $page->id);
        $metaTitle = $this->optional($command->data['meta_title'] ?? null, self::META_TITLE_MAX, 'meta_title', 'Titre SEO');
        $metaDescription = $this->optional($command->data['meta_description'] ?? null, self::META_DESCRIPTION_MAX, 'meta_description', 'Description SEO');
        $this->pages->saveMeta($page->id, $slug, $title, $metaTitle, $metaDescription, true === ($command->data['noindex'] ?? false), $command->adminId, $now);
    }

    /**
     * @param list<array{id: string, type: string, data: array<string, mixed>}> $blocks
     *
     * @return list<array{id: string, type: string, data: array<string, mixed>}>
     */
    private function blocks(array $blocks, PageCommand $command): array
    {
        if ('add_block' === $command->action) {
            $type = PageBlockType::tryFrom(\is_string($command->data['type'] ?? null) ? $command->data['type'] : '') ?? throw new PageRejected('type', 'Choisissez un type de bloc.');
            if (\count($blocks) >= self::BLOCKS_MAX) {
                throw new PageRejected('type', \sprintf('%d blocs au maximum par page.', self::BLOCKS_MAX));
            }
            $blocks[] = ['id' => $this->ids->next(), 'type' => $type->value, 'data' => $type->defaults()];

            return $blocks;
        }
        $index = null;
        foreach ($blocks as $i => $block) {
            $index = $block['id'] === $command->blockId ? $i : $index;
        }
        if (null === $index) {
            throw new PageRejected('block', 'Bloc introuvable.');
        }
        switch ($command->action) {
            case 'remove_block':
                array_splice($blocks, $index, 1);
                break;
            case 'move_up':
            case 'move_down':
                $target = 'move_up' === $command->action ? $index - 1 : $index + 1;
                if (isset($blocks[$target])) {
                    [$blocks[$index], $blocks[$target]] = [$blocks[$target], $blocks[$index]];
                }
                break;
            default:
                $type = PageBlockType::from($blocks[$index]['type']);
                $blocks[$index] = ['id' => $blocks[$index]['id'], 'type' => $type->value, 'data' => $this->validate($type->fields(), $command->data)];
        }

        return array_values($blocks);
    }

    /**
     * @param array<string, array<string, mixed>> $fields
     * @param array<string, mixed>                $input
     *
     * @return array<string, mixed>
     */
    private function validate(array $fields, array $input, string $prefix = ''): array
    {
        $data = [];
        foreach ($fields as $name => $field) {
            $label = \is_string($field['label'] ?? null) ? $field['label'] : $name;
            $raw = $input[$name] ?? null;
            $required = true === ($field['required'] ?? false);
            if ('items' === $field['type']) {
                $items = [];
                foreach (\is_array($raw) ? $raw : [] as $item) {
                    /** @var array<string, mixed> $item */
                    $item = \is_array($item) ? $item : [];
                    if ('' === trim(implode('', array_map(static fn (mixed $v): string => \is_string($v) ? $v : '', $item)))) {
                        continue; // emplacement vide
                    }
                    /** @var array<string, array<string, mixed>> $sub */
                    $sub = $field['fields'];
                    $items[] = $this->validate($sub, $item, $prefix.$label.' : ');
                }
                $min = \is_int($field['min'] ?? null) ? $field['min'] : 0;
                $max = \is_int($field['max'] ?? null) ? $field['max'] : PageBlockType::CARDS_MAX;
                if (\count($items) < $min || \count($items) > $max) {
                    throw new PageRejected($name, \sprintf('%s : entre %d et %d éléments.', $label, $min, $max));
                }
                $data[$name] = $items;
                continue;
            }
            $value = \is_scalar($raw) ? trim((string) $raw) : '';
            if ($required && '' === $value) {
                throw new PageRejected($name, $prefix.$label.' : champ obligatoire.');
            }
            $max = \is_int($field['max'] ?? null) ? $field['max'] : null;
            $data[$name] = match ($field['type']) {
                'int' => $this->int($value, $field, $prefix.$label),
                'choice' => $this->choice($value, \is_array($field['choices'] ?? null) ? $field['choices'] : []),
                'html' => '' === $value ? '' : $this->richText->sanitize($value),
                'link' => $this->link($value, $prefix.$label),
                'media' => $this->mediaId($value, $prefix.$label),
                'faq_topic' => $this->topic($value),
                default => $value,
            };
            if (null !== $max && \is_string($data[$name]) && mb_strlen($data[$name]) > $max) {
                throw new PageRejected($name, \sprintf('%s : %d caractères au maximum.', $prefix.$label, $max));
            }
        }

        return $data;
    }

    /** @param array<mixed> $choices */
    private function choice(string $value, array $choices): string
    {
        $first = array_key_first($choices);

        return \array_key_exists($value, $choices) ? $value : (null === $first ? '' : (string) $first);
    }

    /** @param array<string, mixed> $field */
    private function int(string $value, array $field, string $label): int
    {
        $min = \is_int($field['min'] ?? null) ? $field['min'] : 0;
        $max = \is_int($field['max'] ?? null) ? $field['max'] : \PHP_INT_MAX;
        if (1 !== preg_match('/^\d+$/', $value) || (int) $value < $min || (int) $value > $max) {
            throw new PageRejected('int', \sprintf('%s : nombre entre %d et %d.', $label, $min, $max));
        }

        return (int) $value;
    }

    private function link(string $value, string $label): string
    {
        if ('' === $value || (str_starts_with($value, '/') && !str_starts_with($value, '//')) || 1 === preg_match('#^https://[^\s/$.?\#].[^\s]*$#iu', $value)) {
            return $value;
        }
        throw new PageRejected('link', $label.' : saisissez un chemin du site (/…) ou une adresse https://.');
    }

    private function mediaId(string $value, string $label): string
    {
        if ('' === $value) {
            return '';
        }
        $media = $this->media->find($value);
        if (null === $media || !$media->ready()) {
            throw new PageRejected('media', $label.' : choisissez une image prête de la médiathèque.');
        }

        return $media->id;
    }

    private function topic(string $value): string
    {
        foreach ($this->faq->topics() as $topic) {
            if ($topic->slug === $value) {
                return $value;
            }
        }
        throw new PageRejected('topic', 'Choisissez une rubrique de l’aide.');
    }

    private function title(mixed $value): string
    {
        $title = \is_string($value) ? trim((string) preg_replace('/\s+/u', ' ', $value)) : '';
        if ('' === $title || mb_strlen($title) > self::TITLE_MAX) {
            throw new PageRejected('title', \sprintf('Titre obligatoire (%d caractères au maximum).', self::TITLE_MAX));
        }

        return $title;
    }

    /** Adresse : minuscules, chiffres et tirets (accents retirés), unique. */
    private function slug(string $value, ?string $exceptId): string
    {
        $slug = Slug::make($value, self::SLUG_MAX);
        if ('' === $slug) {
            throw new PageRejected('slug', 'Adresse de la page invalide.');
        }
        if ($this->pages->slugTaken($slug, $exceptId)) {
            throw new PageRejected('slug', 'Cette adresse est déjà utilisée par une autre page.');
        }

        return $slug;
    }

    private function optional(mixed $value, int $max, string $field, string $label): ?string
    {
        $value = \is_string($value) ? trim($value) : '';
        if (mb_strlen($value) > $max) {
            throw new PageRejected($field, \sprintf('%s : %d caractères au maximum.', $label, $max));
        }

        return '' === $value ? null : $value;
    }

    private function scheduleAt(?string $at, \DateTimeImmutable $now): \DateTimeImmutable
    {
        try {
            $date = null === $at || '' === $at ? null : new \DateTimeImmutable($at, new \DateTimeZone('Europe/Paris'));
        } catch (\Exception) {
            $date = null;
        }
        if (null === $date || $date <= $now) {
            throw new PageRejected('at', 'Choisissez une date de publication à venir.');
        }

        return $date;
    }

    private function record(PageCommand $command, \DateTimeImmutable $now): void
    {
        $this->audit->record(new AuditRecord(id: $this->ids->next(), occurredAt: $now, actorType: 'admin', actorId: $command->adminId, action: 'content.page_'.$command->action, subjectType: 'page', subjectId: $command->pageId));
    }
}
