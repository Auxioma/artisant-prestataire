<?php

declare(strict_types=1);

namespace App\Administration\Infrastructure\Settings;

use App\Administration\Domain\Event\SettingChanged;
use App\Shared\Application\Bus\EventHandler;
use Symfony\Contracts\Cache\CacheInterface;

final readonly class InvalidateSettingsCache implements EventHandler
{
    public function __construct(
        private CacheInterface $settingsCache,
        private DbalSettingsProvider $provider,
    ) {
    }

    public function __invoke(SettingChanged $event): void
    {
        $this->settingsCache->delete(DbalSettingsProvider::CACHE_KEY);
        $this->provider->reset();
    }
}
