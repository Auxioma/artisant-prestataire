<?php

declare(strict_types=1);

namespace App\Search\UI\Twig;

use App\Shared\Domain\Exception\InvalidValue;
use App\Shared\Domain\ValueObject\Slug;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/** URL canonique d'une fiche artisan P10 : `/pro/{nom}-{commune}-{id}` (CDC §7 : minuscules, tirets, sans accents). */
final class ArtisanUrlExtension extends AbstractExtension
{
    private const string FALLBACK = 'artisan';

    public function __construct(private readonly UrlGeneratorInterface $urls)
    {
    }

    public function getFunctions(): array
    {
        return [new TwigFunction('tm_artisan_url', $this->url(...))];
    }

    public function url(string $profileId, string $name, ?string $communeName = null, bool $absolute = false): string
    {
        return $this->urls->generate('public_artisan_profile', ['slug' => self::slug($name, $communeName), 'id' => $profileId], $absolute ? UrlGeneratorInterface::ABSOLUTE_URL : UrlGeneratorInterface::ABSOLUTE_PATH);
    }

    public static function slug(string $name, ?string $communeName): string
    {
        try {
            return Slug::fromText(trim($name.' '.($communeName ?? '')))->value;
        } catch (InvalidValue) {
            return self::FALLBACK;
        }
    }
}
