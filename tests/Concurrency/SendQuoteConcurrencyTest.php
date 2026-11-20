<?php

declare(strict_types=1);

namespace App\Tests\Concurrency;

use App\Shared\Infrastructure\Persistence\DbalValue;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;

/**
 * ADR-0006 / RG-101 — envois **simultanés** sur deux connexions réelles (deux processus démarrés ensemble par un verrou
 * consultatif). Base dédiée `…_test_concurrency` créée, migrée, remplie et commitée pour ce banc, puis supprimée : la
 * base de test partagée (transaction annulée après chaque test) n'est jamais touchée.
 *
 * 1. Double clic : le même devis envoyé deux fois en même temps → un seul envoi, un seul numéro, un seul débit, une
 *    seule place occupée.
 * 2. Dernier crédit : deux devis de l'artisan envoyés en même temps avec 1 crédit → un envoi, un refus « crédit »,
 *    solde à 0, aucun trou dans la numérotation.
 * 3. Dernière place (§4.2) : deux artisans envoient en même temps sur une demande à une place → un envoi, un refus
 *    « complète », un seul débit, le brouillon du perdant intact.
 */
final class SendQuoteConcurrencyTest extends TestCase
{
    private const string TOKEN = '_concurrency';
    private const int BARRIER = 504_003;
    private const int WAIT_MS = 30_000;

    private static ?Connection $db = null;
    /** @var list<string> profils A, B, C */
    private static array $artisans = [];

    public static function setUpBeforeClass(): void
    {
        foreach (['doctrine:database:drop --force --if-exists', 'doctrine:database:create', 'doctrine:migrations:migrate --no-interaction --allow-no-migration'] as $command) {
            self::shell('bin/console '.$command.' --env=test -q');
        }
        self::$artisans = explode(',', trim(self::shell('php tests/Concurrency/bin/fixture.php')));
        self::$db = self::connect();
    }

    public static function tearDownAfterClass(): void
    {
        self::$db?->close();
        self::$db = null;
        self::shell('bin/console doctrine:database:drop --force --if-exists --env=test -q');
    }

    public function testDoubleClickSendsOnce(): void
    {
        [$a, $b] = $this->race([[0, ConcurrencyFixture::REQUESTS[0]], [0, ConcurrencyFixture::REQUESTS[0]]]);

        self::assertEqualsCanonicalizing(['sent', 'already_sent'], [$a, $b]);
        self::assertSame(['sent', $this->number(1)], array_values((array) $this->db()->fetchAssociative('SELECT status, number FROM quote.quote WHERE id = :id', ['id' => ConcurrencyFixture::QUOTES[0]])));
        self::assertSame(1, $this->int("SELECT count(*) FROM billing.credit_ledger_entry WHERE type = 'quote_debit' AND reference_id = '".ConcurrencyFixture::QUOTES[0]."'"));
        self::assertSame(1, $this->int("SELECT active_quotes_count FROM request.quote_request WHERE id = '".ConcurrencyFixture::REQUESTS[0]."'"), 'une seule place occupée');
        self::assertSame(1, $this->int("SELECT count(*) FROM quote.quote_version WHERE quote_id = '".ConcurrencyFixture::QUOTES[0]."'"));
        self::assertSame(1, $this->int("SELECT last FROM quote.quote_number_sequence WHERE artisan_id = '".self::$artisans[0]."'"));
        self::assertSame(ConcurrencyFixture::CREDITS - 1, $this->balance(0));
    }

    /** @depends testDoubleClickSendsOnce */
    public function testLastCreditGoesToOneQuote(): void
    {
        [$a, $b] = $this->race([[0, ConcurrencyFixture::REQUESTS[1]], [0, ConcurrencyFixture::REQUESTS[2]]]);

        self::assertEqualsCanonicalizing(['sent', 'no_credit'], [$a, $b]);
        $statuses = $this->db()->fetchFirstColumn('SELECT status FROM quote.quote WHERE id IN (:a, :b) ORDER BY status', ['a' => ConcurrencyFixture::QUOTES[1], 'b' => ConcurrencyFixture::QUOTES[2]]);
        self::assertSame(['draft', 'sent'], $statuses, 'le refusé reste en brouillon');
        self::assertSame(0, $this->balance(0), 'jamais de solde négatif');
        self::assertSame(ConcurrencyFixture::CREDITS, $this->int("SELECT count(*) FROM billing.credit_ledger_entry WHERE type = 'quote_debit' AND artisan_id = '".self::$artisans[0]."'"));
        self::assertSame(1, $this->int("SELECT sum(active_quotes_count) FROM request.quote_request WHERE id IN ('".ConcurrencyFixture::REQUESTS[1]."', '".ConcurrencyFixture::REQUESTS[2]."')"));
        self::assertSame([$this->number(1), $this->number(2)], $this->db()->fetchFirstColumn('SELECT number FROM quote.quote WHERE number IS NOT NULL AND artisan_id = :a ORDER BY number', ['a' => self::$artisans[0]]), 'numérotation sans trou');
    }

