<?php

declare(strict_types=1);

namespace App\Content\Application\Faq;

use App\Shared\Application\Bus\Query;

/**
 * P14 — lectures de l'aide : rubriques (`topics`), questions d'une rubrique, une question, recherche plein texte,
 * questions d'un public (FAQ ciblée P12.5). Réponse : list<FaqTopic>|list<FaqQuestion>|FaqQuestion|null.
 */
final readonly class FaqQuery implements Query
{
    public const string TOPICS = 'topics';
    public const string TOPIC = 'topic';
    public const string QUESTION = 'question';
    public const string SEARCH = 'search';
    public const string AUDIENCE = 'audience';
    public const string POPULAR = 'popular';

    private function __construct(public string $kind, public string $value = '', public string $slug = '', public int $limit = 0)
    {
    }

    public static function topics(): self
    {
        return new self(self::TOPICS);
    }

    public static function topic(string $topicSlug): self
    {
        return new self(self::TOPIC, $topicSlug);
    }

    public static function question(string $topicSlug, string $questionSlug): self
    {
        return new self(self::QUESTION, $topicSlug, $questionSlug);
    }

    public static function search(string $term, int $limit): self
    {
        return new self(self::SEARCH, $term, limit: $limit);
    }

    /** Questions propres à un public (`client` ou `artisan`), dans l'ordre des rubriques. */
    public static function audience(string $audience, int $limit): self
    {
        return new self(self::AUDIENCE, $audience, limit: $limit);
    }

    /** Premières questions de chaque rubrique, dans l'ordre (accueil de l'aide). */
    public static function popular(int $limit): self
    {
        return new self(self::POPULAR, limit: $limit);
    }
}
