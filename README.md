# AI Provider for Anthropic

An Anthropic (Claude) provider for the [PHP AI Client](https://github.com/WordPress/php-ai-client) SDK. Works as both a Composer package and a WordPress plugin.

## Requirements

- PHP 7.4 or higher
- When using with WordPress, requires WordPress 7.0 or higher
    - If using an older WordPress release, the [wordpress/php-ai-client](https://github.com/WordPress/php-ai-client) package must be installed

## Installation

### As a Composer Package

```bash
composer require wordpress/ai-provider-for-anthropic
```

### As a WordPress Plugin

1. Download the plugin files
2. Upload to `/wp-content/plugins/ai-provider-for-anthropic/`
3. Ensure the PHP AI Client plugin is installed and activated
4. Activate the plugin through the WordPress admin

## Usage

### With WordPress

The provider automatically registers itself with the PHP AI Client on the `init` hook. Simply ensure both plugins are active and configure your API key:

```php
// Set your Anthropic API key (or use the ANTHROPIC_API_KEY environment variable)
putenv('ANTHROPIC_API_KEY=your-api-key');

// Use the provider
$result = AiClient::prompt('Hello, world!')
    ->usingProvider('anthropic')
    ->generateTextResult();
```

### As a Standalone Package

```php
use WordPress\AiClient\AiClient;
use WordPress\AnthropicAiProvider\Provider\AnthropicProvider;

// Register the provider
$registry = AiClient::defaultRegistry();
$registry->registerProvider(AnthropicProvider::class);

// Set your API key
putenv('ANTHROPIC_API_KEY=your-api-key');

// Generate text
$result = AiClient::prompt('Explain quantum computing')
    ->usingProvider('anthropic')
    ->generateTextResult();

echo $result->toText();
```

## Supported Models

Available models are dynamically discovered from the Anthropic API. This includes Claude models for text generation with multimodal input support. See the [Anthropic documentation](https://docs.anthropic.com/en/docs/about-claude/models) for the full list of available models.

## Configuration

The provider uses the `ANTHROPIC_API_KEY` environment variable for authentication. You can set this in your environment or via PHP:

```php
putenv('ANTHROPIC_API_KEY=your-api-key');
```

### Maintainers

Maintainers for this repo are [David Stone (@superdav42)](https://github.com/superdav42), [Felix Arntz (@felixarntz)](https://github.com/felixarntz), [Jason Adams (@JasonTheAdams)](https://github.com/JasonTheAdams), [Jeffrey Paul (@jeffpaul)](https://github.com/jeffpaul), and [Lauri Saarni (@saarnilauri)](https://github.com/saarnilauri); all can be reached using the [@WordPress/ai-provider-for-anthropic-maintainers](https://github.com/orgs/WordPress/teams/ai-provider-for-anthropic-maintainers) team.

## License

GPL-2.0-or-later
