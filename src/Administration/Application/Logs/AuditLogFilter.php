<?php

declare(strict_types=1);

namespace App\Administration\Application\Logs;

/**
 * BO15.1 — filtres du journal : vue « audit » (actions des administrateurs) ou « connexions » (connexions et échecs,
 * tous comptes), recherche libre (action, objet, nom d'admin), module (préfixe de l'action), admin, période en jours.
 */
final readonly class AuditLogFilter
{
    public const string VIEW_AUDIT = 'audit';
    public const string VIEW_LOGINS = 'connexions';
    /** Périodes proposées (jours) ; la conservation du journal est d'un an (CDC BO15.1). */
    public const array PERIODS = [1 => '24 heures', 7 => '7 jours', 30 => '30 jours', 90 => '3 mois', 365 => '1 an'];
    public const array LOGIN_ACTIONS = ['identity.login', 'security.login_failed', 'admin.login', 'admin.login_failed', 'admin.backup_code_used'];

    public function __construct(
        public string $view = self::VIEW_AUDIT,
        public string $search = '',
        public ?string $module = null,
        public ?string $adminId = null,
        public ?int $days = null,
    ) {
    }

    /** @return array<string, string|int> filtres non vides, pour l'adresse de la page et le journal de l'export */
    public function toArray(): array
    {
        return array_filter(['q' => $this->search, 'module' => $this->module ?? '', 'admin' => $this->adminId ?? '', 'periode' => $this->days ?? ''], static fn (string|int $v): bool => '' !== $v);
    }
}
