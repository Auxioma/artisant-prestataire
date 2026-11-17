<?php

declare(strict_types=1);

namespace App\Administration\Domain\Admin;

/**
 * Rôles prédéfinis (cadrage §4, BO14.2, ADR-0013) : chacun accorde des permissions `admin.<module>.<action>` ; les
 * Voters vérifient des permissions, jamais des rôles. Rôles personnalisés : P-06 (`features.custom_admin_roles`).
 * Seul le super-admin gère l'équipe et les paramètres.
 */
enum AdminRole: string
{
    case SuperAdmin = 'super_admin';
    case Moderator = 'moderator';
    case Support = 'support';
    case Content = 'content';
    case Finance = 'finance';
    case ReadOnly = 'read_only';

    public function label(): string
    {
        return match ($this) {
            self::SuperAdmin => 'Super-admin',
            self::Moderator => 'Modérateur',
            self::Support => 'Support',
            self::Content => 'Contenu / SEO',
            self::Finance => 'Finance',
            self::ReadOnly => 'Lecture seule',
        };
    }

    /** Rôle Symfony correspondant (cadrage §4). */
    public function securityRole(): string
    {
        return 'ROLE_'.strtoupper($this->value);
    }

    /** @return list<string> */
    public function permissions(): array
    {
        if (self::SuperAdmin === $this) {
            $all = [];
            foreach (AdminModule::cases() as $module) {
                foreach (AdminAction::cases() as $action) {
                    $all[] = $module->permission($action);
                }
            }

            return $all;
        }
        $grants = match ($this) {
            self::Moderator => ['dashboard' => 'v', 'clients' => 'v', 'artisans' => 've', 'requests' => 've', 'moderation' => 'vced', 'support' => 'v'],
            self::Support => ['dashboard' => 'v', 'clients' => 've', 'artisans' => 'v', 'requests' => 've', 'support' => 'vce', 'plans' => 've'],
            self::Content => ['dashboard' => 'v', 'catalog' => 'vced', 'geo' => 've', 'cms' => 'vced', 'seo' => 'vced', 'notifications' => 've'],
            self::Finance => ['dashboard' => 'v', 'plans' => 'vcex', 'offers' => 'vce', 'logs' => 'vx'],
            self::ReadOnly => array_fill_keys(array_map(static fn (AdminModule $m): string => $m->value, array_filter(AdminModule::cases(), static fn (AdminModule $m): bool => !\in_array($m, [AdminModule::Team, AdminModule::Settings, AdminModule::Logs], true))), 'v'),
        };
        $letters = ['v' => AdminAction::View, 'c' => AdminAction::Create, 'e' => AdminAction::Edit, 'd' => AdminAction::Delete, 'x' => AdminAction::Export];
        $permissions = [];
        foreach ($grants as $module => $actions) {
            foreach (str_split($actions) as $letter) {
                $permissions[] = AdminModule::from($module)->permission($letters[$letter]);
            }
        }

        return $permissions;
    }

    public function allows(string $permission): bool
    {
        return \in_array($permission, $this->permissions(), true);
    }
}
