=== AI Provider for Anthropic ===
Contributors:      wordpressdotorg
Tags:              ai, anthropic, claude, artificial-intelligence, connector
Requires at least: 6.9
Tested up to:      7.1
Stable tag:        1.0.5
Requires PHP:      7.4
License:           GPL-2.0-or-later
License URI:       https://www.gnu.org/licenses/gpl-2.0.html

Anthropic (Claude) provider for the PHP AI Client SDK.

== Description ==

This plugin provides Anthropic integration for the PHP AI Client SDK. It enables WordPress sites to use Anthropic's Claude models for text generation and other AI capabilities.

**Features:**

* Text generation with Claude models
* Function calling support
* Extended thinking support
* Automatic provider registration

Available models are dynamically discovered from the Anthropic API, including Claude models for text generation with multimodal input support.

**Requirements:**

* PHP 7.4 or higher
* For WordPress 6.9, the [wordpress/php-ai-client](https://github.com/WordPress/php-ai-client) package must be installed
* For WordPress 7.0 and above, no additional changes are required
* Anthropic API key

== Installation ==

1. Upload the plugin files to `/wp-content/plugins/ai-provider-for-anthropic/`
2. Activate the plugin through the 'Plugins' menu in WordPress
3. On WordPress 7.0 and above, add your Anthropic API key under Settings > Connectors. Alternatively, on any supported WordPress version, provide the key via the `ANTHROPIC_API_KEY` environment variable or PHP constant

== Frequently Asked Questions ==

= How do I get an Anthropic API key? =

Visit the [Anthropic Console](https://console.anthropic.com/) to create an account and generate an API key.

= Does this plugin work without the PHP AI Client? =

No. This plugin provides the Anthropic-specific implementation that the PHP AI Client uses. WordPress 7.0 and above include the PHP AI Client, so nothing else needs to be installed. On WordPress 6.9, the [wordpress/php-ai-client](https://github.com/WordPress/php-ai-client) package must be installed separately.

= Why do I see "It was not possible to connect to the provider using this key"? =

When you save a key under Settings > Connectors, WordPress checks it by requesting the list of available models from the Anthropic API. This message is shown whenever that request fails, whatever the reason. Common causes are:

* The key was mistyped, copied with extra characters, or has been revoked in the Anthropic Console.
* The server cannot reach `api.anthropic.com`, for example because of a firewall, a proxy, or the network setup of a local development environment.
* The request took longer than the default WordPress HTTP timeout of 5 seconds.
* The Anthropic API was temporarily unavailable.

To find out which one applies, run the same request from the server or container that runs WordPress, replacing `YOUR_API_KEY` with your key:

`curl -sS -m 5 https://api.anthropic.com/v1/models -H "x-api-key: YOUR_API_KEY" -H "anthropic-version: 2023-06-01"`

If this returns a list of models, the key and the connection both work. Otherwise the output shows whether the key was rejected or the API could not be reached.

== Changelog ==

= 1.0.5 - 2026-10-01 =

**Changed**

* Throw `TokenLimitReachedException` when the Anthropic API stops with `max_tokens` or `model_context_window_exceeded`, instead of silently returning a truncated result ([#4](https://github.com/WordPress/ai-provider-for-anthropic/pull/4)).
* Added a WordPress Playground preview blueprint ([#40](https://github.com/WordPress/ai-provider-for-anthropic/pull/40)).

**Fixed**

* Preserved extended thinking signatures across conversation turns, preventing "thinking.signature: Field required" errors on multi-turn conversations ([#31](https://github.com/WordPress/ai-provider-for-anthropic/pull/31)).
* Continued turns that stop with `pause_turn` instead of returning a truncated result ([#32](https://github.com/WordPress/ai-provider-for-anthropic/pull/32)).
* Made model sorting robust to empty regex matches and tested compatibility with PHP AI Client 1.3.1 ([#34](https://github.com/WordPress/ai-provider-for-anthropic/pull/34)).
* Anthropic thinking-token usage is now reported via thoughtTokens, with totals preserved and paused-turn continuations accumulated correctly ([#44](https://github.com/WordPress/ai-provider-for-anthropic/pull/44)).

= 1.0.4 - 2026-08-17 =

**Changed**

* Bumped WordPress tested-up-to version 7.1 [#35](https://github.com/WordPress/ai-provider-for-anthropic/pull/35).

**Fixed**

* Corrected model metadata for Claude Opus 4.7 and later, including Claude 5 models, so unsupported sampling parameters are no longer advertised during automatic model selection [#25](https://github.com/WordPress/ai-provider-for-anthropic/pull/25).
* Normalized empty tool-call arguments as JSON objects, preventing Anthropic API errors when replaying tool calls that accept no arguments [#23](https://github.com/WordPress/ai-provider-for-anthropic/pull/23).

= 1.0.3 =

* Add a provider logo to the metadata if the client version > 1.3.0 ([#18](https://github.com/WordPress/ai-provider-for-anthropic/pull/18)).

= 1.0.2 =

* Add plugin directory assets by @shaunandrews in https://github.com/WordPress/ai-provider-for-anthropic/pull/10
* Add 'connector' tag to readme.txt by @jeffpaul in https://github.com/WordPress/ai-provider-for-anthropic/pull/12
* Add provider description by @felixarntz in https://github.com/WordPress/ai-provider-for-anthropic/pull/13

= 1.0.1 =

* Initial release of the plugin
* Support for Claude text generation models
* Function calling support
* Extended thinking support

= 1.0.0 =

* Initial release of the Composer package
