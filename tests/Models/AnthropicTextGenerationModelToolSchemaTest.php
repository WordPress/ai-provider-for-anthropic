<?php

declare(strict_types=1);

namespace WordPress\AnthropicAiProvider\Tests\Models;

use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use stdClass;
use WordPress\AiClient\Providers\Models\DTO\ModelMetadata;
use WordPress\AiClient\Tools\DTO\FunctionDeclaration;
use WordPress\AnthropicAiProvider\Models\AnthropicTextGenerationModel;
use WordPress\AnthropicAiProvider\Provider\AnthropicProvider;

/**
 * Tests for how tool input schemas are prepared for the Anthropic API.
 *
 * @since n.e.x.t
 */
class AnthropicTextGenerationModelToolSchemaTest extends TestCase
{
    /**
     * Creates a model instance.
     *
     * @since n.e.x.t
     */
    private function createModel(): AnthropicTextGenerationModel
    {
        return new AnthropicTextGenerationModel(
            new ModelMetadata('claude-test', 'Claude Test', [], []),
            AnthropicProvider::metadata()
        );
    }

    /**
     * Flattens a schema through the protected helper.
     *
     * @since n.e.x.t
     *
     * @param array<string, mixed> $schema The schema.
     * @return array<string, mixed>
     */
    private function flatten(array $schema): array
    {
        $method = new ReflectionMethod(AnthropicTextGenerationModel::class, 'flattenTopLevelSchemaCombinators');
        $method->setAccessible(true);

        /** @var array<string, mixed> $result */
        $result = $method->invoke($this->createModel(), $schema);

        return $result;
    }

    /**
     * Prepares tools through the protected method.
     *
     * @since n.e.x.t
     *
     * @param FunctionDeclaration $declaration The declaration.
     * @return array<int, array<string, mixed>>
     */
    private function prepareTools(FunctionDeclaration $declaration): array
    {
        $method = new ReflectionMethod(AnthropicTextGenerationModel::class, 'prepareToolsParam');
        $method->setAccessible(true);

        /** @var array<int, array<string, mixed>> $result */
        $result = $method->invoke($this->createModel(), [$declaration], null);

        return $result;
    }

    /**
     * Asserts the flattened schema has no root combinator and is stable.
     *
     * @since n.e.x.t
     *
     * @param array<string, mixed> $input  The original schema.
     * @param array<string, mixed> $result The flattened schema.
     */
    private function assertValidFlattened(array $input, array $result): void
    {
        foreach (['oneOf', 'anyOf', 'allOf'] as $combinator) {
            $this->assertArrayNotHasKey($combinator, $result);
        }
        $this->assertSame('object', $result['type']);
        $this->assertEquals($result, $this->flatten($result), 'Flattening must be idempotent.');
        $this->assertEquals($result, $this->flatten($input));
    }

    /**
     * Builds an object schema with string properties.
     *
     * @since n.e.x.t
     *
     * @return array<string, mixed>
     */
    private function obj(string ...$names): array
    {
        $properties = [];
        foreach ($names as $name) {
            $properties[$name] = ['type' => 'string'];
        }

        return ['type' => 'object', 'properties' => $properties];
    }

    /**
     * @since n.e.x.t
     */
    public function testOneOfBranchesAreUnionedWithRequiredIntersected(): void
    {
        $input = [
            'oneOf' => [
                ['properties' => ['a' => ['type' => 'string'], 'b' => ['type' => 'string']], 'required' => ['a', 'b']],
                ['properties' => ['a' => ['type' => 'string'], 'c' => ['type' => 'string']], 'required' => ['a', 'c']],
            ],
        ];

        $result = $this->flatten($input);

        $this->assertValidFlattened($input, $result);
        $this->assertSame(['a', 'b', 'c'], array_keys($result['properties']));
        $this->assertSame(['a'], $result['required']);
    }

    /**
     * @since n.e.x.t
     */
    public function testAnyOfBranchesAreFlattened(): void
    {
        $input = ['anyOf' => [$this->obj('a'), $this->obj('b')]];

        $result = $this->flatten($input);

        $this->assertValidFlattened($input, $result);
        $this->assertSame(['a', 'b'], array_keys($result['properties']));
        $this->assertArrayNotHasKey('required', $result);
    }

    /**
     * @since n.e.x.t
     */
    public function testAllOfRequiredIsUnioned(): void
    {
        $input = [
            'allOf' => [
                ['properties' => ['a' => ['type' => 'string']], 'required' => ['a']],
                ['properties' => ['b' => ['type' => 'string']], 'required' => ['b']],
            ],
        ];

        $result = $this->flatten($input);

        $this->assertValidFlattened($input, $result);
        $this->assertSame(['a', 'b'], $result['required']);
    }

    /**
     * @since n.e.x.t
     */
    public function testNestedCombinatorsInPropertiesAreUntouched(): void
    {
        $nested = ['oneOf' => [['type' => 'string'], ['type' => 'integer']]];
        $input = [
            'type' => 'object',
            'properties' => ['value' => $nested],
            'oneOf' => [$this->obj('a'), $this->obj('b')],
        ];

        $result = $this->flatten($input);

        $this->assertValidFlattened($input, $result);
        $this->assertSame($nested, $result['properties']['value']);
    }

