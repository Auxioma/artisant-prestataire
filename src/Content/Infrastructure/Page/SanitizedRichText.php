<?php

declare(strict_types=1);

namespace App\Content\Infrastructure\Page;

use App\Content\Application\Page\RichText;
use Symfony\Component\DependencyInjection\Attribute\Target;
use Symfony\Component\HtmlSanitizer\HtmlSanitizerInterface;

/** Liste blanche des contenus éditoriaux (`app.content_sanitizer`, comme la FAQ et les pages légales). */
final readonly class SanitizedRichText implements RichText
{
    public function __construct(#[Target('app.content_sanitizer')] private HtmlSanitizerInterface $sanitizer)
    {
    }

    public function sanitize(string $html): string
    {
        return trim($this->sanitizer->sanitize($html));
    }
}
