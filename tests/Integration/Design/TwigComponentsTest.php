<?php

declare(strict_types=1);

namespace App\Tests\Integration\Design;

use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\UX\TwigComponent\Test\InteractsWithTwigComponents;

/**
 * T-109 : rendu des composants Twig et exigences d'accessibilité des descriptions Figma (RGAA 4.1 / WCAG 2.1 AA).
 */
final class TwigComponentsTest extends KernelTestCase
{
    use InteractsWithTwigComponents;

    /**
     * @param array<string, mixed> $props
     */
    private function render(string $component, array $props = [], ?string $content = null): Crawler
    {
        return $this->renderTwigComponent($component, $props, $content)->crawler();
    }

    public function testButtonVariantsAndStates(): void
    {
        $button = $this->render('Button', ['variant' => 'search'], 'Rechercher')->filter('button');
        self::assertSame('tm-button tm-button--search', $button->attr('class'));
        self::assertSame('button', $button->attr('type'));
        self::assertSame('Rechercher', trim($button->text()));

        $loading = $this->render('Button', ['loading' => true], 'Envoi')->filter('button');
        self::assertSame('true', $loading->attr('aria-busy'));
        self::assertCount(1, $loading->filter('.tm-button__spinner'));
        self::assertSame('Envoi', trim($loading->filter('.tm-button__label')->text()), 'Libellé conservé pendant le chargement');

        self::assertNotNull($this->render('Button', ['disabled' => true], 'Non')->filter('button')->attr('disabled'));

        $link = $this->render('Button', ['href' => '/devis'], 'Déposer')->filter('a');
        self::assertSame('/devis', $link->attr('href'));
        self::assertSame('tm-button tm-button--primary', $link->attr('class'));

        $disabledLink = $this->render('Button', ['href' => '/devis', 'disabled' => true], 'Déposer')->filter('a');
        self::assertNull($disabledLink->attr('href'), 'Un lien désactivé n\'est pas navigable');
        self::assertSame('true', $disabledLink->attr('aria-disabled'));
    }

    public function testIconIsDecorativeUnlessLabelled(): void
    {
        $decorative = $this->render('Icon', ['name' => 'search'])->filter('svg');
        self::assertSame('true', $decorative->attr('aria-hidden'));
        self::assertStringContainsString('#search', (string) $decorative->filter('use')->attr('href'));

        $meaningful = $this->render('Icon', ['name' => 'alert', 'label' => 'Erreur'])->filter('svg');
        self::assertSame('img', $meaningful->attr('role'));
        self::assertSame('Erreur', $meaningful->attr('aria-label'));
        self::assertNull($meaningful->attr('aria-hidden'));
    }

    public function testFieldLinksHelpAndErrorToInput(): void
    {
        $withHelp = $this->render('Field', ['id' => 'q', 'name' => 'q', 'label' => 'Service', 'help' => 'Métier ou raison sociale', 'required' => true]);
        self::assertSame('q', $withHelp->filter('label')->attr('for'));
        self::assertSame('q_help', $withHelp->filter('input')->attr('aria-describedby'));
        self::assertSame('q_help', $withHelp->filter('.tm-field__help')->attr('id'));
        self::assertCount(1, $withHelp->filter('abbr.tm-field__required'));

        $withError = $this->render('Field', ['id' => 'v', 'name' => 'v', 'label' => 'Ville', 'help' => 'Aide', 'error' => 'Ville introuvable']);
        $input = $withError->filter('input');
        self::assertSame('true', $input->attr('aria-invalid'));
        self::assertSame('v_error', $input->attr('aria-describedby'));
        self::assertCount(0, $withError->filter('.tm-field__help'), 'L\'erreur remplace l\'aide');
        self::assertCount(1, $withError->filter('.tm-field__status'), 'Erreur signalée par une icône, pas seulement la couleur');
        self::assertStringContainsString('tm-field--error', (string) $withError->filter('.tm-field')->attr('class'));
    }

    public function testSelectHasAnAccessibleLabel(): void
    {
        $select = $this->render('Select', ['id' => 'tri', 'name' => 'tri', 'prefix' => 'Trier :', 'value' => 'distance', 'options' => ['pertinence' => 'Pertinence', 'distance' => 'Distance']]);
        self::assertSame('tri', $select->filter('label')->attr('for'));
        self::assertSame('Trier :', trim($select->filter('label')->text()));
        self::assertSame('distance', $select->filter('option[selected]')->attr('value'));

        $hiddenLabel = $this->render('Select', ['id' => 's', 'name' => 's', 'label' => 'Rayon', 'options' => ['30' => '30 km']]);
        self::assertSame('tm-visually-hidden', $hiddenLabel->filter('label')->attr('class'));
    }

    public function testLockedCheckboxStaysSubmittedAndChecked(): void
    {
        $locked = $this->render('Checkbox', ['id' => 'verified', 'name' => 'verified', 'label' => 'Professionnels vérifiés uniquement', 'locked' => true]);
        $control = $locked->filter('input.tm-check__input');
        self::assertNotNull($control->attr('checked'));
        self::assertNotNull($control->attr('disabled'));
        self::assertCount(1, $locked->filter('input[type="hidden"][name="verified"][value="1"]'), 'Valeur soumise malgré le disabled');
    }

