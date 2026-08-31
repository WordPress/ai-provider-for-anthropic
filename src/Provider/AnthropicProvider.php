<?php

declare(strict_types=1);

namespace WordPress\AnthropicAiProvider\Provider;

use WordPress\AiClient\AiClient;
use WordPress\AiClient\Common\Exception\RuntimeException;
use WordPress\AiClient\Providers\ApiBasedImplementation\AbstractApiProvider;
use WordPress\AiClient\Providers\ApiBasedImplementation\ListModelsApiBasedProviderAvailability;
use WordPress\AiClient\Providers\Contracts\ModelMetadataDirectoryInterface;
use WordPress\AiClient\Providers\Contracts\ProviderAvailabilityInterface;
use WordPress\AiClient\Providers\DTO\ProviderMetadata;
use WordPress\AiClient\Providers\Enums\ProviderTypeEnum;
use WordPress\AiClient\Providers\Http\Enums\RequestAuthenticationMethod;
use WordPress\AiClient\Providers\Models\Contracts\ModelInterface;
use WordPress\AiClient\Providers\Models\DTO\ModelMetadata;
use WordPress\AnthropicAiProvider\Metadata\AnthropicModelMetadataDirectory;
use WordPress\AnthropicAiProvider\Models\AnthropicTextGenerationModel;

/**
 * Class for the Anthropic provider.
 *
 * @since 1.0.0
 */
class AnthropicProvider extends AbstractApiProvider
{
    /**
     * {@inheritDoc}
     *
     * @since 1.0.0
     */
    protected static function baseUrl(): string
    {
        return 'https://api.anthropic.com/v1';
    }

    /**
     * {@inheritDoc}
     *
     * @since 1.0.0
     */
    protected static function createModel(
        ModelMetadata $modelMetadata,
        ProviderMetadata $providerMetadata
    ): ModelInterface {
        $capabilities = $modelMetadata->getSupportedCapabilities();
        foreach ($capabilities as $capability) {
            if ($capability->isTextGeneration()) {
                return new AnthropicTextGenerationModel($modelMetadata, $providerMetadata);
            }
        }

        throw new RuntimeException('Unsupported model capabilities.');
    }

    /**
     * {@inheritDoc}
     *
     * @since 1.0.0
     */
    protected static function createProviderMetadata(): ProviderMetadata
    {
        $providerMetadataArgs = [
            'anthropic',
            'Anthropic',
            ProviderTypeEnum::cloud(),
            'https://console.anthropic.com/settings/keys',
            RequestAuthenticationMethod::apiKey()
        ];
        // Provider description support was added in 1.2.0.
        if (version_compare(AiClient::VERSION, '1.2.0', '>=')) {
            // For WordPress, we should translate the description.
            if (function_exists('__')) {
                $providerMetadataArgs[] = __('Text generation with Claude.', 'ai-provider-for-anthropic');
            } else {
                $providerMetadataArgs[] = 'Text generation with Claude.';
            }
        }
        // Provider logoPath support was added in 1.3.0.
        if (version_compare(AiClient::VERSION, '1.3.0', '>=')) {
            $providerMetadataArgs[] = dirname(__DIR__, 2) . '/assets/images/anthropic.svg';
        }
        return new ProviderMetadata(...$providerMetadataArgs);
    }

    /**
     * {@inheritDoc}
     *
     * @since 1.0.0
     */
    protected static function createProviderAvailability(): ProviderAvailabilityInterface
    {
        // Check valid API access by attempting to list models.
        return new ListModelsApiBasedProviderAvailability(
            static::modelMetadataDirectory()
        );
    }

    /**
     * {@inheritDoc}
     *
     * @since 1.0.0
     */
    protected static function createModelMetadataDirectory(): ModelMetadataDirectoryInterface
    {
        return new AnthropicModelMetadataDirectory();
    }
}
