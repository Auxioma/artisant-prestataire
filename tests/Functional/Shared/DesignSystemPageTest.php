<?php

declare(strict_types=1);

namespace App\Tests\Functional\Shared;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/** La planche du design system n'existe qu'en développement : jamais exposée en test ni en production. */
final class DesignSystemPageTest extends WebTestCase
{
    public function testDesignSystemPageIsNotRoutedOutsideDev(): void
    {
        $client = self::createClient();
        $client->request('GET', '/_dev/design-system');

        self::assertResponseStatusCodeSame(404);
    }
}
