<?php

declare(strict_types=1);

namespace App\Quote\UI\Http;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/** C10 « Mes rendez-vous » (Figma 324:17351) et prise de rendez-vous C03.6, côté client. */
#[Route('/espace-client/rendez-vous')]
final class ClientAppointmentsController extends AppointmentsController
{
    #[Route('', name: 'client_appointments', methods: ['GET'])]
    public function index(Request $request): Response
    {
        return $this->list($request);
    }

    #[Route('/nouveau', name: 'client_appointment_new', methods: ['GET', 'POST'])]
    public function create(Request $request): Response
    {
        return $this->book($request);
    }

    #[Route('/{id}.ics', name: 'client_appointment_ics', requirements: ['id' => '[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}'], methods: ['GET'])]
    public function calendar(Request $request, string $id): Response
    {
        return $this->ics($request, $id);
    }

    #[Route('/{id}/action', name: 'client_appointment_action', requirements: ['id' => '[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}'], methods: ['POST'])]
    public function action(Request $request, string $id): Response
    {
        return $this->decide($request, $id);
    }

    protected function space(): string
    {
        return 'client';
    }

    protected function party(): string
    {
        return 'client';
    }

    protected function listTemplate(): string
    {
        return 'client/appointments/index.html.twig';
    }

    protected function layout(): string
    {
        return 'client/_space.html.twig';
    }
}
