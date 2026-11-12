<?php

declare(strict_types=1);

namespace App\ArtisanProfile\Application\Edit;

use App\ArtisanProfile\Domain\Profile\ArtisanProfileRepository;
use App\ArtisanProfile\Domain\Profile\ArtisanRuleViolation;
use App\ArtisanProfile\Domain\Profile\ArtisanStatus;
use App\ArtisanProfile\Domain\Profile\OpeningHours;
use App\ArtisanProfile\Domain\Profile\PublicPresentation;
use App\ArtisanProfile\Domain\Profile\ServiceOffer;
use App\Shared\Application\Bus\CommandHandler;
use App\Shared\Application\Bus\EventBus;
use App\Shared\Domain\Time\Clock;

/**
 * PR08 — édition du profil public. RG-031 : les textes sont refusés s'ils contiennent des coordonnées (les clients
 * passent par TrouveMoi, RG-030) ou un terme interdit ; une part `moderation.profile_sample_rate` des modifications
 * est retenue pour une relecture humaine (file BO05).
 */
final readonly class PublicProfileEditor implements CommandHandler
{
    public function __construct(
        private ArtisanProfileRepository $profiles,
        private ProfileModeration $moderation,
        private EventBus $events,
        private Clock $clock,
    ) {
    }

    public function __invoke(UpdatePublicPresentation|UpdateServices|UpdateAvailability $command): void
    {
        $profile = $this->profiles->findByUserId($command->userId) ?? throw new ArtisanRuleViolation('Profil professionnel introuvable.');
        if (ArtisanStatus::Incomplete === $profile->status()) {
            throw new ArtisanRuleViolation('Terminez d’abord votre inscription.');
        }
        $now = $this->clock->now();

        if ($command instanceof UpdatePublicPresentation) {
            $presentation = new PublicPresentation(
                self::clean($command->slogan),
                self::clean($command->description),
                array_values(array_filter(array_map(static fn (string $s): string => trim($s), $command->strengths), static fn (string $s): bool => '' !== $s)),
                array_values(array_unique($command->languages)),
                $command->yearsExperience,
                array_values(array_unique($command->clients)),
            );
            $this->moderation->check(array_merge([$command->tradeName ?? '', $presentation->slogan ?? '', $presentation->description ?? ''], $presentation->strengths));
            $profile->updatePresentation(self::clean($command->tradeName), $presentation, $now);
            $this->moderation->sample($profile->id()->value, 'presentation', $presentation->toArray() + ['trade_name' => self::clean($command->tradeName)], $now);
        } elseif ($command instanceof UpdateServices) {
            $services = [];
            foreach ($command->services as $row) {
                if ('' !== trim($row['title'])) {
                    $services[] = new ServiceOffer(trim($row['title']), self::clean($row['price']));
                }
            }
            $this->moderation->check(array_map(static fn (ServiceOffer $s): string => $s->title, $services));
            $profile->updateServices($services, $now);
            $this->moderation->sample($profile->id()->value, 'services', ['services' => array_map(static fn (ServiceOffer $s): array => ['title' => $s->title, 'price' => $s->price], $services)], $now);
        } else {
            $days = [];
            foreach (OpeningHours::DAYS as $day) {
                $days[$day] = array_values(array_filter(array_map('strval', $command->days[$day] ?? []), static fn (string $r): bool => '' !== trim($r)));
            }
            $note = self::clean($command->emergencyNote);
            $this->moderation->check([$note ?? '']);
            $profile->updateAvailability(new OpeningHours($days, $command->emergencies, $note), $command->phonePublic, $now);
        }
        $this->events->publish(...$profile->releaseEvents());
    }

    private static function clean(?string $value): ?string
    {
        $value = null === $value ? '' : trim(str_replace(["\r\n", "\r"], "\n", $value));

        return '' === $value ? null : $value;
    }
}
