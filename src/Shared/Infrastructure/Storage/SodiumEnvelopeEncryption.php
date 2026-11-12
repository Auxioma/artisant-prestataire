<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Storage;

use App\Shared\Application\Security\SecretBox;
use App\Shared\Application\Storage\Port\EnvelopeEncryption;
use App\Shared\Application\Storage\WrappedKey;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * ADR-0008 : DEK aléatoire par fichier, contenu chiffré par `crypto_secretstream_xchacha20poly1305` (blocs de 64 Kio,
 * authentifiés, troncature détectée) ; DEK enveloppée par `crypto_secretbox` avec la KEK courante.
 * KEK versionnées hors base (`STORAGE_KEKS` = « id:base64;id:base64 », via secrets:set) : la rotation ajoute une
 * nouvelle KEK courante, les anciennes restent pour la lecture (re-enveloppement possible sans rechiffrer les fichiers).
 */
final class SodiumEnvelopeEncryption implements EnvelopeEncryption, SecretBox
{
    private const int CHUNK = 65_536;
    private const int MIN_DISTINCT_BYTES = 16;

    /** @var array<string, string> */
    private array $keks = [];

    public function __construct(
        #[Autowire('%env(STORAGE_KEKS)%')]
        string $keks,
        #[Autowire('%env(STORAGE_KEK_CURRENT)%')]
        private readonly string $currentKekId,
        #[Autowire('%kernel.environment%')]
        private readonly string $environment,
    ) {
        foreach (array_filter(array_map(trim(...), explode(';', $keks))) as $entry) {
            [$id, $encoded] = array_pad(explode(':', $entry, 2), 2, '');
            $key = base64_decode($encoded, true);
            if ('' === $id || !\is_string($key) || \SODIUM_CRYPTO_SECRETBOX_KEYBYTES !== \strlen($key)) {
                throw new \InvalidArgumentException(\sprintf('STORAGE_KEKS : entrée « %s » invalide (32 octets en base64 attendus).', $id));
            }
            $this->keks[$id] = $key;
        }
    }

    public function seal(#[\SensitiveParameter] string $secret): string
    {
        $nonce = random_bytes(\SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);

        return $this->currentKekId.':'.sodium_bin2base64($nonce.sodium_crypto_secretbox($secret, $nonce, $this->currentKek()), \SODIUM_BASE64_VARIANT_ORIGINAL);
    }

    public function open(string $sealed): string
    {
        [$kekId, $encoded] = array_pad(explode(':', $sealed, 2), 2, '');
        try {
            $raw = sodium_base642bin($encoded, \SODIUM_BASE64_VARIANT_ORIGINAL);
        } catch (\SodiumException) {
            throw new \RuntimeException('Secret illisible.');
        }
        $plain = sodium_crypto_secretbox_open(substr($raw, \SODIUM_CRYPTO_SECRETBOX_NONCEBYTES), substr($raw, 0, \SODIUM_CRYPTO_SECRETBOX_NONCEBYTES), $this->kek($kekId));
        if (false === $plain) {
            throw new \RuntimeException('Secret illisible.');
        }

        return $plain;
    }

    public function encrypt($plain, $cipher): WrappedKey
    {
        $kek = $this->currentKek();

        $dek = sodium_crypto_secretstream_xchacha20poly1305_keygen();
        [$state, $header] = sodium_crypto_secretstream_xchacha20poly1305_init_push($dek);
        \assert(\is_string($state) && \is_string($header));
        self::write($cipher, $header);
        $chunk = self::readExactly($plain, self::CHUNK);
        while (true) {
            $next = '' === $chunk ? '' : self::readExactly($plain, self::CHUNK);
            $final = '' === $next;
            self::write($cipher, sodium_crypto_secretstream_xchacha20poly1305_push($state, $chunk, '', $final ? \SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_FINAL : \SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_MESSAGE));
            if ($final) {
                break;
            }
            $chunk = $next;
        }

        $nonce = random_bytes(\SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $wrapped = $nonce.sodium_crypto_secretbox($dek, $nonce, $kek);
        sodium_memzero($dek);

        return new WrappedKey($this->currentKekId, $wrapped);
    }

    public function decrypt($cipher, $plain, WrappedKey $key): void
    {
        $nonce = substr($key->wrappedDek, 0, \SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $dek = sodium_crypto_secretbox_open(substr($key->wrappedDek, \SODIUM_CRYPTO_SECRETBOX_NONCEBYTES), $nonce, $this->kek($key->kekId));
        if (false === $dek) {
            throw new \RuntimeException('Clé de données illisible.');
        }
        $header = self::readExactly($cipher, \SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_HEADERBYTES);
        if (\SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_HEADERBYTES !== \strlen($header)) {
            throw new \RuntimeException('Fichier chiffré tronqué.');
        }
        $state = sodium_crypto_secretstream_xchacha20poly1305_init_pull($header, $dek);
        sodium_memzero($dek);
        do {
            $block = self::readExactly($cipher, self::CHUNK + \SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_ABYTES);
            $result = '' === $block ? false : sodium_crypto_secretstream_xchacha20poly1305_pull($state, $block);
            if (false === $result) {
                throw new \RuntimeException('Fichier chiffré altéré ou tronqué.');
            }
            [$data, $tag] = $result;
            \assert(\is_string($data));
            self::write($plain, $data);
        } while (\SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_FINAL !== $tag);
        if ('' !== self::readExactly($cipher, 1)) {
            throw new \RuntimeException('Données inattendues après la fin du fichier chiffré.');
        }
    }

    private function kek(string $id): string
    {
        return $this->keks[$id] ?? throw new \RuntimeException(\sprintf('Clé maîtresse « %s » inconnue.', $id));
    }

    /** @param resource $stream */
    private static function readExactly($stream, int $length): string
    {
        $buffer = '';
        while (\strlen($buffer) < $length && !feof($stream)) {
            $read = fread($stream, max(1, $length - \strlen($buffer)));
            if (false === $read) {
                throw new \RuntimeException('Lecture du flux impossible.');
            }
            if ('' === $read && feof($stream)) {
                break;
            }
            $buffer .= $read;
        }

        return $buffer;
    }

    /** @param resource $stream */
    private static function write($stream, string $data): void
    {
        if (false === fwrite($stream, $data)) {
            throw new \RuntimeException('Écriture du flux impossible.');
        }
    }

    private function currentKek(): string
    {
        $kek = $this->kek($this->currentKekId);
        if ('prod' === $this->environment && \count(array_unique(str_split($kek))) < self::MIN_DISTINCT_BYTES) {
            throw new \RuntimeException('KEK de développement refusée en production.');
        }

        return $kek;
    }
}
