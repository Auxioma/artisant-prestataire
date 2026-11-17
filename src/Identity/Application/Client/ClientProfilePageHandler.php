<?php

declare(strict_types=1);

namespace App\Identity\Application\Client;

use App\Catalog\Application\Geo\Query\CommuneSummary;
use App\Catalog\Application\Geo\Query\FindCommunes;
use App\Identity\Domain\Client\ClientProfileRepository;
use App\Identity\Domain\User\UserId;
use App\Identity\Domain\User\UserRepository;
use App\Shared\Application\Bus\QueryBus;
use App\Shared\Application\Bus\QueryHandler;

final readonly class ClientProfilePageHandler implements QueryHandler
{
    public function __construct(private UserRepository $users, private ClientProfileRepository $profiles, private QueryBus $queries)
    {
    }

    public function __invoke(ClientProfilePage $query): ?ClientProfileView
    {
        $id = new UserId($query->userId);
        $user = $this->users->find($id);
        $profile = $this->profiles->find($id);
        if (null === $user || null === $profile) {
            return null;
        }
        $communes = $this->queries->ask(new FindCommunes($profile->postalCode(), 1));
        $commune = \is_array($communes) ? ($communes[0] ?? null) : null;

        return new ClientProfileView(
            $user->firstName(),
            $user->lastName(),
            $user->email()->value,
            $user->isEmailVerified(),
            $user->phone()?->value,
            $profile->type(),
            $profile->companyName(),
            $profile->siret()?->value,
            $profile->postalCode(),
            $commune instanceof CommuneSummary ? $commune->name : null,
            $profile->searchRadiusKm(),
            $profile->interests(),
            $profile->isSubscribedToNewsletter(),
            $profile->avatarFileId(),
        );
    }
}
