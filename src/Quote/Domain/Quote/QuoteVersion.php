<?php

declare(strict_types=1);

namespace App\Quote\Domain\Quote;

/** Version d'un devis : v1 à l'envoi, v2, v3… à chaque révision (RG-102) ; figée une fois envoyée. */
final readonly class QuoteVersion
{
    public function __construct(public int $number, public QuoteContent $content, public \DateTimeImmutable $createdAt, public ?\DateTimeImmutable $sentAt = null)
    {
    }

    public function sent(\DateTimeImmutable $at): self
    {
        return new self($this->number, $this->content, $this->createdAt, $at);
    }
}
