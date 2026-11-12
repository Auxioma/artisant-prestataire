<?php

declare(strict_types=1);

namespace App\ArtisanProfile\Application\PublicProfile;

use App\ArtisanProfile\Domain\Profile\ArtisanProfile;
use App\ArtisanProfile\Domain\Profile\ArtisanProfileId;
use App\ArtisanProfile\Domain\Profile\ArtisanProfileRepository;
use App\Shared\Application\Bus\QueryBus;
use App\Shared\Application\Bus\QueryHandler;
use App\Shared\Application\Identity\UserPhone;
use App\Shared\Application\Settings\SettingKey;
use App\Shared\Application\Settings\SettingsProvider;

/**
 * RG-030 — le téléphone n'est donné que pour un artisan public ayant activé l'option « afficher mon téléphone », si
 * la plateforme l'autorise (pro.phone_public_allowed, Q13). Chaque affichage est tracé (RecordPhoneReveal).
 */
final readonly class ArtisanPhoneHandler implements QueryHandler
{
    private const string FRENCH_PREFIX = '+33';
    private const int FRENCH_LENGTH = 12;
    private const int GROUP = 2;

    public function __construct(
        private ArtisanProfileRepository $profiles,
        private QueryBus $queries,
        private SettingsProvider $settings,
    ) {
    }

    public function __invoke(ArtisanPhone $query): ?string
    {
        $profile = $this->revealable($query->profileId);
        $phone = null === $profile ? null : $this->queries->ask(new UserPhone($profile->userId()));

        return \is_string($phone) ? self::display($phone) : null;
    }

    public function revealable(string $profileId): ?ArtisanProfile
    {
        if (1 !== preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/', $profileId) || !$this->settings->bool(SettingKey::ProPhonePublicAllowed)) {
            return null;
        }
        $profile = $this->profiles->find(new ArtisanProfileId($profileId));

        return null !== $profile && $profile->isPublic() && $profile->isPhonePublic() ? $profile : null;
    }

    /** +33612345678 → 06 12 34 56 78 ; numéros étrangers laissés au format international. */
    private static function display(string $e164): string
    {
        if (!str_starts_with($e164, self::FRENCH_PREFIX) || self::FRENCH_LENGTH !== \strlen($e164)) {
            return $e164;
        }

        return implode(' ', str_split('0'.substr($e164, \strlen(self::FRENCH_PREFIX)), self::GROUP));
    }
}
