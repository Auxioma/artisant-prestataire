<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\DependencyInjection;

use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Reference;
use Symfony\Component\HttpClient\NoPrivateNetworkHttpClient;

/**
 * Anti-SSRF (cadrage §5.3, ASVS 5.2.6) : chaque client vers un service tiers refuse toute résolution vers un réseau
 * privé, local ou réservé — y compris après redirection ou rebond DNS. Les services internes (Meilisearch, ClamAV,
 * S3 de développement) n'en font pas partie et n'utilisent jamais ces clients.
 */
final class ExternalHttpClientsPass implements CompilerPassInterface
{
    /** Clients scoped de config/packages/framework.yaml qui sortent vers Internet. */
    public const array EXTERNAL_CLIENTS = ['geo_api.client', 'geocoding.client', 'sirene.client', 'vies.client', 'turnstile.client', 'hibp.client', 'stripe.client'];

    private const int DECORATION_PRIORITY = 32;

    public function process(ContainerBuilder $container): void
    {
        foreach (self::EXTERNAL_CLIENTS as $id) {
            if (!$container->hasDefinition($id) && !$container->hasAlias($id)) {
                throw new \LogicException(\sprintf('Client HTTP sortant « %s » non déclaré dans framework.http_client.scoped_clients.', $id));
            }
            $container->setDefinition($id.'.no_private_network', new Definition(NoPrivateNetworkHttpClient::class, [new Reference('.inner')]))
                // Priorité > 15 (ScopingHttpClient) : décorateur le plus proche du transport, donc appliqué à l'URL absolue
                // déjà résolue contre base_uri. Placé à l'extérieur, il rejetterait toute URL relative.
                ->setDecoratedService($id, null, self::DECORATION_PRIORITY);
        }

        // Contrainte NotCompromisedPassword (HIBP, k-anonymat) : client dédié au lieu du client par défaut.
        if ($container->hasDefinition('validator.not_compromised_password')) {
            $container->getDefinition('validator.not_compromised_password')->replaceArgument(0, new Reference('hibp.client'));
        }
    }
}
