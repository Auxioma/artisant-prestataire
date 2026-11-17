<?php

declare(strict_types=1);

namespace App\Identity\UI\Form;

use App\Identity\Domain\Client\ClientType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\EmailType;
use Symfony\Component\Form\Extension\Core\Type\HiddenType;
use Symfony\Component\Form\Extension\Core\Type\PasswordType;
use Symfony\Component\Form\Extension\Core\Type\RepeatedType;
use Symfony\Component\Form\Extension\Core\Type\TelType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * A02 (Figma 15:24, bloc 393:16299). Validation en ligne côté navigateur, toujours revalidée côté serveur.
 *
 * @extends AbstractType<ClientRegistrationData>
 */
final class ClientRegistrationType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $minLength = $options['password_min_length'];
        \assert(\is_int($minLength));
        $field = static fn (string $icon, string $autocomplete, string $placeholder, array $extra = []): array => $extra + ['data-icon' => $icon, 'autocomplete' => $autocomplete, 'placeholder' => $placeholder, 'data-action' => 'blur->signup#validateField'];

        $builder
            ->add('clientType', ChoiceType::class, [
                'label' => 'Vous commandez des travaux en tant que',
                'choices' => ['Particulier' => ClientType::Individual->value, 'Entreprise ou organisation' => ClientType::Business->value],
                'expanded' => true,
                'attr' => ['data-action' => 'change->signup#toggleBusiness'],
            ])
            ->add('companyName', TextType::class, [
                'label' => 'Raison sociale',
                'required' => false,
                'row_attr' => ['class' => 'tm-signup__business', 'data-signup-target' => 'business'],
                'attr' => $field('building', 'organization', 'Nom de votre entreprise', ['maxlength' => 120, 'data-required-when-business' => 'true']),
            ])
            ->add('siret', TextType::class, [
                'label' => 'SIRET (facultatif)',
                'required' => false,
                'help' => 'Vérifié auprès de l’INSEE s’il est renseigné.',
                'row_attr' => ['class' => 'tm-signup__business', 'data-signup-target' => 'business'],
                'attr' => $field('file', 'off', '14 chiffres', ['inputmode' => 'numeric', 'maxlength' => 17]),
            ])
            ->add('lastName', TextType::class, ['label' => 'Nom', 'attr' => $field('user', 'family-name', 'Votre nom', ['maxlength' => 50, 'minlength' => 2])])
            ->add('firstName', TextType::class, ['label' => 'Prénom', 'attr' => $field('user', 'given-name', 'Votre prénom', ['maxlength' => 50, 'minlength' => 2])])
            ->add('email', EmailType::class, ['label' => 'E-mail', 'attr' => $field('mail', 'email', 'votre@email.com', ['maxlength' => 254])])
            ->add('phone', TelType::class, [
                'label' => 'Téléphone',
                'required' => false,
                'help' => 'Facultatif : il vous sera demandé au premier dépôt de demande.',
                'attr' => $field('phone', 'tel', '06 12 34 56 78'),
            ])
            ->add('postalCode', TextType::class, [
                'label' => 'Code postal',
                'attr' => $field('pin', 'postal-code', '75001', ['inputmode' => 'numeric', 'maxlength' => 5, 'pattern' => '[0-9]{5}']),
            ])
            ->add('password', RepeatedType::class, [
                'type' => PasswordType::class,
                'invalid_message' => 'Les deux mots de passe ne correspondent pas.',
                'first_options' => [
                    'label' => 'Mot de passe',
                    'help' => \sprintf('Au moins %d caractères. Une phrase de passe est idéale.', $minLength),
                    'attr' => $field('lock', 'new-password', '••••••••', ['minlength' => $minLength, 'maxlength' => 128, 'data-password-target' => 'input', 'data-action' => 'input->password#measure blur->signup#validateField']),
                ],
                'second_options' => [
                    'label' => 'Confirmer le mot de passe',
                    'attr' => $field('lock', 'new-password', '••••••••', ['maxlength' => 128]),
                ],
            ])
            ->add('terms', CheckboxType::class, ['label' => false])
            ->add('newsletter', CheckboxType::class, ['label' => 'Je souhaite recevoir les actualités, offres et conseils TrouveMoi (facultatif).', 'required' => false])
            ->add('legalVersions', HiddenType::class);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => ClientRegistrationData::class, 'password_min_length' => 12, 'csrf_token_id' => 'client_registration']);
        $resolver->setAllowedTypes('password_min_length', 'int');
    }
}
