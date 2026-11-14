<?php

declare(strict_types=1);

namespace App\Quote\Domain\Sending;

/** RG-101 — motif de refus d'un envoi (aucun débit, brouillon conservé). */
enum RefusalReason: string
{
    case RequestClosed = 'request_closed';
    case RequestFull = 'request_full';
    case NotVerified = 'not_verified';
    case AlreadySent = 'already_sent';
    case MissingMentions = 'missing_mentions';
    case NoCredit = 'no_credit';
    case SubscriptionSuspended = 'subscription_suspended';
}
