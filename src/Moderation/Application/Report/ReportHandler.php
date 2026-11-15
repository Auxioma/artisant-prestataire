<?php

declare(strict_types=1);

namespace App\Moderation\Application\Report;

use App\Shared\Application\Audit\AuditTrail;
use App\Shared\Application\Bus\CommandHandler;
use App\Shared\Application\Moderation\ReportableContent;
use App\Shared\Domain\Audit\AuditRecord;
use App\Shared\Domain\Identifier\IdGenerator;
use App\Shared\Domain\Time\Clock;

/**
 * BO05.2 — dépôt d'un signalement (instantané du contenu, un seul signalement ouvert par personne et par contenu, pas
 * sur son propre contenu) et décision de l'équipe avec réponse facultative au signalant (E-27). Journalisé.
 */
final readonly class ReportHandler implements CommandHandler
{
    private const int DETAILS_MAX = 1000;
    private const int RESPONSE_MAX = 1000;
    private const int TITLE_MAX = 200;

    /** @param iterable<ReportableContent> $reportables */
    public function __construct(
        private iterable $reportables,
        private Reports $reports,
        private ReportMailer $mailer,
        private AuditTrail $audit,
        private IdGenerator $ids,
        private Clock $clock,
    ) {
    }

    public function __invoke(SubmitReport|DecideReport $command): void
    {
        $command instanceof SubmitReport ? $this->submit($command) : $this->decide($command);
    }

    private function submit(SubmitReport $command): void
    {
        $reportable = $this->reportable($command->type) ?? throw new ReportRejected('Ce contenu ne peut pas être signalé.');
        $content = $reportable->describe($command->subjectId, $command->reporterId) ?? throw new ReportRejected('Ce contenu n’est plus disponible.');
        if ($content->ownerUserId === $command->reporterId) {
            throw new ReportRejected('Vous ne pouvez pas signaler votre propre contenu.');
        }
        $reason = ReportReason::tryFrom($command->reason);
        if (null === $reason || !\in_array($reason, ReportReason::forType($command->type), true)) {
            throw new ReportRejected('Choisissez le motif du signalement.');
        }
        $details = null === $command->details ? null : trim($command->details);
        if (ReportReason::Other === $reason && (null === $details || '' === $details)) {
            throw new ReportRejected('Précisez le motif.');
        }
        if (null !== $details && mb_strlen($details) > self::DETAILS_MAX) {
            throw new ReportRejected(\sprintf('Précisions : %d caractères au maximum.', self::DETAILS_MAX));
        }
        if ($this->reports->hasOpen($command->reporterId, $command->type, $command->subjectId)) {
            throw new ReportRejected('Vous avez déjà signalé ce contenu : l’équipe l’examine.');
        }
        $now = $this->clock->now();
        $this->reports->add(new ReportView($command->reportId, $command->type, $command->subjectId, mb_substr($content->title, 0, self::TITLE_MAX), $content->excerpt, $content->ownerUserId, $command->reporterId, $reason->value, '' === $details ? null : $details, 'open', $now));
        $this->record('user', $command->reporterId, 'moderation.report_submitted', $command->reportId, ['type' => $command->type, 'subject' => $command->subjectId, 'reason' => $reason->value], $now);
    }

    private function decide(DecideReport $command): void
    {
        if (!\in_array($command->decision, DecideReport::DECISIONS, true)) {
            throw new ReportRejected('Décision inconnue.');
        }
        $report = $this->reports->find($command->reportId) ?? throw new ReportRejected('Signalement introuvable.');
        $response = null === $command->response ? null : trim($command->response);
        if (null !== $response && mb_strlen($response) > self::RESPONSE_MAX) {
            throw new ReportRejected(\sprintf('Réponse : %d caractères au maximum.', self::RESPONSE_MAX));
        }
        $response = '' === $response ? null : $response;
        $now = $this->clock->now();
        $status = 'dismiss' === $command->decision ? 'dismissed' : 'actioned';
        if (!$this->reports->decide($report->id, $status, $command->adminId, $response, $now)) {
            throw new ReportRejected('Ce signalement a déjà été traité.');
        }
        foreach ($this->reports->reporterContact($report->id) as $reporter) {
            $this->mailer->reportAnswered($reporter['email'], $reporter['firstName'], $reporter['userId'], $report->title, 'actioned' === $status, $response);
        }
        $this->record('admin', $command->adminId, 'moderation.report_decided', $report->id, ['status' => $status, 'type' => $report->type, 'subject' => $report->subjectId], $now);
    }

    private function reportable(string $type): ?ReportableContent
    {
        foreach ($this->reportables as $reportable) {
            if ($reportable->type() === $type) {
                return $reportable;
            }
        }

        return null;
    }

    /** @param array<string, string> $context */
    private function record(string $actorType, string $actorId, string $action, string $reportId, array $context, \DateTimeImmutable $now): void
    {
        $this->audit->record(new AuditRecord(
            id: $this->ids->next(),
            occurredAt: $now,
            actorType: $actorType,
            actorId: $actorId,
            action: $action,
            subjectType: 'report',
            subjectId: $reportId,
            context: $context,
        ));
    }
}
