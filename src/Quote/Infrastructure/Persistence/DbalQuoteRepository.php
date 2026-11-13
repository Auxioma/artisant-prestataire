<?php

declare(strict_types=1);

namespace App\Quote\Infrastructure\Persistence;

use App\Quote\Domain\Quote\Deposit;
use App\Quote\Domain\Quote\LineType;
use App\Quote\Domain\Quote\Quote;
use App\Quote\Domain\Quote\QuoteConditions;
use App\Quote\Domain\Quote\QuoteContent;
use App\Quote\Domain\Quote\QuoteId;
use App\Quote\Domain\Quote\QuoteLine;
use App\Quote\Domain\Quote\QuotePdf;
use App\Quote\Domain\Quote\QuoteRepository;
use App\Quote\Domain\Quote\QuoteStatus;
use App\Quote\Domain\Quote\QuoteVersion;
use App\Quote\Domain\Quote\VatRate;
use App\Shared\Domain\Identifier\IdGenerator;
use App\Shared\Infrastructure\Persistence\DbalValue;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;

/**
 * Persistance SQL de l'agrégat Quote (schéma `quote`). Une version déjà envoyée n'est jamais réécrite (déclencheur) :
 * seule la version en brouillon est remplacée ; une nouvelle version est insérée sans date d'envoi, ses lignes ensuite,
 * puis la date d'envoi posée.
 */
