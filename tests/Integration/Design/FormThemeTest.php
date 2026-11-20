<?php

declare(strict_types=1);

namespace App\Tests\Integration\Design;

use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\EmailType;
use Symfony\Component\Form\Extension\Core\Type\FormType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormError;
use Symfony\Component\Form\FormFactoryInterface;
use Twig\Environment;

/** Thème de formulaire TM (T-109) : CDC §9 — étiquettes visibles, erreurs reliées au champ, groupes en fieldset. */
final class FormThemeTest extends KernelTestCase
{
    private function renderForm(): Crawler
    {
        self::bootKernel();
        $factory = self::getContainer()->get(FormFactoryInterface::class);
        \assert($factory instanceof FormFactoryInterface);
        $form = $factory->createNamedBuilder('demande', FormType::class, null, ['csrf_protection' => false])
            ->add('service', TextType::class, ['label' => 'Service', 'help' => 'Métier ou <b>raison</b> sociale', 'help_html' => true])
            ->add('email', EmailType::class, ['label' => 'Email'])
            ->add('delai', ChoiceType::class, ['label' => 'Délai', 'expanded' => true, 'choices' => ['Vite' => 'asap', 'Plus tard' => 'later']])
            ->add('cgu', CheckboxType::class, ['label' => 'J’accepte les CGU'])
            ->getForm();
        $form->get('email')->addError(new FormError('Adresse invalide'));

        $twig = self::getContainer()->get('twig');
        \assert($twig instanceof Environment);
        $html = $twig->createTemplate('{{ form_widget(form) }}')->render(['form' => $form->createView()]);

        return new Crawler($html);
    }

    public function testTextFieldWithHelp(): void
    {
        $crawler = $this->renderForm();
        $input = $crawler->filter('#demande_service');

        self::assertSame('tm-field__input', $input->attr('class'));
        self::assertSame('demande_service_help', $input->attr('aria-describedby'));
        self::assertSame('demande_service', $crawler->filter('label[for="demande_service"]')->attr('for'));
        self::assertCount(1, $crawler->filter('label[for="demande_service"] abbr.tm-field__required'));
    }

    public function testHelpIsAlwaysEscapedEvenWithHelpHtml(): void
    {
        $help = $this->renderForm()->filter('#demande_service_help');

        self::assertCount(0, $help->filter('b'), 'Aucun HTML brut dans l\'aide (anti-XSS)');
        self::assertStringContainsString('<b>raison</b>', $help->text());
    }

    public function testErrorIsLinkedAndSignalledWithoutColorOnly(): void
    {
        $crawler = $this->renderForm();
        $input = $crawler->filter('#demande_email');

        self::assertSame('true', $input->attr('aria-invalid'));
        self::assertSame('demande_email_errors', $input->attr('aria-describedby'));
        self::assertSame('Adresse invalide', trim($crawler->filter('#demande_email_errors')->text()));
        self::assertCount(1, $crawler->filter('.tm-field--error .tm-field__status'));
    }

    public function testExpandedChoicesAreAFieldsetWithLegend(): void
    {
        $crawler = $this->renderForm();
        $fieldset = $crawler->filter('fieldset.tm-fieldset');

        self::assertCount(1, $fieldset);
        self::assertStringStartsWith('Délai', trim($fieldset->filter('legend')->text()));
        self::assertCount(2, $fieldset->filter('label.tm-check input[type="radio"].tm-check__input'));
        self::assertCount(0, $crawler->filter('label[for="demande_delai"]'), 'Pas de <label> pointant vers un conteneur');
    }

    public function testCheckboxRow(): void
    {
        $checkbox = $this->renderForm()->filter('label.tm-check[for="demande_cgu"] input#demande_cgu');

        self::assertSame('checkbox', $checkbox->attr('type'));
        self::assertSame('tm-check__input', $checkbox->attr('class'));
    }
}
