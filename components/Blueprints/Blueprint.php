<?php

namespace WordPress\Blueprints;

use WordPress\Blueprints\VersionStrings\VersionConstraint;

class Blueprint {
    /**
     * The original blueprint string.
     *
     * @var string
     */
    private $blueprint_string;

    /**
     * The parsed blueprint array conforming to the Blueprint v2 JSON schema.
     *
     * @var array<string, mixed>
     */
    private $blueprint_array;

    /**
     * The PHP version constraint.
     *
     * @var VersionConstraint|null
     */
    private $php_version_constraint;

    /**
     * The WordPress version constraint.
     *
     * @var VersionConstraint|null
     */
    private $wp_version_constraint;

    /**
     * The Blueprint execution plan represented as an array.
     *
     * @var array{
     *     name: string,
     *     args: array<string, mixed>,
     * }
     */
    private $execution_plan;

    /**
     * The errors encountered while parsing the blueprint.
     *
     * @var array<string, mixed>
     */
    private $errors = [];

    /**
     * Constructor.
     *
     * @param string                 $blueprint_string       The original blueprint string.
     * @param array<string, mixed>   $blueprint_array        The parsed blueprint array conforming to the Blueprint v2 JSON schema.
     * @param VersionConstraint|null $php_version_constraint The PHP version constraint.
     * @param VersionConstraint|null $wp_version_constraint  The WordPress version constraint.
     * @param array{
     *     name: string,
     *     args: array<string, mixed>,
     * }                             $execution_plan         The Blueprint execution plan represented as an array.
     * @param array<string, mixed>   $errors                 The errors encountered while parsing the blueprint.
     */
    public function __construct(
        string $blueprint_string,
        array $blueprint_array,
        ?VersionConstraint $php_version_constraint,
        ?VersionConstraint $wp_version_constraint,
        array $execution_plan,
        array $errors = []
    ) {
        $this->blueprint_string       = $blueprint_string;
        $this->blueprint_array        = $blueprint_array;
        $this->php_version_constraint = $php_version_constraint;
        $this->wp_version_constraint  = $wp_version_constraint;
        $this->execution_plan         = $execution_plan;
        $this->errors                 = $errors;
    }

    /**
     * Check if the blueprint is valid (i.e. has no errors).
     *
     * @return bool
     */
    public function isValid(): bool {
        return count( $this->errors ) === 0;
    }

    /**
     * Get the original blueprint string.
     *
     * @return string
     */
    public function getBlueprintString(): string {
        return $this->blueprint_string;
    }

    /**
     * Get the parsed blueprint array conforming to the Blueprint v2 JSON schema.
     *
     * @return array<string, mixed>
     */
    public function getBlueprintArray(): array {
        return $this->blueprint_array;
    }

    /**
     * Get the PHP version constraint.
     *
     * @return VersionConstraint|null
     */
    public function getPhpVersionConstraint(): ?VersionConstraint {
        return $this->php_version_constraint;
    }

    /**
     * Get the WordPress version constraint.
     *
     * @return VersionConstraint|null
     */
    public function getWpVersionConstraint(): ?VersionConstraint {
        return $this->wp_version_constraint;
    }

    /**
     * Get the Blueprint execution plan represented as an array.
     *
     * @return array{
     *     name: string,
     *     args: array<string, mixed>,
     * }
     */
    public function getExecutionPlan(): array {
        return $this->execution_plan;
    }

    /**
     * Get the errors encountered while parsing the blueprint.
     *
     * @return array<string, mixed>
     */
    public function getErrors(): array {
        return $this->errors;
    }
}
