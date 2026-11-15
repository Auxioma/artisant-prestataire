<?php

declare(strict_types=1);

namespace App\Identity\Application\Client;

use App\Catalog\Application\Geo\Query\FindCommunes;
use App\Identity\Domain\Client\ClientInterest;
use App\Identity\Domain\Client\ClientProfileRepository;
use App\Identity\Domain\User\IdentityRuleViolation;
use App\Identity\Domain\User\PersonName;
use App\Identity\Domain\User\UserId;
use App\Identity\Domain\User\UserRepository;
use App\Shared\Application\Bus\CommandHandler;
use App\Shared\Application\Bus\QueryBus;
use App\Shared\Application\Settings\SettingKey;
use App\Shared\Application\Settings\SettingsProvider;
use App\Shared\Domain\Exception\InvalidValue;
use App\Shared\Domain\Time\Clock;
use App\Shared\Domain\ValueObject\PhoneNumber;

final readonly class UpdateClientProfileHandler implements CommandHandler
{
    public function __construct(
        private UserRepository $users,
        private ClientProfileRepository $profiles,
        private QueryBus $queries,
        private SettingsProvider $settings,
        private Clock $clock,
    ) {
    }

    public function __invoke(UpdateClientProfile $command): void
    {
        $id = new UserId($command->userId);
        $user = $this->users->get($id);
        $profile = $this->profiles->find($id) ?? throw new ClientProfileRejected('firstName', 'Profil client introuvable.');
        $now = $this->clock->now();

        try {
            $firstName = new PersonName($command->firstName);
        } catch (IdentityRuleViolation) {
            throw new ClientProfileRejected('firstName', 'Prénom invalide.');
        }
        try {
            $lastName = new PersonName($command->lastName);
        } catch (IdentityRuleViolation) {
            throw new ClientProfileRejected('lastName', 'Nom invalide.');
        }
        try {
            $phone = new PhoneNumber($command->phone);
        } catch (InvalidValue) {
            throw new ClientProfileRejected('phone', 'Numéro de téléphone invalide.');
        }
        $postalCode = trim($command->postalCode);
        $found = 1 === preg_match('/^\d{5}$/', $postalCode) ? $this->queries->ask(new FindCommunes($postalCode, 1)) : [];
        if (!\is_array($found) || [] === $found) {
            throw new ClientProfileRejected('postalCode', 'Code postal inconnu.');
        }
        $radii = $this->settings->intList(SettingKey::SearchRadiusOptions);
        if (null !== $command->searchRadiusKm && ([] === $radii || $command->searchRadiusKm < min($radii) || $command->searchRadiusKm > max($radii))) {
            throw new ClientProfileRejected('searchRadiusKm', 'Rayon de recherche hors des limites proposées.');
        }

        $user->rename($firstName, $lastName, $now);
        $user->changePhone($phone, $now);
        $profile->relocate($postalCode, $command->searchRadiusKm, $now);
        $profile->changeInterests(array_values(array_filter(array_map(ClientInterest::tryFrom(...), $command->interests))), $now);
        if ($command->newsletter) {
            $profile->subscribeToNewsletter($now);
        } else {
            $profile->unsubscribeFromNewsletter($now);
        }
    }
}
