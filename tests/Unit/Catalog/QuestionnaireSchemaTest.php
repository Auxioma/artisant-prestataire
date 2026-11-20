<?php

declare(strict_types=1);

namespace App\Tests\Unit\Catalog;

use App\Catalog\Domain\Questionnaire\InvalidAnswers;
use App\Catalog\Domain\Questionnaire\InvalidQuestionnaire;
use App\Catalog\Domain\Questionnaire\QuestionnaireSchema;
use PHPUnit\Framework\TestCase;

/** P11.3 : questionnaire dynamique (types, obligatoire, conditions d'affichage, bornes) et validation des réponses. */
final class QuestionnaireSchemaTest extends TestCase
{
    private function schema(): QuestionnaireSchema
    {
        return QuestionnaireSchema::fromArray(['fields' => [
            ['key' => 'logement', 'label' => 'Type de lieu', 'type' => 'choice', 'required' => true, 'options' => ['maison' => 'Maison', 'appartement' => 'Appartement']],
            ['key' => 'etage', 'label' => 'Étage', 'type' => 'number', 'min' => 0, 'max' => 60, 'required' => true, 'show_if' => ['field' => 'logement', 'in' => ['appartement']]],
            ['key' => 'pieces', 'label' => 'Pièces', 'type' => 'multi', 'options' => [['value' => 'cuisine', 'label' => 'Cuisine'], ['value' => 'sdb', 'label' => 'Salle de bains']]],
            ['key' => 'urgent', 'label' => 'Urgent ?', 'type' => 'yesno'],
            ['key' => 'precision', 'label' => 'Précision', 'type' => 'text', 'max' => 10],
        ]]);
    }

    public function testHiddenQuestionsAreNeitherRequiredNorKept(): void
    {
        $answers = $this->schema()->validate(['logement' => 'maison', 'etage' => '4', 'pieces' => ['sdb', 'sdb'], 'urgent' => 'oui', 'precision' => '']);
        self::assertSame(['logement' => 'maison', 'pieces' => ['sdb'], 'urgent' => 'oui'], $answers, 'étage masqué pour une maison : ignoré');

        $answers = $this->schema()->validate(['logement' => 'appartement', 'etage' => '04']);
        self::assertSame(['logement' => 'appartement', 'etage' => '4'], $answers);
        self::assertSame([['label' => 'Type de lieu', 'value' => 'Appartement'], ['label' => 'Étage', 'value' => '4']], $this->schema()->describe($answers));
    }

    public function testInvalidAnswersAreReportedPerField(): void
    {
        try {
            $this->schema()->validate(['logement' => 'appartement', 'etage' => '99', 'pieces' => ['grenier'], 'urgent' => 'peut-être', 'precision' => 'beaucoup trop long']);
            self::fail('Réponses invalides attendues');
        } catch (InvalidAnswers $e) {
            self::assertSame(['etage', 'pieces', 'urgent', 'precision'], array_keys($e->errors));
            self::assertStringContainsString('entre 0 et 60', $e->errors['etage']);
        }
        try {
            $this->schema()->validate([]);
            self::fail('Réponse obligatoire attendue');
        } catch (InvalidAnswers $e) {
            self::assertSame(['logement' => 'Réponse obligatoire.'], $e->errors);
        }
        self::assertSame(['logement' => 'maison'], $this->schema()->keepValid(['logement' => 'maison', 'etage' => 'abc', 'urgent' => 'peut-être']), 'sauvegarde partielle : seules les valeurs valides');
    }

    public function testRoundTripAndMalformedSchemas(): void
    {
        self::assertEquals($this->schema(), QuestionnaireSchema::fromArray($this->schema()->toArray()));
        foreach ([
            [['key' => 'a', 'label' => 'A', 'type' => 'choice']],
            [['key' => 'a', 'label' => 'A', 'type' => 'text'], ['key' => 'a', 'label' => 'B', 'type' => 'text']],
            [['key' => 'b', 'label' => 'B', 'type' => 'text', 'show_if' => ['field' => 'a', 'in' => ['x']]]],
            [['key' => 'a', 'label' => 'A', 'type' => 'yesno'], ['key' => 'b', 'label' => 'B', 'type' => 'text', 'show_if' => ['field' => 'a', 'in' => ['peut-etre']]]],
            [['key' => 'A B', 'label' => 'A', 'type' => 'text']],
            [['key' => 'a', 'label' => 'A', 'type' => 'date']],
        ] as $fields) {
            try {
                QuestionnaireSchema::fromArray(['fields' => $fields]);
                self::fail('Schéma invalide accepté : '.json_encode($fields));
            } catch (InvalidQuestionnaire) {
                self::addToAssertionCount(1);
            }
        }
    }
}
