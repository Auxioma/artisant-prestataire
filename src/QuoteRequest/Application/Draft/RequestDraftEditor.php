<?php

declare(strict_types=1);

namespace App\QuoteRequest\Application\Draft;

use App\ArtisanProfile\Application\PublicProfile\PublicArtisanProfile;
use App\ArtisanProfile\Application\PublicProfile\PublicProfileView;
use App\Catalog\Application\Geo\Port\GeocodedAddress;
use App\Catalog\Application\Geo\Query\GeocodeAddress;
use App\Catalog\Application\Query\JobIndexEntries;
use App\Catalog\Application\Query\JobIndexEntry;
use App\Catalog\Application\Questionnaire\JobQuestionnaire;
use App\Catalog\Application\Questionnaire\JobQuestionnaireView;
use App\Catalog\Domain\Questionnaire\InvalidAnswers;
use App\QuoteRequest\Domain\Request\BudgetRange;
use App\QuoteRequest\Domain\Request\ContactPreference;
use App\QuoteRequest\Domain\Request\OccupantStatus;
use App\QuoteRequest\Domain\Request\PreferredSlot;
use App\QuoteRequest\Domain\Request\ProjectType;
use App\QuoteRequest\Domain\Request\RequestDetails;
use App\QuoteRequest\Domain\Request\Timing;
use App\Shared\Application\Bus\CommandHandler;
use App\Shared\Application\Bus\QueryBus;
use App\Shared\Application\Integration\ExternalServiceUnavailable;
use App\Shared\Application\Settings\SettingKey;
use App\Shared\Application\Settings\SettingsProvider;
use App\Shared\Application\Storage\FileShredder;
use App\Shared\Application\Storage\FileUploader;
use App\Shared\Application\Storage\RejectedUpload;
use App\Shared\Domain\Storage\FilePurpose;
use App\Shared\Domain\Text\ContactDetector;
use App\Shared\Domain\Time\Clock;

/**
 * P11 étapes 1 à 5 — brouillon autosauvegardé (RG-060) : besoin (métier, type de projet, demande directe), détails
 * (questionnaire du métier, description, budget), photos (RG-061), lieu et délai, coordonnées. Chaque étape validée
 * débloque la suivante ; l'envoi (étape 6) est porté par SubmitRequestDraft.
 */
