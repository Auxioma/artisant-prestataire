<?php

declare(strict_types=1);

namespace App\Identity\UI\Form;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\PasswordType;
use Symfony\Component\Form\Extension\Core\Type\RepeatedType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\NotBlank;

/**
 * A05.2 (Figma 230:12498) : nouveau mot de passe + confirmation (la politique complète est appliquée par le cas d'usage).
 *
 * @extends AbstractType<array{password: ?string}>
 */
final class PasswordResetType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $minLength = $options['password_min_length'];
        \assert(\is_int($minLength));
        $builder->add('password', RepeatedType::class, [
            'type' => PasswordType::class,
            'invalid_message' => 'Les deux mots de passe ne correspondent pas.',
            'constraints' => [new NotBlank(message: \sprintf('Votre mot de passe doit contenir au moins %d caractères.', $minLength))],
            'first_options' => [
                'label' => 'Nouveau mot de passe',
                'help' => \sprintf('%d caractères minimum · non présent dans les fuites connues', $minLength),
                'attr' => ['autocomplete' => 'new-password', 'minlength' => $minLength, 'maxlength' => 128, 'data-action' => 'input->password#measure'],
            ],
            'second_options' => [
                'label' => 'Confirmation',
                'attr' => ['autocomplete' => 'new-password', 'maxlength' => 128],
            ],
        ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['password_min_length' => 12, 'csrf_token_id' => 'password_reset']);
        $resolver->setAllowedTypes('password_min_length', 'int');
    }
}
