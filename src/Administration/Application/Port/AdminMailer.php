<?php

declare(strict_types=1);

namespace App\Administration\Application\Port;

interface AdminMailer
{
    /** I-02 — invitation à rejoindre l'équipe (lien unique, durée limitée). */
    public function sendInvitation(string $email, string $name, string $roleLabel, #[\SensitiveParameter] string $token, int $ttlHours, string $adminId): void;
}
