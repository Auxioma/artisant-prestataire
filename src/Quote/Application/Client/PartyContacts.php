<?php

declare(strict_types=1);

namespace App\Quote\Application\Client;

use App\ArtisanProfile\Application\Account\ArtisanAccount;
use App\ArtisanProfile\Application\Account\ArtisanAccountOf;
use App\ArtisanProfile\Application\PublicProfile\ArtisanPhoneHandler;
use App\QuoteRequest\Application\Port\RequestContentStore;
use App\QuoteRequest\Domain\Request\QuoteRequest;
use App\Shared\Application\Bus\QueryBus;
use App\Shared\Application\Identity\UserContact;
use App\Shared\Application\Identity\UserContactDetails;
use App\Shared\Application\Identity\UserPhone;

/**
 * RG-022bis — coordonnées partagées entre le client et l'artisan retenu, une fois le devis accepté seulement : le
 * client voit celles de l'artisan (C03B), l'artisan celles du client et l'adresse complète d'intervention (PR03).
 */
final readonly class PartyContacts
{
    public function __construct(private QueryBus $queries, private RequestContentStore $content)
    {
    }

    /** @return array{name: string, email: ?string, phone: ?string}|null */
    public function artisan(string $artisanId): ?array
    {
        $account = '' === $artisanId ? null : $this->queries->ask(new ArtisanAccountOf($artisanId));
        if (!$account instanceof ArtisanAccount) {
            return null;
        }
        $contact = $this->queries->ask(new UserContact($account->userId));

        return ['name' => $account->name, 'email' => $contact instanceof UserContactDetails ? $contact->email : null, 'phone' => $this->phone($account->userId)];
    }

    /** @return array{name: string, email: ?string, phone: ?string, address: ?string} */
    public function client(QuoteRequest $request): array
    {
        $contact = $this->queries->ask(new UserContact($request->clientId()));
        $address = $this->content->address($request->id()->value);

        return [
            'name' => $contact instanceof UserContactDetails ? trim($contact->firstName.' '.$contact->lastName) : 'Votre client',
            'email' => $contact instanceof UserContactDetails ? $contact->email : null,
            'phone' => $this->phone($request->clientId()),
            'address' => null === $address ? null : trim($address.', '.$request->postalCode()),
        ];
    }

    private function phone(string $userId): ?string
    {
        $phone = $this->queries->ask(new UserPhone($userId));

        return \is_string($phone) ? ArtisanPhoneHandler::display($phone) : null;
    }
}
