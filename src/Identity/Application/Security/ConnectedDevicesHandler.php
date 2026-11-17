<?php

declare(strict_types=1);

namespace App\Identity\Application\Security;

use App\Identity\Application\Port\KnownDevices;
use App\Shared\Application\Bus\QueryHandler;

final readonly class ConnectedDevicesHandler implements QueryHandler
{
    public function __construct(private KnownDevices $devices)
    {
    }

    /** @return list<ConnectedDevice> */
    public function __invoke(ConnectedDevices $query): array
    {
        $list = array_map(static fn (array $d): ConnectedDevice => new ConnectedDevice($d['id'], $d['description'], $d['firstSeen'], $d['lastSeen'], null !== $query->currentDeviceHash && hash_equals($d['hash'], $query->currentDeviceHash)), $this->devices->active($query->userId));
        usort($list, static fn (ConnectedDevice $a, ConnectedDevice $b): int => $b->current <=> $a->current);

        return $list;
    }
}
