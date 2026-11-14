<?php

declare(strict_types=1);

namespace App\Billing\Application\Webhook;

/** Événement Stripe authentifié (signature vérifiée) : identifiant, type et objet concerné (`data.object`). */
final readonly class StripeEvent
{
    /** @param array<string, mixed> $object */
    public function __construct(public string $id, public string $type, public bool $livemode, public array $object)
    {
    }

    public function string(string ...$path): ?string
    {
        $value = $this->object;
        foreach ($path as $key) {
            if (!\is_array($value) || !\array_key_exists($key, $value)) {
                return null;
            }
            $value = $value[$key];
        }

        return \is_string($value) && '' !== $value ? $value : null;
    }

    public function int(string ...$path): ?int
    {
        $value = $this->object;
        foreach ($path as $key) {
            if (!\is_array($value) || !\array_key_exists($key, $value)) {
                return null;
            }
            $value = $value[$key];
        }

        return \is_int($value) ? $value : null;
    }
}
