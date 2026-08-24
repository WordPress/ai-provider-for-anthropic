<?php

declare(strict_types=1);

namespace WordPress\AnthropicAiProvider\Metadata;

use WordPress\AiClient\Messages\Enums\ModalityEnum;
use WordPress\AiClient\Providers\Http\Contracts\RequestAuthenticationInterface;
use WordPress\AiClient\Providers\Http\DTO\ApiKeyRequestAuthentication;
use WordPress\AiClient\Providers\Http\DTO\Request;
use WordPress\AiClient\Providers\Http\DTO\Response;
use WordPress\AiClient\Providers\Http\Enums\HttpMethodEnum;
use WordPress\AiClient\Providers\Http\Exception\ResponseException;
use WordPress\AiClient\Providers\Models\DTO\ModelMetadata;
use WordPress\AiClient\Providers\Models\DTO\SupportedOption;
use WordPress\AiClient\Providers\Models\Enums\CapabilityEnum;
use WordPress\AiClient\Providers\Models\Enums\OptionEnum;
use WordPress\AiClient\Providers\OpenAiCompatibleImplementation\AbstractOpenAiCompatibleModelMetadataDirectory;
use WordPress\AnthropicAiProvider\Authentication\AnthropicApiKeyRequestAuthentication;
use WordPress\AnthropicAiProvider\Provider\AnthropicProvider;

/**
 * Class for the Anthropic model metadata directory.
 *
 * @since 1.0.0
 *
 * @phpstan-type ModelsResponseData array{
 *     data: list<array{id: string, display_name?: string}>
 * }
 */
class AnthropicModelMetadataDirectory extends AbstractOpenAiCompatibleModelMetadataDirectory
{
    /**
     * {@inheritDoc}
     *
     * @since 1.0.0
     */
    public function getRequestAuthentication(): RequestAuthenticationInterface
    {
        /*
         * Since we're calling the Anthropic API here, we need to use the Anthropic specific
         * API key authentication class.
         */
        $requestAuthentication = parent::getRequestAuthentication();
        if (!$requestAuthentication instanceof ApiKeyRequestAuthentication) {
            return $requestAuthentication;
        }
        return new AnthropicApiKeyRequestAuthentication($requestAuthentication->getApiKey());
    }

    /**
     * {@inheritDoc}
     *
     * @since 1.0.0
     */
    protected function createRequest(HttpMethodEnum $method, string $path, array $headers = [], $data = null): Request
    {
        return new Request(
            $method,
            AnthropicProvider::url($path),
            $headers,
            $data
        );
    }

    /**
     * {@inheritDoc}
     *
     * @since 1.0.0
     */
    protected function parseResponseToModelMetadataList(Response $response): array
    {
        /** @var ModelsResponseData $responseData */
        $responseData = $response->getData();
        if (!isset($responseData['data']) || !$responseData['data']) {
            throw ResponseException::fromMissingData('Anthropic', 'data');
        }

        // Unfortunately, the Anthropic API does not return model capabilities, so we have to hardcode them here.
        $anthropicCapabilities = [
            CapabilityEnum::textGeneration(),
            CapabilityEnum::chatHistory(),
        ];
        $anthropicOptions = [
            new SupportedOption(OptionEnum::systemInstruction()),
            new SupportedOption(OptionEnum::maxTokens()),
            new SupportedOption(OptionEnum::stopSequences()),
            new SupportedOption(OptionEnum::outputMimeType(), ['text/plain', 'application/json']),
            new SupportedOption(OptionEnum::outputSchema()),
            new SupportedOption(OptionEnum::functionDeclarations()),
            new SupportedOption(OptionEnum::customOptions()),
            new SupportedOption(
                OptionEnum::inputModalities(),
                [
                    [ModalityEnum::text()],
                    [ModalityEnum::text(), ModalityEnum::image()],
                    [ModalityEnum::text(), ModalityEnum::document()],
                    [ModalityEnum::text(), ModalityEnum::image(), ModalityEnum::document()],
                ]
            ),
            new SupportedOption(OptionEnum::outputModalities(), [[ModalityEnum::text()]]),
        ];
        $anthropicSamplingOptions = [
            new SupportedOption(OptionEnum::temperature()),
            new SupportedOption(OptionEnum::topP()),
            new SupportedOption(OptionEnum::topK()),
        ];

        $modelsData = (array) $responseData['data'];

        $models = array_values(
            array_map(
                static function (array $modelData) use (
                    $anthropicCapabilities,
                    $anthropicOptions,
                    $anthropicSamplingOptions
                ): ModelMetadata {
                    $modelId = $modelData['id'];
                    $modelCaps = $anthropicCapabilities;
                    $modelOptions = $anthropicOptions;

                    if (!self::modelRejectsSamplingParameters($modelId)) {
                        $modelOptions = array_merge($modelOptions, $anthropicSamplingOptions);
                    }

                    if (!preg_match('/^claude-3-[a-z]+/', $modelId)) {
                        // Only models newer than Claude 3 support web search.
                        $modelOptions[] = new SupportedOption(OptionEnum::webSearch());
                    }

                    $modelName = $modelData['display_name'] ?? $modelId;

                    return new ModelMetadata(
                        $modelId,
                        $modelName,
                        $modelCaps,
                        $modelOptions
                    );
                },
                $modelsData
            )
        );

        usort($models, [$this, 'modelSortCallback']);

        return $models;
    }

