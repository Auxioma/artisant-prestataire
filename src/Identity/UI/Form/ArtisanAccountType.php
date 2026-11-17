<?php

declare(strict_types=1);

namespace App\Identity\UI\Form;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\EmailType;
use Symfony\Component\Form\Extension\Core\Type\HiddenType;
use Symfony\Component\Form\Extension\Core\Type\PasswordType;
use Symfony\Component\Form\Extension\Core\Type\RepeatedType;
use Symfony\Component\Form\Extension\Core\Type\TelType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/** @extends AbstractType<ArtisanAccountData> */
final class ArtisanAccountType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $min = $options['password_min_length'];
        \assert(\is_int($min));
        $field = static fn (string $icon, string $autocomplete, string $placeholder, array $extra = []): array => $extra + ['data-icon' => $icon, 'autocomplete' => $autocomplete, 'placeholder' => $placeholder, 'data-action' => 'blur->signup#validateField'];
        $builder
            ->add('firstName', TextType::class, ['label' => 'Prénom du dirigeant', 'attr' => $field('user', 'given-name', 'Votre prénom', ['maxlength' => 50])])
            ->add('lastName', TextType::class, ['label' => 'Nom du dirigeant', 'attr' => $field('user', 'family-name', 'Votre nom', ['maxlength' => 50])])
            ->add('email', EmailType::class, ['label' => 'E-mail professionnel', 'attr' => $field('mail', 'email', 'contact@votre-entreprise.fr', ['maxlength' => 254])])
            ->add('mobile', TelType::class, ['label' => 'Téléphone mobile', 'help' => 'Pour vous joindre au sujet de votre dossier ; jamais affiché sans votre accord.', 'attr' => $field('phone', 'tel', '06 12 34 56 78')])
            ->add('password', RepeatedType::class, [
                'type' => PasswordType::class,
                'invalid_message' => 'Les deux mots de passe ne correspondent pas.',
                'first_options' => ['label' => 'Mot de passe', 'help' => \sprintf('Au moins %d caractères. Une phrase de passe est idéale.', $min), 'attr' => ['autocomplete' => 'new-password', 'minlength' => $min, 'maxlength' => 128, 'data-action' => 'input->password#measure blur->signup#validateField']],
                'second_options' => ['label' => 'Confirmer le mot de passe', 'attr' => ['autocomplete' => 'new-password', 'maxlength' => 128]],
            ])
            ->add('terms', CheckboxType::class, ['label' => false])
            ->add('legalVersions', HiddenType::class);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => ArtisanAccountData::class, 'password_min_length' => 12, 'csrf_token_id' => 'artisan_account']);
        $resolver->setAllowedTypes('password_min_length', 'int');
    }
}
