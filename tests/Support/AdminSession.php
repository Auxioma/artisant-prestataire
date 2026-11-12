<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Administration\Application\Team\InvitationToken;
use App\Administration\Application\Team\InviteAdmin;
use App\Shared\Application\Bus\CommandBus;
use OTPHP\TOTP;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;

/** Ouvre une session back-office complète (invitation, activation, mot de passe puis code TOTP) pour un rôle donné. */
trait AdminSession
{
    private function adminSession(KernelBrowser $client, string $adminId, string $role): void
    {
        $password = 'phrase secrète du back-office fictive';
        $email = $role.'.'.substr($adminId, -4).'@exemple.test';
        $token = InvitationToken::generate();
        $bus = $client->getContainer()->get(CommandBus::class);
        \assert($bus instanceof CommandBus);
        $bus->dispatch(new InviteAdmin($adminId, $email, 'Admin '.$role, $role, $token, null));

        $crawler = $client->request('GET', '/admin/invitation/'.$token);
        $secret = str_replace(' ', '', $crawler->filter('.tm-admin-secret')->text());
        \assert('' !== $secret);
        $client->request('POST', '/admin/invitation/'.$token, ['_token' => (string) $crawler->filter('input[name="_token"]')->attr('value'), 'password' => $password, 'password_confirm' => $password, 'code' => TOTP::createFromSecret($secret)->now()]);

        $crawler = $client->request('GET', '/admin/connexion');
        $client->request('POST', '/admin/connexion', ['_username' => $email, '_password' => $password, '_csrf_token' => (string) $crawler->filter('input[name="_csrf_token"]')->attr('value')]);
        $crawler = $client->request('GET', '/admin/2fa');
        $client->request('POST', '/admin/2fa/verification', ['_auth_code' => TOTP::createFromSecret($secret)->now(), '_csrf_token' => (string) $crawler->filter('input[name="_csrf_token"]')->attr('value')]);
    }
}