final readonly class DbalQuoteRepository implements QuoteRepository
{
    private const string UUID = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/';

    public function __construct(private Connection $connection, private IdGenerator $ids)
    {
    }

    public function find(QuoteId $id): ?Quote
    {
        return $this->load('SELECT * FROM quote.quote WHERE id = :id', ['id' => $id->value]);
    }

    public function findForUpdate(QuoteId $id): ?Quote
    {
        return $this->load('SELECT * FROM quote.quote WHERE id = :id FOR UPDATE', ['id' => $id->value]);
    }

    public function findByRequestAndArtisan(string $requestId, string $artisanId): ?Quote
    {
        if (1 !== preg_match(self::UUID, $requestId) || 1 !== preg_match(self::UUID, $artisanId)) {
            return null;
        }

        return $this->load('SELECT * FROM quote.quote WHERE request_id = :r AND artisan_id = :a', ['r' => $requestId, 'a' => $artisanId]);
    }

    public function artisansWithActiveQuote(string $requestId): array
    {
        if (1 !== preg_match(self::UUID, $requestId)) {
            return [];
        }

        return array_map(DbalValue::string(...), $this->connection->fetchFirstColumn(
            'SELECT artisan_id FROM quote.quote WHERE request_id = :r AND status IN (:active)',
            ['r' => $requestId, 'active' => array_map(static fn (QuoteStatus $s): string => $s->value, QuoteStatus::ACTIVE)],
            ['active' => ArrayParameterType::STRING],
        ));
    }

    public function sentForRequest(string $requestId): array
    {
        if (1 !== preg_match(self::UUID, $requestId)) {
            return [];
        }
        $quotes = [];
        foreach ($this->connection->fetchFirstColumn("SELECT id FROM quote.quote WHERE request_id = :r AND status NOT IN ('draft', 'withdrawn') ORDER BY sent_at, id", ['r' => $requestId]) as $id) {
            $quote = $this->find(new QuoteId(DbalValue::string($id)));
            if (null !== $quote) {
                $quotes[] = $quote;
            }
        }

        return $quotes;
    }

    public function expiredIds(\DateTimeImmutable $now, int $limit): array
    {
        return array_map(DbalValue::string(...), $this->connection->fetchFirstColumn(
            "SELECT id FROM quote.quote WHERE status IN ('sent', 'viewed') AND valid_until <= :now ORDER BY valid_until LIMIT ".max(1, $limit),
            ['now' => self::date($now)],
        ));
    }

    public function save(Quote $quote): void
    {
        $row = [
            'number' => $quote->number(),
            'status' => $quote->status()->value,
            'current_version' => $quote->current()->number,
            'sent_at' => self::date($quote->sentAt()),
            'viewed_at' => self::date($quote->viewedAt()),
            'decided_at' => self::date($quote->decidedAt()),
            'decision_reason' => $quote->decisionReason(),
            'valid_until' => self::date($quote->validUntil()),
            'updated_at' => self::date($quote->updatedAt()),
            'reminder_count' => $quote->reminderCount(),
            'reminded_at' => self::date($quote->remindedAt()),
        ];
        $updated = $this->connection->executeStatement(
            'UPDATE quote.quote SET number = :number, status = :status, current_version = :current_version, sent_at = :sent_at, viewed_at = :viewed_at,
                decided_at = :decided_at, decision_reason = :decision_reason, valid_until = :valid_until, updated_at = :updated_at,
                reminder_count = :reminder_count, reminded_at = :reminded_at, version = version + 1
             WHERE id = :id',
            $row + ['id' => $quote->id()->value],
        );
        if (0 === (int) $updated) {
            $this->connection->insert('quote.quote', $row + [
                'id' => $quote->id()->value,
                'request_id' => $quote->requestId(),
                'artisan_id' => $quote->artisanId(),
                'created_at' => self::date($quote->createdAt()),
            ]);
        }

        $stored = [];
        foreach ($this->connection->fetchAllAssociative('SELECT id, version, sent_at FROM quote.quote_version WHERE quote_id = :q', ['q' => $quote->id()->value]) as $version) {
            $stored[DbalValue::int($version['version'])] = ['id' => DbalValue::string($version['id']), 'sent' => null !== $version['sent_at']];
        }
        foreach ($quote->versions() as $version) {
            $existing = $stored[$version->number] ?? null;
            if (null !== $existing && $existing['sent']) {
                continue; // figée
            }
            if (null !== $existing) {
                $this->connection->executeStatement('DELETE FROM quote.quote_version WHERE id = :id', ['id' => $existing['id']]);
            }
            $this->insertVersion($quote->id()->value, $version);
        }
    }

    private function insertVersion(string $quoteId, QuoteVersion $version): void
    {
        $content = $version->content;
        $totals = $content->totals;
        $versionId = $this->ids->next();
        $byRate = [];
        foreach ($totals->byRate as $rate => $amounts) {
            $byRate[(string) $rate] = ['ht' => $amounts['ht']->amount, 'vat' => $amounts['vat']->amount];
        }
        $conditions = $content->conditions;
        $this->connection->insert('quote.quote_version', [
            'id' => $versionId,
            'quote_id' => $quoteId,
            'version' => $version->number,
            'subject' => $content->subject,
            'gross_ht_cents' => $totals->grossHt->amount,
            'discount_bp' => $content->globalDiscountBp,
            'discount_cents' => $totals->discount->amount,
            'total_ht_cents' => $totals->ht->amount,
            'total_vat_cents' => $totals->vat->amount,
            'total_ttc_cents' => $totals->ttc->amount,
            'vat_by_rate' => json_encode($byRate, \JSON_THROW_ON_ERROR),
            'vat_exempt' => $content->vatExempt ? 'true' : 'false',
            'deposit_percent_bp' => $content->deposit?->percentBp,
            'deposit_amount_cents' => $content->deposit?->amount,
            'deposit_cents' => $totals->deposit?->amount,
            'conditions' => json_encode([
                'validity_days' => $conditions->validityDays,
                'execution_delay' => $conditions->executionDelay,
                'start_date' => $conditions->startDate?->format('Y-m-d'),
                'payment_terms' => $conditions->paymentTerms,
                'payment_methods' => $conditions->paymentMethods,
                'guarantees' => $conditions->guarantees,
                'special_conditions' => $conditions->specialConditions,
                'client_note' => $conditions->clientNote,
            ], \JSON_THROW_ON_ERROR),
            'created_at' => self::date($version->createdAt),
            'pdf_key' => $version->pdf?->key,
            'pdf_sha256' => $version->pdf?->sha256,
        ]);
        foreach ($content->lines as $i => $line) {
            $this->connection->insert('quote.quote_line', [
                'id' => $this->ids->next(),
                'quote_version_id' => $versionId,
                'position' => $i + 1,
                'type' => $line->type->value,
                'label' => $line->label,
                'description' => $line->description,
                'quantity_milli' => $line->quantityMilli,
                'unit' => $line->unit,
                'unit_price_ht_cents' => $line->unitPriceHt,
                'discount_bp' => $line->discountBp,
                'vat_rate_bp' => $line->vatRate->value,
                'total_ht_cents' => $line->totalHt()->amount,
            ]);
        }
        if (null !== $version->sentAt) {
            $this->connection->executeStatement('UPDATE quote.quote_version SET sent_at = :at WHERE id = :id', ['at' => self::date($version->sentAt), 'id' => $versionId]);
        }
    }

    /** @param array<string, string> $params */
    private function load(string $sql, array $params): ?Quote
    {
        $row = $this->connection->fetchAssociative($sql, $params);
        if (false === $row) {
            return null;
        }
        $versions = [];
        foreach ($this->connection->fetchAllAssociative('SELECT * FROM quote.quote_version WHERE quote_id = :q ORDER BY version', ['q' => $row['id']]) as $version) {
            $lines = array_map(static fn (array $l): QuoteLine => new QuoteLine(
                LineType::from(DbalValue::string($l['type'])),
                DbalValue::string($l['label']),
                DbalValue::nullableString($l['description']),
                DbalValue::int($l['quantity_milli']),
                DbalValue::nullableString($l['unit']),
                DbalValue::int($l['unit_price_ht_cents']),
                DbalValue::int($l['discount_bp']),
                VatRate::from(DbalValue::int($l['vat_rate_bp'])),
            ), $this->connection->fetchAllAssociative('SELECT * FROM quote.quote_line WHERE quote_version_id = :v ORDER BY position', ['v' => $version['id']]));
            $c = DbalValue::json($version['conditions']);
            $c = \is_array($c) ? $c : [];
            $text = static fn (string $k): ?string => \is_string($c[$k] ?? null) ? $c[$k] : null;
            $deposit = null !== $version['deposit_percent_bp'] ? Deposit::percent(DbalValue::int($version['deposit_percent_bp']))
                : (null !== $version['deposit_amount_cents'] ? Deposit::amount(DbalValue::int($version['deposit_amount_cents'])) : null);
            $versions[] = new QuoteVersion(
                DbalValue::int($version['version']),
                new QuoteContent(
                    DbalValue::string($version['subject']),
                    $lines,
                    new QuoteConditions(
                        \is_int($c['validity_days'] ?? null) ? $c['validity_days'] : QuoteConditions::VALIDITY_MIN,
                        $text('execution_delay'),
                        null === $text('start_date') ? null : new \DateTimeImmutable($text('start_date')),
                        $text('payment_terms'),
                        array_values(array_filter(\is_array($c['payment_methods'] ?? null) ? $c['payment_methods'] : [], \is_string(...))),
                        $text('guarantees'),
                        $text('special_conditions'),
                        $text('client_note'),
                    ),
                    DbalValue::int($version['discount_bp']),
                    $deposit,
                    true === $version['vat_exempt'],
                ),
                new \DateTimeImmutable(DbalValue::string($version['created_at'])),
                null === $version['sent_at'] ? null : new \DateTimeImmutable(DbalValue::string($version['sent_at'])),
                null === $version['pdf_key'] ? null : new QuotePdf(DbalValue::string($version['pdf_key']), DbalValue::string($version['pdf_sha256'])),
            );
        }
        if ([] === $versions) {
            return null;
        }
        $date = static fn (mixed $v): ?\DateTimeImmutable => null === $v ? null : new \DateTimeImmutable(DbalValue::string($v));

        return Quote::restore(
            new QuoteId(DbalValue::string($row['id'])),
            DbalValue::string($row['request_id']),
            DbalValue::string($row['artisan_id']),
            QuoteStatus::from(DbalValue::string($row['status'])),
            DbalValue::nullableString($row['number']),
            $versions,
            new \DateTimeImmutable(DbalValue::string($row['created_at'])),
            new \DateTimeImmutable(DbalValue::string($row['updated_at'])),
            $date($row['sent_at']),
            $date($row['viewed_at']),
            $date($row['decided_at']),
            DbalValue::nullableString($row['decision_reason']),
            $date($row['valid_until']),
            DbalValue::int($row['reminder_count']),
            $date($row['reminded_at']),
        );
    }

    private static function date(?\DateTimeImmutable $at): ?string
    {
        return $at?->format('Y-m-d\TH:i:s.uP');
    }
}
