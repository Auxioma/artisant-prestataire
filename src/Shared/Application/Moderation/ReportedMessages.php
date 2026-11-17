<?php

declare(strict_types=1);

namespace App\Shared\Application\Moderation;

use App\Shared\Application\Bus\Query;

/** BO05 « Messages signalés » — file des messages signalés non encore traités. Traitée par Messaging ; réponse : list<ReportedMessageItem>. */
final readonly class ReportedMessages implements Query
{
}
