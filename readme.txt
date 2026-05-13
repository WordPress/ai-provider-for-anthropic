=== AI Provider for Anthropic ===
Contributors: wordpressdotorg
Tags: ai, anthropic, claude, artificial-intelligence, connector
Requires at least: 6.9
Tested up to: 7.0
Stable tag: 1.0.3
Requires PHP: 7.4
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

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
3. Configure your Anthropic API key via the `ANTHROPIC_API_KEY` environment variable or constant

== Frequently Asked Questions ==

= How do I get an Anthropic API key? =

Visit the [Anthropic Console](https://console.anthropic.com/) to create an account and generate an API key.

= Does this plugin work without the PHP AI Client? =

No, this plugin requires the PHP AI Client plugin to be installed and activated. It provides the Anthropic-specific implementation that the PHP AI Client uses.

== Changelog ==

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
