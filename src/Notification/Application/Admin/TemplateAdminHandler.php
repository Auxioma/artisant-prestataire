<?php

declare(strict_types=1);

namespace App\Notification\Application\Admin;

use App\Notification\Application\Port\DeliveryLog;
use App\Notification\Application\Port\EmailSender;
use App\Notification\Application\Port\TemplateRenderer;
use App\Notification\Application\RenderedEmail;
use App\Notification\Application\Template;
use App\Shared\Application\Audit\AuditTrail;
use App\Shared\Application\Bus\CommandHandler;
use App\Shared\Domain\Audit\AuditRecord;
use App\Shared\Domain\Identifier\IdGenerator;
use App\Shared\Domain\Time\Clock;

/**
 * BO11.1 — enregistrement d'un modèle (nouvelle version, validée par un rendu complet dans le bac à sable avec des
 * valeurs d'exemple), restauration d'une version antérieure, envoi de test à l'administrateur (journalisé « sent »
 * comme un envoi réel). Un modèle e-mail actif exige objet, HTML et texte.
 */
final readonly class TemplateAdminHandler implements CommandHandler
{
    private const int SUBJECT_MAX = 200;
    private const int PREHEADER_MAX = 200;
    private const int BODY_MAX = 50_000;
    private const int IN_APP_MAX = 500;
    private const int ERROR_MAX = 500;

    public function __construct(
        private TemplateAdminStore $templates,
        private TemplateRenderer $renderer,
        private EmailSender $emails,
        private DeliveryLog $log,
        private AuditTrail $audit,
        private IdGenerator $ids,
        private Clock $clock,
    ) {
    }

    public function __invoke(TemplateAdminCommand $command): void
    {
        $template = $this->templates->find($command->code) ?? throw new TemplateAdminRejected('Modèle inconnu.');
        $now = $this->clock->now();
        match ($command->action) {
            'save' => $this->save($template, $this->content($command->data), true === ($command->data['active'] ?? false), $command->adminId, $now),
            'restore' => $this->restore($template, $command, $now),
            'test' => $this->test($template, $command, $now),
            default => throw new TemplateAdminRejected('Action inconnue.'),
        };
        $this->audit->record(new AuditRecord(id: $this->ids->next(), occurredAt: $now, actorType: 'admin', actorId: $command->adminId, action: 'notification.template_'.$command->action, subjectType: 'template', subjectId: $template->code));
    }

    /** @param array{subject: ?string, preheader: ?string, body_html: ?string, body_text: ?string, in_app_body: ?string} $content */
    private function save(TemplateDetail $template, array $content, bool $active, ?string $adminId, \DateTimeImmutable $now): void
    {
        if ($active && $template->has('email') && (null === $content['subject'] || null === $content['body_html'] || null === $content['body_text'])) {
            throw new TemplateAdminRejected('Un modèle e-mail actif exige un objet, un corps HTML et une version texte.');
        }
        if ($active && $template->has('in_app') && null === $content['in_app_body']) {
            throw new TemplateAdminRejected('Ce déclencheur affiche aussi une notification sur le site : renseignez son texte.');
        }
        $this->validate($template, $content, $now);
        $this->templates->save($template->code, $content, $active, $adminId, $now);
    }

    private function restore(TemplateDetail $template, TemplateAdminCommand $command, \DateTimeImmutable $now): void
    {
        $version = $command->data['version'] ?? null;
        $content = \is_int($version) ? $this->templates->versionContent($template->code, $version) : null;
        if (null === $content) {
            throw new TemplateAdminRejected('Version introuvable.');
        }
        $this->save($template, $content, $template->active, $command->adminId, $now);
    }

    private function test(TemplateDetail $template, TemplateAdminCommand $command, \DateTimeImmutable $now): void
    {
        $to = \is_string($command->data['to'] ?? null) ? $command->data['to'] : '';
        if (!$template->has('email') || 1 !== preg_match('/^[^@\s]+@[^@\s]+\.[^@\s]+$/', $to)) {
            throw new TemplateAdminRejected('Envoi de test impossible : modèle sans e-mail ou adresse invalide.');
        }
        $rendered = $this->renderer->renderEmail($this->template($template, $this->current($template)), SampleVariables::for($template->variables, $now));
        $id = $this->ids->next();
        try {
            $provider = $this->emails->send($to, new RenderedEmail('[TEST] '.$rendered->subject, $rendered->html, $rendered->text), $template->code, $id);
            $this->log->record($id, $template->code, $template->version, 'email', null, $to, 'sent', $provider, 'envoi de test', $now);
        } catch (\RuntimeException $e) {
            $this->log->record($id, $template->code, $template->version, 'email', null, $to, 'failed', null, mb_substr('envoi de test : '.$e->getMessage(), 0, self::ERROR_MAX), $now);
            throw new TemplateAdminRejected('L’envoi de test a échoué : '.$e->getMessage());
        }
    }

    /** @param array{subject: ?string, preheader: ?string, body_html: ?string, body_text: ?string, in_app_body: ?string} $content */
    private function validate(TemplateDetail $template, array $content, \DateTimeImmutable $now): void
    {
        $variables = SampleVariables::for($template->variables, $now);
        $probe = $this->template($template, $content);
        try {
            if (null !== $content['subject'] && null !== $content['body_html'] && null !== $content['body_text']) {
                $this->renderer->renderEmail($probe, $variables);
            }
            if (null !== $content['in_app_body']) {
                $this->renderer->renderInApp($probe, $variables);
            }
        } catch (\RuntimeException $e) {
            throw new TemplateAdminRejected('Modèle invalide : '.$e->getMessage());
        }
    }

    /** @param array{subject: ?string, preheader: ?string, body_html: ?string, body_text: ?string, in_app_body: ?string} $content */
    private function template(TemplateDetail $template, array $content): Template
    {
        return new Template($template->code, $template->channels, true, $template->version, $content['subject'], $content['preheader'], $content['body_html'], $content['body_text'], $content['in_app_body']);
    }

    /** @return array{subject: ?string, preheader: ?string, body_html: ?string, body_text: ?string, in_app_body: ?string} */
    private function current(TemplateDetail $template): array
    {
        return ['subject' => $template->subject, 'preheader' => $template->preheader, 'body_html' => $template->bodyHtml, 'body_text' => $template->bodyText, 'in_app_body' => $template->inAppBody];
    }

    /**
     * @param array<string, mixed> $data
     *
     * @return array{subject: ?string, preheader: ?string, body_html: ?string, body_text: ?string, in_app_body: ?string}
     */
    private function content(array $data): array
    {
        $field = static function (string $name, int $max, string $label) use ($data): ?string {
            $value = \is_string($data[$name] ?? null) ? trim(str_replace("\r\n", "\n", $data[$name])) : '';
            if (mb_strlen($value) > $max) {
                throw new TemplateAdminRejected(\sprintf('%s : %d caractères au maximum.', $label, $max));
            }

            return '' === $value ? null : $value;
        };

        return [
            'subject' => $field('subject', self::SUBJECT_MAX, 'Objet'),
            'preheader' => $field('preheader', self::PREHEADER_MAX, 'Pré-en-tête'),
            'body_html' => $field('body_html', self::BODY_MAX, 'Corps HTML'),
            'body_text' => $field('body_text', self::BODY_MAX, 'Version texte'),
            'in_app_body' => $field('in_app_body', self::IN_APP_MAX, 'Notification sur le site'),
        ];
    }
}
