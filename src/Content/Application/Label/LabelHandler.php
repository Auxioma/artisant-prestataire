<?php

declare(strict_types=1);

namespace App\Content\Application\Label;

use App\Shared\Application\Audit\AuditTrail;
use App\Shared\Application\Bus\CommandHandler;
use App\Shared\Domain\Audit\AuditRecord;
use App\Shared\Domain\Identifier\IdGenerator;
use App\Shared\Domain\Time\Clock;

/** BO10.6 — texte modifié (texte brut, une ligne, même longueur maximale) ou retour au texte d'origine ; journalisé. */
final readonly class LabelHandler implements CommandHandler
{
    private const int TEXT_MAX = 500;

    public function __construct(private LabelStore $labels, private AuditTrail $audit, private IdGenerator $ids, private Clock $clock)
    {
    }

    public function __invoke(LabelCommand $command): void
    {
        $label = $this->labels->find($command->key, $command->locale) ?? throw new LabelRejected('Libellé inconnu.');
        $text = null === $command->text ? null : trim((string) preg_replace('/\s+/u', ' ', $command->text));
        if ('' === $text || $text === $label->defaultText) {
            $text = null; // vide ou identique : retour au texte d'origine
        }
        if (null !== $text && (mb_strlen($text) > self::TEXT_MAX || 1 === preg_match('/[<>]/', $text))) {
            throw new LabelRejected(\sprintf('Texte brut uniquement (sans < ni >), %d caractères au maximum.', self::TEXT_MAX));
        }
        $now = $this->clock->now();
        $this->labels->setText($label->key, $label->locale, $text, $command->adminId, $now);
        $this->audit->record(new AuditRecord(id: $this->ids->next(), occurredAt: $now, actorType: 'admin', actorId: $command->adminId, action: null === $text ? 'content.label_reset' : 'content.label_changed', subjectType: 'ui_label', subjectId: $label->key));
    }
}
