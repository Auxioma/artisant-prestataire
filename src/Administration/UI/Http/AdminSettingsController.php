<?php

declare(strict_types=1);

namespace App\Administration\UI\Http;

use App\Administration\Application\Command\ChangeSetting;
use App\Administration\Application\Settings\SettingRubric;
use App\Administration\Application\Settings\SettingsPage;
use App\Administration\Application\Settings\SettingsPageView;
use App\Shared\Application\Bus\CommandBus;
use App\Shared\Application\Bus\QueryBus;
use App\Shared\Application\Security\CurrentAdministrator;
use App\Shared\Application\Settings\InvalidSettingValue;
use App\Shared\Application\Settings\SettingKey;
use App\Shared\Application\Settings\SettingType;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * BO13 « Paramètres de la plateforme » (Figma 186:5725) : rubriques, réglages typés de la rubrique, journal avant / après.
 * Les secrets (Stripe, transport e-mail) restent dans le coffre ou l'environnement : affichés masqués, jamais saisis ici (P-137).
 */
#[Route('/admin/parametres')]
final class AdminSettingsController extends AbstractController
{
    private const string DEFAULT_RUBRIC = 'demandes';
    private const int VISIBLE_SUFFIX = 4;

    public function __construct(
        private readonly QueryBus $queries,
        private readonly CommandBus $commands,
        private readonly CurrentAdministrator $admin,
        #[Autowire('%env(default::STRIPE_PUBLISHABLE_KEY)%')]
        private readonly ?string $stripePublic = null,
        #[Autowire('%env(default::STRIPE_SECRET_KEY)%')]
        private readonly ?string $stripeSecret = null,
        #[Autowire('%env(default::STRIPE_WEBHOOK_SECRET)%')]
        private readonly ?string $stripeWebhook = null,
        #[Autowire('%env(default::MAILER_DSN)%')]
        private readonly ?string $mailerDsn = null,
    ) {
    }

    #[Route('/{rubric}', name: 'admin_settings', requirements: ['rubric' => '[a-z]+'], defaults: ['rubric' => self::DEFAULT_RUBRIC], methods: ['GET'])]
    #[IsGranted('admin.settings.view')]
    public function index(string $rubric): Response
    {
        return $this->render('admin/settings/index.html.twig', [
            'rubrics' => array_map(static fn (array $r): string => $r[0], SettingRubric::RUBRICS),
            'view' => $this->view($rubric),
            'stripe' => ['public' => $this->mask($this->stripePublic), 'secret' => $this->mask($this->stripeSecret, true), 'webhook' => $this->mask($this->stripeWebhook, true), 'mode' => $this->stripeMode()],
            'mailer' => $this->mailerTransport(),
        ]);
    }

    #[Route('/{rubric}', name: 'admin_settings_save', requirements: ['rubric' => '[a-z]+'], methods: ['POST'])]
    #[IsGranted('admin.settings.edit')]
    public function save(Request $request, string $rubric): Response
    {
        if (!$this->isCsrfTokenValid('admin_settings_'.$rubric, $request->request->getString('_token'))) {
            throw new AccessDeniedHttpException('Jeton CSRF invalide.');
        }
        $view = $this->view($rubric);
        $posted = $request->request->all('v');
        $changes = [];
        $errors = [];
        foreach ($view->fields as $field) {
            $key = SettingKey::from($field['key']);
            try {
                $value = $key->definition()->normalize($key, $this->raw(SettingType::from($field['type']), $posted[$field['key']] ?? null));
            } catch (InvalidSettingValue $e) {
                $errors[] = $e->getMessage();
                continue;
            }
            if ($value !== $field['value']) {
                $changes[$field['key']] = $value;
            }
        }
        if ([] !== $errors) {
            foreach ($errors as $error) {
                $this->addFlash('error', $error);
            }

            return $this->redirectToRoute('admin_settings', ['rubric' => $rubric], Response::HTTP_SEE_OTHER);
        }
        $adminId = $this->admin->id() ?? throw new AccessDeniedHttpException();
        foreach ($changes as $key => $value) {
            $this->commands->dispatch(new ChangeSetting($key, $value, $adminId, 'BO13 : '.$view->label, $request->getClientIp()));
        }
        $this->addFlash('success', [] === $changes ? 'Aucune valeur modifiée.' : \sprintf('%d paramètre(s) enregistré(s) : ils s’appliquent sous quelques minutes (cache des paramètres).', \count($changes)));

        return $this->redirectToRoute('admin_settings', ['rubric' => $rubric], Response::HTTP_SEE_OTHER);
    }

    private function view(string $rubric): SettingsPageView
    {
        $view = $this->queries->ask(new SettingsPage($rubric));

        return $view instanceof SettingsPageView ? $view : throw new NotFoundHttpException();
    }

    /** Valeur brute du formulaire mise au format attendu par la définition (la validation reste dans normalize()). */
    private function raw(SettingType $type, mixed $posted): mixed
    {
        return match ($type) {
            SettingType::Boolean => '1' === $posted,
            SettingType::IntegerList => array_values(array_filter(array_map('trim', explode(',', \is_string($posted) ? $posted : '')), static fn (string $v): bool => '' !== $v)),
            SettingType::StringList => \is_array($posted)
                ? array_values(array_filter($posted, 'is_string'))
                : array_values(array_filter(array_map('trim', preg_split('/\R/', \is_string($posted) ? $posted : '') ?: []), static fn (string $v): bool => '' !== $v)),
            SettingType::WeightMap => \is_array($posted) ? $posted : null,
            SettingType::NullableInteger, SettingType::NullableDecimal, SettingType::NullableUrl => \is_string($posted) && '' !== trim($posted) ? trim($posted) : null,
            default => \is_string($posted) ? trim($posted) : $posted,
        };
    }

    /** Préfixe (pk_live_, sk_test_…) et, pour une clé publique, ses derniers caractères ; jamais la valeur. */
    private function mask(?string $value, bool $secret = false): ?string
    {
        if (null === $value || '' === $value) {
            return null;
        }
        $prefix = preg_match('/^[a-z]+_(live|test)_/', $value, $m) ? $m[0] : (preg_match('/^[a-z]+_/', $value, $m) ? $m[0] : '');

        return $prefix.str_repeat('•', 12).($secret ? ' (masquée)' : substr($value, -self::VISIBLE_SUFFIX));
    }

    private function stripeMode(): ?string
    {
        foreach ([$this->stripeSecret, $this->stripePublic] as $key) {
            if (null !== $key && preg_match('/^[a-z]+_(live|test)_/', $key, $m)) {
                return $m[1];
            }
        }

        return null;
    }

    /** Transport e-mail : seul le schéma du DSN est affiché (smtp, brevo+api…), sans hôte ni identifiants. */
    private function mailerTransport(): ?string
    {
        if (null === $this->mailerDsn || '' === $this->mailerDsn) {
            return null;
        }
        $scheme = parse_url($this->mailerDsn, \PHP_URL_SCHEME);

        return \is_string($scheme) ? $scheme : null;
    }
}
