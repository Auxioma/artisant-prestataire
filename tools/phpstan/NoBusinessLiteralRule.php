<?php

declare(strict_types=1);

namespace App\Tools\PHPStan;

use PhpParser\Node;
use PHPStan\Analyser\Scope;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;

/**
 * RG-014 / ADR-0007 : interdit les littéraux numériques dans le code des policies et handlers métier.
 * Les valeurs métier proviennent de SettingsProvider ; les constantes de classe nommées restent autorisées
 * (elles documentent une valeur technique, pas un paramètre métier).
 *
 * @implements Rule<Node\Scalar>
 */
final class NoBusinessLiteralRule implements Rule
{
    private const array ALLOWED = [-1, 0, 1, 2, 100];

    public function getNodeType(): string
    {
        return Node\Scalar::class;
    }

    public function processNode(Node $node, Scope $scope): array
    {
        if (!$node instanceof Node\Scalar\Int_ && !$node instanceof Node\Scalar\Float_) {
            return [];
        }
        if (!$scope->isInClass() || null === $scope->getFunction()) {
            return [];
        }
        $class = $scope->getClassReflection()->getName();
        if (str_starts_with($class, 'App\\Shared\\') || str_starts_with($class, 'App\\Tools\\')) {
            return [];
        }
        if (1 !== preg_match('/\\\\(Domain|Application)\\\\.*(Policy|Handler|Specification|Rule)$/', $class)) {
            return [];
        }
        if (\in_array($node->value, self::ALLOWED, true)) {
            return [];
        }

        return [
            RuleErrorBuilder::message(\sprintf('Littéral %s dans %s : une valeur métier doit venir de SettingsProvider (RG-014) ou d\'une constante nommée.', (string) $node->value, $class))
                ->identifier('trouvemoi.businessLiteral')
                ->build(),
        ];
    }
}
