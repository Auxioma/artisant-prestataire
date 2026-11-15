<?php

declare(strict_types=1);

namespace App\Content\Application\Faq\Admin;

use App\Shared\Application\Bus\Command;

/** BO10.4 — action sur l'aide : topic_save, topic_up, topic_down, topic_delete, question_save, question_up, question_down, question_delete. */
final readonly class FaqAdminCommand implements Command
{
    public const array ACTIONS = ['topic_save', 'topic_up', 'topic_down', 'topic_delete', 'question_save', 'question_up', 'question_down', 'question_delete'];

    /** @param array<string, mixed> $data */
    public function __construct(public string $action, public string $id, public ?string $adminId, public array $data = [], public ?string $topicId = null)
    {
    }
}
