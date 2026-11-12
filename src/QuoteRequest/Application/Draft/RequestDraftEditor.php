<?php

declare(strict_types=1);

namespace App\QuoteRequest\Application\Draft;

use App\ArtisanProfile\Application\PublicProfile\PublicArtisanProfile;
use App\ArtisanProfile\Application\PublicProfile\PublicProfileView;
use App\Catalog\Application\Query\JobIndexEntries;
use App\Catalog\Application\Query\JobIndexEntry;
use App\Catalog\Application\Questionnaire\JobQuestionnaire;
use App\Catalog\Application\Questionnaire\JobQuestionnaireView;
use App\Catalog\Domain\Questionnaire\InvalidAnswers;
use App\QuoteRequest\Domain\Request\BudgetRange;
use App\QuoteRequest\Domain\Request\ProjectType;
use App\QuoteRequest\Domain\Request\RequestDetails;
use App\Shared\Application\Bus\CommandHandler;
use App\Shared\Application\Bus\QueryBus;
use App\Shared\Application\Settings\SettingKey;
use App\Shared\Application\Settings\SettingsProvider;
use App\Shared\Application\Storage\FileShredder;
use App\Shared\Application\Storage\FileUploader;
use App\Shared\Application\Storage\RejectedUpload;
use App\Shared\Domain\Storage\FilePurpose;
use App\Shared\Domain\Time\Clock;

/**
 * P11 étapes 1 à 3 — brouillon autosauvegardé (RG-060) : besoin (métier, type de projet, demande directe), détails
 * (questionnaire du métier, description, budget), photos (RG-061). Chaque étape validée débloque la suivante.
 */
