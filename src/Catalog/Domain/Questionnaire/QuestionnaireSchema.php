<?php

declare(strict_types=1);

namespace App\Catalog\Domain\Questionnaire;

/**
 * P11.3 — questionnaire dynamique d'un métier : liste ordonnée de questions, dont certaines conditionnelles (affichées
 * selon une réponse précédente). Valide et nettoie les réponses : une question masquée n'est ni exigée ni conservée.
 */
final readonly class QuestionnaireSchema
{
    public const int FIELDS_MAX = 20;
    private const string YES = 'oui';
    private const string NO = 'non';

    /** @var array<string, QuestionnaireField> */
    public array $fields;

    /** @param list<QuestionnaireField> $fields */
    public function __construct(array $fields)
    {
        if (\count($fields) > self::FIELDS_MAX) {
            throw new InvalidQuestionnaire(\sprintf('%d questions au plus.', self::FIELDS_MAX));
        }
        $indexed = [];
        foreach ($fields as $field) {
            if (isset($indexed[$field->key])) {
                throw new InvalidQuestionnaire(\sprintf('Question « %s » en double.', $field->key));
            }
            if (null !== $field->showIfField) {
                $parent = $indexed[$field->showIfField] ?? throw new InvalidQuestionnaire(\sprintf('Question « %s » : la condition doit porter sur une question précédente.', $field->key));
                $allowed = FieldType::YesNo === $parent->type ? [self::YES, self::NO] : array_keys($parent->options);
                if ([] === $allowed || [] !== array_diff($field->showIfValues, $allowed)) {
                    throw new InvalidQuestionnaire(\sprintf('Question « %s » : condition sur des réponses inexistantes.', $field->key));
                }
            }
            $indexed[$field->key] = $field;
        }
        $this->fields = $indexed;
    }

    /** @param array<array-key, mixed> $data forme stockée (`toArray`) ou importée */
    public static function fromArray(array $data): self
    {
        $fields = [];
        foreach (\is_array($data['fields'] ?? null) ? $data['fields'] : [] as $row) {
            if (!\is_array($row)) {
                throw new InvalidQuestionnaire('Question mal formée.');
            }
            $type = FieldType::tryFrom(self::string($row, 'type')) ?? throw new InvalidQuestionnaire(\sprintf('Question « %s » : type inconnu.', self::string($row, 'key')));
            $options = [];
            foreach (\is_array($row['options'] ?? null) ? $row['options'] : [] as $value => $option) {
                if (\is_array($option)) {
                    $options[self::string($option, 'value')] = self::string($option, 'label');
                } elseif (\is_string($value) && \is_string($option)) {
                    $options[$value] = trim($option); // forme abrégée du YAML : valeur: libellé
                }
            }
            $showIf = \is_array($row['show_if'] ?? null) ? $row['show_if'] : [];
            $fields[] = new QuestionnaireField(
                self::string($row, 'key'),
                self::string($row, 'label'),
                $type,
                true === ($row['required'] ?? false),
                $options,
                '' === self::string($row, 'help') ? null : self::string($row, 'help'),
                '' === self::string($showIf, 'field') ? null : self::string($showIf, 'field'),
                array_values(array_map(strval(...), array_filter(\is_array($showIf['in'] ?? null) ? $showIf['in'] : [], \is_scalar(...)))),
                \is_int($row['min'] ?? null) ? $row['min'] : null,
                \is_int($row['max'] ?? null) ? $row['max'] : null,
                '' === self::string($row, 'widget') ? null : self::string($row, 'widget'),
                true === ($row['half'] ?? false),
            );
        }

        return new self($fields);
    }

    /** @return array{fields: list<array<string, mixed>>} */
    public function toArray(): array
    {
        return ['fields' => array_values(array_map(static fn (QuestionnaireField $f): array => $f->toArray(), $this->fields))];
    }

    /**
     * @param array<array-key, mixed> $raw saisie brute (formulaire)
     *
     * @return array<string, string|list<string>> réponses nettoyées des seules questions affichées
     *
     * @throws InvalidAnswers
     */
    public function validate(array $raw): array
    {
        $answers = [];
        $errors = [];
        foreach ($this->fields as $key => $field) {
            if (!$this->isVisible($field, $answers)) {
                continue;
            }
            $value = $raw[$key] ?? null;
            try {
                $clean = $this->clean($field, $value);
            } catch (InvalidAnswers $e) {
                $errors += $e->errors;
                continue;
            }
            if (null === $clean || [] === $clean) {
                if ($field->required) {
                    $errors[$key] = 'Réponse obligatoire.';
                }
                continue;
            }
            $answers[$key] = $clean;
        }
        if ([] !== $errors) {
            throw new InvalidAnswers($errors);
        }

        return $answers;
    }

    /**
     * Saisie partielle (sauvegarde automatique) : ne garde que les valeurs valides, sans rien exiger.
     *
     * @param array<array-key, mixed> $raw
     *
     * @return array<string, string|list<string>>
     */
    public function keepValid(array $raw): array
    {
        $answers = [];
        foreach ($this->fields as $key => $field) {
            try {
                $clean = $this->clean($field, $raw[$key] ?? null);
            } catch (InvalidAnswers) {
                continue;
            }
            if (null !== $clean && [] !== $clean) {
                $answers[$key] = $clean;
            }
        }

        return $answers;
    }

    /** @param array<string, string|list<string>> $answers */
    public function isVisible(QuestionnaireField $field, array $answers): bool
    {
        if (null === $field->showIfField) {
            return true;
        }
        $given = $answers[$field->showIfField] ?? [];

        return [] !== array_intersect(\is_array($given) ? $given : [$given], $field->showIfValues);
    }

    /**
     * Réponses lisibles, dans l'ordre du questionnaire (récapitulatif P11.7, fiche artisan PR03).
     *
     * @param array<string, string|list<string>> $answers
     *
     * @return list<array{label: string, value: string}>
     */
    public function describe(array $answers): array
    {
        $lines = [];
        foreach ($this->fields as $key => $field) {
            if (isset($answers[$key])) {
                $lines[] = ['label' => $field->label, 'value' => $field->display($answers[$key])];
            }
        }

        return $lines;
    }

    /** @return string|list<string>|null */
    private function clean(QuestionnaireField $field, mixed $value): string|array|null
    {
        $fail = static fn (string $message): InvalidAnswers => new InvalidAnswers([$field->key => $message]);
        if (FieldType::Multi === $field->type) {
            $values = array_values(array_unique(array_filter(\is_array($value) ? $value : [], \is_string(...))));
            if ([] !== array_diff($values, array_keys($field->options))) {
                throw $fail('Réponse inconnue.');
            }

            return $values;
        }
        if (!\is_string($value) && !\is_int($value)) {
            return null;
        }
        $value = trim((string) $value);
        if ('' === $value) {
            return null;
        }

        return match ($field->type) {
            FieldType::Choice => \array_key_exists($value, $field->options) ? $value : throw $fail('Réponse inconnue.'),
            FieldType::YesNo => \in_array($value, [self::YES, self::NO], true) ? $value : throw $fail('Répondez par oui ou non.'),
            FieldType::Number => 1 === preg_match('/^-?\d{1,9}$/', $value) && (null === $field->min || (int) $value >= $field->min) && (null === $field->max || (int) $value <= $field->max)
                ? (string) (int) $value
                : throw $fail(\sprintf('Nombre entier attendu%s.', null !== $field->min && null !== $field->max ? \sprintf(' entre %d et %d', $field->min, $field->max) : '')),
            default => mb_strlen($value) <= ($field->max ?? QuestionnaireField::TEXT_MAX_DEFAULT) ? $value : throw $fail(\sprintf('%d caractères au plus.', $field->max ?? QuestionnaireField::TEXT_MAX_DEFAULT)),
        };
    }

    /** @param array<array-key, mixed> $row */
    private static function string(array $row, string $key): string
    {
        return \is_scalar($row[$key] ?? null) ? trim((string) $row[$key]) : '';
    }
}
