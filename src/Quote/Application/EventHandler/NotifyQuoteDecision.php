<?php

declare(strict_types=1);

namespace App\Quote\Application\EventHandler;

use App\ArtisanProfile\Application\Account\ArtisanAccount;
use App\ArtisanProfile\Application\Account\ArtisanAccountOf;
use App\ArtisanProfile\Application\PublicProfile\ArtisanPhoneHandler;
use App\Quote\Application\Port\QuoteMailer;
use App\Quote\Domain\Quote\Event\QuoteStatusChanged;
use App\Quote\Domain\Quote\QuoteId;
use App\Quote\Domain\Quote\QuoteRepository;
use App\Quote\Domain\Quote\QuoteStatus;
use App\QuoteRequest\Application\Port\RequestContentStore;
use App\QuoteRequest\Domain\Request\QuoteRequestId;
use App\QuoteRequest\Domain\Request\QuoteRequestRepository;
use App\Shared\Application\Bus\EventHandler;
use App\Shared\Application\Bus\QueryBus;
use App\Shared\Application\Identity\UserContact;
use App\Shared\Application\Identity\UserContactDetails;
use App\Shared\Application\Identity\UserPhone;
use App\Shared\Application\Settings\SettingKey;
use App\Shared\Application\Settings\SettingsProvider;
use App\Shared\Domain\Text\FrenchNumber;

/**
 * C03 — décisions du client prévenues : E-35b (consulté, in-app artisan) ; acceptation : E-34 à l'artisan avec les
 * coordonnées du client et l'adresse complète (RG-022bis), E-13 au client avec celles de l'artisan ; E-35 au refus
 * (motif) et aux non retenus (`quotes.auto_notify_not_selected`).
 */
final readonly class NotifyQuoteDecision implements EventHandler
{
    /** Motif affiché à l'artisan quand le client annule sa demande ou supprime son compte (RG-121, RG-090). */
    private const string REQUEST_CANCELLED = 'demande annulée par le client';

    public function __construct(
        private QuoteRepository $quotes,
        private QuoteRequestRepository $requests,
        private RequestContentStore $content,
        private QueryBus $queries,
        private QuoteMailer $mailer,
        private SettingsProvider $settings,
    ) {
    }

    public function __invoke(QuoteStatusChanged $event): void
    {
        $to = QuoteStatus::tryFrom($event->to);
        if (!\in_array($to, [QuoteStatus::Viewed, QuoteStatus::Accepted, QuoteStatus::Refused, QuoteStatus::NotSelected, QuoteStatus::RequestCancelled], true)
            || (QuoteStatus::NotSelected === $to && !$this->settings->bool(SettingKey::QuotesAutoNotifyNotSelected))) {
            return;
        }
        $quote = $this->quotes->find(new QuoteId($event->quoteId));
        $request = $this->requests->find(new QuoteRequestId($event->requestId));
        $account = $this->queries->ask(new ArtisanAccountOf($event->artisanId));
        $artisan = $account instanceof ArtisanAccount ? $this->queries->ask(new UserContact($account->userId)) : null;
        if (null === $quote || null === $request || !$account instanceof ArtisanAccount || !$artisan instanceof UserContactDetails) {
            return;
        }
        $number = $quote->number() ?? '';
        if (QuoteStatus::Viewed === $to) {
            $this->mailer->quoteViewed($account->userId, $event->requestId, $number, $request->number());

            return;
        }
        if (QuoteStatus::Accepted !== $to) {
            $cancelled = QuoteStatus::RequestCancelled === $to;
            $this->mailer->quoteDeclined($artisan->email, $artisan->firstName, $account->userId, $event->requestId, $number, $request->number(), QuoteStatus::Refused === $to || $cancelled, $cancelled ? self::REQUEST_CANCELLED : $quote->decisionReason());

            return;
        }
        $amount = FrenchNumber::money($quote->current()->content->totals->ttc->amount);
        $client = $this->queries->ask(new UserContact($request->clientId()));
        $address = $this->content->address($event->requestId);
        $this->mailer->quoteAccepted($artisan->email, $artisan->firstName, $account->userId, $event->requestId, $number, $request->number(), $amount, [
            'name' => $client instanceof UserContactDetails ? trim($client->firstName.' '.$client->lastName) : 'Votre client',
            'email' => $client instanceof UserContactDetails ? $client->email : null,
            'phone' => $this->phone($request->clientId()),
            'address' => null === $address ? null : trim($address.', '.$request->postalCode()),
        ]);
        if ($client instanceof UserContactDetails) {
            $this->mailer->acceptanceSummary($client->email, $client->firstName, $request->clientId(), $event->requestId, $number, $request->number(), $amount, [
                'name' => $account->name,
                'email' => $artisan->email,
                'phone' => $this->phone($account->userId),
            ]);
        }
    }

    private function phone(string $userId): ?string
    {
        $phone = $this->queries->ask(new UserPhone($userId));

        return \is_string($phone) ? ArtisanPhoneHandler::display($phone) : null;
    }
}
