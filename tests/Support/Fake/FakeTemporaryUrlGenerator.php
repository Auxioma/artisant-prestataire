<?php

declare(strict_types=1);

namespace App\Tests\Support\Fake;

use League\Flysystem\Config;
use League\Flysystem\UrlGeneration\TemporaryUrlGenerator;

/** URL « signée » de test : expose l'échéance et les options demandées pour les assertions. */
final class FakeTemporaryUrlGenerator implements TemporaryUrlGenerator
{
    public function temporaryUrl(string $path, \DateTimeInterface $expiresAt, Config $config): string
    {
        $options = $config->get('get_object_options', []);

        return 'https://s3.test/'.$path.'?'.http_build_query(['expires' => $expiresAt->getTimestamp()] + (\is_array($options) ? $options : []));
    }
}