    public function testLastPlaceGoesToOneArtisan(): void
    {
        $request = ConcurrencyFixture::REQUESTS[3];
        [$b, $c] = $this->race([[1, $request], [2, $request]]);

        self::assertEqualsCanonicalizing(['sent', 'request_full'], [$b, $c]);
        $winner = 'sent' === $b ? 1 : 2;
        $loser = 3 - $winner;
        $status = fn (int $i): string => DbalValue::string($this->db()->fetchOne('SELECT status FROM quote.quote WHERE id = :id', ['id' => ConcurrencyFixture::QUOTES[2 + $i]]));
        self::assertSame(['sent', 'draft'], [$status($winner), $status($loser)], 'le brouillon du perdant est intact');
        self::assertSame(['full', 1], array_values((array) $this->db()->fetchAssociative('SELECT status, active_quotes_count FROM request.quote_request WHERE id = :id', ['id' => $request])));
        self::assertSame([0, 1], [$this->balance($winner), $this->balance($loser)], 'seul le gagnant est débité');
        self::assertSame(1, $this->int("SELECT count(*) FROM billing.credit_ledger_entry WHERE type = 'quote_debit' AND artisan_id IN ('".self::$artisans[1]."', '".self::$artisans[2]."')"));
        self::assertSame(0, $this->int("SELECT count(*) FROM quote.quote_number_sequence WHERE artisan_id = '".self::$artisans[$loser]."'"), 'aucun numéro consommé par le perdant');
    }

    /**
     * Lance deux envois, attend qu'ils soient tous deux bloqués sur la barrière, puis les libère ensemble.
     *
     * @param list<array{int, string}> $sends artisan (0 = A, 1 = B, 2 = C) et demande
     *
     * @return list<string>
     */
    private function race(array $sends): array
    {
        $db = $this->db();
        $db->executeQuery('SELECT pg_advisory_lock(:k)', ['k' => self::BARRIER]);
        $processes = [];
        foreach ($sends as [$artisan, $request]) {
            $pipes = [];
            $process = proc_open(['php', 'tests/Concurrency/bin/send-quote.php', ConcurrencyFixture::USERS[$artisan], $request, (string) self::BARRIER], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, self::root(), self::env());
            self::assertIsResource($process);
            $processes[] = [$process, $pipes];
        }
        $deadline = hrtime(true) + self::WAIT_MS * 1_000_000;
        while (2 > $this->int("SELECT count(*) FROM pg_locks WHERE locktype = 'advisory' AND NOT granted AND objid = ".self::BARRIER)) {
            self::assertLessThan($deadline, hrtime(true), 'les deux processus doivent atteindre la barrière');
            usleep(20_000);
        }
        $db->executeQuery('SELECT pg_advisory_unlock(:k)', ['k' => self::BARRIER]);

        $results = [];
        foreach ($processes as [$process, $pipes]) {
            $out = (string) stream_get_contents($pipes[1]);
            $err = (string) stream_get_contents($pipes[2]);
            $code = proc_close($process);
            self::assertSame(0, $code, $out.$err);
            $results[] = trim($out);
        }

        return $results;
    }

    private function number(int $n): string
    {
        return \sprintf('DEV-%s-%04d', new \DateTimeImmutable('now', new \DateTimeZone('Europe/Paris'))->format('Y'), $n);
    }

    private function balance(int $artisan): int
    {
        return $this->int("SELECT free + plan + extra FROM billing.credit_balance WHERE artisan_id = '".self::$artisans[$artisan]."'");
    }

    private function int(string $sql): int
    {
        return DbalValue::int($this->db()->fetchOne($sql));
    }

    private function db(): Connection
    {
        return self::$db ?? throw new \LogicException('Base de concurrence absente.');
    }

    private static function connect(): Connection
    {
        $dsn = $_SERVER['DATABASE_URL'] ?? $_ENV['DATABASE_URL'] ?? null;
        \assert(\is_string($dsn));
        $url = parse_url($dsn);
        \assert(\is_array($url));

        return DriverManager::getConnection([
            'driver' => 'pdo_pgsql',
            'host' => $url['host'] ?? 'database',
            'port' => $url['port'] ?? 5432,
            'user' => urldecode($url['user'] ?? ''),
            'password' => urldecode($url['pass'] ?? ''),
            'dbname' => ltrim($url['path'] ?? '', '/').'_test'.self::TOKEN,
        ]);
    }

    private static function shell(string $command): string
    {
        $pipes = [];
        $process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, self::root(), self::env());
        self::assertIsResource($process);
        $out = (string) stream_get_contents($pipes[1]);
        $err = (string) stream_get_contents($pipes[2]);
        self::assertSame(0, proc_close($process), $command."\n".$out.$err);

        return $out;
    }

    /** @return array<string, string> */
    private static function env(): array
    {
        $env = array_filter(getenv(), \is_string(...));

        return ['APP_ENV' => 'test', 'TEST_TOKEN' => self::TOKEN] + array_diff_key($env, ['APP_ENV' => 1, 'APP_DEBUG' => 1, 'TEST_TOKEN' => 1]);
    }

    private static function root(): string
    {
        return \dirname(__DIR__, 2);
    }
}