final readonly class RequestDraftEditor implements CommandHandler
{
    public const int STEP_NEED = 1;
    public const int STEP_DETAILS = 2;
    public const int STEP_PHOTOS = 3;
    public const int STEP_PLACE = 4;
    public const int STEP_CONTACT = 5;
    public const int STEP_RECAP = 6;
    /** P11 « Date précise » : à partir d'aujourd'hui, au plus 12 mois plus tard. */
    public const int DESIRED_DATE_MAX_MONTHS = 12;
    public const string JOB_MESSAGE = 'Indiquez le type de professionnel recherché.';
    public const string ADDRESS_MESSAGE = 'Sélectionnez une adresse dans la liste.';
    public const string DATE_MESSAGE = 'Choisissez une date à venir.';
    private const string SHRED_REASON = 'request_draft_photo_removed';
    private const int ADDRESS_MAX = 200;

    public function __construct(
        private DraftStore $drafts,
        private QueryBus $queries,
        private SettingsProvider $settings,
        private FileUploader $uploader,
        private FileShredder $shredder,
        private Clock $clock,
    ) {
    }

    public function __invoke(StartRequestDraft|SaveDraftStep|AddDraftAttachment|RemoveDraftAttachment|AdoptVisitorDraft|MarkDraftAccount|RequestDraftSubmission $command): void
    {
        if ($command instanceof MarkDraftAccount || $command instanceof RequestDraftSubmission) {
            $draft = $this->drafts->find($command->owner) ?? throw new DraftRejected('Votre brouillon a expiré : merci de recommencer votre demande.');
            if ($command instanceof MarkDraftAccount) {
                $this->drafts->markPendingAccount($draft->id, $command->userId);

                return;
            }
            if ($draft->step < self::STEP_RECAP) {
                throw new DraftRejected('Votre demande est incomplète : vérifiez chaque étape.');
            }
            if (null !== $draft->targetArtisanId && null !== $command->broadcastToOthers) {
                $this->store($command->owner, $draft->with(['broadcast_to_others' => $command->broadcastToOthers], $draft->step, $this->clock->now()));
            }
            $this->drafts->requestSubmission($draft->id, $this->clock->now());

            return;
        }
        if ($command instanceof AdoptVisitorDraft) {
            foreach ($this->drafts->adopt($command->visitor, $command->userId) as $fileId) {
                $this->shredder->shred($fileId, self::SHRED_REASON);
            }

            return;
        }
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
        // BO04.1 « Source » : premier point d'entrée retenu (une demande directe l'emporte).
        if (null !== $command->source && (null === $draft->source || null !== $command->targetArtisanId)) {
            $changes['source'] = $command->source;
        }
        if (null !== $command->targetArtisanId) {
            $artisan = $this->artisan($command->targetArtisanId) ?? throw new DraftRejected('Ce professionnel n’est pas disponible pour une demande directe.');
            if (!$artisan->acceptsDirectRequests) {
                throw new DraftRejected('Ce professionnel ne prend pas de nouvelles demandes pour le moment : déposez une demande ouverte aux professionnels de votre secteur.');
            }
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
        if (null !== $command->desiredDate && $this->isAcceptableDate($command->desiredDate)) {
            $changes['timing'] = Timing::Date->value;
            $changes['desired_date'] = $command->desiredDate;
        }
        if (null !== PreferredSlot::tryFrom($command->preferredSlot ?? '')) {
            $changes['preferred_slots'] = [$command->preferredSlot];
        }
        if (null !== $command->offerLabel && '' === trim($draft->description)) {
            $changes['description'] = mb_substr(\sprintf('Je souhaite profiter de votre offre « %s ».', $command->offerLabel), 0, RequestDetails::DESCRIPTION_MAX);
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
            self::STEP_PLACE => $this->place($draft, $input, $command->complete),
            self::STEP_CONTACT => $this->contact($command->owner, $draft, $input, $command->complete),
            default => [],
        };
        $step = $command->complete ? max($draft->step, min($command->step + 1, self::STEP_RECAP)) : $draft->step;
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
            $errors['job_id'] = null === $jobId ? self::JOB_MESSAGE : (null !== $draft->targetArtisanId ? 'Ce professionnel n’exerce pas ce métier.' : 'Ce métier n’est pas disponible.');
        }
        $type = ProjectType::tryFrom(self::text($input, 'project_type') ?? '');
        if (null !== $type) {
            $changes['project_type'] = $type->value;
        } elseif ($complete) {
            $errors['project_type'] = 'Choisissez le type de projet.';
        }
        if (\array_key_exists('work_nature', $input)) {
            $changes['work_nature'] = $this->optionalText($input, 'work_nature', RequestDetails::NATURE_MAX, $complete, $errors);
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
            $errors['description'] = RequestDetails::DESCRIPTION_MESSAGE;
        } elseif ($complete && [] !== ContactDetector::find($description)) {
            $errors['description'] = RequestDetails::CONTACT_MESSAGE;
        }
        $title = $this->optionalText($input, 'title', RequestDetails::TITLE_MAX, $complete, $errors);
        $summary = $this->optionalText($input, 'summary', RequestDetails::SUMMARY_MAX, $complete, $errors);
        $budget = self::text($input, 'budget_range');
        if (null !== $budget && null === BudgetRange::tryFrom($budget)) {
            $budget = null;
        }
        if ([] !== $errors) {
            throw new DraftRejected('Certaines réponses sont incomplètes.', $errors);
        }

        return ['answers' => $answers, 'questionnaire_version' => $questionnaire?->version, 'description' => $description, 'budget_range' => $budget, 'title' => $title, 'summary' => $summary];
    }

    /**
     * P11.5 — adresse reconnue par la BAN (re-géocodée côté serveur : la saisie du navigateur n'est jamais crue), délai,
     * créneaux, statut. La sauvegarde automatique ne géocode pas (aucun appel externe toutes les 10 s).
     *
     * @param array<array-key, mixed> $input
     *
     * @return array<string, mixed>
     */
    private function place(RequestDraftView $draft, array $input, bool $complete): array
    {
        $errors = [];
        $changes = [];
        $timing = Timing::tryFrom(self::text($input, 'timing') ?? '');
        $changes['timing'] = null !== $timing ? $timing->value : ($complete ? null : $draft->timing);
        $date = self::text($input, 'desired_date');
        $changes['desired_date'] = Timing::Date === $timing ? $date : null;
        $slots = array_values(array_unique(array_filter(\is_array($input['preferred_slots'] ?? null) ? $input['preferred_slots'] : [], static fn (mixed $v): bool => \is_string($v) && null !== PreferredSlot::tryFrom($v))));
        $changes['preferred_slots'] = $slots;
        $occupant = OccupantStatus::tryFrom(self::text($input, 'occupant_status') ?? '');
        $changes['occupant_status'] = null !== $occupant ? $occupant->value : ($complete ? null : $draft->occupantStatus);
        if (!$complete) {
            return $changes;
        }

        $typed = self::text($input, 'address');
        if (null === $typed || mb_strlen($typed) > self::ADDRESS_MAX) {
            $errors['address'] = self::ADDRESS_MESSAGE;
        } elseif (null === $draft->address || $typed !== $draft->address['label']) {
            try {
                $found = $this->queries->ask(new GeocodeAddress($typed, self::text($input, 'city_insee') ?? self::text($input, 'address_insee'), self::text($input, 'address_postal')));
            } catch (ExternalServiceUnavailable) {
                throw new DraftRejected('Le service de recherche d’adresses est momentanément indisponible : réessayez dans quelques minutes, votre brouillon est conservé.', ['address' => self::ADDRESS_MESSAGE]);
            }
            $city = self::text($input, 'city_insee');
            if ($found instanceof GeocodedAddress && null !== $city && $found->inseeCode !== $city) {
                $errors['address'] = 'Cette adresse n’est pas dans la ville choisie.';
            } elseif ($found instanceof GeocodedAddress) {
                $changes['address'] = ['label' => $found->label, 'city_insee' => $found->inseeCode, 'postal_code' => $found->postalCode, 'city' => $found->city, 'lat' => $found->point->latitude, 'lon' => $found->point->longitude];
            } else {
                $errors['address'] = self::ADDRESS_MESSAGE;
            }
        }
        if (null === $timing) {
            $errors['timing'] = 'Indiquez le délai souhaité.';
        } elseif (Timing::Date === $timing && !$this->isAcceptableDate($date)) {
            $errors['desired_date'] = self::DATE_MESSAGE;
        }
        if (null === $occupant) {
            $errors['occupant_status'] = 'Indiquez votre statut.';
        }
        if ([] !== $errors) {
            throw new DraftRejected('Certaines informations sont manquantes.', $errors);
        }

        return $changes;
    }

    /**
     * P11.6 — coordonnées : le dépôt exige un compte (RG-040) ; préférence de contact. Le téléphone éventuel est porté
     * par le compte (Identity), contrôlé par le contrôleur avant cette étape.
     *
     * @param array<array-key, mixed> $input
     *
     * @return array<string, mixed>
     */
    private function contact(DraftOwner $owner, RequestDraftView $draft, array $input, bool $complete): array
    {
        $preference = ContactPreference::tryFrom(self::text($input, 'contact_preference') ?? '');
        if ($complete && null === $owner->userId && null === $draft->pendingUserId) {
            throw new DraftRejected('Connectez-vous ou créez votre compte pour envoyer votre demande : votre brouillon est conservé.');
        }
        if ($complete && null === $preference) {
            throw new DraftRejected('Indiquez comment les professionnels peuvent vous contacter.', ['contact_preference' => 'Choisissez une préférence de contact.']);
        }

        return null === $preference ? [] : ['contact_preference' => $preference->value];
    }

    /**
     * Texte facultatif (Figma P11) : borné ; sans coordonnées à la validation de l'étape.
     *
     * @param array<array-key, mixed> $input
     * @param array<string, string>   $errors
     */
    private function optionalText(array $input, string $key, int $max, bool $complete, array &$errors): ?string
    {
        $value = mb_substr(self::text($input, $key) ?? '', 0, $max);
        if ($complete && [] !== ContactDetector::find($value)) {
            $errors[$key] = RequestDetails::CONTACT_MESSAGE;
        }

        return '' === $value ? null : $value;
    }

    private function isAcceptableDate(?string $value): bool
    {
        $today = $this->clock->now()->setTime(0, 0);
        $date = null === $value ? false : \DateTimeImmutable::createFromFormat('!Y-m-d', $value, $today->getTimezone());

        return false !== $date && $date->format('Y-m-d') === $value && $date >= $today && $date <= $today->modify(\sprintf('+%d months', self::DESIRED_DATE_MAX_MONTHS));
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
                RejectedUpload::TYPE_NOT_ALLOWED, RejectedUpload::TOO_LARGE => \sprintf('Ce fichier n’est pas accepté (formats : JPG, PNG, WEBP, PDF — %d Mo max).', $this->settings->int(SettingKey::RequestsMaxFileMb)), RejectedUpload::IMAGE_TOO_LARGE => 'Image trop grande : réduisez sa résolution.', default => 'Ce fichier n’a pas pu être accepté.',
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
