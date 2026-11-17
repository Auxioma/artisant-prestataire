<?php

declare(strict_types=1);

namespace App\Billing\Infrastructure\Payment;

use Stripe\Exception\ApiConnectionException;
use Stripe\Exception\UnexpectedValueException;
use Stripe\HttpClient\ClientInterface;
use Stripe\Util\CaseInsensitiveArray;
use Stripe\Util\Util;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Transport de la bibliothèque officielle stripe-php confié au client Symfony `stripe.client` : hôte fixe, délais
 * bornés, réseau privé interdit (cadrage §5.3, ExternalHttpClientsPass) et simulable dans les tests (MockHttpClient).
 * Même construction d'URL et de corps que le client cURL de stripe-php.
 */
final readonly class SymfonyStripeHttpClient implements ClientInterface
{
    public function __construct(private HttpClientInterface $client)
    {
    }

    /**
     * @param 'delete'|'get'|'post'   $method
     * @param string                  $absUrl
     * @param list<string>            $headers
     * @param array<array-key, mixed> $params
     * @param bool                    $hasFile
     * @param 'v1'|'v2'               $apiMode
     * @param int|null                $maxNetworkRetries
     *
     * @return array{string, int, CaseInsensitiveArray}
     */
    public function request($method, $absUrl, $headers, $params, $hasFile, $apiMode = 'v1', $maxNetworkRetries = null): array
    {
        if ($hasFile) {
            throw new UnexpectedValueException('Envoi de fichiers vers Stripe non pris en charge.');
        }
        /** @var array<array-key, mixed> $params */
        $params = Util::objectsToIds($params);
        $options = ['headers' => self::headers($headers)];
        if ('post' === $method) {
            if ('v2' === $apiMode) {
                $options['body'] = [] === $params ? '' : json_encode($params, \JSON_THROW_ON_ERROR);
            } else {
                $options['body'] = Util::encodeParameters($params);
            }
        } elseif ([] !== $params) {
            $absUrl .= '?'.Util::encodeParameters($params, $apiMode);
        }

        try {
            $response = $this->client->request(strtoupper($method), $absUrl, $options);
            $body = $response->getContent(false);
            $responseHeaders = [];
            foreach ($response->getHeaders(false) as $name => $values) {
                $responseHeaders[$name] = implode(', ', $values);
            }

            return [$body, $response->getStatusCode(), new CaseInsensitiveArray($responseHeaders)];
        } catch (TransportExceptionInterface $e) {
            throw new ApiConnectionException('Stripe injoignable : '.$e->getMessage(), 0, $e);
        }
    }

    /**
     * « Nom: valeur » (format de stripe-php) → tableau associatif.
     *
     * @param list<string> $headers
     *
     * @return array<string, string>
     */
    private static function headers(array $headers): array
    {
        $parsed = [];
        foreach ($headers as $header) {
            $parts = explode(':', $header, 2);
            if (2 === \count($parts)) {
                $parsed[trim($parts[0])] = trim($parts[1]);
            }
        }

        return $parsed;
    }
}
