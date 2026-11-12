<?php

declare(strict_types=1);

namespace App\QuoteRequest\Application\Draft;

/**
 * Brouillon P11 tel qu'affiché par le tunnel ; `step` est l'étape la plus avancée atteinte. L'adresse est en clair dans
 * la vue et scellée au repos par le stockage (RG-022bis : jamais lisible en base).
 */
final readonly class RequestDraftView
{
    /**
     * @param array<string, string|list<string>>                                                                         $answers
     * @param list<DraftAttachmentView>                                                                                  $attachments
     * @param array{label: string, city_insee: string, postal_code: string, city: string, lat: ?float, lon: ?float}|null $address
     * @param list<string>                                                                                               $preferredSlots
     */
    public function __construct(
        public string $id,
        public int $step,
        public ?string $jobId,
        public ?string $projectType,
        public ?string $targetArtisanId,
        public bool $broadcastToOthers,
        public ?int $questionnaireVersion,
        public array $answers,
        public string $description,
        public ?string $budgetRange,
        public array $attachments,
        public \DateTimeImmutable $updatedAt,
        public ?array $address = null,
        public ?string $timing = null,
        public ?string $desiredDate = null,
        public array $preferredSlots = [],
        public ?string $occupantStatus = null,
        public ?string $contactPreference = null,
        public ?string $title = null,
        public ?string $summary = null,
        public ?string $workNature = null,
    ) {
    }

    /** @return array<string, mixed> forme stockée (payload jsonb, adresse scellée par le stockage) */
    public function payload(): array
    {
        return [
            'job_id' => $this->jobId, 'project_type' => $this->projectType, 'target_artisan_id' => $this->targetArtisanId,
            'broadcast_to_others' => $this->broadcastToOthers, 'questionnaire_version' => $this->questionnaireVersion,
            'answers' => $this->answers, 'description' => $this->description, 'budget_range' => $this->budgetRange,
            'address' => $this->address, 'timing' => $this->timing, 'desired_date' => $this->desiredDate,
            'preferred_slots' => $this->preferredSlots, 'occupant_status' => $this->occupantStatus, 'contact_preference' => $this->contactPreference,
            'title' => $this->title, 'summary' => $this->summary, 'work_nature' => $this->workNature,
        ];
    }

    /**
     * @param array<string, mixed>      $payload
     * @param list<DraftAttachmentView> $attachments
     */
    public static function fromPayload(string $id, int $step, array $payload, array $attachments, \DateTimeImmutable $updatedAt): self
    {
        $string = static fn (string $k): ?string => \is_string($payload[$k] ?? null) && '' !== $payload[$k] ? $payload[$k] : null;
        $answers = [];
        foreach (\is_array($payload['answers'] ?? null) ? $payload['answers'] : [] as $key => $value) {
            if (\is_string($value)) {
                $answers[(string) $key] = $value;
            } elseif (\is_array($value)) {
                $answers[(string) $key] = array_values(array_filter($value, \is_string(...)));
            }
        }

        return new self(
            $id,
            $step,
            $string('job_id'),
            $string('project_type'),
            $string('target_artisan_id'),
            true === ($payload['broadcast_to_others'] ?? true),
            \is_int($payload['questionnaire_version'] ?? null) ? $payload['questionnaire_version'] : null,
            $answers,
            $string('description') ?? '',
            $string('budget_range'),
            $attachments,
            $updatedAt,
            self::address($payload['address'] ?? null),
            $string('timing'),
            $string('desired_date'),
            array_values(array_filter(\is_array($payload['preferred_slots'] ?? null) ? $payload['preferred_slots'] : [], \is_string(...))),
            $string('occupant_status'),
            $string('contact_preference'),
            $string('title'),
            $string('summary'),
            $string('work_nature'),
        );
    }

    /** @param array<string, mixed> $changes */
    public function with(array $changes, int $step, \DateTimeImmutable $now): self
    {
        return self::fromPayload($this->id, $step, $changes + $this->payload(), $this->attachments, $now);
    }

    /** @return array{label: string, city_insee: string, postal_code: string, city: string, lat: ?float, lon: ?float}|null */
    private static function address(mixed $value): ?array
    {
        if (!\is_array($value)) {
            return null;
        }
        $text = static fn (string $k): ?string => \is_string($value[$k] ?? null) && '' !== $value[$k] ? $value[$k] : null;
        $label = $text('label');
        $insee = $text('city_insee');
        $postal = $text('postal_code');
        $city = $text('city');
        if (null === $label || null === $insee || null === $postal || null === $city) {
            return null;
        }
        $number = static fn (string $k): ?float => \is_int($value[$k] ?? null) || \is_float($value[$k] ?? null) ? (float) $value[$k] : null;

        return ['label' => $label, 'city_insee' => $insee, 'postal_code' => $postal, 'city' => $city, 'lat' => $number('lat'), 'lon' => $number('lon')];
    }
}
