<?php

declare(strict_types=1);

namespace App\Content\Application\Faq\Admin;

use App\Content\Application\Page\RichText;
use App\Content\Application\Slug;
use App\Shared\Application\Audit\AuditTrail;
use App\Shared\Application\Bus\CommandHandler;
use App\Shared\Domain\Audit\AuditRecord;
use App\Shared\Domain\Identifier\IdGenerator;
use App\Shared\Domain\Time\Clock;

/**
 * BO10.4 — rubriques (titre, description, icône, active, ordre) et questions (public client / artisan / tous, réponse
 * assainie, publiée ou non, ordre). Une rubrique ne se supprime que vide. Adresses stables : modifiables, uniques.
 */
final readonly class FaqAdminHandler implements CommandHandler
{
    public const array ICONS = ['users' => 'Clients', 'briefcase' => 'Professionnels', 'lock' => 'Compte et sécurité', 'tag' => 'Tarifs', 'star' => 'Avis', 'shield' => 'Confiance', 'help' => 'Aide', 'msg' => 'Messagerie', 'fileEuro' => 'Devis', 'calendar' => 'Rendez-vous'];
    private const int TITLE_MAX = 120;
    private const int DESCRIPTION_MAX = 300;
    private const int TOPIC_SLUG_MAX = 100;
    private const int QUESTION_MAX = 200;
    private const int QUESTION_SLUG_MAX = 120;
    private const int ANSWER_MAX = 20000;

    public function __construct(private FaqAdminStore $store, private RichText $richText, private AuditTrail $audit, private IdGenerator $ids, private Clock $clock)
    {
    }

    public function __invoke(FaqAdminCommand $command): void
    {
        $now = $this->clock->now();
        match ($command->action) {
            'topic_save' => $this->saveTopic($command),
            'topic_up', 'topic_down' => $this->store->moveTopic($command->id, 'topic_up' === $command->action ? -1 : 1),
            'topic_delete' => $this->deleteTopic($command->id),
            'question_save' => $this->saveQuestion($command, $now),
            'question_up', 'question_down' => $this->store->moveQuestion($command->id, 'question_up' === $command->action ? -1 : 1),
            'question_delete' => $this->store->deleteQuestion($command->id),
            default => throw new FaqAdminRejected('Action inconnue.'),
        };
        $this->audit->record(new AuditRecord(id: $this->ids->next(), occurredAt: $now, actorType: 'admin', actorId: $command->adminId, action: 'content.faq_'.$command->action, subjectType: str_starts_with($command->action, 'topic') ? 'faq_topic' : 'faq_question', subjectId: $command->id));
    }

    private function saveTopic(FaqAdminCommand $command): void
    {
        $existing = $this->store->topic($command->id);
        $title = $this->text($command->data['title'] ?? '', self::TITLE_MAX, 'Titre de la rubrique', true);
        $description = $this->text($command->data['description'] ?? '', self::DESCRIPTION_MAX, 'Description', true);
        $icon = \is_string($command->data['icon'] ?? null) && \array_key_exists($command->data['icon'], self::ICONS) ? $command->data['icon'] : 'help';
        $slug = Slug::make($this->text($command->data['slug'] ?? '', self::TOPIC_SLUG_MAX, 'Adresse', false) ?: $title, self::TOPIC_SLUG_MAX);
        if ('' === $slug || $this->store->topicSlugTaken($slug, $existing?->id)) {
            throw new FaqAdminRejected('Adresse de rubrique invalide ou déjà utilisée.');
        }
        $this->store->saveTopic($command->id, $slug, $title, $description, $icon, null === $existing || true === ($command->data['active'] ?? false));
    }

    private function deleteTopic(string $id): void
    {
        $topic = $this->store->topic($id) ?? throw new FaqAdminRejected('Rubrique introuvable.');
        if ($topic->questions > 0) {
            throw new FaqAdminRejected('Supprimez ou déplacez d’abord les questions de cette rubrique.');
        }
        $this->store->deleteTopic($id);
    }

    private function saveQuestion(FaqAdminCommand $command, \DateTimeImmutable $now): void
    {
        $existing = $this->store->question($command->id);
        $topicId = \is_string($command->data['topic'] ?? null) && '' !== $command->data['topic'] ? $command->data['topic'] : (null !== $existing ? $existing->topicId : $command->topicId);
        if (null === $topicId || null === $this->store->topic($topicId)) {
            throw new FaqAdminRejected('Choisissez une rubrique.');
        }
        $question = $this->text($command->data['question'] ?? '', self::QUESTION_MAX, 'Question', true);
        $answer = $this->richText->sanitize($this->text($command->data['answer'] ?? '', self::ANSWER_MAX, 'Réponse', true));
        if ('' === trim(strip_tags($answer))) {
            throw new FaqAdminRejected('Réponse : texte vide après nettoyage.');
        }
        $audience = \is_string($command->data['audience'] ?? null) && \array_key_exists($command->data['audience'], FaqAdminQuestion::AUDIENCES) ? $command->data['audience'] : 'all';
        $slug = Slug::make($this->text($command->data['slug'] ?? '', self::QUESTION_SLUG_MAX, 'Adresse', false) ?: $question, self::QUESTION_SLUG_MAX);
        if ('' === $slug || $this->store->questionSlugTaken($topicId, $slug, $existing?->id)) {
            throw new FaqAdminRejected('Adresse de question invalide ou déjà utilisée dans cette rubrique.');
        }
        $published = null === $existing || true === ($command->data['published'] ?? false);
        $this->store->saveQuestion($command->id, $topicId, $slug, $question, $answer, $audience, $published, $now);
    }

    private function text(mixed $value, int $max, string $label, bool $required): string
    {
        $value = \is_string($value) ? trim($value) : '';
        if ($required && '' === $value) {
            throw new FaqAdminRejected($label.' : champ obligatoire.');
        }
        if (mb_strlen($value) > $max) {
            throw new FaqAdminRejected(\sprintf('%s : %d caractères au maximum.', $label, $max));
        }

        return $value;
    }
}
