<?php

declare(strict_types=1);

namespace App\Tests\Integration\Notification;

use App\Notification\Application\Admin\DeliveryLogPage;
use App\Notification\Application\Admin\DeliveryLogQuery;
use App\Notification\Application\Admin\PreviewTemplate;
use App\Notification\Application\Admin\TemplateAdminCommand;
use App\Notification\Application\Admin\TemplateAdminQuery;
use App\Notification\Application\Admin\TemplateAdminRejected;
use App\Notification\Application\Admin\TemplateDetail;
use App\Shared\Application\Bus\CommandBus;
use App\Shared\Application\Bus\QueryBus;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\Exception\HandlerFailedException;

/** T-802a — BO11.1 : édition versionnée validée par rendu, restauration, aperçu, envoi de test ; BO11.2 : journal filtré. */
final class TemplateAdminTest extends KernelTestCase
{
    private const string ADMIN = '0190f5e4-0000-7000-8000-0000000a8f01';

    private CommandBus $commands;
    private QueryBus $queries;

    protected function setUp(): void
    {
        self::bootKernel();
        $commands = self::getContainer()->get(CommandBus::class);
        $queries = self::getContainer()->get(QueryBus::class);
        \assert($commands instanceof CommandBus && $queries instanceof QueryBus);
        $this->commands = $commands;
        $this->queries = $queries;
    }

    private function template(string $code): TemplateDetail
    {
        $result = $this->queries->ask(new TemplateAdminQuery($code));
        \assert(\is_array($result) && $result['template'] instanceof TemplateDetail);

        return $result['template'];
    }

    /** @param array<string, mixed> $data */
    private function rejected(string $action, array $data): string
    {
        try {
            $this->commands->dispatch(new TemplateAdminCommand($action, 'E-04', self::ADMIN, $data));
        } catch (TemplateAdminRejected|HandlerFailedException $e) {
            return $e->getMessage();
        }
        self::fail('refus attendu');
    }

    public function testEditValidateRestorePreviewAndTest(): void
    {
        $before = $this->template('E-04');
        $content = ['subject' => 'Mot de passe modifié, {{ prenom }}', 'preheader' => '', 'body_html' => '<p>Bonjour {{ prenom }}, modifié le {{ date|date("d/m/Y") }}.</p>', 'body_text' => 'Bonjour {{ prenom }}.', 'in_app_body' => 'Mot de passe modifié.', 'active' => true];

        self::assertStringContainsString('Modèle invalide', $this->rejected('save', ['body_html' => '<p>{{ prenom </p>'] + $content));
        self::assertStringContainsString('objet, un corps HTML et une version texte', $this->rejected('save', ['body_text' => ''] + $content));

        $preview = $this->queries->ask(new PreviewTemplate('E-04', $content));
        \assert(\is_array($preview));
        self::assertSame('Mot de passe modifié, Camille', $preview['subject']);
        self::assertNull($preview['error']);

        $this->commands->dispatch(new TemplateAdminCommand('save', 'E-04', self::ADMIN, $content));
        $after = $this->template('E-04');
        self::assertSame($before->version + 1, $after->version);
        self::assertTrue($after->customized);
        self::assertSame('Mot de passe modifié, {{ prenom }}', $after->subject);

        $this->commands->dispatch(new TemplateAdminCommand('restore', 'E-04', self::ADMIN, ['version' => $before->version]));
        $restored = $this->template('E-04');
        self::assertSame($before->subject, $restored->subject);
        self::assertSame($before->version + 2, $restored->version, 'la restauration crée une nouvelle version');

        $this->commands->dispatch(new TemplateAdminCommand('test', 'E-04', self::ADMIN, ['to' => 'admin.test@exemple.test']));
        $log = $this->queries->ask(new DeliveryLogQuery(recipient: 'Admin.Test@exemple.test'));
        \assert($log instanceof DeliveryLogPage);
        self::assertSame(1, $log->total);
        self::assertSame(['E-04', 'sent', 'a***@exemple.test'], [$log->rows[0]['code'], $log->rows[0]['status'], $log->rows[0]['recipient']]);
        $mail = self::getMailerMessage();
        self::assertInstanceOf(\Symfony\Component\Mime\Email::class, $mail);
        self::assertSame('[TEST] '.str_replace('{{ prenom }}', 'Camille', (string) $restored->subject), $mail->getSubject());
    }
}