    /**
     * Determines whether the given model rejects sampling parameters.
     *
     * Beginning with Claude Opus 4.7, the Anthropic API returns a 400 error when 'temperature', 'top_p', or 'top_k'
     * are set to any non-default value. The same applies to the Claude 5 generation models (e.g. Claude Fable 5).
     * Earlier Opus versions and the Sonnet and Haiku families (as of Sonnet 4.6 / Haiku 4.5) still accept sampling
     * parameters.
     *
     * Unknown future models at major version 5 or higher are assumed to reject sampling parameters as well. If that
     * assumption turns out wrong for a model, the model is merely not selected for prompts that require sampling
     * parameters, whereas advertising support the model does not have would lead to hard API errors.
     *
     * @link https://platform.claude.com/docs/en/about-claude/models/migration-guide
     *
     * @since 1.0.4
     *
     * @param string $modelId The model identifier, e.g. 'claude-opus-4-7'.
     * @return bool True if the model rejects sampling parameters, false otherwise.
     */
    protected static function modelRejectsSamplingParameters(string $modelId): bool
    {
        /*
         * Parse the Claude model family and major/minor version, e.g. 'claude-opus-4-7'.
         * The minor version is limited to two digits so that a date suffix in model IDs
         * without a minor version (e.g. 'claude-opus-4-20250514') is not mistaken for one.
         */
        if (!preg_match('/^claude-([a-z]+)-(\d+)(?:-(\d{1,2})(?!\d))?/', $modelId, $matches)) {
            return false;
        }

        $family = $matches[1];
        $major = (int) $matches[2];
        $minor = isset($matches[3]) ? (int) $matches[3] : 0;

        // All model families reject sampling parameters from major version 5 onwards.
        if ($major >= 5) {
            return true;
        }

        // Claude Opus 4.7 was the first model to reject sampling parameters.
        return $family === 'opus' && $major === 4 && $minor >= 7;
    }

    /**
     * Callback function for sorting models by ID, to be used with `usort()`.
     *
     * This method expresses preferences for certain models or model families within the provider by putting them
     * earlier in the sorted list. The objective is not to be opinionated about which models are better, but to ensure
     * that more commonly used, more recent, or flagship models are presented first to users.
     *
     * @since 1.0.0
     *
     * @param ModelMetadata $a First model.
     * @param ModelMetadata $b Second model.
     * @return int Comparison result.
     */
    protected function modelSortCallback(ModelMetadata $a, ModelMetadata $b): int
    {
        $aId = $a->getId();
        $bId = $b->getId();

        // Prefer Claude models over non-Claude models.
        if (str_starts_with($aId, 'claude-') && !str_starts_with($bId, 'claude-')) {
            return -1;
        }
        if (str_starts_with($bId, 'claude-') && !str_starts_with($aId, 'claude-')) {
            return 1;
        }

        /*
         * Prefer Claude models where the version number isn't the second segment (e.g. 'claude-sonnet-4')
         * over those where it is (e.g. 'claude-2', 'claude-3-5-sonnet'). The latter is only used for older models.
         */
        if (!preg_match('/^claude-\d/', $aId) && preg_match('/^claude-\d/', $bId)) {
            return -1;
        }
        if (!preg_match('/^claude-\d/', $bId) && preg_match('/^claude-\d/', $aId)) {
            return 1;
        }

        /*
         * Prefer Claude models with type and version number (e.g. 'claude-sonnet-4', 'claude-sonnet-4-5-20250929')
         * over those without. An optional date suffix may also be present.
         */
        $aMatch = preg_match('/^claude-([a-z]+)-(\d(-\d)?)(-[0-9]+)?$/', $aId, $aMatches);
        $bMatch = preg_match('/^claude-([a-z]+)-(\d(-\d)?)(-[0-9]+)?$/', $bId, $bMatches);
        if ($aMatch && !$bMatch) {
            return -1;
        }
        if ($bMatch && !$aMatch) {
            return 1;
        }
        if ($aMatch && $bMatch) {
            // Prefer later model versions.
            $aVersion = str_replace('-', '.', $aMatches[2]);
            $bVersion = str_replace('-', '.', $bMatches[2]);
            if (version_compare($aVersion, $bVersion, '>')) {
                return -1;
            }
            if (version_compare($bVersion, $aVersion, '>')) {
                return 1;
            }

            // Prefer models without a suffix (i.e. base models) over those with a suffix.
            if (!isset($aMatches[4]) && isset($bMatches[4])) {
                return -1;
            }
            if (!isset($bMatches[4]) && isset($aMatches[4])) {
                return 1;
            }

            // Prefer 'sonnet' models over other types.
            if ($aMatches[1] === 'sonnet' && $bMatches[1] !== 'sonnet') {
                return -1;
            }
            if ($bMatches[1] === 'sonnet' && $aMatches[1] !== 'sonnet') {
                return 1;
            }

            // Prefer later release dates.
            if (isset($aMatches[4]) && isset($bMatches[4])) {
                $aDate = (int) substr($aMatches[4], 1);
                $bDate = (int) substr($bMatches[4], 1);
                if ($aDate > $bDate) {
                    return -1;
                }
                if ($bDate > $aDate) {
                    return 1;
                }
            }
        }

        // Fallback: Sort alphabetically.
        return strcmp($a->getId(), $b->getId());
    }
}
