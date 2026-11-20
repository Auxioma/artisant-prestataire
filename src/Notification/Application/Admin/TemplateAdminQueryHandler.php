<?php

declare(strict_types=1);

namespace App\Notification\Application\Admin;

use App\Notification\Application\Port\TemplateRenderer;
use App\Notification\Application\Template;
use App\Shared\Application\Bus\QueryHandler;
use App\Shared\Domain\Time\Clock;

final readonly class TemplateAdminQueryHandler implements QueryHandler
{
    public function __construct(private TemplateAdminStore $templates, private TemplateRenderer $renderer, private DeliveryLogReader $log, private Clock $clock)
    {
    }

    /** @return list<TemplateDetail>|array<string, mixed>|DeliveryLogPage|null */
    public function __invoke(TemplateAdminQuery|PreviewTemplate|DeliveryLogQuery $query): array|DeliveryLogPage|null
    {
        if ($query instanceof DeliveryLogQuery) {
            return $this->log->page($query);
        }
        if ($query instanceof PreviewTemplate) {
            return $this->preview($query);
        }
        if (null === $query->code) {
            return $this->templates->all();
        }
        $template = $this->templates->find($query->code);

        return null === $template ? null : ['template' => $template, 'versions' => $this->templates->versions($template->code)];
    }

    /** @return array{subject: ?string, html: ?string, text: ?string, in_app: ?string, error: ?string} */
    private function preview(PreviewTemplate $query): array
    {
        $empty = ['subject' => null, 'html' => null, 'text' => null, 'in_app' => null, 'error' => null];
        $t = $this->templates->find($query->code);
        if (null === $t) {
            return ['error' => 'Modèle inconnu.'] + $empty;
        }
        $value = static fn (string $name, ?string $current): ?string => null === $query->content ? $current : (\is_string($query->content[$name] ?? null) && '' !== trim($query->content[$name]) ? $query->content[$name] : null);
        $template = new Template($t->code, $t->channels, true, $t->version, $value('subject', $t->subject), $value('preheader', $t->preheader), $value('body_html', $t->bodyHtml), $value('body_text', $t->bodyText), $value('in_app_body', $t->inAppBody));
        $variables = SampleVariables::for($t->variables, $this->clock->now());
        $result = $empty;
        try {
            if (null !== $template->subject && null !== $template->bodyHtml && null !== $template->bodyText) {
                $email = $this->renderer->renderEmail($template, $variables);
                $result = ['subject' => $email->subject, 'html' => $email->html, 'text' => $email->text] + $result;
            }
            if (null !== $template->inAppBody) {
                $result['in_app'] = $this->renderer->renderInApp($template, $variables);
            }
        } catch (\RuntimeException $e) {
            $result['error'] = $e->getMessage();
        }

        return $result;
    }
}
