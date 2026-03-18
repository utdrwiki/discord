<?php

namespace MediaWiki\Extension\Discord;

use MediaWiki\Config\ServiceOptions;
use MediaWiki\Logger\LoggerFactory;
use Psr\Log\LoggerInterface;
use function is_array;
use function is_string;

class ExtensionConfig {
	public const SERVICE_NAME = 'Discord.Config';
	public const LOG_CHANNEL = 'Discord';
	public const WEBHOOK_URL = 'DiscordWebhookURL';
	public const DISABLED_NAMESPACES = 'DiscordDisabledNS';
	public const DISABLED_USERS = 'DiscordDisabledUsers';
	public const EMOJIS = 'DiscordEmojis';
	public const CONSTRUCTOR_OPTIONS = [
		self::WEBHOOK_URL,
		self::DISABLED_NAMESPACES,
		self::DISABLED_USERS,
		self::EMOJIS,
	];

	private LoggerInterface $logger;

	public function __construct(
		private readonly ServiceOptions $options,
	) {
		$this->options->assertRequiredOptions( self::CONSTRUCTOR_OPTIONS );
		$this->logger = LoggerFactory::getInstance( self::LOG_CHANNEL );
	}

	public function getWebhookURL(): array {
		$webhookUrl = $this->options->get( self::WEBHOOK_URL );
		if ( is_string( $webhookUrl ) ) {
			return [ $webhookUrl ];
		}
		if ( is_array( $webhookUrl ) ) {
			return $webhookUrl;
		}
		$this->logger->warning( 'The value of $wgDiscordWebhookURL is not valid and therefore no messages will be sent.' );
		return [];
	}

	public function getDisabledNamespaces(): array {
		$disabledNamespaces = $this->options->get( self::DISABLED_NAMESPACES );
		if ( is_array( $disabledNamespaces ) ) {
			return $disabledNamespaces;
		}
		$this->logger->warning( 'The value of $wgDiscordDisabledNS is not valid and therefore all namespaces are enabled.' );
		return [];
	}

	public function getDisabledUsers(): array {
		$disabledUsers = $this->options->get( self::DISABLED_USERS );
		if ( is_array( $disabledUsers ) ) {
			return $disabledUsers;
		}
		$this->logger->warning( 'The value of $wgDiscordDisabledUsers is not valid and therefore all users can trigger messages.' );
		return [];
	}

	public function getEmoji( string $emoji ): string|null {
		return $this->options->get( self::EMOJIS )[$emoji] ?? null;
	}
}
