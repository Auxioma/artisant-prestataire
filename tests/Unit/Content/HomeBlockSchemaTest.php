<?php

declare(strict_types=1);

namespace App\Tests\Unit\Content;

use App\Content\Application\Home\HomeBlockSchema;
use App\Content\Domain\Home\HomeBlockType;
use App\Content\Domain\Home\HomeContentViolation;
use PHPUnit\Framework\TestCase;

final class HomeBlockSchemaTest extends TestCase
{
    public function testEmptyRowsAreIgnoredAndBoundsChecked(): void
    {
        $rows = [
            ['icon' => 'shield', 'label' => 'Professionnels vérifiés', 'target' => ''],
            ['icon' => 'zap', 'label' => '  ', 'target' => 'search'],
            ['icon' => 'star', 'label' => 'Avis clients certifiés', 'target' => 'how_it_works'],
        ];
        $settings = HomeBlockSchema::normalize(HomeBlockType::Reassurance, ['mobile_layout' => 'grid', 'items' => $rows]);

        self::assertIsArray($settings['items']);
        self::assertCount(2, $settings['items'], 'ligne sans libellé ignorée');

        $this->expectException(HomeContentViolation::class);
        $this->expectExceptionMessage('entre 2 et 6 éléments');
        HomeBlockSchema::normalize(HomeBlockType::Reassurance, ['mobile_layout' => 'grid', 'items' => [$rows[0]]]);
    }

    public function testRejectsInvalidValues(): void
    {
        $hero = ['title' => 'Titre', 'subtitle' => 'Sous-titre', 'job_placeholder' => 'a', 'place_placeholder' => 'b', 'button' => 'c', 'veil' => '90'];
        try {
            HomeBlockSchema::normalize(HomeBlockType::Hero, $hero);
            self::fail('voile hors bornes accepté');
        } catch (HomeContentViolation $e) {
            self::assertStringContainsString('entre 0 et 60', $e->getMessage());
        }

        $this->expectExceptionMessage('choix invalide');
        HomeBlockSchema::normalize(HomeBlockType::Cta, ['title' => 'a', 'text' => 'b', 'button' => 'c', 'background' => 'rose']);
    }
}
