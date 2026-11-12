<?php

declare(strict_types=1);

namespace App\Administration\Infrastructure\Security;

use App\Administration\Application\Team\BackupCodes;
use App\Administration\Application\Team\ConsumeAdminBackupCode;
use App\Shared\Application\Bus\CommandBus;
use Scheb\TwoFactorBundle\Security\TwoFactor\Backup\BackupCodeManagerInterface;

/** Codes de secours vérifiés par empreinte HMAC, consommés par le cas d'usage (usage unique, journalisé). */
final readonly class AdminBackupCodeManager implements BackupCodeManagerInterface
{
    public function __construct(private BackupCodes $codes, private CommandBus $commands)
    {
    }

    public function isBackupCode(object $user, string $code): bool
    {
        if (!$user instanceof AdminSecurityUser) {
            return false;
        }
        $hash = $this->codes->hash($code);
        foreach ($user->backupCodeHashes() as $candidate) {
            if (hash_equals($candidate, $hash)) {
                return true;
            }
        }

        return false;
    }

    public function invalidateBackupCode(object $user, string $code): void
    {
        if ($user instanceof AdminSecurityUser) {
            $this->commands->dispatch(new ConsumeAdminBackupCode($user->id, $code));
        }
    }
}
