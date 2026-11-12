<?php

declare(strict_types=1);

namespace App\QuoteRequest\Application\Draft;

/** Brouillon P11 tel qu'affiché par le tunnel ; `step` est l'étape la plus avancée atteinte. */
final readonly class RequestDraftView
{
    /**
     * @param array<string, string|list<string>> $answers
     * @param list<DraftAttachmentView>          $attachments
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
    ) {
    }

    /** @return array<string, mixed> forme stockée (payload jsonb) */
    public function payload(): array
    {
        return [
            'job_id' => $this->jobId, 'project_type' => $this->projectType, 'target_artisan_id' => $this->targetArtisanId,
            'broadcast_to_others' => $this->broadcastToOthers, 'questionnaire_version' => $this->questionnaireVersion,
            'answers' => $this->answers, 'description' => $this->description, 'budget_range' => $this->budgetRange,
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
        );
    }

    /** @param array<string, mixed> $changes */
    public function with(array $changes, int $step, \DateTimeImmutable $now): self
    {
        return self::fromPayload($this->id, $step, $changes + $this->payload(), $this->attachments, $now);
    }
}
