<?php

declare(strict_types=1);

namespace Tools\Rector;

use PhpParser\Node;
use PhpParser\Node\Expr\PropertyFetch;
use PhpParser\Node\Expr\StaticPropertyFetch;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Identifier;
use PhpParser\Node\Param;
use PhpParser\Node\Stmt\PropertyProperty;
use PhpParser\Node\VarLikeIdentifier;
use Rector\Core\Rector\AbstractRector;
use Rector\RuleDocGenerator\ValueObject\CodeSample\CodeSample;
use Rector\RuleDocGenerator\ValueObject\RuleDefinition;

/**
 * Rename all variables and class properties from camelCase to snake_case.
 *
 * - Renames: variables (local, parameters) and properties (decl + fetch)
 * - Skips: method names, function names, class names
 * - Skips: superglobals like $this, $_GET, $GLOBALS, etc.
 */
final class CamelCaseToSnakeCaseVariableAndPropertyRector extends AbstractRector
{
    /** @var array<string, true> */
    private const SKIP_VARIABLES = [
        'this' => true,
        'GLOBALS' => true,
        '_GET' => true,
        '_POST' => true,
        '_SERVER' => true,
        '_COOKIE' => true,
        '_SESSION' => true,
        '_ENV' => true,
        '_REQUEST' => true,
        'argv' => true,
        'argc' => true,
        'php_errormsg' => true,
    ];

    public function getRuleDefinition(): RuleDefinition
    {
        return new RuleDefinition(
            'Rename variables and properties from camelCase to snake_case',
            [
                new CodeSample(
                    <<<'PHP'
class Example {
    private $camelCaseProp;

    public function run($inputValue) {
        $localVarName = $inputValue + $this->camelCaseProp;
        self::$staticPropName = $localVarName;
    }
}
PHP,
                    <<<'PHP'
class Example {
    private $camel_case_prop;

    public function run($input_value) {
        $local_var_name = $input_value + $this->camel_case_prop;
        self::$static_prop_name = $local_var_name;
    }
}
PHP
                ),
            ]
        );
    }

    /**
     * @return array<class-string<Node>>
     */
    public function getNodeTypes(): array
    {
        return [
            Variable::class,
            Param::class,
            PropertyProperty::class,
            PropertyFetch::class,
            StaticPropertyFetch::class,
        ];
    }

    /**
     * @param Variable|Param|PropertyProperty|PropertyFetch|StaticPropertyFetch $node
     */
    public function refactor(Node $node): ?Node
    {
        if ($node instanceof Variable) {
            // Only simple variables like $foo, not variable variables ${...}
            if (! is_string($node->name)) {
                return null;
            }

            $name = $node->name;

            if (isset(self::SKIP_VARIABLES[$name])) {
                return null;
            }

            if (! $this->isCamelCase($name)) {
                return null;
            }

            $node->name = $this->toSnakeCase($name);
            return $node;
        }

        if ($node instanceof Param) {
            // Param->var is always Variable
            $var = $node->var;
            if (! is_string($var->name)) {
                return null;
            }

            $name = $var->name;

            if (isset(self::SKIP_VARIABLES[$name])) {
                return null;
            }

            if (! $this->isCamelCase($name)) {
                return null;
            }

            $var->name = $this->toSnakeCase($name);
            return $node;
        }

        if ($node instanceof PropertyProperty) {
            $name = $this->getName($node->name);
            if ($name === null || ! $this->isCamelCase($name)) {
                return null;
            }

            $node->name = new VarLikeIdentifier($this->toSnakeCase($name));
            return $node;
        }

        if ($node instanceof PropertyFetch) {
            $name = $this->getName($node->name);
            if ($name === null || ! $this->isCamelCase($name)) {
                return null;
            }

            $node->name = new Identifier($this->toSnakeCase($name));
            return $node;
        }

        if ($node instanceof StaticPropertyFetch) {
            $name = $this->getName($node->name);
            if ($name === null || ! $this->isCamelCase($name)) {
                return null;
            }

            $node->name = new VarLikeIdentifier($this->toSnakeCase($name));
            return $node;
        }

        return null;
    }

    private function isCamelCase(string $name): bool
    {
        // Skip names that already contain underscores or are all lower-case
        if (str_contains($name, '_')) {
            return false;
        }

        // Consider camelCase if it contains any uppercase letter
        return (bool) preg_match('/[A-Z]/', $name);
    }

    private function toSnakeCase(string $name): string
    {
        // Handle transitions like: fooBAR => foo_BAR, XMLHttp => XML_Http => xml_http
        $name = preg_replace('/([A-Z]+)([A-Z][a-z])/', '$1_$2', $name);
        $name = preg_replace('/([a-z0-9])([A-Z])/', '$1_$2', $name);
        return strtolower((string) $name);
    }
}

