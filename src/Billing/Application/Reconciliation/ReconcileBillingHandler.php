<?php

declare(strict_types=1);

namespace App\Billing\Application\Reconciliation;

use App\Billing\Application\Port\PaymentGateway;
use App\Shared\Application\Audit\AuditTrail;
use App\Shared\Application\Bus\CommandHandler;
use App\Shared\Application\Integration\ExternalServiceUnavailable;
use App\Shared\Domain\Audit\AuditRecord;
use App\Shared\Domain\Identifier\IdGenerator;
use App\Shared\Domain\Time\Clock;

/**
 * ADR-0010 / ADR-0006 — chaque nuit : abonnements Stripe comparés à la base (existence, statut, fin de période),
 * factures récentes (réplique, statut, crédits de période accordés), soldes dénormalisés comparés à la somme du
 * registre, événements Stripe en souffrance. Un écart reste ouvert tant qu'il est constaté (un seul par type et
 * référence) et se résout seul quand il disparaît ; l'équipe peut aussi le clore. Sans clé Stripe : contrôles locaux.
 */
final readonly class ReconcileBillingHandler implements CommandHandler
{
    private const string INVOICE_WINDOW = '-35 days';
    private const string WEBHOOK_DELAY = '-1 hour';
    private const array STRIPE_KINDS = ['subscription_missing_local', 'subscription_missing_stripe', 'subscription_status', 'subscription_period', 'invoice_missing', 'invoice_status', 'credits_not_granted'];
    private const array LOCAL_KINDS = ['ledger_balance', 'webhook_backlog'];
    /** Statuts locaux acceptables pour chaque statut Stripe. */
    private const array EXPECTED = [
        'active' => ['active'], 'trialing' => ['active'], 'past_due' => ['past_due', 'suspended'], 'unpaid' => ['suspended'],
        'paused' => ['suspended'], 'canceled' => ['cancelled'], 'incomplete_expired' => ['cancelled', 'incomplete'], 'incomplete' => ['incomplete'],
    ];
    private const array PERIOD_REASONS = ['subscription_create', 'subscription_cycle'];
    private const int PERIOD_TOLERANCE_SECONDS = 3600;

    public function __construct(
        private ReconciliationStore $store,
        private PaymentGateway $gateway,
        private AuditTrail $audit,
        private IdGenerator $ids,
        private Clock $clock,
    ) {
    }

    public function __invoke(ReconcileBilling|ResolveReconciliationIssue $command): void
    {
        $now = $this->clock->now();
        if ($command instanceof ResolveReconciliationIssue) {
            if ($this->store->resolve($command->issueId, $command->adminId, $now)) {
                $this->audit->record(new AuditRecord($this->ids->next(), $now, 'admin', $command->adminId, 'billing.reconciliation_resolved', 'reconciliation_issue', $command->issueId));
            }

            return;
        }
        $issues = 0;
        $checked = 0;
        $raise = function (string $kind, string $reference, string $details) use (&$issues, $now): void {
            $this->store->raise($this->ids->next(), $kind, $reference, $details, $now);
            ++$issues;
        };

        foreach ($this->store->ledgerMismatches() as $m) {
            $raise('ledger_balance', $m['artisan_id'].':'.$m['bucket'], \sprintf('Compartiment %s : solde %d, registre %d.', $m['bucket'], $m['balance'], $m['ledger']));
        }
        foreach ($this->store->stuckEvents($now->modify(self::WEBHOOK_DELAY)) as $eventId) {
            $raise('webhook_backlog', $eventId, 'Reçu depuis plus d’une heure sans être traité (voir le transport « failed »).');
        }
        $checked += \count($this->store->localSubscriptions());

        $stripeChecked = $this->gateway->isConfigured();
        if ($stripeChecked) {
            try {
                $checked += $this->stripe($raise);
            } catch (ExternalServiceUnavailable) {
                $stripeChecked = false;
            }
        }
        $this->store->resolveStale($stripeChecked ? [...self::STRIPE_KINDS, ...self::LOCAL_KINDS] : self::LOCAL_KINDS, $now, $this->clock->now());
        $this->store->recordRun($this->ids->next(), $now, $this->clock->now(), $stripeChecked, $checked, $issues);
    }

    /**
     * @param \Closure(string, string, string): void $raise
     *
     * @return int éléments Stripe vérifiés
     */
    private function stripe(\Closure $raise): int
    {
        $now = $this->clock->now();
        $local = $this->store->localSubscriptions();
        $seen = [];
        $checked = 0;
        foreach ($this->gateway->allSubscriptions() as $state) {
            ++$checked;
            $mine = $local[$state->id] ?? null;
            if (null === $mine) {
                if (isset($state->metadata['subscription_id']) && !\in_array($state->status, ['incomplete', 'incomplete_expired', 'canceled'], true)) {
                    $raise('subscription_missing_local', $state->id, \sprintf('Statut Stripe « %s », abonnement local %s.', $state->status, $state->metadata['subscription_id']));
                }
                continue;
            }
            $seen[$state->id] = true;
            if (!\in_array($mine['status'], self::EXPECTED[$state->status] ?? [$mine['status']], true)) {
                $raise('subscription_status', $state->id, \sprintf('Stripe « %s », plateforme « %s ».', $state->status, $mine['status']));
            }
            $localEnd = null === $mine['period_end'] ? null : new \DateTimeImmutable($mine['period_end']);
            if (null !== $state->periodEnd && null !== $localEnd && 'active' === $mine['status'] && abs($state->periodEnd->getTimestamp() - $localEnd->getTimestamp()) > self::PERIOD_TOLERANCE_SECONDS) {
                $raise('subscription_period', $state->id, \sprintf('Fin de période Stripe %s, plateforme %s.', $state->periodEnd->format('d/m/Y'), $localEnd->format('d/m/Y')));
            }
        }
        foreach ($local as $stripeId => $row) {
            if (!isset($seen[$stripeId]) && \in_array($row['status'], ['active', 'past_due', 'suspended'], true)) {
                $raise('subscription_missing_stripe', $stripeId, \sprintf('Abonnement « %s » sur la plateforme, absent de Stripe.', $row['status']));
            }
        }

        $customers = array_flip($this->store->knownCustomers());
        $since = $now->modify(self::INVOICE_WINDOW);
        $invoices = $this->store->localInvoices($since);
        foreach ($this->gateway->invoicesSince($since) as $invoice) {
            if (!isset($customers[$invoice->customerId]) || 'draft' === $invoice->status) {
                continue;
            }
            ++$checked;
            $mine = $invoices[$invoice->id] ?? null;
            if (null === $mine) {
                $raise('invoice_missing', $invoice->id, \sprintf('Facture « %s » de %d centimes payés.', $invoice->status, $invoice->amountPaidCents));
                continue;
            }
            if ($mine['status'] !== $invoice->status) {
                $raise('invoice_status', $invoice->id, \sprintf('Stripe « %s », plateforme « %s ».', $invoice->status, $mine['status']));
            }
            if ('paid' === $invoice->status && \in_array($invoice->billingReason, self::PERIOD_REASONS, true) && !$mine['period_granted']) {
                $raise('credits_not_granted', $invoice->id, 'Facture d’abonnement payée sans période ni crédits enregistrés : rejouer l’événement invoice.paid.');
            }
        }

        return $checked;
    }
}
