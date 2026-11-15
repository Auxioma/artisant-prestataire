<?php

declare(strict_types=1);

namespace App\Quote\UI\Http;

use App\Quote\Application\Appointment\AppointmentDecision;
use App\Quote\Application\Appointment\BookableSlots;
use App\Quote\Application\Appointment\BookableSlotsView;
use App\Quote\Application\Appointment\MyAppointments;
use App\Quote\Application\Appointment\MyAppointmentsView;
use App\Quote\Application\Appointment\ProposeAppointment;
use App\Quote\Domain\Appointment\AppointmentRuleViolation;
use App\Shared\Application\Bus\CommandBus;
use App\Shared\Application\Bus\QueryBus;
use App\Shared\Application\Security\CurrentUser;
use App\Shared\Domain\Identifier\IdGenerator;
use App\Shared\Domain\Time\Clock;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Rendez-vous des deux espaces (C10 client, PR06 artisan) : liste filtrée, prise de rendez-vous sur l'agenda de
 * l'artisan (C03.6), décisions (confirmer, annuler avec motif, réalisé). L'espace fixe les routes et le gabarit.
 */
abstract class AppointmentsController extends AbstractController
{
    protected const string CSRF = 'appointments';
    protected const array TABS = ['a-venir' => 'upcoming', 'passes' => 'past', 'annules' => 'cancelled'];

    public function __construct(
        protected readonly QueryBus $queries,
        protected readonly CommandBus $commands,
        protected readonly CurrentUser $user,
        protected readonly IdGenerator $ids,
        protected readonly Clock $clock,
    ) {
    }

    /** `client` ou `pro` (préfixe des routes). */
    abstract protected function space(): string;

    /** @return 'client'|'artisan' */
    abstract protected function party(): string;

    abstract protected function listTemplate(): string;

    abstract protected function layout(): string;

    protected function list(Request $request): Response
    {
        $tab = \array_key_exists($request->query->getString('onglet'), self::TABS) ? $request->query->getString('onglet') : 'a-venir';
        $view = $this->queries->ask(new MyAppointments($this->userId(), $this->party(), self::TABS[$tab], $request->query->getString('q')));
        \assert($view instanceof MyAppointmentsView);
        $zone = new \DateTimeZone('Europe/Paris');
        $month = \DateTimeImmutable::createFromFormat('!Y-m', $request->query->getString('mois'), $zone) ?: $this->clock->now()->setTimezone($zone)->modify('first day of this month')->setTime(0, 0);

        return $this->render($this->listTemplate(), [
            'view' => $view,
            'tab' => $tab,
            'q' => $request->query->getString('q'),
            'month' => $month,
            'today' => $this->clock->now()->setTimezone($zone),
            'space' => $this->space(),
            'csrf_id' => self::CSRF,
        ] + $this->extra($view));
    }

    /**
     * Données propres à l'espace (téléphones des clients côté artisan…).
     *
     * @return array<string, mixed>
     */
    protected function extra(MyAppointmentsView $view): array
    {
        return [];
    }

    protected function book(Request $request): Response
    {
        $userId = $this->userId();
        $quoteId = $request->query->getString('devis');
        $slots = $this->queries->ask(new BookableSlots($userId, $quoteId));
        if (!$slots instanceof BookableSlotsView) {
            throw new NotFoundHttpException();
        }
        $error = null;
        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid(self::CSRF, $request->request->getString('_token'))) {
                throw new AccessDeniedHttpException();
            }
            try {
                $this->commands->dispatch(new ProposeAppointment($userId, $this->ids->next(), $quoteId, $request->request->getString('creneau'), $request->request->getString('note') ?: null, $slots->current?->id));
                $this->addFlash('success', 'Créneau proposé : il est réservé pour vous en attendant la confirmation.');

                return $this->redirectToRoute($this->space().'_appointments', [], Response::HTTP_SEE_OTHER);
            } catch (AppointmentRuleViolation $e) {
                $error = $e->getMessage();
            }
        }

        return $this->render('appointments/book.html.twig', [
            'slots' => $slots,
            'error' => $error,
            'chosen' => $request->request->getString('creneau'),
            'note' => $request->request->getString('note'),
            'space' => $this->space(),
            'layout' => $this->layout(),
            'csrf_id' => self::CSRF,
        ], new Response(null, null === $error ? Response::HTTP_OK : Response::HTTP_UNPROCESSABLE_ENTITY));
    }

    protected function decide(Request $request, string $id): Response
    {
        $userId = $this->userId();
        if (!$this->isCsrfTokenValid(self::CSRF, $request->request->getString('_token'))) {
            throw new AccessDeniedHttpException();
        }
        $action = $request->request->getString('action');
        try {
            $this->commands->dispatch(new AppointmentDecision($userId, $id, $action, $request->request->getString('motif') ?: null));
            $this->addFlash('success', [AppointmentDecision::CONFIRM => 'Rendez-vous confirmé.', AppointmentDecision::CANCEL => 'Rendez-vous annulé : le professionnel et le client sont prévenus.', AppointmentDecision::COMPLETE => 'Merci : la prestation est notée comme réalisée.'][$action] ?? 'C’est fait.');
        } catch (AppointmentRuleViolation $e) {
            $this->addFlash('error', $e->getMessage());
        }
        $back = $request->request->getString('retour');

        return $this->redirect(str_starts_with($back, '/espace-') && !str_starts_with($back, '//') ? $back : $this->generateUrl($this->space().'_appointments'), Response::HTTP_SEE_OTHER);
    }

    protected function userId(): string
    {
        return $this->user->id() ?? throw new AccessDeniedHttpException();
    }
}
