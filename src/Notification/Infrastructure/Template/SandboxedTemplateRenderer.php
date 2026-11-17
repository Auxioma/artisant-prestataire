<?php

declare(strict_types=1);

namespace App\Notification\Infrastructure\Template;

use App\Notification\Application\Port\TemplateRenderer;
use App\Notification\Application\RenderedEmail;
use App\Notification\Application\Template;
use Twig\Environment;
use Twig\Error\Error as TwigError;
use Twig\Loader\ArrayLoader;
use Twig\Sandbox\Sandbox;
use Twig\Sandbox\SecurityPolicy;
use Twig\TwigFunction;

/**
 * Modèles saisis en back-office (BO11) rendus dans un bac à sable Twig : balises, filtres et fonctions en liste
 * blanche, aucun appel de méthode ni accès de propriété, aucune inclusion. Variables échappées (HTML) ; le corps est
 * ensuite placé dans le gabarit commun email/_layout.html.twig (charte, pré-en-tête, pied de page légal).
 */
final readonly class SandboxedTemplateRenderer implements TemplateRenderer
{
    private const array TAGS = ['if', 'for', 'set', 'apply'];
    private const array FILTERS = ['escape', 'e', 'date', 'default', 'upper', 'lower', 'capitalize', 'nl2br', 'length', 'trim', 'join', 'striptags', 'number_format'];
    private const array FUNCTIONS = ['bouton'];

    public function __construct(private Environment $twig)
    {
    }

    public function renderEmail(Template $template, array $variables): RenderedEmail
    {
        if (null === $template->subject || null === $template->bodyHtml || null === $template->bodyText) {
            throw new \RuntimeException(\sprintf('Modèle %s incomplet (objet, HTML et texte requis).', $template->code));
        }
        $subject = trim((string) preg_replace('/\s+/', ' ', $this->render($template->subject, $variables, false)));
        $preheader = null === $template->preheader ? '' : trim($this->render($template->preheader, $variables, false));
        $body = $this->render($template->bodyHtml, $variables, true);

        try {
            $html = $this->twig->render('email/_layout.html.twig', [
                'subject' => $subject,
                'preheader' => $preheader,
                'content' => $body,
                'palette' => EmailPalette::COLORS,
            ]);
        } catch (TwigError $e) {
            throw new \RuntimeException('Gabarit commun des e-mails : '.$e->getMessage(), 0, $e);
        }

        return new RenderedEmail($subject, $html, $this->render($template->bodyText, $variables, false)."\n\n— TrouveMoi.com\n");
    }

    public function renderInApp(Template $template, array $variables): string
    {
        if (null === $template->inAppBody) {
            throw new \RuntimeException(\sprintf('Modèle %s sans texte in-app.', $template->code));
        }

        return mb_substr(trim($this->render($template->inAppBody, $variables, false)), 0, 500);
    }

    /**
     * Twig\Sandbox\Sandbox (Twig 3.29+) : environnement dédié, politique stricte, toujours en bac à sable.
     *
     * @param array<string, scalar|\DateTimeInterface|null> $variables
     */
    private function render(string $source, array $variables, bool $html): string
    {
        $environment = new Environment(new ArrayLoader(['modele' => $source]), [
            'autoescape' => $html ? 'html' : false,
            'strict_variables' => false,
            'cache' => false,
        ]);
        $environment->addFunction(new TwigFunction('bouton', self::button(...), ['is_safe' => ['html']]));
        $policy = new SecurityPolicy(self::TAGS, self::FILTERS, [], [], self::FUNCTIONS);
        $policy->setStrict(true);

        try {
            return new Sandbox($environment, $policy)->render('modele', $variables);
        } catch (TwigError $e) {
            throw new \RuntimeException('Modèle invalide : '.$e->getRawMessage(), 0, $e);
        }
    }

    /** Bouton d'appel à l'action ; seuls des liens http(s) sont acceptés. */
    private static function button(mixed $href, mixed $label): string
    {
        $href = \is_string($href) ? $href : '';
        if (1 !== preg_match('#^https?://#', $href)) {
            return '';
        }
        $c = EmailPalette::COLORS;
        $href = htmlspecialchars($href, \ENT_QUOTES);
        $label = htmlspecialchars(\is_string($label) ? $label : '', \ENT_QUOTES);

        return '<table role="presentation" cellpadding="0" cellspacing="0" style="margin:20px 0;"><tr>'
            .'<td style="border-radius:999px;background:'.$c['button'].';"><a href="'.$href.'" style="display:inline-block;padding:12px 24px;font-weight:600;font-size:15px;color:'.$c['navy'].';text-decoration:none;border-radius:999px;">'.$label.'</a></td>'
            .'</tr></table>'
            .'<p class="muted" style="font-size:13px;line-height:20px;">Si le bouton ne fonctionne pas, copiez ce lien dans votre navigateur :<br><a href="'.$href.'" style="word-break:break-all;">'.$href.'</a></p>';
    }
}
