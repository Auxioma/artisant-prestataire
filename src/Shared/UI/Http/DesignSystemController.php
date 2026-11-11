<?php

declare(strict_types=1);

namespace App\Shared\UI\Http;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Planche de référence des design tokens (T-108), pour comparaison visuelle avec la bibliothèque Figma V2.
 * Route déclarée uniquement en environnement de développement.
 */
final class DesignSystemController extends AbstractController
{
    private const string SPRITE = 'assets/icons/sprite.svg';

    #[Route('/_dev/design-system', name: 'dev_design_system', methods: ['GET'], env: 'dev')]
    public function __invoke(#[Autowire('%kernel.project_dir%')] string $projectDir): Response
    {
        $sprite = (string) file_get_contents($projectDir.'/'.self::SPRITE);
        preg_match_all('/<symbol id="([A-Za-z]+)"/', $sprite, $matches);

        return $this->render('dev/design_system.html.twig', ['icons' => $matches[1]]);
    }
}
