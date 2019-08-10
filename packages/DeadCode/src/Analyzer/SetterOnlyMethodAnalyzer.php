<?php declare(strict_types=1);

namespace Rector\DeadCode\Analyzer;

use PhpParser\Node;
use PhpParser\Node\Expr\Assign;
use PhpParser\Node\Expr\PropertyFetch;
use PhpParser\Node\Stmt\Class_;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\Node\Stmt\Expression;
use Rector\NodeContainer\ParsedNodesByType;
use Rector\PhpParser\Node\Manipulator\ClassManipulator;
use Rector\PhpParser\Node\Resolver\NameResolver;
use Rector\PhpParser\NodeTraverser\CallableNodeTraverser;
use Rector\Testing\PHPUnit\PHPUnitEnvironment;

final class SetterOnlyMethodAnalyzer
{
    /**
     * @var ParsedNodesByType
     */
    private $parsedNodesByType;

    /**
     * @var ClassManipulator
     */
    private $classManipulator;

    /**
     * @var NameResolver
     */
    private $nameResolver;

    /**
     * @var CallableNodeTraverser
     */
    private $callableNodeTraverser;

    /**
     * @var string[][][]
     */
    private $propertiesAndMethodsToRemoveByType = [];

    public function __construct(
        ParsedNodesByType $parsedNodesByType,
        ClassManipulator $classManipulator,
        NameResolver $nameResolver,
        CallableNodeTraverser $callableNodeTraverser
    ) {
        $this->parsedNodesByType = $parsedNodesByType;
        $this->classManipulator = $classManipulator;
        $this->nameResolver = $nameResolver;
        $this->callableNodeTraverser = $callableNodeTraverser;
    }

    /**
     * Returns 1. setter only properties + 2. setter only method names by class type
     * @return string[][][]
     */
    public function provideSetterOnlyPropertiesAndMethodsByType(): array
    {
        if ($this->propertiesAndMethodsToRemoveByType !== [] && ! PHPUnitEnvironment::isPHPUnitRun()) {
            return $this->propertiesAndMethodsToRemoveByType;
        }

        foreach ($this->parsedNodesByType->getClasses() as $class) {
            $type = $this->nameResolver->getName($class);

            // 1. setter only properties by class
            $assignOnlyPrivatePropertyNames = $this->classManipulator->getAssignOnlyPrivatePropertyNames($class);
            if ($assignOnlyPrivatePropertyNames) {
                $this->propertiesAndMethodsToRemoveByType[$type]['properties'] = $assignOnlyPrivatePropertyNames;
            }

            // 2. setter only methods by class
            $setterOnlyMethodNames = $this->resolveSetterOnlyMethodNames($class, $assignOnlyPrivatePropertyNames);
            if ($setterOnlyMethodNames) {
                $this->propertiesAndMethodsToRemoveByType[$type]['methods'] = $setterOnlyMethodNames;
            }
        }

        return $this->propertiesAndMethodsToRemoveByType;
    }

    /**
     * @param string[] $assignOnlyPrivatePropertyNames
     * @return string[]
     */
    private function resolveSetterOnlyMethodNames(Class_ $class, array $assignOnlyPrivatePropertyNames): array
    {
        $methodNamesToBeRemoved = [];

        $this->callableNodeTraverser->traverseNodesWithCallable($class, function (Node $node) use (
            $assignOnlyPrivatePropertyNames,
            &$methodNamesToBeRemoved
        ): void {
            if (! $this->isClassMethodWithSinglePropertyAssignOfNames($node, $assignOnlyPrivatePropertyNames)) {
                return;
            }
            /** @var string $classMethodName */
            $classMethodName = $this->nameResolver->getName($node);
            $methodNamesToBeRemoved[] = $classMethodName;
        });

        return $methodNamesToBeRemoved;
    }

    /**
     * Looks for:
     *
     * public function <someMethod>($value)
     * {
     *     $this->value = $value
     * }
     *
     * @param string[] $propertyNames
     */
    private function isClassMethodWithSinglePropertyAssignOfNames(Node $node, array $propertyNames): bool
    {
        if (! $node instanceof ClassMethod) {
            return false;
        }

        if ($this->nameResolver->isName($node, '__construct')) {
            return false;
        }

        if (count((array) $node->stmts) !== 1) {
            return false;
        }

        if (! $node->stmts[0] instanceof Expression) {
            return false;
        }

        /** @var Expression $onlyExpression */
        $onlyExpression = $node->stmts[0];

        $onlyStmt = $onlyExpression->expr;

        return $this->isPropertyAssignWithPropertyNames($onlyStmt, $propertyNames);
    }

    /**
     * Is: "$this->value = <$value>"
     *
     * @param string[] $propertyNames
     */
    private function isPropertyAssignWithPropertyNames(Node $node, array $propertyNames): bool
    {
        if (! $node instanceof Assign) {
            return false;
        }

        if (! $node->var instanceof PropertyFetch) {
            return false;
        }

        $propertyFetch = $node->var;
        if (! $this->nameResolver->isName($propertyFetch->var, 'this')) {
            return false;
        }

        return $this->nameResolver->isNames($propertyFetch->name, $propertyNames);
    }
}