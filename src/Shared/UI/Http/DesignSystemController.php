<?php

declare(strict_types=1);

namespace App\Shared\UI\Http;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\EmailType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormError;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Planche de référence des design tokens (T-108), pour comparaison visuelle avec la bibliothèque Figma V2.
 * Route déclarée uniquement en environnement de développement.
 */
final class DesignSystemController extends AbstractController
{
    private const string SPRITE = 'assets/icons/sprite.svg';

    #[Route('/_dev/design-system', name: 'dev_design_system', methods: ['GET'], env: 'dev')]
    public function __invoke(#[Autowire('%kernel.project_dir%')] string $projectDir): Response
    {
        $sprite = (string) file_get_contents($projectDir.'/'.self::SPRITE);
        preg_match_all('/<symbol id="([A-Za-z-]+)"/', $sprite, $matches);

        return $this->render('dev/design_system.html.twig', ['icons' => $matches[1]]);
    }

    /**
     * Planche de la mise en page commune (T-110) : ?variant=visitor|client|artisan|tunnel, &credits=72|14|0.
     */
    #[Route('/_dev/layout', name: 'dev_layout', methods: ['GET'], env: 'dev')]
    public function layout(Request $request): Response
    {
        $variant = $request->query->getString('variant', 'visitor');
        if (!\in_array($variant, ['visitor', 'client', 'artisan', 'tunnel'], true)) {
            $variant = 'visitor';
        }
        $remaining = $request->query->getInt('credits', 72);
        $state = 0 === $remaining ? 'exhausted' : ($remaining <= 20 ? 'alert' : 'normal');

        return $this->render('dev/layout.html.twig', [
            'variant' => $variant,
            'credits' => ['remaining' => $remaining, 'total' => 100, 'state' => $state],
        ]);
    }

    /**
     * Planche des composants Twig (T-109) dans tous leurs états, dont un formulaire Symfony rendu par le thème TM.
     */
    #[Route('/_dev/components', name: 'dev_components', methods: ['GET'], env: 'dev')]
    public function components(): Response
    {
        $form = $this->createFormBuilder(['email' => 'camille@exemple'], ['csrf_protection' => false])
            ->add('service', TextType::class, ['label' => 'Quel service recherchez-vous ?', 'help' => 'Métier, prestation ou raison sociale', 'attr' => ['placeholder' => 'Ex. Plombier', 'data-icon' => 'search']])
            ->add('email', EmailType::class, ['label' => 'Adresse email', 'help' => 'Nous ne la partageons jamais.'])
            ->add('urgence', ChoiceType::class, ['label' => 'Délai souhaité', 'expanded' => true, 'choices' => ['Dès que possible' => 'asap', 'Sous 1 mois' => 'month', 'Pas pressé' => 'later'], 'data' => 'month'])
            ->add('cgu', CheckboxType::class, ['label' => 'J’accepte les conditions générales d’utilisation'])
            ->getForm();
        $form->get('email')->addError(new FormError('Saisissez une adresse email valide (ex. nom@domaine.fr).'));

        return $this->render('dev/components.html.twig', ['form' => $form->createView()]);
    }
}
