<?php

declare(strict_types=1);

namespace App\Content\Application\Page;

/**
 * BO10.2 — blocs réutilisables des pages (CDC : texte, image, CTA, cartes, FAQ, carrousel artisans, bannière). `fields()`
 * décrit les champs (type text, textarea, html, link, media, choice, int, items) pour l'éditeur et la validation.
 */
enum PageBlockType: string
{
    case Text = 'text';
    case Image = 'image';
    case Cta = 'cta';
    case Cards = 'cards';
    case Faq = 'faq';
    case Artisans = 'artisans';
    case Banner = 'banner';

    public const int CARDS_MAX = 6;
    private const int TITLE = 120;
    private const int TEXT = 600;
    private const int HTML = 20000;
    private const int LABEL = 40;
    private const int LINK = 500;
    private const int ARTISANS_MIN = 3;
    private const int ARTISANS_MAX = 12;

    public function label(): string
    {
        return match ($this) {
            self::Text => 'Texte',
            self::Image => 'Image',
            self::Cta => 'Appel à l’action',
            self::Cards => 'Cartes',
            self::Faq => 'Questions fréquentes',
            self::Artisans => 'Carrousel de professionnels',
            self::Banner => 'Bannière',
        };
    }

    /** @return array<string, array<string, mixed>> */
    public function fields(): array
    {
        return match ($this) {
            self::Text => [
                'title' => ['type' => 'text', 'label' => 'Intertitre (H2, facultatif)', 'max' => self::TITLE],
                'html' => ['type' => 'html', 'label' => 'Texte (paragraphes, listes, liens, gras)', 'max' => self::HTML, 'required' => true],
            ],
            self::Image => [
                'media' => ['type' => 'media', 'label' => 'Image de la médiathèque', 'required' => true],
                'caption' => ['type' => 'text', 'label' => 'Légende (facultative)', 'max' => self::TITLE],
                'width' => ['type' => 'choice', 'label' => 'Largeur', 'choices' => ['full' => 'Pleine largeur', 'content' => 'Largeur du texte']],
            ],
            self::Cta => [
                'title' => ['type' => 'text', 'label' => 'Titre', 'max' => self::TITLE, 'required' => true],
                'text' => ['type' => 'textarea', 'label' => 'Texte', 'max' => self::TEXT],
                'button' => ['type' => 'text', 'label' => 'Libellé du bouton', 'max' => self::LABEL, 'required' => true],
                'link' => ['type' => 'link', 'label' => 'Lien du bouton (/chemin ou https://…)', 'max' => self::LINK, 'required' => true],
            ],
            self::Cards => [
                'title' => ['type' => 'text', 'label' => 'Titre de la section (facultatif)', 'max' => self::TITLE],
                'items' => ['type' => 'items', 'label' => 'Cartes', 'min' => 1, 'max' => self::CARDS_MAX, 'fields' => [
                    'title' => ['type' => 'text', 'label' => 'Titre', 'max' => self::TITLE, 'required' => true],
                    'text' => ['type' => 'textarea', 'label' => 'Texte', 'max' => self::TEXT],
                    'link' => ['type' => 'link', 'label' => 'Lien (facultatif)', 'max' => self::LINK],
                    'media' => ['type' => 'media', 'label' => 'Image (facultative)'],
                ]],
            ],
            self::Faq => [
                'title' => ['type' => 'text', 'label' => 'Titre', 'max' => self::TITLE, 'required' => true],
                'topic' => ['type' => 'faq_topic', 'label' => 'Rubrique de l’aide', 'required' => true],
            ],
            self::Artisans => [
                'title' => ['type' => 'text', 'label' => 'Titre', 'max' => self::TITLE, 'required' => true],
                'count' => ['type' => 'int', 'label' => 'Nombre de professionnels', 'min' => self::ARTISANS_MIN, 'max' => self::ARTISANS_MAX],
            ],
            self::Banner => [
                'media' => ['type' => 'media', 'label' => 'Image de fond', 'required' => true],
                'title' => ['type' => 'text', 'label' => 'Titre', 'max' => self::TITLE, 'required' => true],
                'text' => ['type' => 'textarea', 'label' => 'Texte', 'max' => self::TEXT],
                'button' => ['type' => 'text', 'label' => 'Libellé du bouton (facultatif)', 'max' => self::LABEL],
                'link' => ['type' => 'link', 'label' => 'Lien du bouton', 'max' => self::LINK],
            ],
        };
    }

    /** @return array<string, mixed> valeurs initiales d'un bloc ajouté */
    public function defaults(): array
    {
        return match ($this) {
            self::Text => ['title' => '', 'html' => '<p>Votre texte.</p>'],
            self::Image => ['media' => '', 'caption' => '', 'width' => 'content'],
            self::Cta => ['title' => 'Besoin d’un professionnel ?', 'text' => '', 'button' => 'Demander un devis', 'link' => '/demande'],
            self::Cards => ['title' => '', 'items' => [['title' => 'Carte', 'text' => '', 'link' => '', 'media' => '']]],
            self::Faq => ['title' => 'Questions fréquentes', 'topic' => ''],
            self::Artisans => ['title' => 'Des professionnels vérifiés', 'count' => 6],
            self::Banner => ['media' => '', 'title' => 'Titre de la bannière', 'text' => '', 'button' => '', 'link' => ''],
        };
    }
}
