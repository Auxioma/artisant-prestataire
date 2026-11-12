<?php

declare(strict_types=1);

namespace App\Content\Application\Seo;

use App\Content\Application\Port\NotFoundLog;
use App\Content\Application\Port\RedirectStore;
use App\Content\Application\Port\SeoMeta;
use App\Shared\Application\Audit\AuditTrail;
use App\Shared\Application\Bus\CommandHandler;
use App\Shared\Domain\Audit\AuditRecord;
use App\Shared\Domain\Identifier\IdGenerator;
use App\Shared\Domain\Time\Clock;

/** BO12.1 / BO12.2 — gabarits, surcharges, redirections et rapport des 404 ; chaque action est journalisée. */
final readonly class SeoAdminHandler implements CommandHandler
{
    private const string PATH = '~^/(?!/)[^\s?#]{0,499}$~';
    private const array STATUSES = [301, 302];
    private const int DEFAULT_STATUS = 301;
    private const int ECHO_MAX = 80;
    private const int CSV_MAX_LINES = 5000;
    private const string ORIGIN = 'bo12';

    public function __construct(
        private SeoMeta $meta,
        private RedirectStore $redirects,
        private NotFoundLog $notFound,
        private AuditTrail $audit,
        private IdGenerator $ids,
        private Clock $clock,
    ) {
    }

    public function __invoke(SaveSeoTemplate|SaveSeoOverride|DeleteSeoOverride|SaveRedirect|DeleteRedirect|ImportRedirects|DismissNotFound $command): void
    {
        $now = $this->clock->now();
        switch (true) {
            case $command instanceof SaveSeoTemplate:
                if (!\array_key_exists($command->pageType, SeoPageTypes::TYPES)) {
                    throw new SeoRuleViolation('Type de page inconnu.');
                }
                $this->meta->saveTemplate($command->pageType, self::text($command->title, SeoPageTypes::TITLE_MAX, 'Title'), self::text($command->description, SeoPageTypes::DESCRIPTION_MAX, 'Description'), $command->adminId, $now);
                $action = 'seo.template_saved';
                $context = ['page_type' => $command->pageType];
                break;
            case $command instanceof SaveSeoOverride:
                $path = self::path($command->path);
                $this->meta->saveOverride($path, self::text($command->title, SeoPageTypes::TITLE_MAX, 'Title'), self::text($command->description, SeoPageTypes::DESCRIPTION_MAX, 'Description'), $command->noindex, $command->adminId, $now);
                $action = 'seo.override_saved';
                $context = ['path' => $path, 'noindex' => $command->noindex];
                break;
            case $command instanceof DeleteSeoOverride:
                $this->meta->deleteOverride($command->path);
                $action = 'seo.override_deleted';
                $context = ['path' => $command->path];
                break;
            case $command instanceof SaveRedirect:
                $this->redirect($command->source, $command->target, $command->status, $command->prefix);
                $this->notFound->dismiss(self::path($command->source));
                $action = 'seo.redirect_saved';
                $context = ['source' => $command->source, 'target' => $command->target, 'status' => $command->status];
                break;
            case $command instanceof DeleteRedirect:
                $this->redirects->delete($command->id);
                $action = 'seo.redirect_deleted';
                $context = ['id' => $command->id];
                break;
            case $command instanceof ImportRedirects:
                $context = ['imported' => $this->import($command->csv)];
                $action = 'seo.redirects_imported';
                break;
            default:
                $this->notFound->dismiss($command->path);
                $action = 'seo.not_found_dismissed';
                $context = ['path' => $command->path];
        }
        $this->audit->record(new AuditRecord(
            id: $this->ids->next(),
            occurredAt: $now,
            actorType: 'admin',
            actorId: $command->adminId,
            action: $action,
            subjectType: 'seo',
            subjectId: null,
            context: $context,
        ));
    }

    private function redirect(string $source, string $target, int $status, bool $prefix): void
    {
        $source = self::path($source);
        $target = self::path($target);
        if ($source === $target) {
            throw new SeoRuleViolation('La source et la cible doivent être différentes.');
        }
        if (!\in_array($status, self::STATUSES, true)) {
            throw new SeoRuleViolation('Code de redirection : 301 ou 302.');
        }
        $this->redirects->redirect($source, $target, $prefix, self::ORIGIN, $status);
    }

    /** Toutes les lignes sont contrôlées avant d'enregistrer : un fichier invalide n'est pas importé à moitié. */
    private function import(string $csv): int
    {
        $rows = [];
        foreach (preg_split('/\R/', trim($csv)) ?: [] as $n => $line) {
            if ('' === trim($line) || (0 === $n && 1 === preg_match('/^\W*source/i', $line))) {
                continue;
            }
            $cells = array_map(static fn (?string $cell): string => trim((string) $cell), str_getcsv($line, str_contains($line, ';') ? ';' : ',', '"', ''));
            $status = isset($cells[2]) && '' !== $cells[2] ? (int) $cells[2] : self::DEFAULT_STATUS;
            try {
                $source = self::path($cells[0] ?? '');
                $target = self::path($cells[1] ?? '');
            } catch (SeoRuleViolation $e) {
                throw new SeoRuleViolation(\sprintf('Ligne %d : %s', $n + 1, $e->getMessage()));
            }
            if ($source === $target || !\in_array($status, self::STATUSES, true)) {
                throw new SeoRuleViolation(\sprintf('Ligne %d : source et cible identiques ou code différent de 301 / 302.', $n + 1));
            }
            $rows[] = [$source, $target, $status];
        }
        if ([] === $rows || \count($rows) > self::CSV_MAX_LINES) {
            throw new SeoRuleViolation(\sprintf('Le fichier doit contenir entre 1 et %d redirections.', self::CSV_MAX_LINES));
        }
        foreach ($rows as [$source, $target, $status]) {
            $this->redirects->redirect($source, $target, false, self::ORIGIN, $status);
            $this->notFound->dismiss($source);
        }

        return \count($rows);
    }

    /** Chemin interne uniquement (aucune redirection ouverte) : « /… », sans espace, paramètre ni ancre. */
    private static function path(string $path): string
    {
        $path = trim($path);
        if (1 !== preg_match(self::PATH, $path)) {
            throw new SeoRuleViolation(\sprintf('« %s » : chemin interne attendu, commençant par « / » (sans domaine ni paramètres).', mb_substr($path, 0, self::ECHO_MAX)));
        }

        return $path;
    }

    private static function text(?string $value, int $max, string $label): ?string
    {
        $value = null === $value ? '' : trim($value);
        if (mb_strlen($value) > $max) {
            throw new SeoRuleViolation(\sprintf('%s : %d caractères au maximum.', $label, $max));
        }

        return '' === $value ? null : $value;
    }
}
