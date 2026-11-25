<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Security;

use App\Shared\Application\Security\ImpersonationTicket;
use App\Shared\Application\Security\ImpersonationTickets;
use Psr\Cache\CacheItemPoolInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/** Tickets conservés une minute en JSON, sous l'empreinte du jeton (le jeton lui-même n'est jamais stocké). */
final readonly class CacheImpersonationTickets implements ImpersonationTickets
{
    public function __construct(#[Autowire(service: 'cache.impersonation')] private CacheItemPoolInterface $cache)
    {
    }

    public function issue(ImpersonationTicket $ticket): string
    {
        $token = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
        $item = $this->cache->getItem(self::key($token));
        $item->set(json_encode(['admin_id' => $ticket->adminId, 'admin_name' => $ticket->adminName, 'user_id' => $ticket->userId, 'reason' => $ticket->reason, 'return_url' => $ticket->returnUrl, 'issued_at' => $ticket->issuedAt->format(\DATE_ATOM)], \JSON_THROW_ON_ERROR));
        $item->expiresAfter(self::TTL_SECONDS);
        $this->cache->save($item);

        return $token;
    }

    public function consume(string $token): ?ImpersonationTicket
    {
        if (1 !== preg_match('/^[A-Za-z0-9_-]{43}$/', $token)) {
            return null;
        }
        $key = self::key($token);
        $item = $this->cache->getItem($key);
        $this->cache->deleteItem($key);
        $data = $item->isHit() && \is_string($item->get()) ? json_decode($item->get(), true) : null;
        if (!\is_array($data)) {
            return null;
        }
        $field = static fn (string $name): string => \is_string($data[$name] ?? null) ? $data[$name] : '';

        return new ImpersonationTicket($field('admin_id'), $field('admin_name'), $field('user_id'), $field('reason'), $field('return_url'), new \DateTimeImmutable($field('issued_at')));
    }

    private static function key(string $token): string
    {
        return 'ticket_'.hash('sha256', $token);
    }
}
