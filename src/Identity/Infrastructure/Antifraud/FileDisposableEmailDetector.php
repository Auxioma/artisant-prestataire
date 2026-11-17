<?php

declare(strict_types=1);

namespace App\Identity\Infrastructure\Antifraud;

use App\Identity\Application\Port\DisposableEmailDetector;
use App\Shared\Domain\ValueObject\Email;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/** Liste versionnée dans le dépôt (config/identity/disposable-email-domains.txt) ; sous-domaines compris. */
final class FileDisposableEmailDetector implements DisposableEmailDetector
{
    /** @var array<string, true>|null */
    private ?array $domains = null;

    public function __construct(
        #[Autowire('%kernel.project_dir%/config/identity/disposable-email-domains.txt')]
        private readonly string $listPath,
    ) {
    }

    public function isDisposable(Email $email): bool
    {
        $domains = $this->domains ??= $this->load();
        $labels = explode('.', strtolower($email->domain()));
        for ($i = 0, $n = \count($labels); $i < $n - 1; ++$i) {
            if (isset($domains[implode('.', \array_slice($labels, $i))])) {
                return true;
            }
        }

        return false;
    }

    /** @return array<string, true> */
    private function load(): array
    {
        $lines = file($this->listPath, \FILE_IGNORE_NEW_LINES | \FILE_SKIP_EMPTY_LINES);
        if (false === $lines) {
            throw new \RuntimeException('Liste des domaines jetables illisible.');
        }
        $domains = [];
        foreach ($lines as $line) {
            $line = trim($line);
            if ('' !== $line && !str_starts_with($line, '#')) {
                $domains[strtolower($line)] = true;
            }
        }

        return $domains;
    }
}
