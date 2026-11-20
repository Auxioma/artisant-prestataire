<?php

declare(strict_types=1);

namespace App\Review\Application\Review;

use App\Review\Application\Port\ReviewDirectory;
use App\Review\Application\Port\ReviewMailer;
use App\Review\Domain\Review\ReviewRuleViolation;
use App\Shared\Application\Bus\CommandHandler;
use App\Shared\Application\Settings\SettingKey;
use App\Shared\Application\Settings\SettingsProvider;
use App\Shared\Domain\Time\Clock;

/** E-16 — invitation à déposer un avis J+1 après l'ouverture de la fenêtre, relance J+7, tant que la fenêtre est ouverte. */
final readonly class ReviewInvitationHandler implements CommandHandler
{
    private const string FIRST = '+1 day';
    private const string REMINDER = '+6 days';

    public function __construct(private ReviewDirectory $directory, private ReviewMailer $mailer, private SettingsProvider $settings, private Clock $clock)
    {
    }

    public function __invoke(SendReviewInvitations|RemindReviewer $command): void
    {
        if ($command instanceof RemindReviewer) {
            $this->remind($command);

            return;
        }
        $now = $this->clock->now();
        $days = $this->settings->int(SettingKey::ReviewsWindowDays);
        foreach ($this->directory->invitationCandidates() as $c) {
            [$opens, $closes] = ReviewWindow::of($c['accepted_at'], $c['service_at'], $days);
            if (null === $c['client_email'] || $now > $closes) {
                continue;
            }
            if (null === $c['invited_at'] && $now >= $opens->modify(self::FIRST)) {
                $this->mailer->invitation($c['client_email'], $c['client_first_name'], $c['client_user_id'], $c['artisan_name'], $c['request_id'], false);
                $this->directory->markInvitation($c['request_id'], 'invited_at', $now);
            } elseif (null !== $c['invited_at'] && null === $c['reminded_at'] && $now >= $c['invited_at']->modify(self::REMINDER)) {
                $this->mailer->invitation($c['client_email'], $c['client_first_name'], $c['client_user_id'], $c['artisan_name'], $c['request_id'], true);
                $this->directory->markInvitation($c['request_id'], 'reminded_at', $now);
            }
        }
    }

    /** PR09.3 — relance manuelle par l'artisan, une seule fois par prestation, fenêtre ouverte. */
    private function remind(RemindReviewer $command): void
    {
        $artisanId = $this->directory->artisanOfUser($command->userId) ?? throw new ReviewRuleViolation('Prestation introuvable.');
        $now = $this->clock->now();
        foreach ($this->directory->invitationCandidates(true) as $c) {
            if ($c['request_id'] !== $command->requestId || $c['artisan_id'] !== $artisanId) {
                continue;
            }
            if (null !== $c['artisan_reminded_at']) {
                throw new ReviewRuleViolation('Vous avez déjà relancé ce client.');
            }
            [$opens, $closes] = ReviewWindow::of($c['accepted_at'], $c['service_at'], $this->settings->int(SettingKey::ReviewsWindowDays));
            if ($now < $opens || $now > $closes || null === $c['client_email']) {
                throw new ReviewRuleViolation('Ce client ne peut pas être relancé pour le moment.');
            }
            $this->mailer->invitation($c['client_email'], $c['client_first_name'], $c['client_user_id'], $c['artisan_name'], $c['request_id'], true);
            $this->directory->markInvitation($c['request_id'], 'artisan_reminded_at', $now);

            return;
        }
        throw new ReviewRuleViolation('Prestation introuvable ou déjà évaluée.');
    }
}
