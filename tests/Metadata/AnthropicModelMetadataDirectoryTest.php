<?php

declare(strict_types=1);

namespace WordPress\AnthropicAiProvider\Tests\Metadata;

use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use WordPress\AiClient\Providers\Http\DTO\Response;
use WordPress\AiClient\Providers\Models\DTO\ModelMetadata;
use WordPress\AnthropicAiProvider\Metadata\AnthropicModelMetadata;
use WordPress\AnthropicAiProvider\Metadata\AnthropicModelMetadataDirectory;

/**
 * Tests for the Anthropic model metadata directory.
 *
 * @since n.e.x.t
 */
class AnthropicModelMetadataDirectoryTest extends TestCase
{
    /**
     * Parses a Models API response body through the protected method.
     *
     * @since n.e.x.t
     *
     * @param array<string, mixed> $body The response body.
     * @return array<string, ModelMetadata> Map of model ID to model metadata.
     */
    private function parseModels(array $body): array
    {
        $method = new ReflectionMethod(AnthropicModelMetadataDirectory::class, 'parseResponseToModelMetadataList');
        $method->setAccessible(true);

        /** @var list<ModelMetadata> $models */
        $models = $method->invoke(
            new AnthropicModelMetadataDirectory(),
            new Response(200, ['Content-Type' => 'application/json'], (string) json_encode($body))
        );

        $map = [];
        foreach ($models as $model) {
            $map[$model->getId()] = $model;
        }

        return $map;
    }

    /**
     * @since n.e.x.t
     */
    public function testParsesOutputTokenLimitFromModelsApi(): void
    {
        $models = $this->parseModels(
            [
                'data' => [
                    ['id' => 'claude-opus-5-5', 'display_name' => 'Claude Opus 5.5', 'max_tokens' => 128000],
                    ['id' => 'claude-3-haiku-20240307', 'display_name' => 'Claude Haiku 3', 'max_tokens' => 4096],
                    ['id' => 'claude-null-limit', 'max_tokens' => null],
                    ['id' => 'claude-missing-limit'],
                ],
            ]
        );

        $expected = [
            'claude-opus-5-5' => 128000,
            'claude-3-haiku-20240307' => 4096,
            'claude-null-limit' => null,
            'claude-missing-limit' => null,
        ];

        $this->assertCount(count($expected), $models);
        foreach ($expected as $modelId => $maxOutputTokens) {
            $this->assertInstanceOf(AnthropicModelMetadata::class, $models[$modelId]);
            $this->assertSame($maxOutputTokens, $models[$modelId]->getMaxOutputTokens(), $modelId);
        }
    }

    /**
     * @since n.e.x.t
     */
    public function testModelMetadataSurvivesArrayRoundTrip(): void
    {
        $metadata = new AnthropicModelMetadata('claude-opus-5-5', 'Claude Opus 5.5', [], [], 128000);

        $restored = AnthropicModelMetadata::fromArray($metadata->toArray());

        $this->assertSame(128000, $restored->getMaxOutputTokens());
        $this->assertSame($metadata->toArray(), $restored->toArray());
    }

    /**
     * @since n.e.x.t
     */
    public function testModelMetadataFromArrayWithoutLimit(): void
    {
        $data = (new ModelMetadata('claude-test', 'Claude Test', [], []))->toArray();

        $this->assertNull(AnthropicModelMetadata::fromArray($data)->getMaxOutputTokens());
    }

    /**
     * @since n.e.x.t
     */
    public function testNonPositiveLimitIsTreatedAsUnknown(): void
    {
        $this->assertNull((new AnthropicModelMetadata('claude-test', 'Claude Test', [], [], 0))->getMaxOutputTokens());
    }
}
