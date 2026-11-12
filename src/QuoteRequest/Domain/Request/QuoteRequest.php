<?php

declare(strict_types=1);

namespace App\QuoteRequest\Domain\Request;

use App\QuoteRequest\Domain\Request\Event\QuoteRequestDetailsEdited;
use App\QuoteRequest\Domain\Request\Event\QuoteRequestStatusChanged;
use App\QuoteRequest\Domain\Request\Event\QuoteRequestSubmitted;
use App\Shared\Domain\AggregateRoot;

/**
 * Demande de devis (P11, C02, C03 ; RG-063 à RG-066, RG-070). Machine à états RG-066 : voir RequestStatus.
 *
 * - `maxQuotes` est figé à l'envoi (§4.2) ; `activeQuotesCount` compte les devis qui occupent une place
 *   (0 ≤ n ≤ maxQuotes, contrainte en base) et `receivedQuotesCount` tous les devis reçus (verrou de modification).
 * - L'échéance RG-064 court à partir de la publication (ouverture), pas de la mise en modération.
 * - Le plafond de demandes ouvertes et l'anti-doublon (RG-065) dépendent des autres demandes du client : ils sont
 *   contrôlés par le cas d'usage d'envoi.
 */
final class QuoteRequest extends AggregateRoot
{
    /** RG-064 — « le client peut la clôturer ou la relancer une fois ». */
    public const int RELAUNCH_MAX = 1;
    private const string UUID = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/';
    private const string INSEE = '/^(\d{5}|2[AB]\d{3})$/';
    private const string POSTAL_CODE = '/^\d{5}$/';
    private const int REASON_MAX = 500;

    private RequestStatus $status;
    private ProjectType $projectType;
    private string $description;
    /** @var array<string, string|list<string>> */
    private array $answers;
    private ?string $budgetRange;
    private Timing $timing;
    private ?\DateTimeImmutable $desiredDate;
    private RequestSource $source = RequestSource::Other;
    /** Empreinte (HMAC) de l'IP d'envoi : signal « rafale » du score de risque (RG-063), jamais l'IP elle-même. */
    private ?string $ipHash = null;
    private ?string $title = null;
    private ?string $summary = null;
    private ?string $workNature = null;
    /** @var list<string> */
    private array $preferredSlots = [];
    private ?OccupantStatus $occupantStatus = null;
    private ContactPreference $contactPreference = ContactPreference::MessagingOnly;
    /** Version du questionnaire du métier avec laquelle les réponses ont été données (libellés stables, PR03). */
    private ?int $questionnaireVersion = null;
    private int $activeQuotesCount = 0;
    private int $receivedQuotesCount = 0;
    private int $riskScore;
    /** @var list<string> */
    private array $riskSignals;
    private ?\DateTimeImmutable $publishedAt = null;
    private ?\DateTimeImmutable $expiresAt = null;
    private int $reopenCount = 0;
    private int $relaunchCount = 0;
    private ?string $statusReason = null;
    private \DateTimeImmutable $statusChangedAt;
    private \DateTimeImmutable $updatedAt;
    private int $version = 1;

    private function __construct(
        private QuoteRequestId $id,
        private string $number,
        private string $clientId,
        private string $jobId,
        private ?string $targetArtisanId,
        private bool $broadcastToOthers,
        private string $cityInsee,
        private string $postalCode,
        private string $duplicateKey,
        private int $maxQuotes,
        private \DateTimeImmutable $submittedAt,
    ) {
        $this->statusChangedAt = $submittedAt;
        $this->updatedAt = $submittedAt;
    }

