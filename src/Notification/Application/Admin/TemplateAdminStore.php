<?php

declare(strict_types=1);

namespace App\Notification\Application\Admin;

/** BO11.1 — lecture et écriture versionnée des modèles. */
interface TemplateAdminStore
{
    /** @return list<TemplateDetail> par code */
    public function all(): array;

    public function find(string $code): ?TemplateDetail;

    /** @return list<TemplateVersion> de la plus récente à la plus ancienne */
    public function versions(string $code): array;

    /** @return array{subject: ?string, preheader: ?string, body_html: ?string, body_text: ?string, in_app_body: ?string}|null */
    public function versionContent(string $code, int $version): ?array;

    /**
     * Nouvelle version (marquée « personnalisée » : la synchronisation du catalogue ne l'écrase plus).
     *
     * @param array{subject: ?string, preheader: ?string, body_html: ?string, body_text: ?string, in_app_body: ?string} $content
     */
    public function save(string $code, array $content, bool $active, ?string $adminId, \DateTimeImmutable $at): int;
}
