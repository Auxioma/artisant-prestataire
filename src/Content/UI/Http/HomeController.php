<?php

declare(strict_types=1);

namespace App\Content\UI\Http;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Accueil PROVISOIRE : évite une 404 à la racine pendant le développement. Remplacé par la page d'accueil P01
 * (10 blocs administrables, Figma) au lot 3. Non indexable.
 */
final class HomeController extends AbstractController
{
    #[Route('/', name: 'public_home', methods: ['GET'])]
    public function __invoke(): Response
    {
        $response = $this->render('content/home_provisional.html.twig');
        $response->headers->set('X-Robots-Tag', 'noindex');

        return $response;
    }
}