    /**
     * @since n.e.x.t
     */
    public function testSchemaWithoutTopLevelCombinatorIsUnchanged(): void
    {
        $input = [
            'type' => 'object',
            'properties' => ['a' => ['anyOf' => [['type' => 'string'], ['type' => 'null']]]],
            'required' => ['a'],
        ];

        $this->assertSame($input, $this->flatten($input));
    }

    /**
     * @since n.e.x.t
     */
    public function testNullParametersYieldEmptyObjectSchema(): void
    {
        $tools = $this->prepareTools(new FunctionDeclaration('noop', 'Does nothing.', null));

        $this->assertEquals(
            ['type' => 'object', 'properties' => new stdClass()],
            $tools[0]['input_schema']
        );
    }

    /**
     * @since n.e.x.t
     */
    public function testPrepareToolsParamFlattensTopLevelCombinator(): void
    {
        $tools = $this->prepareTools(new FunctionDeclaration('tool', 'A tool.', [
            'oneOf' => [$this->obj('a'), $this->obj('b')],
        ]));

        $schema = $tools[0]['input_schema'];
        $this->assertArrayNotHasKey('oneOf', $schema);
        $this->assertSame('object', $schema['type']);
        $this->assertSame(['a', 'b'], array_keys($schema['properties']));
    }

    /**
     * @since n.e.x.t
     */
    public function testAllOfContainingOneOfNeverReachesRoot(): void
    {
        $input = [
            'allOf' => [
                ['oneOf' => [$this->obj('a'), $this->obj('b')]],
                ['properties' => ['c' => ['type' => 'string']]],
            ],
        ];

        $result = $this->flatten($input);

        $this->assertValidFlattened($input, $result);
        $this->assertSame(['a', 'b', 'c'], array_keys($result['properties']));
    }

    /**
     * @since n.e.x.t
     */
    public function testAllOfContainingTwoOneOfsKeepsAllProperties(): void
    {
        $input = [
            'allOf' => [
                ['oneOf' => [$this->obj('a'), $this->obj('b')]],
                ['oneOf' => [$this->obj('c'), $this->obj('d')]],
            ],
        ];

        $result = $this->flatten($input);

        $this->assertValidFlattened($input, $result);
        $this->assertSame(['a', 'b', 'c', 'd'], array_keys($result['properties']));
    }

    /**
     * @since n.e.x.t
     */
    public function testNestedCombinatorsThroughPrepareToolsParam(): void
    {
        $tools = $this->prepareTools(new FunctionDeclaration('tool', 'A tool.', [
            'allOf' => [
                ['oneOf' => [$this->obj('a'), $this->obj('b')]],
                ['oneOf' => [$this->obj('c'), $this->obj('d')]],
            ],
        ]));

        $schema = $tools[0]['input_schema'];
        $this->assertArrayNotHasKey('oneOf', $schema);
        $this->assertArrayNotHasKey('allOf', $schema);
        $this->assertSame(['a', 'b', 'c', 'd'], array_keys($schema['properties']));
    }

    /**
     * @since n.e.x.t
     */
    public function testConstDiscriminatorsKeepEveryAlternative(): void
    {
        $input = [
            'oneOf' => [
                ['properties' => ['kind' => ['const' => 'a']]],
                ['properties' => ['kind' => ['const' => 'b']]],
            ],
        ];

        $result = $this->flatten($input);

        $this->assertValidFlattened($input, $result);
        $this->assertSame(
            ['anyOf' => [['const' => 'a'], ['const' => 'b']]],
            $result['properties']['kind']
        );
    }

    /**
     * @since n.e.x.t
     */
    public function testDifferentTypesForSamePropertyAreBothKept(): void
    {
        $input = [
            'anyOf' => [
                ['properties' => ['value' => ['type' => 'string']]],
                ['properties' => ['value' => ['type' => 'integer']]],
            ],
        ];

        $result = $this->flatten($input);

        $this->assertValidFlattened($input, $result);
        $this->assertSame(
            ['anyOf' => [['type' => 'string'], ['type' => 'integer']]],
            $result['properties']['value']
        );
    }

    /**
     * @since n.e.x.t
     */
    public function testIdenticalPropertySchemasAreDeduplicated(): void
    {
        $input = [
            'oneOf' => [
                ['properties' => ['id' => ['type' => 'integer']]],
                ['properties' => ['id' => ['type' => 'integer']]],
            ],
        ];

        $this->assertSame(['type' => 'integer'], $this->flatten($input)['properties']['id']);
    }

    /**
     * @since n.e.x.t
     */
    public function testUnconstrainedBranchKeepsPropertyUnconstrained(): void
    {
        $input = [
            'oneOf' => [
                ['properties' => ['value' => ['type' => 'string']]],
                ['properties' => ['value' => []]],
                ['properties' => ['value' => ['type' => 'integer']]],
            ],
        ];

        $result = $this->flatten($input);

        $this->assertValidFlattened($input, $result);
        $this->assertSame('{}', json_encode($result['properties']['value']));
    }

