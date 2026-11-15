<?php

declare(strict_types=1);

namespace App\Notification\Application;

use App\Notification\Application\Port\DeliveryLog;
use App\Notification\Application\Port\EmailSender;
use App\Notification\Application\Port\InAppNotifications;
use App\Notification\Application\Port\TemplateRenderer;
use App\Notification\Application\Port\TemplateStore;
use App\Notification\Application\Preference\NotificationPreferences;
use App\Notification\Application\Preference\NotificationTopic;
use App\Shared\Domain\Identifier\IdGenerator;
use App\Shared\Domain\Time\Clock;

/**
 * Envoi d'une notification sur les canaux actifs de son modèle. Modèle absent ou désactivé (BO11) : rien n'est envoyé,
 * la tentative est journalisée « skipped ». Un échec de rendu ou d'envoi est journalisé « failed » sans interrompre le
 * parcours de l'utilisateur. SMS : lot V2.
 */
final readonly class NotificationDispatcher implements Notifier
{
    private const int ERROR_MAX = 500;

    public function __construct(
        private TemplateStore $templates,
        private TemplateRenderer $renderer,
        private EmailSender $emails,
        private InAppNotifications $inApp,
        private DeliveryLog $log,
        private IdGenerator $ids,
        private Clock $clock,
        private NotificationPreferences $preferences,
    ) {
    }

    public function notify(Notification $notification): void
    {
        $template = $this->templates->find($notification->code);
        if (null === $template || !$template->active) {
            $this->log->record($this->ids->next(), $notification->code, $template->version ?? 0, 'email', $notification->userId, $notification->email, 'skipped', null, null === $template ? 'modèle absent' : 'modèle désactivé', $this->clock->now());

            return;
        }

        if ($template->has('email') && null !== $notification->email) {
            if ($this->optedOut($notification)) {
                $this->log->record($this->ids->next(), $template->code, $template->version, 'email', $notification->userId, $notification->email, 'skipped', null, 'désactivé par le destinataire', $this->clock->now());
            } else {
                $this->sendEmail($template, $notification);
            }
        }
        if ($template->has('in_app') && null !== $notification->userId) {
            $this->addInApp($template, $notification);
        }
    }

    /** C07 : sujet coupé par le titulaire (les messages indispensables n'ont pas de sujet et partent toujours). */
    private function optedOut(Notification $notification): bool
    {
        $topic = NotificationTopic::of($notification->code);

        return null !== $topic && null !== $notification->userId && !$this->preferences->enabled($notification->userId, $topic, NotificationPreferences::EMAIL);
    }

    private function sendEmail(Template $template, Notification $notification): void
    {
        $id = $this->ids->next();
        \assert(null !== $notification->email);
        try {
            $rendered = $this->renderer->renderEmail($template, $notification->variables);
            $providerId = $this->emails->send($notification->email, $rendered, $template->code, $id);
            $this->log->record($id, $template->code, $template->version, 'email', $notification->userId, $notification->email, 'sent', $providerId, null, $this->clock->now());
        } catch (\RuntimeException $e) {
            $this->log->record($id, $template->code, $template->version, 'email', $notification->userId, $notification->email, 'failed', null, mb_substr($e->getMessage(), 0, self::ERROR_MAX), $this->clock->now());
        }
    }

    private function addInApp(Template $template, Notification $notification): void
    {
        $id = $this->ids->next();
        \assert(null !== $notification->userId);
        try {
            $body = $this->renderer->renderInApp($template, $notification->variables);
            $this->inApp->add($id, $notification->userId, $template->code, $body, $notification->inAppLink, $this->clock->now());
            $this->log->record($id, $template->code, $template->version, 'in_app', $notification->userId, null, 'sent', null, null, $this->clock->now());
        } catch (\RuntimeException $e) {
            $this->log->record($id, $template->code, $template->version, 'in_app', $notification->userId, null, 'failed', null, mb_substr($e->getMessage(), 0, self::ERROR_MAX), $this->clock->now());
        }
    }
}
