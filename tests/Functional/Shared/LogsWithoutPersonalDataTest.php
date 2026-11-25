<?php

declare(strict_types=1);

namespace App\Tests\Functional\Shared;

use App\Identity\Application\Command\RegisterUser;
use App\Shared\Application\Bus\CommandBus;
use App\Tests\Support\Fake\AcceptingHumanVerification;
use Monolog\Handler\FingersCrossedHandler;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * SECURITY.md §6 / ASVS 7.1.1 (T-905a) : après un parcours complet (échec de connexion, connexion, mot de passe
 * oublié, message de contact), aucun journal, à aucun niveau ni sur aucun canal, ne contient l'adresse e-mail ou le
 * téléphone en clair. Le tampon du gestionnaire principal (tous canaux, tous niveaux, processeurs déjà appliqués) est
 * vidé dans le fichier de journal, dont seule la partie ajoutée est relue.
 */
final class LogsWithoutPersonalDataTest extends WebTestCase
{
    private const string EMAIL = 'dominique.journal@exemple.test';
    private const string PASSWORD = 'cheval agrafe batterie';
    private const string PHONE = '06 12 34 56 78';

    public function testFullFlowLeavesNoEmailOrPhoneInLogs(): void
    {
        $client = self::createClient();
        $client->disableReboot();
        $client->setServerParameter('HTTP_ORIGIN', 'http://localhost');
        $buffer = self::getContainer()->get('monolog.handler.main');
        \assert($buffer instanceof FingersCrossedHandler);
        $file = self::getContainer()->getParameter('kernel.logs_dir').'/test.log';
        \assert(\is_string($file));
        $offset = is_file($file) ? (int) filesize($file) : 0;
        $bus = self::getContainer()->get(CommandBus::class);
        \assert($bus instanceof CommandBus);
        $bus->dispatch(new RegisterUser('0190f5e4-0000-7000-8000-00000000f001', self::EMAIL, self::PASSWORD, 'client', 'Dominique', 'Journal'));

        foreach (['mauvais mot de passe', self::PASSWORD] as $password) {
            $page = $client->request('GET', '/connexion');
            $client->request('POST', '/connexion', ['_username' => self::EMAIL, '_password' => $password, '_csrf_token' => (string) $page->filter('input[name="_csrf_token"]')->attr('value')]);
        }
        $client->request('GET', '/deconnexion');
        $page = $client->request('GET', '/mot-de-passe-oublie');
        $client->request('POST', '/mot-de-passe-oublie', ['email' => self::EMAIL, '_token' => (string) $page->filter('input[name="_token"]')->attr('value')]);
        $client->request('POST', '/contact', [
            '_token' => 'csrf-token', 'reason' => 'Mon compte', 'name' => 'Dominique Journal', 'email' => self::EMAIL, 'phone' => self::PHONE,
            'message' => 'Bonjour, je souhaite modifier les informations de mon compte, merci.', 'consent' => '1',
            'cf-turnstile-response' => AcceptingHumanVerification::HUMAN,
        ]);

        $buffer->activate();
        $logs = (string) file_get_contents($file, false, null, $offset);
        self::assertGreaterThan(20, substr_count($logs, "\n"), 'le parcours produit des journaux');
        foreach (explode("\n", $logs) as $line) {
            self::assertStringNotContainsStringIgnoringCase(self::EMAIL, $line);
            self::assertStringNotContainsString(self::PHONE, $line);
            self::assertStringNotContainsString(str_replace(' ', '', self::PHONE), $line);
        }
    }
}
