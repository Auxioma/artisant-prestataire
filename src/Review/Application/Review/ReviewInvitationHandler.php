<?php

declare(strict_types=1);

namespace App\Review\Application\Review;

use App\Review\Application\Port\ReviewDirectory;
use App\Review\Application\Port\ReviewMailer;
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

    public function __invoke(SendReviewInvitations $command): void
    {
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
}
