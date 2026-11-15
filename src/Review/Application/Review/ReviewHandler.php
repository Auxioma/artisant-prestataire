<?php

declare(strict_types=1);

namespace App\Review\Application\Review;

use App\Review\Application\Port\ReviewDirectory;
use App\Review\Domain\Review\Event\ReviewChanged;
use App\Review\Domain\Review\Review;
use App\Review\Domain\Review\ReviewContent;
use App\Review\Domain\Review\ReviewId;
use App\Review\Domain\Review\ReviewRepository;
use App\Review\Domain\Review\ReviewRuleViolation;
use App\Shared\Application\Bus\CommandHandler;
use App\Shared\Application\Bus\EventBus;
use App\Shared\Application\Settings\SettingKey;
use App\Shared\Application\Settings\SettingsProvider;
use App\Shared\Application\Storage\FileUploader;
use App\Shared\Application\Storage\RejectedUpload;
use App\Shared\Domain\Storage\FilePurpose;
use App\Shared\Domain\Time\Clock;

/**
 * C06 / PR09 — avis certifiés. Dépôt réservé au client ayant accepté un devis sur la demande (RG-033), un par demande,
 * dans la fenêtre RG-034 ; modération selon `reviews.moderation_mode` et le score de risque (RG-035) ; modification par
 * l'auteur pendant `reviews.edit_days` ou à la demande de la modération ; suppression à tout moment ; réponse et
 * signalement par l'artisan (RG-036).
 */
final readonly class ReviewHandler implements CommandHandler
{
    public function __construct(
        private ReviewRepository $reviews,
        private ReviewDirectory $directory,
        private ReviewRiskScorer $risk,
        private SettingsProvider $settings,
        private FileUploader $uploader,
        private EventBus $events,
        private Clock $clock,
    ) {
    }

    public function __invoke(SubmitReview|ReviewAction $command): void
    {
        $now = $this->clock->now();
        if ($command instanceof SubmitReview) {
            $this->submit($command, $now);

            return;
        }
        $review = $this->reviews->findForUpdate(new ReviewId($command->reviewId)) ?? throw new ReviewRuleViolation('Avis introuvable.');
        switch ($command->action) {
            case ReviewAction::DELETE:
                $review->guardAuthor($command->userId);
                $this->reviews->delete($review->id());
                $this->events->publish(new ReviewChanged($review->id()->value, $review->artisanId(), 'deleted', null, $now));

                return;
            case ReviewAction::ANSWER:
                $text = (string) $command->text;
                $review->answer($this->artisan($command->userId), $text, $this->risk->needsReview($this->risk->score($text)), $this->settings->int(SettingKey::ReviewsEditDays), $now);
                break;
            case ReviewAction::REPORT:
                $review->report($this->artisan($command->userId), (string) $command->text, $now);
                break;
            default:
                throw new ReviewRuleViolation('Action inconnue.');
        }
        $this->reviews->save($review);
        $this->events->publish(...$review->releaseEvents());
    }

    private function submit(SubmitReview $command, \DateTimeImmutable $now): void
    {
        $quote = $this->directory->acceptedQuote($command->userId, $command->requestId) ?? throw new ReviewRuleViolation('Vous pouvez donner votre avis sur un professionnel dont vous avez accepté le devis.');
        [$opens, $closes] = ReviewWindow::of($quote['accepted_at'], $quote['service_at'], $this->settings->int(SettingKey::ReviewsWindowDays));
        $existing = $this->reviews->findForUpdate(new ReviewId($command->reviewId));
        if (null === $existing) {
            if ($this->reviews->existsForRequest($command->requestId)) {
                throw new ReviewRuleViolation('Vous avez déjà donné votre avis sur cette demande.');
            }
            if ($now < $opens) {
                throw new ReviewRuleViolation(\sprintf('Vous pourrez donner votre avis à partir du %s, après la prestation.', $opens->setTimezone(new \DateTimeZone('Europe/Paris'))->format('d/m/Y')));
            }
            if ($now > $closes) {
                throw new ReviewRuleViolation('Le délai pour donner votre avis sur cette prestation est dépassé.');
            }
        }
        $serviceDate = null === $command->serviceDate ? $opens : (\DateTimeImmutable::createFromFormat('!Y-m-d', $command->serviceDate) ?: throw new ReviewRuleViolation('Date de prestation invalide.'));
        if ($serviceDate > $now) {
            throw new ReviewRuleViolation('La date de prestation ne peut pas être dans le futur.');
        }
        $kept = null === $existing ? [] : array_values(array_intersect($command->photos, $existing->content()->photos));
        if (\count($kept) + \count($command->uploads) > ReviewContent::PHOTOS_MAX) {
            throw new ReviewRuleViolation(\sprintf('%d photos au plus.', ReviewContent::PHOTOS_MAX));
        }
        $title = null === $command->title || '' === trim($command->title) ? null : trim($command->title);
        $text = trim(str_replace("\r\n", "\n", $command->text));
        // Contrôle du contenu avant de stocker les photos : pas de fichier orphelin pour un avis refusé.
        new ReviewContent($command->rating, $command->subRatings, $title, $text, $serviceDate, $command->recommends, $kept);
        foreach ($command->uploads as $upload) {
            $kept[] = $this->upload($upload['path'], $upload['name']);
        }
        $content = new ReviewContent($command->rating, $command->subRatings, $title, $text, $serviceDate, $command->recommends, $kept);
        $score = $this->risk->score(($content->title ?? '').' '.$content->text);
        if (null !== $existing) {
            $existing->edit($command->userId, $content, $score, $this->risk->needsReview($score), $this->settings->int(SettingKey::ReviewsEditDays), $now);
            $this->reviews->save($existing);
            $this->events->publish(...$existing->releaseEvents());

            return;
        }
        $review = Review::submit(new ReviewId($command->reviewId), $command->requestId, $quote['quote_id'], $command->userId, $quote['artisan_id'], $content, $score, $this->risk->needsReview($score), $now);
        $this->reviews->save($review);
        $this->events->publish(...$review->releaseEvents());
    }

    /** C06.2 — photo d'avis : type réel contrôlé, quarantaine puis antivirus ; affichée une fois saine. */
    private function upload(string $path, ?string $name): string
    {
        try {
            return $this->uploader->upload($path, $name, FilePurpose::ReviewPhoto)->id;
        } catch (RejectedUpload $e) {
            throw new ReviewRuleViolation(match ($e->reason) {
                RejectedUpload::TYPE_NOT_ALLOWED => 'Photo non acceptée : formats JPG, PNG ou WEBP.', RejectedUpload::TOO_LARGE => \sprintf('Photo trop lourde (%d Mo au plus).', $this->settings->int(SettingKey::RequestsMaxFileMb)), RejectedUpload::IMAGE_TOO_LARGE => 'Image trop grande : réduisez sa résolution.', default => 'Cette photo n’a pas pu être acceptée.',
            });
        }
    }

    private function artisan(string $userId): string
    {
        return $this->directory->artisanOfUser($userId) ?? throw new ReviewRuleViolation('Avis introuvable.');
    }
}
