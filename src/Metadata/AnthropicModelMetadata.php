<?php

declare(strict_types=1);

namespace WordPress\AnthropicAiProvider\Metadata;

use WordPress\AiClient\Providers\Models\DTO\ModelMetadata;
use WordPress\AiClient\Providers\Models\DTO\SupportedOption;
use WordPress\AiClient\Providers\Models\Enums\CapabilityEnum;

/**
 * Class for Anthropic model metadata.
 *
 * Extends the generic model metadata with the model's output token limit, as reported by the
 * Anthropic Models API, so that requests without a configured limit can use a default that fits
 * the model.
 *
 * @since n.e.x.t
 */
class AnthropicModelMetadata extends ModelMetadata
{
    public const KEY_MAX_OUTPUT_TOKENS = 'maxOutputTokens';

    /**
     * @var int|null The maximum value for the `max_tokens` parameter, or null if unknown.
     */
    protected ?int $maxOutputTokens;

    /**
     * Constructor.
     *
     * @since n.e.x.t
     *
     * @param string $id The model's unique identifier.
     * @param string $name The model's display name.
     * @param list<CapabilityEnum> $supportedCapabilities The model's supported capabilities.
     * @param list<SupportedOption> $supportedOptions The model's supported configuration options.
     * @param int|null $maxOutputTokens The maximum value for the `max_tokens` parameter, or null if unknown.
     */
    public function __construct(
        string $id,
        string $name,
        array $supportedCapabilities,
        array $supportedOptions,
        ?int $maxOutputTokens = null
    ) {
        parent::__construct($id, $name, $supportedCapabilities, $supportedOptions);

        $this->maxOutputTokens = $maxOutputTokens !== null && $maxOutputTokens > 0 ? $maxOutputTokens : null;
    }

    /**
     * Returns the maximum value for the `max_tokens` parameter when using this model.
     *
     * @since n.e.x.t
     *
     * @return int|null The output token limit, or null if unknown.
     */
    public function getMaxOutputTokens(): ?int
    {
        return $this->maxOutputTokens;
    }

    /**
     * {@inheritDoc}
     *
     * @since n.e.x.t
     */
    public static function getJsonSchema(): array
    {
        $schema = parent::getJsonSchema();

        $properties = isset($schema['properties']) && is_array($schema['properties']) ? $schema['properties'] : [];
        $properties[self::KEY_MAX_OUTPUT_TOKENS] = [
            'type' => ['integer', 'null'],
            'description' => 'The maximum value for the max_tokens parameter when using this model.',
        ];
        $schema['properties'] = $properties;

        return $schema;
    }

    /**
     * {@inheritDoc}
     *
     * @since n.e.x.t
     */
    public function toArray(): array
    {
        $data = parent::toArray();
        $data[self::KEY_MAX_OUTPUT_TOKENS] = $this->maxOutputTokens;

        return $data;
    }

    /**
     * {@inheritDoc}
     *
     * @since n.e.x.t
     */
    public static function fromArray(array $array): self
    {
        $metadata = ModelMetadata::fromArray($array);
        $maxOutputTokens = $array[self::KEY_MAX_OUTPUT_TOKENS] ?? null;

        return new self(
            $metadata->getId(),
            $metadata->getName(),
            $metadata->getSupportedCapabilities(),
            $metadata->getSupportedOptions(),
            is_int($maxOutputTokens) ? $maxOutputTokens : null
        );
    }
}
