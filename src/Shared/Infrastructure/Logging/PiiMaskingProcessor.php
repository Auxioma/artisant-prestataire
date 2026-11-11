<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Logging;

use Monolog\Attribute\AsMonologProcessor;
use Monolog\LogRecord;
use Monolog\Processor\ProcessorInterface;

/**
 * Supprime les données personnelles et secrets des journaux (cadrage §5.8, ASVS 7.1.1) :
 * emails, téléphones, IBAN, jetons (Bearer/JWT/Stripe), et toute clé de contexte sensible.
 */
#[AsMonologProcessor]
final class PiiMaskingProcessor implements ProcessorInterface
{
    private const string REDACTED = '[redacted]';
    private const int MAX_DEPTH = 8;

    private const array SENSITIVE_KEY_PATTERNS = [
        'password', 'passwd', 'secret', 'token', 'authorization', 'cookie', 'api_key', 'apikey', 'iban', 'card', 'cvc', 'otp', 'totp', 'csrf', 'signature',
    ];

    private const string EMAIL_PATTERN = '/[A-Za-z0-9._%+\-]+@[A-Za-z0-9.\-]+\.[A-Za-z]{2,}/';

    /** @var array<string, string> motif => remplacement */
    private readonly array $patterns;

    public function __construct()
    {
        $this->patterns = [
            '/\b(?:Bearer|Basic)\s+[A-Za-z0-9\-._~+\/]+=*/i' => self::REDACTED,
            '/\beyJ[A-Za-z0-9_-]+\.[A-Za-z0-9_-]+\.[A-Za-z0-9_-]+\b/' => self::REDACTED,
            '/\b(?:sk|rk|pk|whsec)_(?:live|test)?_?[A-Za-z0-9]{8,}\b/' => self::REDACTED,
            '/\b[A-Z]{2}\d{2}(?:\s?[A-Z0-9]{4}){2,7}(?:\s?[A-Z0-9]{1,4})?\b/' => '[iban]',
            '/(?<![\w])(?:\+33\s?|0033\s?|0)[1-9](?:[\s.\-]?\d{2}){4}(?![\w])/' => '[phone]',
            '/(?<![\w])\+[1-9]\d{7,14}(?![\w])/' => '[phone]',
        ];
    }

    public function __invoke(LogRecord $record): LogRecord
    {
        return $record->with(
            message: $this->maskString($record->message),
            context: $this->maskArray($record->context, 0),
            extra: $this->maskArray($record->extra, 0),
        );
    }

    /**
     * @param array<mixed> $data
     *
     * @return array<mixed>
     */
    private function maskArray(array $data, int $depth): array
    {
        if ($depth > self::MAX_DEPTH) {
            return ['[truncated]'];
        }
        foreach ($data as $key => $value) {
            if (\is_string($key) && $this->isSensitiveKey($key)) {
                $data[$key] = self::REDACTED;
            } elseif (\is_string($value)) {
                $data[$key] = $this->maskString($value);
            } elseif (\is_array($value)) {
                $data[$key] = $this->maskArray($value, $depth + 1);
            } elseif ($value instanceof \Stringable && !$value instanceof \Throwable) {
                $data[$key] = $this->maskString((string) $value);
            }
        }

        return $data;
    }

    private function maskString(string $value): string
    {
        foreach ($this->patterns as $pattern => $replacement) {
            $value = preg_replace($pattern, $replacement, $value) ?? self::REDACTED;
        }

        return preg_replace_callback(self::EMAIL_PATTERN, static fn (array $match): string => self::maskEmail($match[0]), $value) ?? self::REDACTED;
    }

    private function isSensitiveKey(string $key): bool
    {
        $normalized = strtolower($key);
        foreach (self::SENSITIVE_KEY_PATTERNS as $needle) {
            if (str_contains($normalized, $needle)) {
                return true;
            }
        }

        return false;
    }

    private static function maskEmail(string $email): string
    {
        [$local, $domain] = explode('@', $email, 2);

        return mb_substr($local, 0, 1).'***@'.mb_substr($domain, 0, 1).'***';
    }
}