    /**
     * P11.7 — envoi : ouverte d'emblée, ou en modération si le score de risque atteint le seuil (RG-063).
     * Demande directe (RG-062) : `targetArtisanId` renseigné, `broadcastToOthers` = « Recevoir aussi d'autres devis ».
     */
    public static function submit(
        QuoteRequestId $id,
        string $number,
        string $clientId,
        string $jobId,
        ?string $targetArtisanId,
        bool $broadcastToOthers,
        string $cityInsee,
        string $postalCode,
        DuplicateKey $duplicateKey,
        RequestDetails $details,
        SubmissionTerms $terms,
        \DateTimeImmutable $now,
        ContactPreference $contactPreference = ContactPreference::MessagingOnly,
        ?int $questionnaireVersion = null,
        RequestSource $source = RequestSource::Other,
        ?string $ipHash = null,
    ): self {
        foreach ([$clientId, $jobId, $targetArtisanId ?? $clientId] as $uuid) {
            if (1 !== preg_match(self::UUID, $uuid)) {
                throw new RequestRuleViolation('Identifiant invalide.');
            }
        }
        if (1 !== preg_match(self::INSEE, $cityInsee) || 1 !== preg_match(self::POSTAL_CODE, $postalCode)) {
            throw new RequestRuleViolation('Commune d’intervention invalide.');
        }
        $request = new self($id, $number, $clientId, $jobId, $targetArtisanId, null === $targetArtisanId || $broadcastToOthers, $cityInsee, $postalCode, $duplicateKey->value, $terms->maxQuotes, $now);
        $request->apply($details);
        $request->contactPreference = $contactPreference;
        $request->questionnaireVersion = $questionnaireVersion;
        $request->source = $source;
        $request->ipHash = $ipHash;
        $request->riskScore = $terms->riskScore;
        $request->riskSignals = $terms->riskSignals;
        if ($terms->needsModeration()) {
            $request->status = RequestStatus::PendingModeration;
        } else {
            $request->status = RequestStatus::Open;
            $request->publish($terms->expiryDays, $now);
        }
        $request->recordEvent(new QuoteRequestSubmitted($id->value, $clientId, $jobId, $cityInsee, $request->status->value, $terms->maxQuotes, $targetArtisanId, $request->broadcastToOthers, $now));

        return $request;
    }

    /** RG-066 — modification tant qu'aucun devis n'a été reçu ; ensuite, complément uniquement (request_addendum). */
    public function edit(RequestDetails $details, \DateTimeImmutable $now): void
    {
        if (!\in_array($this->status, [RequestStatus::PendingModeration, RequestStatus::Open], true)) {
            throw new RequestRuleViolation('Cette demande ne peut plus être modifiée.');
        }
        if ($this->receivedQuotesCount > 0) {
            throw new RequestRuleViolation('Vous avez déjà reçu un devis : ajoutez un complément à votre demande plutôt que de la modifier.');
        }
        $this->apply($details);
        $this->touch($now);
        $this->recordEvent(new QuoteRequestDetailsEdited($this->id->value, $this->clientId, $now));
    }

    /** BO05 — la modération valide la demande : elle est publiée et son échéance RG-064 commence. */
    public function approve(int $expiryDays, \DateTimeImmutable $now): void
    {
        $this->guard(RequestStatus::PendingModeration, 'Cette demande n’est pas en attente de modération.');
        $this->transition(RequestStatus::Open, 'moderation_approved', $now);
        $this->publish($expiryDays, $now);
    }

    public function reject(string $reason, \DateTimeImmutable $now): void
    {
        $this->guard(RequestStatus::PendingModeration, 'Cette demande n’est pas en attente de modération.');
        $this->transition(RequestStatus::Rejected, self::reason($reason), $now);
    }

    /**
     * RG-070 / RG-101 — un devis envoyé occupe une place ; le dernier fait passer la demande en « complète ».
     * L'appelant (envoi d'un devis, T-504) verrouille la demande (`FOR UPDATE`) avant d'appeler cette méthode.
     */
    public function recordQuote(\DateTimeImmutable $now): void
    {
        $this->guard(RequestStatus::Open, 'Cette demande n’accepte plus de devis.');
        if (null !== $this->expiresAt && $now >= $this->expiresAt) {
            throw new RequestRuleViolation('Cette demande a expiré.');
        }
        ++$this->activeQuotesCount;
        ++$this->receivedQuotesCount;
        if ($this->activeQuotesCount >= $this->maxQuotes) {
            $this->transition(RequestStatus::Full, 'max_quotes_reached', $now);
        } else {
            $this->touch($now);
        }
    }

    /** RG-103 — devis retiré avant consultation : la place est libérée, « complète » redevient « ouverte ». */
    public function releaseQuote(\DateTimeImmutable $now): void
    {
        if (!\in_array($this->status, [RequestStatus::Open, RequestStatus::Full], true) || 0 === $this->activeQuotesCount) {
            throw new RequestRuleViolation('Aucune place de devis à libérer sur cette demande.');
        }
        --$this->activeQuotesCount;
        if (RequestStatus::Full === $this->status) {
            $this->transition(RequestStatus::Open, 'quote_withdrawn', $now);
        } else {
            $this->touch($now);
        }
    }

