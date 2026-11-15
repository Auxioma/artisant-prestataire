<?php

declare(strict_types=1);

namespace App\Quote\UI\Http;

use App\ArtisanProfile\Application\PublicProfile\ArtisanPhoneHandler;
use App\Quote\Application\Appointment\MyAppointmentsView;
use App\Shared\Application\Identity\UserPhone;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * PR06 « Mes rendez-vous » (Figma 232:13148) et PR06.2 : liste, indicateurs, agenda du jour, devis acceptés à planifier,
 * proposition d'un créneau de l'agenda, confirmation, report, annulation motivée, « Marquer réalisé ».
 */
#[Route('/espace-pro/rendez-vous')]
final class ProAppointmentsController extends AppointmentsController
{
    #[Route('', name: 'pro_appointments', methods: ['GET'])]
    public function index(Request $request): Response
    {
        return $this->list($request);
    }

    #[Route('/nouveau', name: 'pro_appointment_new', methods: ['GET', 'POST'])]
    public function create(Request $request): Response
    {
        return $this->book($request);
    }

    #[Route('/{id}/action', name: 'pro_appointment_action', requirements: ['id' => '[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}'], methods: ['POST'])]
    public function action(Request $request, string $id): Response
    {
        return $this->decide($request, $id);
    }

    protected function space(): string
    {
        return 'pro';
    }

    protected function party(): string
    {
        return 'artisan';
    }

    protected function listTemplate(): string
    {
        return 'pro/appointments/index.html.twig';
    }

    protected function layout(): string
    {
        return 'pro/_space.html.twig';
    }

    /** Téléphone des clients (révélé à l'acceptation du devis, RG-022bis). */
    protected function extra(MyAppointmentsView $view): array
    {
        $phones = [];
        foreach ($view->rows as $a) {
            if (\array_key_exists($a->clientUserId, $phones)) {
                continue;
            }
            $phone = $this->queries->ask(new UserPhone($a->clientUserId));
            $phones[$a->clientUserId] = \is_string($phone) ? ArtisanPhoneHandler::display($phone) : null;
        }

        return ['phones' => $phones];
    }
}