final readonly class RequestDraftEditor implements CommandHandler
{
    public const int STEP_NEED = 1;
    public const int STEP_DETAILS = 2;
    public const int STEP_PHOTOS = 3;
    public const int STEP_PLACE = 4;
    private const string SHRED_REASON = 'request_draft_photo_removed';

    public function __construct(
        private DraftStore $drafts,
        private QueryBus $queries,
        private SettingsProvider $settings,
        private FileUploader $uploader,
        private FileShredder $shredder,
        private Clock $clock,
    ) {
    }

    public function __invoke(StartRequestDraft|SaveDraftStep|AddDraftAttachment|RemoveDraftAttachment $command): void
    {
        match (true) {
            $command instanceof StartRequestDraft => $this->start($command),
            $command instanceof SaveDraftStep => $this->saveStep($command),
            $command instanceof AddDraftAttachment => $this->attach($command),
            default => $this->detach($command),
        };
    }

    private function start(StartRequestDraft $command): void
    {
        $draft = $this->current($command->owner, $command->draftId);
        $changes = [];
        if (null !== $command->targetArtisanId) {
            $artisan = $this->artisan($command->targetArtisanId) ?? throw new DraftRejected('Ce professionnel n’est pas disponible pour une demande directe.');
            $changes['target_artisan_id'] = $artisan->profileId;
            $changes['broadcast_to_others'] = $this->settings->bool(SettingKey::RequestsDirectBroadcastDefault);
            if (null === $command->jobId && (null === $draft->jobId || !\in_array($draft->jobId, $artisan->jobIds, true))) {
                $changes += $this->jobChange($draft, $artisan->mainJobId);
            }
        }
        if (null !== $command->jobId) {
            $this->assertJob($command->jobId, $changes['target_artisan_id'] ?? $draft->targetArtisanId);
            $changes += $this->jobChange($draft, $command->jobId);
        }
        $this->store($command->owner, $draft->with($changes, $draft->step, $this->clock->now()));
    }

    private function saveStep(SaveDraftStep $command): void
    {
        $draft = $this->current($command->owner, $command->draftId);
        if ($command->step > $draft->step) {
            throw new DraftRejected('Terminez d’abord les étapes précédentes.');
        }
        $input = $command->input;
        $changes = match ($command->step) {
            self::STEP_NEED => $this->need($draft, $input, $command->complete),
            self::STEP_DETAILS => $this->details($draft, $input, $command->complete),
            default => [],
        };
        $step = $command->complete ? max($draft->step, min($command->step + 1, self::STEP_PLACE)) : $draft->step;
        $this->store($command->owner, $draft->with($changes, $step, $this->clock->now()));
    }

    /**
     * @param array<array-key, mixed> $input
     *
     * @return array<string, mixed>
     */
    private function need(RequestDraftView $draft, array $input, bool $complete): array
    {
        $errors = [];
        $changes = [];
        $jobId = self::text($input, 'job_id');
        if (null !== $jobId && $this->isJobAvailable($jobId, $draft->targetArtisanId)) {
            $changes += $this->jobChange($draft, $jobId);
        } elseif ($complete) {
            $errors['job_id'] = null === $jobId ? 'Choisissez le métier correspondant à votre besoin.' : (null !== $draft->targetArtisanId ? 'Ce professionnel n’exerce pas ce métier.' : 'Ce métier n’est pas disponible.');
        }
        $type = ProjectType::tryFrom(self::text($input, 'project_type') ?? '');
        if (null !== $type) {
            $changes['project_type'] = $type->value;
        } elseif ($complete) {
            $errors['project_type'] = 'Choisissez le type de projet.';
        }
        if (null !== $draft->targetArtisanId && \array_key_exists('broadcast', $input)) {
            $changes['broadcast_to_others'] = '1' === $input['broadcast'];
        }
        if ([] !== $errors) {
            throw new DraftRejected('Certaines informations sont manquantes.', $errors);
        }

        return $changes;
    }

    /**
     * @param array<array-key, mixed> $input
     *
     * @return array<string, mixed>
     */
    private function details(RequestDraftView $draft, array $input, bool $complete): array
    {
        $errors = [];
        $questionnaire = null === $draft->jobId ? null : $this->questionnaire($draft->jobId);
        $raw = \is_array($input['answers'] ?? null) ? $input['answers'] : [];
        $answers = [];
        if (null !== $questionnaire) {
            if ($complete) {
                try {
                    $answers = $questionnaire->schema->validate($raw);
                } catch (InvalidAnswers $e) {
                    foreach ($e->errors as $key => $message) {
                        $errors['answers_'.$key] = $message;
                    }
                }
            } else {
                $answers = $questionnaire->schema->keepValid($raw);
            }
        }
        $description = mb_substr(trim(self::text($input, 'description') ?? ''), 0, RequestDetails::DESCRIPTION_MAX);
        if ($complete && mb_strlen($description) < RequestDetails::DESCRIPTION_MIN) {
            $errors['description'] = \sprintf('Décrivez votre projet en %d caractères au moins.', RequestDetails::DESCRIPTION_MIN);
        }
        $budget = self::text($input, 'budget_range');
        if (null !== $budget && null === BudgetRange::tryFrom($budget)) {
            $budget = null;
        }
        if ([] !== $errors) {
            throw new DraftRejected('Certaines réponses sont incomplètes.', $errors);
        }

        return ['answers' => $answers, 'questionnaire_version' => $questionnaire?->version, 'description' => $description, 'budget_range' => $budget];
    }

    private function attach(AddDraftAttachment $command): void
    {
        $draft = $this->current($command->owner, $command->draftId);
        if ($draft->step < self::STEP_PHOTOS) {
            throw new DraftRejected('Terminez d’abord les étapes précédentes.');
        }
        $max = $this->settings->int(SettingKey::RequestsMaxPhotos);
        if (\count($draft->attachments) >= $max) {
            throw new DraftRejected(\sprintf('%d fichiers au maximum par demande.', $max));
        }
        try {
            $file = $this->uploader->upload($command->path, $command->clientName, FilePurpose::RequestPhoto);
        } catch (RejectedUpload $e) {
            throw new DraftRejected(match ($e->reason) {
                RejectedUpload::TYPE_NOT_ALLOWED => 'Format non accepté : JPG, PNG, WEBP ou PDF.', RejectedUpload::TOO_LARGE => \sprintf('Fichier trop lourd : %d Mo au maximum.', $this->settings->int(SettingKey::RequestsMaxFileMb)), RejectedUpload::IMAGE_TOO_LARGE => 'Image trop grande : réduisez sa résolution.', default => 'Ce fichier n’a pas pu être accepté.',
            });
        }
        $now = $this->clock->now();
        $this->store($command->owner, $draft->with([], $draft->step, $now));
        $this->drafts->attach($draft->id, $file->id, $file->originalName ?? 'photo', $now);
    }

    private function detach(RemoveDraftAttachment $command): void
    {
        $draft = $this->drafts->find($command->owner);
        if (null === $draft || !$this->drafts->detach($draft->id, $command->fileId)) {
            throw new DraftRejected('Fichier introuvable.');
        }
        $this->shredder->shred($command->fileId, self::SHRED_REASON, $command->owner->userId);
    }

    /** Brouillon existant, ou nouveau brouillon vide (étape 1). */
    private function current(DraftOwner $owner, string $draftId): RequestDraftView
    {
        return $this->drafts->find($owner) ?? RequestDraftView::fromPayload($draftId, self::STEP_NEED, ['broadcast_to_others' => true], [], $this->clock->now());
    }

    private function store(DraftOwner $owner, RequestDraftView $draft): void
    {
        $this->drafts->save($owner, $draft, $this->clock->now()->modify(\sprintf('+%d days', $this->settings->int(SettingKey::RequestsDraftRetentionDays))));
    }

    /**
     * Un autre métier remet le questionnaire à zéro (ses questions ne s'appliquent plus).
     *
     * @return array<string, mixed>
     */
    private function jobChange(RequestDraftView $draft, ?string $jobId): array
    {
        if (null === $jobId || $jobId === $draft->jobId) {
            return [];
        }

        return ['job_id' => $jobId, 'answers' => [], 'questionnaire_version' => null];
    }

    private function assertJob(string $jobId, ?string $targetArtisanId): void
    {
        if (!$this->isJobAvailable($jobId, $targetArtisanId)) {
            throw new DraftRejected(null !== $targetArtisanId ? 'Ce professionnel n’exerce pas ce métier.' : 'Ce métier n’est pas disponible.');
        }
    }

    private function isJobAvailable(string $jobId, ?string $targetArtisanId): bool
    {
        $entries = $this->queries->ask(new JobIndexEntries([$jobId]));
        $available = false;
        foreach (\is_array($entries) ? $entries : [] as $entry) {
            $available = $available || ($entry instanceof JobIndexEntry && $entry->id === $jobId && $entry->searchable);
        }
        if (!$available || null === $targetArtisanId) {
            return $available;
        }

        return \in_array($jobId, $this->artisan($targetArtisanId)->jobIds ?? [], true);
    }

    private function artisan(string $profileId): ?PublicProfileView
    {
        if (1 !== preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/', $profileId)) {
            return null;
        }
        $view = $this->queries->ask(new PublicArtisanProfile($profileId));

        return $view instanceof PublicProfileView ? $view : null;
    }

    private function questionnaire(string $jobId): ?JobQuestionnaireView
    {
        $view = $this->queries->ask(new JobQuestionnaire($jobId));

        return $view instanceof JobQuestionnaireView ? $view : null;
    }

    /** @param array<array-key, mixed> $input */
    private static function text(array $input, string $key): ?string
    {
        $value = $input[$key] ?? null;

        return \is_string($value) && '' !== trim($value) ? trim($value) : null;
    }
}
