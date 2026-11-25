<?php

declare(strict_types=1);

namespace App\Content\UI\Http;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Plan du site HTML (maquette Mondesir « Plan du site — Desktop 1440 · V2 ») : pages publiques classées par rubriques,
 * barre d'accès rapide. Lien « Plan du site » du pied de page (menu footer_info). Une page dont la route n'existe pas
 * encore (blog, V2) n'est pas listée : aucun lien mort.
 */
final class SitemapPageController extends AbstractController
{
    #[Route('/plan-du-site', name: 'content_sitemap', methods: ['GET'])]
    public function __invoke(): Response
    {
        return $this->render('content/sitemap.html.twig');
    }
}
