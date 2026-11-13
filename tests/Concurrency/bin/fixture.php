<?php

declare(strict_types=1);

/* Banc de concurrence : construit et commite les données (base `…_test_concurrency`), affiche l'id du profil artisan. */

use App\Kernel;
use App\Tests\Concurrency\ConcurrencyFixture;
use Symfony\Component\DependencyInjection\ContainerInterface;

require dirname(__DIR__, 2).'/bootstrap.php';

$kernel = new Kernel('test', false);
$kernel->boot();
$container = $kernel->getContainer()->get('test.service_container');
assert($container instanceof ContainerInterface);
echo implode(',', ConcurrencyFixture::build($container));
