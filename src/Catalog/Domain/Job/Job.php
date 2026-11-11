<?php

declare(strict_types=1);

namespace App\Catalog\Domain\Job;

use App\Catalog\Domain\Category\CategoryId;
use App\Catalog\Domain\Event\JobChanged;
use App\Catalog\Domain\Event\JobCreated;
use App\Catalog\Domain\Event\JobDeactivated;
use App\Catalog\Domain\Event\JobSlugChanged;
use App\Catalog\Domain\Exception\CatalogRuleViolation;
use App\Catalog\Domain\Label;
use App\Shared\Domain\AggregateRoot;
use App\Shared\Domain\ValueObject\Slug;

/**
 * Métier du référentiel (BO06) : rattaché à une catégorie ou sous-catégorie, synonymes pour la recherche (RG-010),
 * justificatifs exigés (A03.3, Q16), désactivation encadrée (assistant de migration).
 */
final class Job extends AggregateRoot
{
    private const int MAX_SYNONYMS = 50;
    private const int MAX_SYNONYM_LENGTH = 80;
    private const string DOCUMENT_CODE_PATTERN = '/^[a-z][a-z0-9_]{1,40}$/';

    private string $name;
    /** @var list<string> */
    private array $synonyms = [];
    /** @var list<string> */
    private array $requiredDocuments = [];
    private bool $insuranceRequired = true;
    private bool $decennialRequired = false;
    private bool $active = true;
    private ?JobId $replacedBy = null;
    private ?string $description = null;
    private \DateTimeImmutable $updatedAt;
    private int $version = 1;

    private function __construct(
        private JobId $id,
        private CategoryId $categoryId,
        Label $name,
        private Slug $slug,
        private int $position,
        private \DateTimeImmutable $createdAt,
    ) {
        $this->name = $name->value;
        $this->updatedAt = $createdAt;
    }

    public static function create(JobId $id, CategoryId $categoryId, Label $name, Slug $slug, int $position, \DateTimeImmutable $now): self
    {
        $job = new self($id, $categoryId, $name, $slug, $position, $now);
        $job->recordEvent(new JobCreated($id->value, $categoryId->value, $slug->value, $now));

        return $job;
    }

    public function rename(Label $name, Slug $slug, \DateTimeImmutable $now): void
    {
        $previousSlug = $this->slug;
        $this->name = $name->value;
        $this->slug = $slug;
        $this->touch($now, 'renamed');
        if (!$previousSlug->equals($slug)) {
            $this->recordEvent(new JobSlugChanged($this->id->value, $previousSlug->value, $slug->value, $now));
        }
    }

    public function moveToCategory(CategoryId $categoryId, int $position, \DateTimeImmutable $now): void
    {
        $this->categoryId = $categoryId;
        $this->position = $position;
        $this->touch($now, 'moved');
    }

    /**
     * RG-010 : synonymes de recherche (« plombier » ↔ « chauffagiste sanitaire »), dédoublonnés sans tenir compte
     * de la casse ni des accents ; le libellé du métier lui-même n'est pas un synonyme.
     *
     * @param list<string> $synonyms
     */
    public function replaceSynonyms(array $synonyms, \DateTimeImmutable $now): void
    {
        $kept = [];
        $seen = [self::normalize($this->name) => true];
        foreach ($synonyms as $synonym) {
            $synonym = trim((string) preg_replace('/\s+/u', ' ', $synonym));
            $key = self::normalize($synonym);
            if ('' === $key || isset($seen[$key])) {
                continue;
            }
            if (mb_strlen($synonym) > self::MAX_SYNONYM_LENGTH) {
                throw new CatalogRuleViolation(\sprintf('Un synonyme ne peut dépasser %d caractères.', self::MAX_SYNONYM_LENGTH));
            }
            $seen[$key] = true;
            $kept[] = $synonym;
        }
        if (\count($kept) > self::MAX_SYNONYMS) {
            throw new CatalogRuleViolation(\sprintf('%d synonymes maximum par métier.', self::MAX_SYNONYMS));
        }
        $this->synonyms = $kept;
        $this->touch($now, 'synonyms');
    }

