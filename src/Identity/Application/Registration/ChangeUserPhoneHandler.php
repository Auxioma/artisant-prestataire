<?php

declare(strict_types=1);

namespace App\Identity\Application\Registration;

use App\Identity\Domain\User\UserId;
use App\Identity\Domain\User\UserRepository;
use App\Shared\Application\Bus\CommandHandler;
use App\Shared\Application\Bus\EventBus;
use App\Shared\Application\Identity\ChangeUserPhone;
use App\Shared\Domain\Time\Clock;
use App\Shared\Domain\ValueObject\PhoneNumber;

final readonly class ChangeUserPhoneHandler implements CommandHandler
{
    public function __construct(private UserRepository $users, private EventBus $events, private Clock $clock)
    {
    }

    public function __invoke(ChangeUserPhone $command): void
    {
        $user = $this->users->get(new UserId($command->userId));
        $user->changePhone(new PhoneNumber($command->phone), $this->clock->now());
        $this->events->publish(...$user->releaseEvents());
    }
}
