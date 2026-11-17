<?php

declare(strict_types=1);

namespace App\ArtisanProfile\Application\Signup;

use App\ArtisanProfile\Domain\Document\ArtisanDocument;
use App\ArtisanProfile\Domain\Profile\ArtisanProfile;
use App\ArtisanProfile\Domain\Profile\InsuranceKind;
use App\ArtisanProfile\Domain\Profile\InsuranceRequirements;
use App\Catalog\Application\Query\JobRequirement;
use App\Catalog\Application\Query\JobRequirements;
use App\Shared\Application\Bus\QueryBus;

/**
 * Pièces et assurances attendues d'un artisan (A03.5, A03.6) : pièce d'identité du dirigeant et Kbis pour tous,
 * justificatifs paramétrés par métier en BO06 (Q16), attestation de chaque assurance déclarée, certificat de chaque
 * label, RIB facultatif au MVP.
 */
final readonly class SignupRequirements
{
    /** Libellés des codes du référentiel (un code inconnu reste affiché tel quel, jamais ignoré). */
    private const array LABELS = [
        'identity' => 'Pièce d’identité du dirigeant',
        'kbis' => 'Extrait Kbis ou avis de situation INSEE (< 3 mois)',
        'insurance_decennial' => 'Attestation d’assurance décennale',
        'insurance_rc' => 'Attestation d’assurance RC Professionnelle',
        'rge' => 'Certificat RGE',
        'electrical_clearance' => 'Habilitation électrique',
        'rib' => 'RIB (non requis au lancement)',
    ];
    private const array ALWAYS = ['identity', 'kbis'];
    public const string QUALIFICATION = 'qualification';
    private const string OPTIONAL_RIB = 'rib';

    public function __construct(private QueryBus $queries)
    {
    }

    public function insurances(ArtisanProfile $profile): InsuranceRequirements
    {
        $any = $decennial = $liability = false;
        foreach ($this->jobs($profile) as $job) {
            $any = $any || $job->insuranceRequired;
            $decennial = $decennial || $job->decennialRequired || \in_array('insurance_decennial', $job->requiredDocuments, true);
            $liability = $liability || \in_array('insurance_rc', $job->requiredDocuments, true);
        }

        return new InsuranceRequirements($any || $decennial || $liability, $decennial, $liability);
    }

    /**
     * @param list<ArtisanDocument> $documents pièces actuelles de l'artisan
     *
     * @return list<DocumentSlot>
     */
    public function checklist(ArtisanProfile $profile, array $documents): array
    {
        /** @var array<string, array{type: string, subject: ?string, label: string, required: bool, jobs: list<string>, expires: ?\DateTimeImmutable}> $slots */
        $slots = [];
        $add = static function (string $type, ?string $subject, string $label, bool $required, ?string $job = null, ?\DateTimeImmutable $expires = null) use (&$slots): void {
            $key = DocumentSlot::key($type, $subject);
            $slots[$key] ??= ['type' => $type, 'subject' => $subject, 'label' => $label, 'required' => false, 'jobs' => [], 'expires' => null];
            $slots[$key]['required'] = $slots[$key]['required'] || $required;
            $slots[$key]['expires'] ??= $expires;
            if (null !== $job && !\in_array($job, $slots[$key]['jobs'], true)) {
                $slots[$key]['jobs'][] = $job;
            }
        };

        foreach (self::ALWAYS as $type) {
            $add($type, null, self::label($type), true);
        }
        foreach ($profile->insurances() as $insurance) {
            $type = InsuranceKind::Decennial === $insurance->kind ? 'insurance_decennial' : 'insurance_rc';
            $add($type, null, self::label($type), true, null, $insurance->validUntil);
        }
        foreach ($this->jobs($profile) as $job) {
            foreach ($job->requiredDocuments as $type) {
                if (1 === preg_match('/^[a-z][a-z_]{1,39}$/', $type) && self::QUALIFICATION !== $type) {
                    $add($type, null, self::label($type), true, $job->name);
                }
            }
        }
        foreach ($profile->qualifications() as $qualification) {
            $add(self::QUALIFICATION, $qualification->name, 'Certificat '.$qualification->name, true, null, $qualification->validUntil);
        }
        $add(self::OPTIONAL_RIB, null, self::label(self::OPTIONAL_RIB), false);

        $current = [];
        foreach ($documents as $document) {
            $current[DocumentSlot::key($document->type(), $document->subject())] ??= $document; // plus récent d'abord
        }

        return array_values(array_map(static function (array $slot) use ($current): DocumentSlot {
            $document = $current[DocumentSlot::key($slot['type'], $slot['subject'])] ?? null;

            return new DocumentSlot($slot['type'], $slot['subject'], $slot['label'], $slot['required'], $slot['jobs'], $slot['expires'], $document?->status()->value, $document?->originalName(), $document?->uploadedAt());
        }, $slots));
    }

    private static function label(string $type): string
    {
        return self::LABELS[$type] ?? 'Justificatif « '.str_replace('_', ' ', $type).' »';
    }

    /** @return list<JobRequirement> */
    private function jobs(ArtisanProfile $profile): array
    {
        $ids = array_map(static fn ($id): string => $id->value, $profile->jobIds());
        if ([] === $ids) {
            return [];
        }
        $jobs = $this->queries->ask(new JobRequirements($ids));

        return array_values(array_filter(\is_array($jobs) ? $jobs : [], static fn (mixed $j): bool => $j instanceof JobRequirement));
    }
}