    /**
     * A03.3 / Q16 : justificatifs exigés à l'inscription des artisans de ce métier (codes : kbis, insurance_rc,
     * insurance_decennial, rge, electrical_clearance…), assurance obligatoire, décennale (gros œuvre).
     *
     * @param list<string> $documentCodes
     */
    public function requireDocuments(array $documentCodes, bool $insuranceRequired, bool $decennialRequired, \DateTimeImmutable $now): void
    {
        foreach ($documentCodes as $code) {
            if (1 !== preg_match(self::DOCUMENT_CODE_PATTERN, $code)) {
                throw new CatalogRuleViolation(\sprintf('Code de justificatif invalide : « %s ».', $code));
            }
        }
        $this->requiredDocuments = array_values(array_unique($documentCodes));
        $this->insuranceRequired = $insuranceRequired || $decennialRequired;
        $this->decennialRequired = $decennialRequired;
        $this->touch($now, 'documents');
    }

    public function describe(?string $description, \DateTimeImmutable $now): void
    {
        $description = null === $description ? null : trim($description);
        $this->description = '' === $description ? null : $description;
        $this->touch($now, 'described');
    }

    public function moveTo(int $position, \DateTimeImmutable $now): void
    {
        if ($position < 0) {
            throw new CatalogRuleViolation('Position invalide.');
        }
        $this->position = $position;
        $this->touch($now, 'moved');
    }

    /**
     * BO06 : un métier utilisé (artisans, demandes) ne peut être désactivé sans métier de remplacement.
     */
    public function deactivate(?self $replacement, int $usageCount, \DateTimeImmutable $now): void
    {
        if (null !== $replacement) {
            if ($replacement->id->equals($this->id)) {
                throw new CatalogRuleViolation('Un métier ne peut se remplacer lui-même.');
            }
            if (!$replacement->isActive()) {
                throw new CatalogRuleViolation('Le métier de remplacement doit être actif.');
            }
        } elseif ($usageCount > 0) {
            throw new CatalogRuleViolation(\sprintf('Ce métier est utilisé (%d référence(s)) : choisissez un métier de remplacement.', $usageCount));
        }
        $this->active = false;
        $this->replacedBy = $replacement?->id;
        $this->touch($now, 'deactivated');
        $this->recordEvent(new JobDeactivated($this->id->value, $replacement?->id->value, $now));
    }

    public function activate(\DateTimeImmutable $now): void
    {
        $this->active = true;
        $this->replacedBy = null;
        $this->touch($now, 'activated');
    }

    public function id(): JobId
    {
        return $this->id;
    }

    public function categoryId(): CategoryId
    {
        return $this->categoryId;
    }

    public function name(): string
    {
        return $this->name;
    }

    public function slug(): Slug
    {
        return $this->slug;
    }

    public function position(): int
    {
        return $this->position;
    }

    /** @return list<string> */
    public function synonyms(): array
    {
        return $this->synonyms;
    }

    /** @return list<string> */
    public function requiredDocuments(): array
    {
        return $this->requiredDocuments;
    }

    public function isInsuranceRequired(): bool
    {
        return $this->insuranceRequired;
    }

    public function isDecennialRequired(): bool
    {
        return $this->decennialRequired;
    }

    public function isActive(): bool
    {
        return $this->active;
    }

    public function replacedBy(): ?JobId
    {
        return $this->replacedBy;
    }

    private function touch(\DateTimeImmutable $now, string $change): void
    {
        $this->updatedAt = $now;
        $this->recordEvent(new JobChanged($this->id->value, $change, $now));
    }

    private static function normalize(string $text): string
    {
        $lower = mb_strtolower(trim($text));

        return strtr($lower, ['à' => 'a', 'â' => 'a', 'ä' => 'a', 'ç' => 'c', 'é' => 'e', 'è' => 'e', 'ê' => 'e', 'ë' => 'e', 'î' => 'i', 'ï' => 'i', 'ô' => 'o', 'ö' => 'o', 'ù' => 'u', 'û' => 'u', 'ü' => 'u', 'œ' => 'oe']);
    }
}
