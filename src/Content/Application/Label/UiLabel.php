<?php

declare(strict_types=1);

namespace App\Content\Application\Label;

final readonly class UiLabel
{
    public function __construct(public string $key, public string $locale, public string $defaultText, public ?string $text, public \DateTimeImmutable $updatedAt)
    {
    }

    public function value(): string
    {
        return $this->text ?? $this->defaultText;
    }

    /** Rubrique : premier segment de la clé (« header », « footer »…). */
    public function group(): string
    {
        return explode('.', $this->key, 2)[0];
    }
}
