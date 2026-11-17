<?php

declare(strict_types=1);

namespace App\Catalog\Domain\Questionnaire;

/**
 * Question d'un questionnaire métier : type, options, caractère obligatoire, condition d'affichage (« afficher si la
 * réponse à {field} est l'une de {values} »), bornes.
 */
final readonly class QuestionnaireField
{
    public const int LABEL_MAX = 120;
    public const int HELP_MAX = 200;
    public const int OPTIONS_MAX = 20;
    public const int TEXT_MAX_DEFAULT = 200;
    public const int TEXT_MAX_LIMIT = 500;
    /** Présentation d'une question à choix (Figma P11 5:57) : pastilles, boutons radio ou liste déroulante. */
    public const array WIDGETS = ['chips', 'radio', 'select'];
    private const string KEY = '/^[a-z][a-z0-9_]{0,39}$/';
    private const string VALUE = '/^[a-z0-9][a-z0-9_-]{0,39}$/';

    /**
     * @param array<string, string> $options      valeur → libellé (ordre d'affichage)
     * @param list<string>          $showIfValues
     */
    public function __construct(
        public string $key,
        public string $label,
        public FieldType $type,
        public bool $required,
        public array $options = [],
        public ?string $help = null,
        public ?string $showIfField = null,
        public array $showIfValues = [],
        public ?int $min = null,
        public ?int $max = null,
        public ?string $widget = null,
        public bool $half = false,
    ) {
        if (null !== $widget && (!\in_array($widget, self::WIDGETS, true) || !$type->hasOptions() && FieldType::YesNo !== $type || (FieldType::Multi === $type && 'chips' !== $widget))) {
            throw new InvalidQuestionnaire(\sprintf('Question « %s » : présentation « %s » impossible pour ce type.', $key, $widget));
        }
        if (1 !== preg_match(self::KEY, $key)) {
            throw new InvalidQuestionnaire(\sprintf('Clé de question « %s » invalide (minuscules, chiffres, « _ »).', $key));
        }
        if ('' === trim($label) || mb_strlen($label) > self::LABEL_MAX || (null !== $help && mb_strlen($help) > self::HELP_MAX)) {
            throw new InvalidQuestionnaire(\sprintf('Question « %s » : libellé ou aide invalide.', $key));
        }
        if ($type->hasOptions() !== ([] !== $options) || \count($options) > self::OPTIONS_MAX) {
            throw new InvalidQuestionnaire(\sprintf('Question « %s » : options attendues pour un choix, interdites sinon (%d au plus).', $key, self::OPTIONS_MAX));
        }
        foreach ($options as $value => $optionLabel) {
            if (1 !== preg_match(self::VALUE, $value) || '' === trim($optionLabel) || mb_strlen($optionLabel) > self::LABEL_MAX) {
                throw new InvalidQuestionnaire(\sprintf('Question « %s » : option « %s » invalide.', $key, $value));
            }
        }
        if ((null === $showIfField) !== ([] === $showIfValues)) {
            throw new InvalidQuestionnaire(\sprintf('Question « %s » : condition d’affichage incomplète.', $key));
        }
        if (null !== $min && null !== $max && $min > $max) {
            throw new InvalidQuestionnaire(\sprintf('Question « %s » : bornes incohérentes.', $key));
        }
        if (FieldType::Text === $type && null !== $max && $max > self::TEXT_MAX_LIMIT) {
            throw new InvalidQuestionnaire(\sprintf('Question « %s » : %d caractères au plus.', $key, self::TEXT_MAX_LIMIT));
        }
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return array_filter([
            'key' => $this->key, 'label' => $this->label, 'type' => $this->type->value, 'required' => $this->required,
            'options' => [] === $this->options ? null : array_map(static fn (string $v, string $l): array => ['value' => $v, 'label' => $l], array_keys($this->options), $this->options),
            'help' => $this->help, 'show_if' => null === $this->showIfField ? null : ['field' => $this->showIfField, 'in' => $this->showIfValues],
            'min' => $this->min, 'max' => $this->max, 'widget' => $this->widget, 'half' => $this->half ? true : null,
        ], static fn (mixed $v): bool => null !== $v);
    }

    /** Présentation retenue : celle du schéma, sinon pastilles pour un choix, boutons radio pour oui / non. */
    public function presentation(): string
    {
        return $this->widget ?? (FieldType::YesNo === $this->type ? 'radio' : 'chips');
    }

    /**
     * Libellé lisible d'une réponse stockée (artisans, récapitulatif).
     *
     * @param string|list<string> $answer
     */
    public function display(string|array $answer): string
    {
        $values = \is_array($answer) ? $answer : [$answer];

        return implode(', ', array_map(fn (string $v): string => match ($this->type) {
            FieldType::YesNo => 'oui' === $v ? 'Oui' : 'Non',
            default => $this->options[$v] ?? $v,
        }, $values));
    }
}
