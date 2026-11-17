<?php

declare(strict_types=1);

namespace App\Notification\Infrastructure\Template;

use App\Shared\Domain\Time\Clock;
use App\Shared\Infrastructure\Persistence\DbalValue;
use Doctrine\DBAL\Connection;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Yaml\Yaml;

/**
 * Charge le catalogue de l'annexe F (config/notification/catalog.yaml) et les contenus par défaut
 * (config/notification/defaults). Un modèle modifié en back-office (`customized`) n'est jamais écrasé ; un modèle
 * resté par défaut suit les évolutions du dépôt (nouvelle version enregistrée dans l'historique).
 */
final readonly class TemplateCatalogSync
{
    private const array PARTS = ['subject', 'preheader', 'html' => 'body_html', 'txt' => 'body_text', 'in_app' => 'in_app_body'];

    public function __construct(
        private Connection $connection,
        private Clock $clock,
        #[Autowire('%kernel.project_dir%/config/notification')]
        private string $directory,
    ) {
    }

    /** @return array{created: int, updated: int, customized: int} */
    public function sync(): array
    {
        $catalog = Yaml::parseFile($this->directory.'/catalog.yaml');
        $entries = \is_array($catalog) && \is_array($catalog['notifications'] ?? null) ? $catalog['notifications'] : [];
        $counts = ['created' => 0, 'updated' => 0, 'customized' => 0];
        $now = $this->clock->now()->format(\DATE_ATOM);

        $this->connection->transactional(function () use ($entries, &$counts, $now): void {
            foreach ($entries as $code => $entry) {
                if (!\is_string($code) || !\is_array($entry)) {
                    continue;
                }
                $content = $this->defaults($code);
                $channels = array_values(array_filter(\is_array($entry['channels'] ?? null) ? $entry['channels'] : [], \is_string(...)));
                $variables = array_values(array_filter(\is_array($entry['variables'] ?? null) ? $entry['variables'] : [], \is_string(...)));
                $meta = [
                    'label' => \is_string($entry['label'] ?? null) ? $entry['label'] : $code,
                    'recipient' => \is_string($entry['recipient'] ?? null) ? $entry['recipient'] : '',
                    'channels' => '{'.implode(',', $channels).'}',
                    'variables' => '{'.implode(',', $variables).'}',
                ];
                $existing = $this->connection->fetchAssociative('SELECT * FROM notification.template WHERE code = :c', ['c' => $code]);

                if (false === $existing) {
                    $this->connection->insert('notification.template', $meta + $content + [
                        'code' => $code,
                        'active' => $this->complete($channels, $content) ? 'true' : 'false',
                        'version' => [] === array_filter($content) ? 0 : 1,
                        'customized' => 'false',
                        'updated_at' => $now,
                    ]);
                    if ([] !== array_filter($content)) {
                        $this->version($code, 1, $content, $now);
                    }
                    ++$counts['created'];
                    continue;
                }

                $this->connection->update('notification.template', $meta, ['code' => $code]);
                if (true === $existing['customized']) {
                    ++$counts['customized'];
                    continue;
                }
                $changed = array_filter($content, static fn (?string $v, string $k): bool => $v !== $existing[$k], \ARRAY_FILTER_USE_BOTH);
                if ([] !== $changed) {
                    $version = DbalValue::int($existing['version']) + 1;
                    $this->connection->update('notification.template', $content + [
                        'version' => $version,
                        'active' => $this->complete($channels, $content) ? 'true' : 'false',
                        'updated_at' => $now,
                    ], ['code' => $code]);
                    $this->version($code, $version, $content, $now);
                    ++$counts['updated'];
                }
            }
        });

        return $counts;
    }

    /** @return array<string, ?string> colonnes de contenu (null si aucun fichier par défaut) */
    private function defaults(string $code): array
    {
        $content = [];
        foreach (self::PARTS as $suffix => $column) {
            $suffix = \is_int($suffix) ? $column : $suffix;
            $file = \sprintf('%s/defaults/%s.%s.twig', $this->directory, $code, $suffix);
            $content[$column] = is_file($file) ? rtrim((string) file_get_contents($file))."\n" : null;
        }
        foreach (['subject', 'preheader'] as $column) {
            $content[$column] = null === $content[$column] ? null : trim($content[$column]);
        }

        return $content;
    }

    /**
     * Actif par défaut seulement si chaque canal disponible a son contenu (SMS : lot V2, ignoré).
     *
     * @param list<string>           $channels
     * @param array<string, ?string> $content
     */
    private function complete(array $channels, array $content): bool
    {
        $email = !\in_array('email', $channels, true) || (null !== $content['subject'] && null !== $content['body_html'] && null !== $content['body_text']);
        $inApp = !\in_array('in_app', $channels, true) || null !== $content['in_app_body'];
        $hasContent = null !== $content['body_html'] || null !== $content['in_app_body'];

        return $hasContent && $email && $inApp;
    }

    /** @param array<string, ?string> $content */
    private function version(string $code, int $version, array $content, string $now): void
    {
        $this->connection->insert('notification.template_version', $content + ['code' => $code, 'version' => $version, 'created_at' => $now]);
    }
}
