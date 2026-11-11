<?php

declare(strict_types=1);

namespace App\Identity\Infrastructure\Turnstile;

use App\Identity\Application\Port\HumanVerification;
use App\Shared\Application\Integration\ExternalServiceUnavailable;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\DependencyInjection\Attribute\Target;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/** Cloudflare Turnstile — validation serveur obligatoire (`siteverify`) ; un jeton côté client seul ne prouve rien. */
final readonly class TurnstileHumanVerification implements HumanVerification
{
    private const string SERVICE = 'turnstile';
    private const int MAX_TOKEN_LENGTH = 2048;
    /** Erreurs de configuration ou du fournisseur : ne pas les confondre avec un échec du visiteur. */
    private const array PROVIDER_ERRORS = ['missing-input-secret', 'invalid-input-secret', 'internal-error'];

    public function __construct(
        #[Target('turnstile.client')]
        private HttpClientInterface $client,
        #[Autowire('%env(TURNSTILE_SECRET_KEY)%')]
        private string $secret,
        private LoggerInterface $logger = new NullLogger(),
    ) {
    }

    public function verify(string $token, ?string $remoteIp = null, ?string $expectedAction = null): bool
    {
        if ('' === $this->secret) {
            throw new ExternalServiceUnavailable(self::SERVICE, 'clé secrète non configurée');
        }
        $token = trim($token);
        if ('' === $token || \strlen($token) > self::MAX_TOKEN_LENGTH) {
            return false;
        }

        $body = ['secret' => $this->secret, 'response' => $token];
        if (null !== $remoteIp && false !== filter_var($remoteIp, \FILTER_VALIDATE_IP)) {
            $body['remoteip'] = $remoteIp;
        }

        try {
            $data = $this->client->request('POST', 'siteverify', ['body' => $body])->toArray(false);
        } catch (ExceptionInterface $e) {
            $this->logger->warning('Turnstile injoignable.', ['error' => $e->getMessage()]);
            throw new ExternalServiceUnavailable(self::SERVICE, $e->getMessage(), $e);
        }

        $errors = \is_array($data['error-codes'] ?? null) ? array_values(array_filter($data['error-codes'], \is_string(...))) : [];
        $providerErrors = array_values(array_intersect($errors, self::PROVIDER_ERRORS));
        if ([] !== $providerErrors) {
            $this->logger->error('Turnstile : erreur de configuration ou du fournisseur.', ['errors' => $providerErrors]);
            throw new ExternalServiceUnavailable(self::SERVICE, implode(', ', $providerErrors));
        }
        if (true !== ($data['success'] ?? null)) {
            return false;
        }

        return null === $expectedAction || !isset($data['action']) || $expectedAction === $data['action'];
    }
}
