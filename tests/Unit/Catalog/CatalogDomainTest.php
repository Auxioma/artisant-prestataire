<?php

declare(strict_types=1);

namespace App\Tests\Unit\Catalog;

use App\Catalog\Application\JobSlugPolicy;
use App\Catalog\Domain\Category\Category;
use App\Catalog\Domain\Category\CategoryId;
use App\Catalog\Domain\Event\CategorySlugChanged;
use App\Catalog\Domain\Event\JobDeactivated;
use App\Catalog\Domain\Event\JobSlugChanged;
use App\Catalog\Domain\Exception\CatalogRuleViolation;
use App\Catalog\Domain\Job\Job;
use App\Catalog\Domain\Job\JobId;
use App\Catalog\Domain\Label;
use App\Shared\Domain\ValueObject\Slug;
use App\Shared\Infrastructure\Identifier\UuidV7Generator;
use PHPUnit\Framework\TestCase;

/** BO06 : arborescence, slugs (301), synonymes (RG-010), justificatifs (Q16), désactivation encadrée. */
final class CatalogDomainTest extends TestCase
{
    private \DateTimeImmutable $now;
    private UuidV7Generator $ids;

    protected function setUp(): void
    {
        $this->now = new \DateTimeImmutable('2026-11-11T10:00:00Z');
        $this->ids = new UuidV7Generator();
    }

    private function category(?Category $parent = null, string $name = 'Plomberie'): Category
    {
        return Category::create(new CategoryId($this->ids->next()), $parent, new Label($name), Slug::fromText($name), 'wrench', 0, $this->now);
    }

    private function job(string $name = 'Plombier'): Job
    {
        return Job::create(new JobId($this->ids->next()), new CategoryId($this->ids->next()), new Label($name), Slug::fromText($name), 0, $this->now);
    }

    public function testTreeIsLimitedToTwoLevels(): void
    {
        $root = $this->category();
        $sub = $this->category($root, 'Sanitaire');
        self::assertFalse($sub->isRoot());

        $this->expectException(CatalogRuleViolation::class);
        $this->category($sub, 'Trop profond');
    }

    public function testRenamingWithNewSlugEmitsSlugChangedOnly(): void
    {
        $category = $this->category();
        $category->releaseEvents();

        $category->rename(new Label('Plomberie générale'), Slug::fromText('Plomberie'), $this->now);
        self::assertSame([], array_filter($category->releaseEvents(), static fn (object $e): bool => $e instanceof CategorySlugChanged), 'Libellé seul : pas de redirection');

        $category->rename(new Label('Plomberie générale'), new Slug('plomberie-generale'), $this->now);
        $slugEvents = array_values(array_filter($category->releaseEvents(), static fn (object $e): bool => $e instanceof CategorySlugChanged));
        self::assertCount(1, $slugEvents);
        self::assertSame(['plomberie', 'plomberie-generale'], [$slugEvents[0]->oldSlug, $slugEvents[0]->newSlug]);
    }

    public function testRejectsInvalidIconAndLabel(): void
    {
        $this->expectException(CatalogRuleViolation::class);
        Category::create(new CategoryId($this->ids->next()), null, new Label('Plomberie'), new Slug('plomberie'), '<svg onload=x>', 0, $this->now);
    }

    public function testLabelBounds(): void
    {
        self::assertSame('Rénovation & Construction', new Label("  Rénovation \t&  Construction ")->value);
        $this->expectException(CatalogRuleViolation::class);
        new Label('x');
    }

    public function testSynonymsAreDeduplicatedIgnoringCaseAndAccents(): void
    {
        $job = $this->job();
        $job->replaceSynonyms(['Chauffagiste sanitaire', 'CHAUFFAGISTE SANITAIRE', 'plombier', '  ', 'Plombière'], $this->now);

        self::assertSame(['Chauffagiste sanitaire', 'Plombière'], $job->synonyms(), 'Doublons et libellé du métier retirés');
    }

    public function testDecennialImpliesInsuranceAndDocumentCodesAreValidated(): void
    {
        $job = $this->job('Maçon');
        $job->requireDocuments(['kbis', 'insurance_rc', 'insurance_decennial', 'kbis'], false, true, $this->now);

        self::assertTrue($job->isDecennialRequired());
        self::assertTrue($job->isInsuranceRequired(), 'La décennale est une assurance obligatoire');
        self::assertSame(['kbis', 'insurance_rc', 'insurance_decennial'], $job->requiredDocuments());

        $this->expectException(CatalogRuleViolation::class);
        $job->requireDocuments(['Kbis !'], true, false, $this->now);
    }

    public function testUsedJobCannotBeDeactivatedWithoutReplacement(): void
    {
        $job = $this->job();

        $this->expectException(CatalogRuleViolation::class);
        $this->expectExceptionMessage('choisissez un métier de remplacement');
        $job->deactivate(null, 12, $this->now);
    }

    public function testDeactivationWithReplacement(): void
    {
        $job = $this->job();
        $replacement = $this->job('Plombier chauffagiste');
        $job->releaseEvents();

        $job->deactivate($replacement, 12, $this->now);

        self::assertFalse($job->isActive());
        self::assertTrue($replacement->id()->equals($job->replacedBy() ?? new JobId($this->ids->next())));
        $events = array_values(array_filter($job->releaseEvents(), static fn (object $e): bool => $e instanceof JobDeactivated));
        self::assertSame($replacement->id()->value, $events[0]->replacementJobId);
    }

    public function testReplacementMustBeActiveAndDifferent(): void
    {
        $job = $this->job();
        $inactive = $this->job('Plombier chauffagiste');
        $inactive->deactivate(null, 0, $this->now);

        try {
            $job->deactivate($job, 0, $this->now);
            self::fail('Auto-remplacement accepté');
        } catch (CatalogRuleViolation) {
        }
        $this->expectException(CatalogRuleViolation::class);
        $job->deactivate($inactive, 3, $this->now);
    }

    public function testUnusedJobCanBeDeactivatedAndReactivated(): void
    {
        $job = $this->job();
        $job->deactivate(null, 0, $this->now);
        $job->activate($this->now);

        self::assertTrue($job->isActive());
        self::assertNull($job->replacedBy());
    }

    public function testJobRenameEmitsSlugChange(): void
    {
        $job = $this->job();
        $job->releaseEvents();
        $job->rename(new Label('Plombier chauffagiste'), new Slug('plombier-chauffagiste'), $this->now);

        self::assertCount(1, array_filter($job->releaseEvents(), static fn (object $e): bool => $e instanceof JobSlugChanged));
    }

    public function testReservedSlugsAreRejected(): void
    {
        JobSlugPolicy::assertAllowed(new Slug('plombier'));
        $this->expectException(CatalogRuleViolation::class);
        JobSlugPolicy::assertAllowed(new Slug('recherche'));
    }
}