    public function testBadgeAlwaysCarriesText(): void
    {
        foreach (['verified' => 'Vérifié', 'available' => 'Disponible', 'pending' => 'En attente', 'direct' => 'Demande directe'] as $variant => $label) {
            self::assertSame($label, trim($this->render('Badge', ['variant' => $variant])->filter('.tm-badge')->text()));
        }
        self::assertSame('Dossier en cours', trim($this->render('Badge', ['variant' => 'pending'], 'Dossier en cours')->filter('.tm-badge')->text()));
    }

    public function testToastAnnouncementAndAutoclose(): void
    {
        $success = $this->render('Toast', ['variant' => 'success', 'title' => 'Demande envoyée'])->filter('.tm-toast');
        self::assertSame('status', $success->attr('role'));
        self::assertSame('5000', $success->attr('data-toast-delay-value'), 'Fermeture automatique 5 s (CDC §3.3)');
        self::assertSame('Fermer la notification', $success->filter('.tm-toast__close')->attr('aria-label'));

        $error = $this->render('Toast', ['variant' => 'error', 'title' => 'Échec'])->filter('.tm-toast');
        self::assertSame('alert', $error->attr('role'));
        self::assertSame('0', $error->attr('data-toast-delay-value'), 'Les erreurs ne se ferment pas seules');
    }

    public function testModalIsALabelledNativeDialog(): void
    {
        $dialog = $this->render('Modal', ['id' => 'envoi', 'title' => 'Envoyer ce devis ?'])->filter('dialog');
        self::assertSame('envoi', $dialog->attr('id'));
        self::assertSame('envoi_title', $dialog->attr('aria-labelledby'));
        self::assertSame('Envoyer ce devis ?', trim($dialog->filter('#envoi_title')->text()));
        self::assertSame('Fermer', $dialog->filter('.tm-modal__close')->attr('aria-label'));
    }

    public function testStepperExposesCurrentStep(): void
    {
        $stepper = $this->render('Stepper', ['steps' => ['Besoin', 'Détails', 'Photos'], 'current' => 2, 'label' => 'Étapes']);
        self::assertSame('Étapes', $stepper->filter('nav')->attr('aria-label'));
        $current = $stepper->filter('[aria-current="step"]');
        self::assertCount(1, $current);
        self::assertStringContainsString('Détails', $current->text());
        self::assertStringContainsString('(terminée)', $stepper->filter('li')->eq(0)->text());
        self::assertSame('Étape 2/3 · Détails', trim($stepper->filter('.tm-stepper__count')->text()));
    }

    public function testCreditGaugeStates(): void
    {
        $normal = $this->render('CreditGauge', ['remaining' => 72, 'total' => 100, 'status' => 'devis restants sur 100 gratuits', 'ctaHref' => '/forfaits']);
        self::assertSame('72 devis', trim($normal->filter('.tm-credit-gauge__count')->text()));
        self::assertSame('72 100', $normal->filter('.tm-credit-gauge__value')->attr('stroke-dasharray'));
        self::assertSame('Voir les forfaits', trim($normal->filter('a.tm-button--secondary')->text()));
        self::assertSame('true', $normal->filter('svg')->attr('aria-hidden'));

        $exhausted = $this->render('CreditGauge', ['remaining' => 0, 'total' => 100, 'state' => 'exhausted', 'status' => 'Crédit épuisé', 'ctaHref' => '/forfaits']);
        self::assertSame('alert', $exhausted->filter('.tm-credit-gauge__status')->attr('role'), 'RG-106 : blocage annoncé');
        self::assertSame('Choisir un forfait', trim($exhausted->filter('a.tm-button--primary')->text()));
    }

    public function testCardArtisanAccessibility(): void
    {
        $card = $this->render('CardArtisan', [
            'name' => 'Atelier Exemple', 'href' => '/artisan/atelier-exemple', 'trades' => 'Plomberie', 'location' => 'Le Havre (76) · 3 km',
            'rating' => 4.8, 'reviewsCount' => 126, 'verified' => true, 'available' => true, 'favorite' => false,
        ]);
        self::assertSame('/artisan/atelier-exemple', $card->filter('h3 a')->attr('href'));
        self::assertSame('Note : 4,8 sur 5', trim($card->filter('.tm-card-artisan__score')->text()), 'Étoiles doublées de la note chiffrée');
        self::assertSame('true', $card->filter('.tm-card-artisan__stars')->attr('aria-hidden'));
        self::assertSame('(126 avis)', trim($card->filter('.tm-card-artisan__reviews')->text()));
        $favorite = $card->filter('.tm-card-artisan__favorite');
        self::assertSame('false', $favorite->attr('aria-pressed'));
        self::assertSame('Ajouter Atelier Exemple aux favoris', $favorite->attr('aria-label'));
        self::assertCount(2, $card->filter('.tm-badge'));

        $withPhoto = $this->render('CardArtisan', ['name' => 'X', 'href' => '/x', 'trades' => 'T', 'location' => 'L', 'photo' => '/media/x.webp', 'photoAlt' => 'Salle de bains rénovée']);
        self::assertSame('Salle de bains rénovée', $withPhoto->filter('img')->attr('alt'));
        self::assertSame('lazy', $withPhoto->filter('img')->attr('loading'));
        self::assertCount(0, $withPhoto->filter('.tm-card-artisan__favorite'), 'Pas de bouton favori hors contexte client');
    }

    public function testEmptyStateErrorIsAnnounced(): void
    {
        $error = $this->render('EmptyState', ['variant' => 'error', 'title' => 'Recherche indisponible', 'headingLevel' => 3]);
        self::assertSame('alert', $error->filter('section')->attr('role'));
        self::assertSame('Recherche indisponible', trim($error->filter('h3')->text()));
    }
}
