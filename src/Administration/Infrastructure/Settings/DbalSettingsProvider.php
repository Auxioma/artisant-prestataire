<?php

declare(strict_types=1);

namespace App\Administration\Infrastructure\Settings;

use App\Shared\Application\Settings\InvalidSettingValue;
use App\Shared\Application\Settings\SettingKey;
use App\Shared\Application\Settings\TypedSettingsProvider;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;

/**
 * Lit admin.setting (mis en cache, invalidé sur SettingChanged), avec repli sur la valeur par défaut de la définition.
 * Une valeur stockée devenue invalide (bornes modifiées) est ignorée et signalée, jamais propagée au domaine.
 */
final class DbalSettingsProvider extends TypedSettingsProvider
{
    public const string CACHE_KEY = 'admin.settings.all';
    private const int CACHE_TTL_SECONDS = 300;

    /** @var array<string, mixed>|null */
    private ?array $memo = null;

    public function __construct(
        private readonly DbalSettingStore $store,
        private readonly CacheInterface $settingsCache,
        private readonly LoggerInterface $logger,
    ) {
    }

    protected function value(SettingKey $key): int|bool|string|float|array|null
    {
        $definition = $key->definition();
        $stored = $this->load();
        if (!\array_key_exists($key->value, $stored)) {
            return $definition->normalize($key, $definition->default);
        }

        try {
            return $definition->normalize($key, $stored[$key->value]);
        } catch (InvalidSettingValue $e) {
            $this->logger->error('Paramètre stocké invalide, valeur par défaut utilisée.', ['key' => $key->value, 'error' => $e->getMessage()]);

            return $definition->normalize($key, $definition->default);
        }
    }

    public function reset(): void
    {
        $this->memo = null;
    }

    /** @return array<string, mixed> */
    private function load(): array
    {
        return $this->memo ??= $this->settingsCache->get(self::CACHE_KEY, function (ItemInterface $item): array {
            $item->expiresAfter(self::CACHE_TTL_SECONDS);

            return $this->store->all();
        });
    }
}
