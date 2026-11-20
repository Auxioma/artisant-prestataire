<?php

declare(strict_types=1);

namespace App\Administration\UI\Http;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/** CDC BO15 : l'adresse /admin/finance mène à l'onglet Finance de /admin/logs. */
final class AdminFinanceController extends AbstractController
{
    #[Route('/admin/finance', name: 'admin_finance', methods: ['GET'])]
    #[IsGranted('admin.logs.view')]
    public function __invoke(Request $request): Response
    {
        return $this->redirectToRoute('admin_logs', ['onglet' => 'finance'] + $request->query->all(), Response::HTTP_MOVED_PERMANENTLY);
    }
}
