<?php

declare(strict_types=1);

namespace App\Catalog\Application\Proposal;

use App\Catalog\Application\CatalogAudit;
use App\Catalog\Application\JobSlugPolicy;
use App\Catalog\Domain\Category\CategoryRepository;
use App\Catalog\Domain\Event\JobProposalDecided;
use App\Catalog\Domain\Exception\CatalogRuleViolation;
use App\Catalog\Domain\Job\JobId;
use App\Catalog\Domain\Job\JobRepository;
use App\Catalog\Domain\Label;
use App\Shared\Application\Bus\CommandHandler;
use App\Shared\Application\Bus\EventBus;
use App\Shared\Domain\Time\Clock;
use App\Shared\Domain\ValueObject\Slug;

/**
 * Ajout : catégorie et métier activés → JobChanged / CategoryChanged (réindexation de la recherche, méga-menu, pages,
 * filtres et listes à jour partout). Rattachement : le métier proposé reste inactif, remplacé par le métier choisi.
 * Dans tous les cas JobProposalDecided : les demandes en attente sont publiées (ou refusées) et le client prévenu.
 */
final readonly class DecideJobProposalHandler implements CommandHandler
{
    private const int NOTE_MAX = 300;

    public function __construct(
        private JobProposalStore $proposals,
        private JobRepository $jobs,
        private CategoryRepository $categories,
        private EventBus $events,
        private Clock $clock,
        private CatalogAudit $audit,
    ) {
    }

    public function __invoke(DecideJobProposal $command): void
    {
        $proposal = $this->proposals->find($command->proposalId);
        if (null === $proposal || !$proposal->isPending()) {
            throw new CatalogRuleViolation('Cette proposition a déjà été traitée.');
        }
        $now = $this->clock->now();
        $job = $this->jobs->get(new JobId($proposal->jobId));
        $note = '' === trim((string) $command->note) ? null : mb_substr(trim((string) $command->note), 0, self::NOTE_MAX);
        $final = null;

        switch ($command->decision) {
            case DecideJobProposal::APPROVE:
                $name = trim((string) $command->name);
                if ('' !== $name && $name !== $job->name()) {
                    $label = new Label($name);
                    $slug = Slug::fromText($label->value);
                    JobSlugPolicy::assertAllowed($slug);
                    $existing = $this->jobs->findBySlug($slug);
                    if (null !== $existing && !$existing->id()->equals($job->id())) {
                        throw new CatalogRuleViolation(\sprintf('Le métier « %s » existe déjà : utilisez « Rattacher à un métier existant ».', $existing->name()));
                    }
                    $job->rename($label, $slug, $now);
                }
                if (null !== $command->categorySlug && '' !== $command->categorySlug) {
                    $target = $this->categories->findBySlug(new Slug($command->categorySlug)) ?? throw new CatalogRuleViolation('Catégorie introuvable.');
                    if (!$target->id()->equals($job->categoryId())) {
                        $job->moveToCategory($target->id(), $this->jobs->nextPosition($target->id()), $now);
                    }
                }
                $category = $this->categories->get($job->categoryId());
                if (!$category->isActive()) {
                    $category->activate($now);
                }
                $job->activate($now);
                $this->events->publish(...$category->releaseEvents(), ...$job->releaseEvents());
                $final = $job;
                $status = 'approved';
                break;
            case DecideJobProposal::MERGE:
                $replacement = $this->jobs->findBySlug(new Slug((string) $command->replacementSlug));
                if (null === $replacement || !$replacement->isActive()) {
                    throw new CatalogRuleViolation('Choisissez le métier existant auquel rattacher cette proposition.');
                }
                $job->deactivate($replacement, 0, $now);
                $this->events->publish(...$job->releaseEvents());
                $final = $replacement;
                $status = 'merged';
                break;
            case DecideJobProposal::REJECT:
                if (null === $note) {
                    throw new CatalogRuleViolation('Indiquez le motif du refus : il est communiqué au client.');
                }
                $status = 'rejected';
                break;
            default:
                throw new CatalogRuleViolation('Décision inconnue.');
        }

        $count = $this->proposals->decidePending($proposal->jobId, $status, $final?->id()->value, $note, $command->adminId, $now);
        $this->events->publish(new JobProposalDecided($proposal->jobId, $proposal->proposedJobName, $final?->id()->value, $final?->name(), $note, $now));
        $this->audit->record($command->adminId, 'job_proposal_'.$status, 'job', $proposal->jobId, ['final' => $final?->slug()->value, 'proposals' => $count]);
    }
}
