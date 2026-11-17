<?php

declare(strict_types=1);

namespace App\Messaging\UI\Http;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/** C04 (/espace-client/messages, Figma 160:644) — messagerie du client. */
#[Route('/espace-client/messages')]
final class ClientMessagesController extends MessagesController
{
    private const string UUID = '[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}';

    #[Route('', name: 'client_messages', methods: ['GET'])]
    public function index(Request $request): Response
    {
        return $this->page($request, null);
    }

    #[Route('/nouveau', name: 'client_message_new', methods: ['GET', 'POST'])]
    public function create(Request $request): Response
    {
        return $this->compose($request);
    }

    #[Route('/{id}', name: 'client_conversation', requirements: ['id' => self::UUID], methods: ['GET'])]
    public function show(Request $request, string $id): Response
    {
        return $this->page($request, $id);
    }

    #[Route('/{id}', name: 'client_conversation_send', requirements: ['id' => self::UUID], methods: ['POST'])]
    public function post(Request $request, string $id): Response
    {
        return $this->send($request, $id);
    }

    #[Route('/{id}/fil', name: 'client_conversation_messages', requirements: ['id' => self::UUID], methods: ['GET'])]
    public function messages(string $id): Response
    {
        return $this->fragment($id);
    }

    #[Route('/{id}/pieces-jointes/{file}', name: 'client_conversation_attachment', requirements: ['id' => self::UUID, 'file' => self::UUID], methods: ['GET'])]
    public function download(Request $request, string $id, string $file): Response
    {
        return $this->attachment($request, $id, $file);
    }

    #[Route('/{id}/action', name: 'client_conversation_action', requirements: ['id' => self::UUID], methods: ['POST'])]
    public function action(Request $request, string $id): Response
    {
        return $this->act($request, $id);
    }

    protected function space(): string
    {
        return 'client';
    }

    protected function template(): string
    {
        return 'client/messages/index.html.twig';
    }
}
