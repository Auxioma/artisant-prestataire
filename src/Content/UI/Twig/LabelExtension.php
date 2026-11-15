<?php

declare(strict_types=1);

namespace App\Content\UI\Twig;

use App\Content\Application\Label\LabelQuery;
use App\Shared\Application\Bus\QueryBus;
use Symfony\Component\HttpFoundation\RequestStack;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * BO10.6 — `tm_label('rubrique.cle', 'Texte d’origine')` : texte modifié en back-office, sinon le texte d'origine écrit
 * dans le gabarit (relevé par `app:labels:sync`). Une lecture des textes modifiés par requête.
 */
final class LabelExtension extends AbstractExtension
{
    /** @var array<string, string>|null */
    private ?array $overrides = null;
    private ?int $request = null;

    public function __construct(private readonly QueryBus $queries, private readonly RequestStack $requests)
    {
    }

    public function getFunctions(): array
    {
        return [new TwigFunction('tm_label', $this->label(...))];
    }

    public function label(string $key, string $default): string
    {
        $request = $this->requests->getMainRequest();
        $requestId = null === $request ? null : spl_object_id($request);
        if (null === $this->overrides || $requestId !== $this->request) {
            $this->request = $requestId;
            $overrides = $this->queries->ask(new LabelQuery(overridesOnly: true));
            $this->overrides = [];
            foreach (\is_array($overrides) ? $overrides : [] as $k => $text) {
                if (\is_string($k) && \is_string($text)) {
                    $this->overrides[$k] = $text;
                }
            }
        }

        return $this->overrides[$key] ?? $default;
    }
}
