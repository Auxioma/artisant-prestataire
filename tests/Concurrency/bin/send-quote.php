<?php

declare(strict_types=1);

/*
 * Banc de concurrence : un envoi de devis (SendQuote) dans son propre processus et sa propre connexion. Attend la
 * barrière (verrou consultatif tenu par le test) pour démarrer en même temps que l'autre processus, puis affiche
 * « sent » ou le motif de refus RG-101.
 */

use App\Kernel;
use App\Quote\Application\Sending\SendQuote;
use App\Quote\Domain\Sending\QuoteSendingRefused;
use App\Shared\Application\Bus\CommandBus;
use Doctrine\DBAL\Connection;
use Symfony\Component\DependencyInjection\ContainerInterface;

require dirname(__DIR__, 2).'/bootstrap.php';

[, $userId, $requestId, $barrier] = $argv;
$kernel = new Kernel('test', true); // debug : conteneur recompilé si le code a changé (sinon conteneur périmé)
$kernel->boot();
$container = $kernel->getContainer()->get('test.service_container');
assert($container instanceof ContainerInterface);
$bus = $container->get(CommandBus::class);
$connection = $container->get(Connection::class);
assert($bus instanceof CommandBus && $connection instanceof Connection);

$connection->executeQuery('SELECT pg_advisory_lock_shared(:k)', ['k' => (int) $barrier]);
$connection->executeQuery('SELECT pg_advisory_unlock_shared(:k)', ['k' => (int) $barrier]);
try {
    $bus->dispatch(new SendQuote($userId, $requestId));
    echo 'sent';
} catch (QuoteSendingRefused $e) {
    echo $e->reason->value;
}
