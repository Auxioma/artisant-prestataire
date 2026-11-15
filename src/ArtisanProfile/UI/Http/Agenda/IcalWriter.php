<?php

declare(strict_types=1);

namespace App\ArtisanProfile\UI\Http\Agenda;

use App\ArtisanProfile\Application\Agenda\IcalFeedView;

/** Calendrier iCalendar (RFC 5545) : lignes CRLF repliées à 75 octets, texte échappé, horaires en UTC, journées en DATE. */
final class IcalWriter
{
    private const int FOLD = 75;

    public static function write(IcalFeedView $feed, \DateTimeImmutable $now, string $host): string
    {
        $stamp = $now->setTimezone(new \DateTimeZone('UTC'))->format('Ymd\THis\Z');
        $lines = ['BEGIN:VCALENDAR', 'VERSION:2.0', 'PRODID:-//TrouveMoi//Agenda artisan//FR', 'CALSCALE:GREGORIAN', 'METHOD:PUBLISH', 'X-WR-CALNAME:'.self::text('TrouveMoi — '.$feed->name), 'X-WR-TIMEZONE:Europe/Paris'];
        foreach ($feed->events as $e) {
            $lines[] = 'BEGIN:VEVENT';
            $lines[] = 'UID:'.$e['uid'].'@'.$host;
            $lines[] = 'DTSTAMP:'.$stamp;
            if ($e['all_day']) {
                $lines[] = 'DTSTART;VALUE=DATE:'.$e['start']->format('Ymd');
                $lines[] = 'DTEND;VALUE=DATE:'.$e['end']->format('Ymd');
                $lines[] = 'TRANSP:TRANSPARENT';
            } else {
                $lines[] = 'DTSTART:'.$e['start']->setTimezone(new \DateTimeZone('UTC'))->format('Ymd\THis\Z');
                $lines[] = 'DTEND:'.$e['end']->setTimezone(new \DateTimeZone('UTC'))->format('Ymd\THis\Z');
            }
            $lines[] = 'SUMMARY:'.self::text($e['summary']);
            $lines[] = 'END:VEVENT';
        }
        $lines[] = 'END:VCALENDAR';

        return implode("\r\n", array_map(self::fold(...), $lines))."\r\n";
    }

    private static function text(string $value): string
    {
        return str_replace(['\\', ';', ',', "\r\n", "\n"], ['\\\\', '\;', '\,', '\n', '\n'], $value);
    }

    /** Repli des lignes longues (RFC 5545 §3.1) sans couper un caractère UTF-8. */
    private static function fold(string $line): string
    {
        $out = '';
        $current = '';
        foreach (mb_str_split($line) as $char) {
            if (\strlen($current) + \strlen($char) > self::FOLD) {
                $out .= $current."\r\n ";
                $current = '';
            }
            $current .= $char;
        }

        return $out.$current;
    }
}
