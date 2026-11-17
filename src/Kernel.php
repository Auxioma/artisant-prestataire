<?php

declare(strict_types=1);

namespace App;

use App\Shared\Infrastructure\DependencyInjection\ExternalHttpClientsPass;
use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\HttpKernel\Kernel as BaseKernel;

class Kernel extends BaseKernel
{
    use MicroKernelTrait;

    protected function build(ContainerBuilder $container): void
    {
        $container->addCompilerPass(new ExternalHttpClientsPass());
    }

    /**
     * Symfony 8.1 (recette framework-bundle) : toute autre valeur d'APP_ENV est refusée au démarrage.
     *
     * @return list<string>
     */
    private function getAllowedEnvs(): array
    {
        return ['prod', 'dev', 'test'];
    }
}
