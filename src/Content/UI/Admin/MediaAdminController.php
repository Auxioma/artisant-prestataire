<?php

declare(strict_types=1);

namespace App\Content\UI\Admin;

use App\Content\Application\Media\DeleteMedia;
use App\Content\Application\Media\MediaList;
use App\Content\Application\Media\MediaRejected;
use App\Content\Application\Media\MediaView;
use App\Content\Application\Media\UpdateMedia;
use App\Content\Application\Media\UploadMedia;
use App\Shared\Application\Bus\CommandBus;
use App\Shared\Application\Bus\QueryBus;
use App\Shared\Application\Security\CurrentAdministrator;
use App\Shared\Domain\Identifier\IdGenerator;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * BO10.7 « Médiathèque » (/admin/cms/medias) : dépôt avec texte alternatif obligatoire, point focal (recadrage),
 * variantes WebP / AVIF automatiques, modification et suppression. Pas de maquette dédiée : P-129.
 */
#[Route('/admin/cms/medias')]
final class MediaAdminController extends AbstractController
{
    private const string CSRF = 'admin_cms_media';
    private const string UUID = '[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}';

    public function __construct(private readonly CommandBus $commands, private readonly QueryBus $queries, private readonly CurrentAdministrator $admin, private readonly IdGenerator $ids)
    {
    }

    #[Route('', name: 'admin_cms_media', methods: ['GET'])]
    #[IsGranted('admin.cms.view')]
    public function index(): Response
    {
        return $this->page([], []);
    }

    #[Route('', name: 'admin_cms_media_upload', methods: ['POST'])]
    #[IsGranted('admin.cms.edit')]
    public function upload(Request $request): Response
    {
        $this->assertCsrf($request);
        $file = $request->files->get('file');
        $values = ['title' => $request->request->getString('title'), 'alt' => $request->request->getString('alt'), 'focal' => $request->request->getString('focal', 'cc')];
        if (!$file instanceof UploadedFile) {
            return $this->page(['file' => 'Choisissez une image.'], $values);
        }
        try {
            $this->commands->dispatch(new UploadMedia($this->ids->next(), $this->admin->id(), $file->getPathname(), $file->getClientOriginalName(), $values['title'], $values['alt'], $values['focal']));
        } catch (MediaRejected $e) {
            return $this->page([$e->field => $e->getMessage()], $values);
        }
        $this->addFlash('success', 'Image déposée : ses variantes WebP et AVIF sont générées après l’analyse antivirus.');

        return $this->redirectToRoute('admin_cms_media', [], Response::HTTP_SEE_OTHER);
    }

    #[Route('/{id}', name: 'admin_cms_media_update', requirements: ['id' => self::UUID], methods: ['POST'])]
    #[IsGranted('admin.cms.edit')]
    public function update(Request $request, string $id): Response
    {
        $this->assertCsrf($request);
        try {
            if ('delete' === $request->request->getString('action')) {
                $this->commands->dispatch(new DeleteMedia($id, $this->admin->id()));
                $this->addFlash('success', 'Image supprimée (original et variantes détruits).');
            } else {
                $this->commands->dispatch(new UpdateMedia($id, $this->admin->id(), $request->request->getString('title'), $request->request->getString('alt'), $request->request->getString('focal', 'cc')));
                $this->addFlash('success', 'Image mise à jour.');
            }
        } catch (MediaRejected $e) {
            $this->addFlash('error', $e->getMessage());
        }

        return $this->redirectToRoute('admin_cms_media', [], Response::HTTP_SEE_OTHER);
    }

    /**
     * @param array<string, string> $errors
     * @param array<string, string> $values
     */
    private function page(array $errors, array $values): Response
    {
        $media = $this->queries->ask(new MediaList());

        return $this->render('admin/cms/media.html.twig', [
            'media' => \is_array($media) ? array_values(array_filter($media, static fn (mixed $m): bool => $m instanceof MediaView)) : [],
            'focals' => MediaView::FOCALS,
            'errors' => $errors,
            'values' => $values,
            'csrf_id' => self::CSRF,
        ], new Response(status: [] === $errors ? Response::HTTP_OK : Response::HTTP_UNPROCESSABLE_ENTITY));
    }

    private function assertCsrf(Request $request): void
    {
        if (!$this->isCsrfTokenValid(self::CSRF, $request->request->getString('_token'))) {
            throw new AccessDeniedHttpException();
        }
    }
}