    /**
     * @since n.e.x.t
     */
    public function testAllOfCombinesPropertySchemasWithAllOf(): void
    {
        $input = [
            'allOf' => [
                ['properties' => ['n' => ['type' => 'integer']]],
                ['properties' => ['n' => ['minimum' => 1]]],
            ],
        ];

        $result = $this->flatten($input);

        $this->assertValidFlattened($input, $result);
        $this->assertSame(
            ['allOf' => [['type' => 'integer'], ['minimum' => 1]]],
            $result['properties']['n']
        );
    }

    /**
     * @since n.e.x.t
     */
    public function testParentPropertyIsCombinedWithBranchAlternatives(): void
    {
        $input = [
            'type' => 'object',
            'properties' => ['kind' => ['type' => 'string']],
            'oneOf' => [
                ['properties' => ['kind' => ['const' => 'a']]],
                ['properties' => ['kind' => ['const' => 'b']]],
            ],
        ];

        $result = $this->flatten($input);

        $this->assertValidFlattened($input, $result);
        $this->assertSame(
            [
                'allOf' => [
                    ['type' => 'string'],
                    ['anyOf' => [['const' => 'a'], ['const' => 'b']]],
                ],
            ],
            $result['properties']['kind']
        );
    }

    /**
     * Enum values are no longer unioned, so loosely-equal but distinct values survive.
     *
     * @since n.e.x.t
     */
    public function testMixedTypeEnumsKeepAllDistinctValues(): void
    {
        $input = [
            'oneOf' => [
                ['properties' => ['status' => ['enum' => [true]], 'n' => ['enum' => [0]]]],
                ['properties' => ['status' => ['enum' => ['1', 'draft']], 'n' => ['enum' => ['0']]]],
            ],
        ];

        $result = $this->flatten($input);

        $this->assertValidFlattened($input, $result);
        $this->assertSame(
            ['anyOf' => [['enum' => [true]], ['enum' => ['1', 'draft']]]],
            $result['properties']['status']
        );
        $this->assertSame(
            ['anyOf' => [['enum' => [0]], ['enum' => ['0']]]],
            $result['properties']['n']
        );
    }

    /**
     * @since n.e.x.t
     */
    public function testDisjunctionBranchKeywordsAreNotLiftedToRoot(): void
    {
        $input = [
            'oneOf' => [
                [
                    '$ref' => '#/$defs/A',
                    'not' => ['required' => ['x']],
                    'minProperties' => 1,
                    'if' => ['required' => ['a']],
                    'then' => ['required' => ['b']],
                    'dependentRequired' => ['a' => ['b']],
                    'title' => 'Branch A',
                    'properties' => ['a' => ['type' => 'string']],
                ],
                ['$ref' => '#/$defs/B'],
            ],
        ];

        $result = $this->flatten($input);

        $this->assertValidFlattened($input, $result);
        foreach (['$ref', 'not', 'minProperties', 'if', 'then', 'dependentRequired'] as $key) {
            $this->assertArrayNotHasKey($key, $result);
        }
        $this->assertSame('Branch A', $result['title']);
        $this->assertSame(['a'], array_keys($result['properties']));
    }

    /**
     * @since n.e.x.t
     */
    public function testWooCommerceStyleDiscriminatorKeepsEveryProductType(): void
    {
        $input = [
            'type' => 'object',
            'oneOf' => [
                [
                    'properties' => [
                        'product_type_alias' => ['type' => 'string', 'enum' => ['simple']],
                        'regular_price' => ['type' => 'string'],
                    ],
                    'required' => ['product_type_alias'],
                ],
                [
                    'properties' => [
                        'product_type_alias' => ['type' => 'string', 'enum' => ['variable']],
                        'attributes' => ['type' => 'array'],
                    ],
                    'required' => ['product_type_alias'],
                ],
                [
                    'properties' => [
                        'product_type_alias' => ['type' => 'string', 'enum' => ['grouped']],
                    ],
                    'required' => ['product_type_alias'],
                ],
            ],
        ];

        $tools = $this->prepareTools(new FunctionDeclaration('woocommerce-create-product', 'Create.', $input));
        $schema = $tools[0]['input_schema'];

        $this->assertArrayNotHasKey('oneOf', $schema);
        $this->assertSame(['product_type_alias'], $schema['required']);
        $this->assertSame(
            [
                'anyOf' => [
                    ['type' => 'string', 'enum' => ['simple']],
                    ['type' => 'string', 'enum' => ['variable']],
                    ['type' => 'string', 'enum' => ['grouped']],
                ],
            ],
            $schema['properties']['product_type_alias']
        );
        $this->assertArrayHasKey('regular_price', $schema['properties']);
        $this->assertArrayHasKey('attributes', $schema['properties']);
    }
}
