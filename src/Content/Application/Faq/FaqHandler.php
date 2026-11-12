<?php

declare(strict_types=1);

namespace App\Content\Application\Faq;

use App\Content\Application\Port\FaqStore;
use App\Shared\Application\Bus\QueryHandler;
use App\Shared\Application\Settings\SettingKey;
use App\Shared\Application\Settings\SettingsProvider;

/** P14 — lectures de l'aide, avec les variables de contenu remplacées par les paramètres (RG-014). */
final readonly class FaqHandler implements QueryHandler
{
    private const int TERM_MIN = 2;
    private const int TERM_MAX = 100;

    public function __construct(private FaqStore $store, private SettingsProvider $settings)
    {
    }

    /** @return list<FaqTopic>|list<FaqQuestion>|FaqQuestion|null */
    public function __invoke(FaqQuery $query): array|FaqQuestion|null
    {
        return match ($query->kind) {
            FaqQuery::TOPICS => $this->store->topics(),
            FaqQuery::TOPIC => $this->vars($this->store->questionsOf($query->value)),
            FaqQuery::QUESTION => $this->store->question($query->value, $query->slug)?->withVariables($this->variables()),
            FaqQuery::SEARCH => mb_strlen(trim($query->value)) < self::TERM_MIN ? [] : $this->vars($this->store->search(mb_substr(trim($query->value), 0, self::TERM_MAX), $query->limit)),
            FaqQuery::AUDIENCE => $this->vars($this->store->forAudience($query->value, $query->limit)),
            default => $this->vars($this->store->popular($query->limit)),
        };
    }

    /**
     * @param list<FaqQuestion> $questions
     *
     * @return list<FaqQuestion>
     */
    private function vars(array $questions): array
    {
        $variables = $this->variables();

        return array_map(static fn (FaqQuestion $q): FaqQuestion => $q->withVariables($variables), $questions);
    }

    /** @return array<string, string> */
    private function variables(): array
    {
        return [
            '{max_devis}' => (string) $this->settings->int(SettingKey::QuotesMaxPerRequest),
            '{free_credits}' => (string) $this->settings->int(SettingKey::CreditsFreeOnSignup),
            '{reply_hours}' => (string) $this->settings->int(SettingKey::ContactReplyHours),
        ];
    }
}
