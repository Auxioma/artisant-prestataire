<?php

declare(strict_types=1);

namespace App\Shared\UI\Twig;

use App\Shared\Application\Settings\SettingKey;
use App\Shared\Application\Settings\SettingsProvider;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/** BO12.4 — activation des données structurées par type : `{% if tm_schema('faq') %}…{% endif %}`. */
final class SeoExtension extends AbstractExtension
{
    private const array TYPES = [
        'organization' => SettingKey::SeoSchemaOrganization,
        'local_business' => SettingKey::SeoSchemaLocalBusiness,
        'faq' => SettingKey::SeoSchemaFaq,
        'breadcrumb' => SettingKey::SeoSchemaBreadcrumb,
        'article' => SettingKey::SeoSchemaArticle,
    ];

    public function __construct(private readonly SettingsProvider $settings)
    {
    }

    public function getFunctions(): array
    {
        return [new TwigFunction('tm_schema', $this->enabled(...))];
    }

    public function enabled(string $type): bool
    {
        $key = self::TYPES[$type] ?? null;

        return null !== $key && $this->settings->bool($key);
    }
}