    /**
     * RG-070 — le client qui a refusé tous les devis reçus peut rouvrir une demande complète pour {max} nouvelles
     * places (`requests.reopen_allowed` fois). Le refus de tous les devis est vérifié par le module Quote.
     */
    public function reopen(int $reopenAllowed, bool $allQuotesRefused, \DateTimeImmutable $now): void
    {
        $this->guard(RequestStatus::Full, 'Seule une demande complète peut être rouverte.');
        if ($this->reopenCount >= $reopenAllowed) {
            throw new RequestRuleViolation('Cette demande a déjà été rouverte.');
        }
        if (!$allQuotesRefused) {
            throw new RequestRuleViolation('Refusez d’abord les devis reçus pour rouvrir votre demande.');
        }
        ++$this->reopenCount;
        $this->activeQuotesCount = 0;
        $this->transition(RequestStatus::Open, 'reopened', $now);
    }

    /** RG-072 — un devis accepté : la demande est attribuée et sort des listes des artisans. */
    public function award(\DateTimeImmutable $now): void
    {
        if (!\in_array($this->status, [RequestStatus::Open, RequestStatus::Full], true)) {
            throw new RequestRuleViolation('Aucun devis ne peut être accepté sur cette demande.');
        }
        $this->transition(RequestStatus::Awarded, 'quote_accepted', $now);
    }

    /** RG-072 — acceptation annulée : la demande retrouve « ouverte » ou « complète » selon ses places occupées. */
    public function revokeAward(string $reason, \DateTimeImmutable $now): void
    {
        $this->guard(RequestStatus::Awarded, 'Cette demande n’est pas attribuée.');
        $this->transition($this->activeQuotesCount >= $this->maxQuotes ? RequestStatus::Full : RequestStatus::Open, self::reason($reason), $now);
    }

    /** Prestation réalisée (ouvre le dépôt d'avis, RG-033). */
    public function complete(\DateTimeImmutable $now): void
    {
        $this->guard(RequestStatus::Awarded, 'Seule une demande attribuée peut être terminée.');
        $this->transition(RequestStatus::Completed, 'service_done', $now);
    }

    /** RG-064 — échéance atteinte sans devis accepté (tâche planifiée, T-407). */
    public function expire(\DateTimeImmutable $now): void
    {
        if (!$this->isDue($now)) {
            throw new RequestRuleViolation('Cette demande n’est pas arrivée à échéance.');
        }
        $this->transition(RequestStatus::Expired, 'expired', $now);
    }

    public function isDue(\DateTimeImmutable $now): bool
    {
        return \in_array($this->status, [RequestStatus::Open, RequestStatus::Full], true) && null !== $this->expiresAt && $now >= $this->expiresAt;
    }

    /** RG-064 — relance unique d'une demande expirée : nouvelle échéance, places conservées. */
    public function relaunch(int $expiryDays, \DateTimeImmutable $now): void
    {
        $this->guard(RequestStatus::Expired, 'Seule une demande expirée peut être relancée.');
        if ($this->relaunchCount >= self::RELAUNCH_MAX) {
            throw new RequestRuleViolation('Cette demande a déjà été relancée une fois.');
        }
        ++$this->relaunchCount;
        $this->transition($this->activeQuotesCount >= $this->maxQuotes ? RequestStatus::Full : RequestStatus::Open, 'relaunched', $now);
        $this->publish($expiryDays, $now);
    }

    /** C02 « Clôturer » — annulation par le client (les devis passent en « demande annulée », module Quote). */
    public function cancel(string $reason, \DateTimeImmutable $now): void
    {
        if (!$this->status->canTransitionTo(RequestStatus::Cancelled)) {
            throw new RequestRuleViolation('Cette demande ne peut plus être annulée.');
        }
        $this->transition(RequestStatus::Cancelled, self::reason($reason), $now);
    }

    public function id(): QuoteRequestId
    {
        return $this->id;
    }

    public function number(): string
    {
        return $this->number;
    }

    public function clientId(): string
    {
        return $this->clientId;
    }

