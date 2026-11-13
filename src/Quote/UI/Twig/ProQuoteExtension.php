<?php

declare(strict_types=1);

namespace App\Quote\UI\Twig;

use App\Quote\Application\Editor\QuoteEditor;
use App\Quote\Application\Editor\QuoteEditorView;
use App\Quote\Domain\Quote\QuoteStatus;
use App\Shared\Application\Bus\QueryBus;
use App\Shared\Application\Security\CurrentUser;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/** PR03 — devis de l'artisan connecté sur une demande : « Voir mon devis » une fois envoyé (PR04.5). */
final class ProQuoteExtension extends AbstractExtension
{
    public function __construct(private readonly QueryBus $queries, private readonly CurrentUser $user)
    {
    }

    public function getFunctions(): array
    {
        return [new TwigFunction('tm_pro_sent_quote', $this->sentQuote(...))];
    }

    /** Numéro du devis envoyé par l'artisan connecté sur cette demande, sinon null (aucun devis ou brouillon). */
    public function sentQuote(string $requestId): ?string
    {
        $userId = $this->user->id();
        $view = null === $userId ? null : $this->queries->ask(new QuoteEditor($userId, $requestId));

        return $view instanceof QuoteEditorView && QuoteStatus::Draft !== $view->status ? $view->number : null;
    }
}
