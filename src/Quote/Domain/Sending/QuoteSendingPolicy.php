<?php

declare(strict_types=1);

namespace App\Quote\Domain\Sending;

use App\Quote\Domain\Mention\MandatoryMentionsChecker;
use App\Quote\Domain\Mention\MentionCheck;
use App\Quote\Domain\Quote\QuoteStatus;

/**
 * RG-101 — contrôle de l'envoi, dans l'ordre (a) demande ouverte, (b) places restantes (max figé), (c) artisan vérifié,
 * (d) aucun devis déjà envoyé sur la demande, (e) mentions obligatoires RG-120, (f) abonnement non suspendu pour impayé
 * (§4.3, RG-106), (g) crédit disponible.
 */
final readonly class QuoteSendingPolicy
{
    public function assertCanSend(SendingFacts $facts): void
    {
        if (!$facts->requestOpen) {
            throw new QuoteSendingRefused($facts->activeQuotes >= $facts->maxQuotes ? RefusalReason::RequestFull : RefusalReason::RequestClosed, $facts->activeQuotes >= $facts->maxQuotes ? \sprintf('Désolé, cette demande vient d’atteindre %d devis.', $facts->maxQuotes) : 'Cette demande n’accepte plus de devis.');
        }
        if ($facts->activeQuotes >= $facts->maxQuotes) {
            throw new QuoteSendingRefused(RefusalReason::RequestFull, \sprintf('Désolé, cette demande vient d’atteindre %d devis.', $facts->maxQuotes));
        }
        if (!$facts->artisanVerified) {
            throw new QuoteSendingRefused(RefusalReason::NotVerified, 'Votre compte doit être vérifié pour envoyer un devis.');
        }
        if (QuoteStatus::Draft !== $facts->quoteStatus) {
            throw new QuoteSendingRefused(RefusalReason::AlreadySent, 'Vous avez déjà envoyé un devis sur cette demande : révisez-le depuis « Mes devis ».');
        }
        $missing = array_values(array_filter(array_map(static fn (MentionCheck $c): ?string => $c->ok ? null : $c->missing, $facts->mentions)));
        if ([] !== $missing) {
            throw new QuoteSendingRefused(RefusalReason::MissingMentions, \sprintf(MandatoryMentionsChecker::MISSING_MESSAGE, implode(', ', $missing)));
        }
        if ($facts->subscriptionSuspended) {
            throw new QuoteSendingRefused(RefusalReason::SubscriptionSuspended, 'Votre abonnement est suspendu pour impayé : réglez votre facture depuis « Mes abonnements » pour envoyer à nouveau des devis.');
        }
        if ($facts->creditsAvailable <= 0) {
            throw new QuoteSendingRefused(RefusalReason::NoCredit, 'Vous n’avez plus de crédit : souscrivez un forfait pour envoyer ce devis.');
        }
    }
}
