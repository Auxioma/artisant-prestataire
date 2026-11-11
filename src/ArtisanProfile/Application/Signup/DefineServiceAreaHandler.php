<?php

declare(strict_types=1);

namespace App\ArtisanProfile\Application\Signup;

use App\ArtisanProfile\Domain\Profile\ArtisanProfileRepository;
use App\ArtisanProfile\Domain\Profile\ArtisanRuleViolation;
use App\ArtisanProfile\Domain\Profile\ServiceArea;
use App\ArtisanProfile\Domain\Profile\ServiceAreaMode;
use App\Catalog\Application\Geo\Query\CommunesByInsee;
use App\Catalog\Application\Geo\Query\CommuneSummary;
use App\Catalog\Application\Geo\Query\Departments;
use App\Shared\Application\Bus\CommandHandler;
use App\Shared\Application\Bus\EventBus;
use App\Shared\Application\Bus\QueryBus;
use App\Shared\Application\Settings\SettingKey;
use App\Shared\Application\Settings\SettingsProvider;
use App\Shared\Domain\Time\Clock;

final readonly class DefineServiceAreaHandler implements CommandHandler
{
    public function __construct(
        private ArtisanProfileRepository $profiles,
        private QueryBus $queries,
        private SettingsProvider $settings,
        private EventBus $events,
        private Clock $clock,
    ) {
    }

    public function __invoke(DefineServiceArea $command): void
    {
        $profile = $this->profiles->findByUserId($command->userId) ?? throw new ArtisanRuleViolation('Déclarez d’abord votre entreprise.');

        if (ServiceAreaMode::Radius === ServiceAreaMode::tryFrom($command->mode)) {
            if (null === $command->centerInsee || null === $command->radiusKm) {
                throw new ArtisanRuleViolation('Définissez votre zone d’intervention.');
            }
            $this->assertCommunesExist([$command->centerInsee]);
            $area = ServiceArea::radius($command->centerInsee, $command->radiusKm, $this->settings->int(SettingKey::ProRadiusMinKm), $this->settings->int(SettingKey::ProRadiusMaxKm));
        } elseif (ServiceAreaMode::List === ServiceAreaMode::tryFrom($command->mode)) {
            $area = ServiceArea::list($command->departments, $command->communes);
            $known = $this->queries->ask(new Departments());
            foreach ($area->departments as $code) {
                if (!\is_array($known) || !isset($known[$code])) {
                    throw new SignupRejected('departments', SignupRejected::AREA_UNKNOWN, 'Un des départements choisis est inconnu.');
                }
            }
            $this->assertCommunesExist($area->communes);
        } else {
            throw new ArtisanRuleViolation('Définissez votre zone d’intervention.');
        }

        $profile->defineServiceArea($area, $this->clock->now());
        $this->events->publish(...$profile->releaseEvents());
    }

    /** @param list<string> $codes */
    private function assertCommunesExist(array $codes): void
    {
        if ([] === $codes) {
            return;
        }
        $found = $this->queries->ask(new CommunesByInsee($codes));
        $foundCodes = array_map(static fn (CommuneSummary $c): string => $c->insee, array_filter(\is_array($found) ? $found : [], static fn (mixed $c): bool => $c instanceof CommuneSummary));
        if ([] !== array_diff($codes, $foundCodes)) {
            throw new SignupRejected('communes', SignupRejected::AREA_UNKNOWN, 'Commune inconnue : choisissez-la dans la liste proposée.');
        }
    }
}
