<?php

declare(strict_types=1);

namespace App\Identity\Application\Command;

/**
 * Un compte existe déjà pour cet e-mail. L'interface ne doit PAS le révéler tel quel (anti-énumération, T-202) :
 * elle affiche le même message que pour une inscription réussie et prévient le titulaire par e-mail.
 */
final class EmailAlreadyRegistered extends \DomainException
{
}
