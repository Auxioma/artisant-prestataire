<?php

declare(strict_types=1);

namespace App\Content\Application\Home;

use App\Content\Application\Port\HomeVersions;
use App\Content\Domain\Home\HomeBlockType;
use App\Content\Domain\Home\HomeContentViolation;
use App\Shared\Application\Audit\AuditTrail;
use App\Shared\Application\Bus\CommandHandler;
use App\Shared\Domain\Audit\AuditRecord;
use App\Shared\Domain\Identifier\IdGenerator;
use App\Shared\Domain\Time\Clock;

/**
 * BO10.1 — édition de l'accueil sur un brouillon unique (créé depuis la version en ligne, sinon le contenu initial),
 * publication immédiate ou programmée, restauration d'une version. Chaque action est journalisée.
 *
 * @phpstan-import-type Block from HomeVersion
 */
final readonly class HomeEditorHandler implements CommandHandler
{
    public function __construct(
        private HomeVersions $versions,
        private HomeDefaults $defaults,
        private AuditTrail $audit,
        private IdGenerator $ids,
        private Clock $clock,
    ) {
    }

    public function __invoke(SaveHomeBlock|ToggleHomeBlock|MoveHomeBlock|PublishHome|RestoreHomeVersion|DiscardHomeDraft $command): void
    {
        $now = $this->clock->now();
        if ($command instanceof PublishHome) {
            $draft = $this->versions->draft() ?? throw new HomeContentViolation('Aucune modification à publier.');
            $at = null === $command->publishAt || $command->publishAt < $now ? $now : $command->publishAt;
            if (null !== $this->versions->scheduled($now)) {
                throw new HomeContentViolation('Une publication est déjà programmée : attendez sa mise en ligne avant d’en programmer une autre.');
            }
            $this->versions->publish($draft->id, $at, $command->adminId);
            $this->log($command->adminId, $at > $now ? 'cms.home.scheduled' : 'cms.home.published', $draft->id, ['version' => $draft->number, 'publish_at' => $at->format(\DATE_ATOM)], $now);

            return;
        }
        if ($command instanceof DiscardHomeDraft) {
            $draft = $this->versions->draft();
            if (null !== $draft) {
                $this->versions->delete($draft->id);
                $this->log($command->adminId, 'cms.home.draft_discarded', $draft->id, [], $now);
            }

            return;
        }
        if ($command instanceof RestoreHomeVersion) {
            $source = $this->versions->find($command->versionId) ?? throw new HomeContentViolation('Version introuvable.');
            $this->versions->saveDraft($this->draft($command->adminId, $now)->withBlocks($source->blocks, $command->adminId, $now));
            $this->log($command->adminId, 'cms.home.restored', $source->id, ['version' => $source->number], $now);

            return;
        }

        $type = HomeBlockType::tryFrom($command->type) ?? throw new HomeContentViolation('Bloc inconnu.');
        $draft = $this->draft($command->adminId, $now);
        $blocks = $this->defaults->complete($draft->blocks);
        $index = array_search($type->value, array_column($blocks, 'type'), true);
        if (!\is_int($index)) {
            throw new HomeContentViolation('Bloc inconnu.');
        }
        if ($command instanceof MoveHomeBlock) {
            $target = $index + ($command->offset < 0 ? -1 : 1);
            if ($type->isGlobal() || !isset($blocks[$target]) || HomeBlockType::from($blocks[$target]['type'])->isGlobal()) {
                return; // en-tête et pied de page restent aux extrémités
            }
            [$blocks[$index], $blocks[$target]] = [$blocks[$target], $blocks[$index]];
        } else {
            if ($type->isGlobal()) {
                throw new HomeContentViolation('En-tête et pied de page sont des composants globaux (BO > Apparence).');
            }
            $blocks[$index]['enabled'] = $command->enabled;
            if ($command instanceof SaveHomeBlock) {
                $blocks[$index]['settings'] = HomeBlockSchema::normalize($type, $command->input);
            }
        }
        /** @var list<Block> $blocks */
        $blocks = array_values($blocks);
        $this->versions->saveDraft($draft->withBlocks($blocks, $command->adminId, $now));
        $this->log($command->adminId, 'cms.home.block_saved', $draft->id, ['block' => $type->value, 'action' => match (true) {
            $command instanceof MoveHomeBlock => 'move',
            $command instanceof ToggleHomeBlock => $command->enabled ? 'enable' : 'disable',
            default => 'edit',
        }], $now);
    }

    private function draft(string $adminId, \DateTimeImmutable $now): HomeVersion
    {
        $draft = $this->versions->draft();
        if (null !== $draft) {
            return $draft;
        }
        $base = $this->versions->scheduled($now) ?? $this->versions->live($now);

        return new HomeVersion($this->ids->next(), $this->versions->nextNumber(), $base->blocks ?? $this->defaults->blocks(), $adminId, $now);
    }

    /** @param array<string, scalar|null> $context */
    private function log(string $adminId, string $action, string $versionId, array $context, \DateTimeImmutable $now): void
    {
        $this->audit->record(new AuditRecord(
            id: $this->ids->next(),
            occurredAt: $now,
            actorType: 'admin',
            actorId: $adminId,
            action: $action,
            subjectType: 'home_version',
            subjectId: $versionId,
            context: $context,
        ));
    }
}
