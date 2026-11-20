<?php

declare(strict_types=1);

/*
 * Chargeur du gestionnaire d'entités pour phpstan-doctrine : PHPStan connaît ainsi le mapping XML
 * (propriétés gérées par Doctrine — version, dates — et types des requêtes DQL). Aucune connexion n'est ouverte.
 */
use App\Kernel;
use Symfony\Component\Dotenv\Dotenv;

require dirname(__DIR__, 2).'/vendor/autoload.php';
new Dotenv()->bootEnv(dirname(__DIR__, 2).'/.env');

$kernel = new Kernel('dev', true);
$kernel->boot();

return $kernel->getContainer()->get('doctrine')->getManager();
