<?php

declare(strict_types=1);

namespace App\Quote\UI\Twig;

use App\Quote\Application\Client\AcceptedClientContact;
use App\Quote\Application\Editor\QuoteEditor;
use App\Quote\Application\Editor\QuoteEditorView;
use App\Quote\Domain\Quote\QuoteStatus;
use App\Shared\Application\Bus\QueryBus;
use App\Shared\Application\Security\CurrentUser;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/** PR03 — devis de l'artisan connecté sur une demande : « Voir mon devis » une fois envoyé (PR04.5), même retiré. */
final class ProQuoteExtension extends AbstractExtension
{
    public function __construct(private readonly QueryBus $queries, private readonly CurrentUser $user)
    {
    }

    public function getFunctions(): array
    {
        return [new TwigFunction('tm_pro_sent_quote', $this->sentQuote(...)), new TwigFunction('tm_pro_client_contact', $this->clientContact(...))];
    }

    /**
     * Devis envoyé par l'artisan connecté sur cette demande (numéro, statut RG-121), sinon null (aucun devis ou brouillon).
     *
     * @return array{number: ?string, status: string, label: string}|null
     */
    public function sentQuote(string $requestId): ?array
    {
        $userId = $this->user->id();
        $view = null === $userId ? null : $this->queries->ask(new QuoteEditor($userId, $requestId));

        return $view instanceof QuoteEditorView && QuoteStatus::Draft !== $view->status
            ? ['number' => $view->number, 'status' => $view->status->value, 'label' => $view->status->label()]
            : null;
    }

    /**
     * RG-022bis — coordonnées du client révélées à l'artisan retenu (devis accepté), sinon null.
     *
     * @return array<string, string|null>|null clés name, email, phone, address
     */
    public function clientContact(string $requestId): ?array
    {
        $userId = $this->user->id();
        $contact = null === $userId ? null : $this->queries->ask(new AcceptedClientContact($userId, $requestId));
        if (!\is_array($contact)) {
            return null;
        }
        $values = [];
        foreach (['name', 'email', 'phone', 'address'] as $key) {
            $values[$key] = \is_string($contact[$key] ?? null) ? $contact[$key] : null;
        }

        return $values;
    }
}
