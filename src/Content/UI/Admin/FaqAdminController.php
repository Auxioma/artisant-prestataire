<?php

declare(strict_types=1);

namespace App\Content\UI\Admin;

use App\Content\Application\Faq\Admin\FaqAdminCommand;
use App\Content\Application\Faq\Admin\FaqAdminHandler;
use App\Content\Application\Faq\Admin\FaqAdminQuery;
use App\Content\Application\Faq\Admin\FaqAdminQuestion;
use App\Content\Application\Faq\Admin\FaqAdminRejected;
use App\Content\Application\Faq\Admin\FaqAdminTopic;
use App\Shared\Application\Bus\CommandBus;
use App\Shared\Application\Bus\QueryBus;
use App\Shared\Application\Security\CurrentAdministrator;
use App\Shared\Domain\Identifier\IdGenerator;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * BO10.4 « FAQ / Aide » (/admin/cms/faq) : rubriques (titre, description, icône, active, ordre), questions (public,
 * réponse, publication, ordre, déplacement) et statistiques de votes « Cette réponse vous a-t-elle aidé ? ». P-131.
 */
#[Route('/admin/cms/faq')]
final class FaqAdminController extends AbstractController
{
    private const string CSRF = 'admin_cms_faq';

    public function __construct(private readonly CommandBus $commands, private readonly QueryBus $queries, private readonly CurrentAdministrator $admin, private readonly IdGenerator $ids)
    {
    }

    #[Route('', name: 'admin_cms_faq', methods: ['GET'])]
    #[IsGranted('admin.cms.view')]
    public function index(Request $request): Response
    {
        $first = $this->queries->ask(new FaqAdminQuery());
        \assert(\is_array($first));
        /** @var list<FaqAdminTopic> $topics */
        $topics = $first['topics'];
        $selected = $request->query->getString('rubrique');
        $current = null;
        foreach ($topics as $topic) {
            $current = $topic->id === $selected ? $topic : $current;
        }
        $current ??= $topics[0] ?? null;
        $data = null === $current ? ['questions' => []] : $this->queries->ask(new FaqAdminQuery($current->id));
        \assert(\is_array($data));
        /** @var list<FaqAdminQuestion> $questions */
        $questions = $data['questions'];

        return $this->render('admin/cms/faq.html.twig', [
            'topics' => $topics,
            'current' => $current,
            'questions' => $questions,
            'icons' => FaqAdminHandler::ICONS,
            'audiences' => FaqAdminQuestion::AUDIENCES,
            'csrf_id' => self::CSRF,
        ]);
    }

    #[Route('', name: 'admin_cms_faq_action', methods: ['POST'])]
    #[IsGranted('admin.cms.edit')]
    public function action(Request $request): Response
    {
        if (!$this->isCsrfTokenValid(self::CSRF, $request->request->getString('_token'))) {
            throw new AccessDeniedHttpException();
        }
        $action = $request->request->getString('action');
        if (!\in_array($action, FaqAdminCommand::ACTIONS, true)) {
            throw new NotFoundHttpException();
        }
        /** @var array<string, mixed> $data */
        $data = $request->request->all('data');
        foreach (['active', 'published'] as $flag) {
            $data[$flag] = $request->request->getBoolean($flag);
        }
        $id = $request->request->getString('id') ?: $this->ids->next();
        $topic = $request->request->getString('topic');
        try {
            $this->commands->dispatch(new FaqAdminCommand($action, $id, $this->admin->id(), $data, '' === $topic ? null : $topic));
            $this->addFlash('success', str_ends_with($action, 'delete') ? 'Élément supprimé.' : 'Aide mise à jour.');
        } catch (FaqAdminRejected $e) {
            $this->addFlash('error', $e->getMessage());
        }
        $rubrique = str_starts_with($action, 'topic') && 'topic_delete' !== $action ? $id : ($topic ?: null);

        return $this->redirectToRoute('admin_cms_faq', null === $rubrique ? [] : ['rubrique' => $rubrique], Response::HTTP_SEE_OTHER);
    }
}