    public function jobId(): string
    {
        return $this->jobId;
    }

    public function targetArtisanId(): ?string
    {
        return $this->targetArtisanId;
    }

    public function broadcastToOthers(): bool
    {
        return $this->broadcastToOthers;
    }

    public function cityInsee(): string
    {
        return $this->cityInsee;
    }

    public function postalCode(): string
    {
        return $this->postalCode;
    }

    public function status(): RequestStatus
    {
        return $this->status;
    }

    public function details(): RequestDetails
    {
        return new RequestDetails(
            $this->projectType,
            $this->description,
            $this->answers,
            $this->budgetRange,
            $this->timing,
            $this->desiredDate,
            array_values(array_filter(array_map(PreferredSlot::tryFrom(...), $this->preferredSlots))),
            $this->occupantStatus,
            $this->title,
            $this->summary,
            $this->workNature,
        );
    }

    public function contactPreference(): ContactPreference
    {
        return $this->contactPreference;
    }

    public function source(): RequestSource
    {
        return $this->source;
    }

    public function questionnaireVersion(): ?int
    {
        return $this->questionnaireVersion;
    }

    public function maxQuotes(): int
    {
        return $this->maxQuotes;
    }

    public function activeQuotesCount(): int
    {
        return $this->activeQuotesCount;
    }

    /** C03.2 / PR02 — « {max − k} places restantes ». */
    public function remainingSlots(): int
    {
        return max(0, $this->maxQuotes - $this->activeQuotesCount);
    }

    public function receivedQuotesCount(): int
    {
        return $this->receivedQuotesCount;
    }

    public function riskScore(): int
    {
        return $this->riskScore;
    }

    /** @return list<string> */
    public function riskSignals(): array
    {
        return $this->riskSignals;
    }

    public function expiresAt(): ?\DateTimeImmutable
    {
        return $this->expiresAt;
    }

    public function reopenCount(): int
    {
        return $this->reopenCount;
    }

    public function relaunchCount(): int
    {
        return $this->relaunchCount;
    }

    public function statusReason(): ?string
    {
        return $this->statusReason;
    }

    public function submittedAt(): \DateTimeImmutable
    {
        return $this->submittedAt;
    }

    private function apply(RequestDetails $details): void
    {
        $this->projectType = $details->projectType;
        $this->description = $details->description;
        $this->answers = $details->answers;
        $this->budgetRange = $details->budgetRange;
        $this->timing = $details->timing;
        $this->desiredDate = $details->desiredDate;
        $this->preferredSlots = array_values(array_unique(array_map(static fn (PreferredSlot $s): string => $s->value, $details->preferredSlots)));
        $this->occupantStatus = $details->occupantStatus;
        $this->title = $details->title;
        $this->summary = $details->summary;
        $this->workNature = $details->workNature;
    }

    private function publish(int $expiryDays, \DateTimeImmutable $now): void
    {
        if ($expiryDays < 1) {
            throw new RequestRuleViolation('Durée de vie d’une demande invalide.');
        }
        $this->publishedAt ??= $now;
        $this->expiresAt = $now->modify(\sprintf('+%d days', $expiryDays));
    }

    private function guard(RequestStatus $expected, string $message): void
    {
        if ($this->status !== $expected) {
            throw new RequestRuleViolation($message);
        }
    }

    private function transition(RequestStatus $to, string $reason, \DateTimeImmutable $now): void
    {
        if (!$this->status->canTransitionTo($to)) {
            throw new RequestRuleViolation(\sprintf('Transition interdite : %s → %s.', $this->status->label(), $to->label()));
        }
        $from = $this->status;
        $this->status = $to;
        $this->statusReason = $reason;
        $this->statusChangedAt = $now;
        $this->touch($now);
        $this->recordEvent(new QuoteRequestStatusChanged($this->id->value, $this->clientId, $from->value, $to->value, $reason, $now));
    }

    private function touch(\DateTimeImmutable $now): void
    {
        $this->updatedAt = $now;
    }

    private static function reason(string $reason): string
    {
        $reason = trim($reason);
        if ('' === $reason || mb_strlen($reason) > self::REASON_MAX) {
            throw new RequestRuleViolation(\sprintf('Motif obligatoire (%d caractères au maximum).', self::REASON_MAX));
        }

        return $reason;
    }
}
