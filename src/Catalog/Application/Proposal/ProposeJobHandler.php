<?php

declare(strict_types=1);

namespace App\Catalog\Application\Proposal;

use App\Catalog\Application\CatalogAudit;
use App\Catalog\Application\JobSlugPolicy;
use App\Catalog\Domain\Category\Category;
use App\Catalog\Domain\Category\CategoryId;
use App\Catalog\Domain\Category\CategoryRepository;
use App\Catalog\Domain\Exception\CatalogRuleViolation;
use App\Catalog\Domain\Job\Job;
use App\Catalog\Domain\Job\JobId;
use App\Catalog\Domain\Job\JobRepository;
use App\Catalog\Domain\Label;
use App\Shared\Application\Bus\CommandHandler;
use App\Shared\Application\Bus\EventBus;
use App\Shared\Domain\Identifier\IdGenerator;
use App\Shared\Domain\Time\Clock;
use App\Shared\Domain\ValueObject\Slug;

/**
 * Le métier (et la catégorie nouvelle) sont créés INACTIFS : ils n'apparaissent nulle part (recherche, menus, filtres,
 * pages, inscription pro) tant que le back-office ne les a pas validés. Un nom déjà proposé réutilise le même métier.
 */
final readonly class ProposeJobHandler implements CommandHandler
{
    public const string NEW_CATEGORY_ICON = 'briefcase';
    /** Garde-fou anti-abus : propositions par compte (ou par l'ensemble des visiteurs) sur une heure. */
    private const int MAX_PER_HOUR_USER = 5;
    private const int MAX_PER_HOUR_VISITORS = 30;

    public function __construct(
        private CategoryRepository $categories,
        private JobRepository $jobs,
        private JobProposalStore $proposals,
        private EventBus $events,
        private IdGenerator $ids,
        private Clock $clock,
        private CatalogAudit $audit,
    ) {
    }

    public function __invoke(ProposeJob $command): void
    {
        $now = $this->clock->now();
        $limit = null === $command->userId ? self::MAX_PER_HOUR_VISITORS : self::MAX_PER_HOUR_USER;
        if ($this->proposals->countSince($command->userId, $now->modify('-1 hour')) >= $limit) {
            throw new CatalogRuleViolation('Trop de propositions récentes : choisissez la prestation la plus proche dans la liste ou réessayez plus tard.');
        }
        $label = new Label(self::clean($command->jobName));
        $slug = Slug::fromText($label->value);
        try {
            JobSlugPolicy::assertAllowed($slug);
        } catch (CatalogRuleViolation) {
            throw new CatalogRuleViolation('Ce nom de prestation n’est pas utilisable : reformulez-le.');
        }

        $job = $this->jobs->findBySlug($slug);
        $newCategory = false;
        if (null !== $job) {
            $category = $this->categories->get($job->categoryId());
        } else {
            [$category, $newCategory] = $this->category($command, $now);
            $job = Job::create(new JobId($this->ids->next()), $category->id(), $label, $slug, $this->jobs->nextPosition($category->id()), $now);
            $job->deactivate(null, 0, $now); // invisible jusqu'à la validation
            $this->jobs->add($job);
        }
        // Un métier déjà actif (nom saisi au lieu d'être choisi) est repris sans validation.
        $status = $job->isActive() && $category->isActive() ? 'merged' : JobProposalView::PENDING;
        $this->proposals->add($command->proposalId, $job->id()->value, $category->id()->value, $newCategory, $label->value, null === $command->categorySlug ? self::clean((string) $command->categoryName) : null, $command->userId, $status, $now);

        $this->events->publish(...$category->releaseEvents(), ...$job->releaseEvents());
        $this->audit->record(null, 'job_proposed', 'job', $job->id()->value, ['proposal' => $command->proposalId, 'status' => $status, 'new_category' => $newCategory]);
    }

    /** @return array{0: Category, 1: bool} catégorie existante active, ou nouvelle (inactive) */
    private function category(ProposeJob $command, \DateTimeImmutable $now): array
    {
        if (null !== $command->categorySlug) {
            $category = $this->categories->findBySlug(new Slug($command->categorySlug));
            if (null === $category || !$category->isActive() || !$category->isRoot()) {
                throw new CatalogRuleViolation('Choisissez une catégorie de la liste.');
            }

            return [$category, false];
        }
        $label = new Label(self::clean((string) $command->categoryName));
        $existing = $this->categories->findBySlug(Slug::fromText($label->value));
        if (null !== $existing) {
            return [$existing, false];
        }
        $category = Category::create(new CategoryId($this->ids->next()), null, $label, Slug::fromText($label->value), self::NEW_CATEGORY_ICON, $this->categories->nextPosition(null), $now);
        $category->deactivate($now);
        $this->categories->add($category);

        return [$category, true];
    }

    private static function clean(string $text): string
    {
        $text = trim((string) preg_replace('/\s+/u', ' ', strip_tags($text)));

        return mb_strtoupper(mb_substr($text, 0, 1)).mb_substr($text, 1);
    }
}
