<?php

declare(strict_types=1);

namespace App\ArtisanProfile\UI\Twig;

use App\ArtisanProfile\Domain\Profile\ArtisanProfileRepository;
use App\Shared\Application\Bus\QueryBus;
use App\Shared\Application\Identity\UserContact;
use App\Shared\Application\Identity\UserContactDetails;
use App\Shared\Application\Security\CurrentUser;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * `tm_pro_identity()` : carte profil du menu de l'espace pro (Figma 334:17263) — nom de l'artisan, entreprise, logo
 * public (profil vérifié et visible), initiales sinon. Calculée une fois par requête.
 */
final class ProSpaceExtension extends AbstractExtension
{
    /** @var array{name: string, company: string, initials: string, logoProfileId: ?string}|false|null */
    private array|false|null $identity = null;

    public function __construct(private readonly ArtisanProfileRepository $profiles, private readonly QueryBus $queries, private readonly CurrentUser $user)
    {
    }

    public function getFunctions(): array
    {
        return [new TwigFunction('tm_pro_identity', $this->identity(...))];
    }

    /** @return array{name: string, company: string, initials: string, logoProfileId: ?string}|null */
    public function identity(): ?array
    {
        if (null === $this->identity) {
            $this->identity = $this->load() ?? false;
        }

        return false === $this->identity ? null : $this->identity;
    }

    /** @return array{name: string, company: string, initials: string, logoProfileId: ?string}|null */
    private function load(): ?array
    {
        $userId = $this->user->id();
        $profile = null === $userId || !$this->user->isArtisan() ? null : $this->profiles->findByUserId($userId);
        $contact = null === $userId ? null : $this->queries->ask(new UserContact($userId));
        if (null === $profile || !$contact instanceof UserContactDetails) {
            return null;
        }
        $name = trim($contact->firstName.' '.$contact->lastName);
        $public = $profile->isPublic() && null !== $profile->logoFileId();

        return [
            'name' => $name,
            'company' => $profile->tradeName() ?? $profile->companyName() ?? '',
            'initials' => mb_strtoupper(mb_substr($contact->firstName, 0, 1).mb_substr($contact->lastName, 0, 1)),
            'logoProfileId' => $public ? $profile->id()->value : null,
        ];
    }
}
