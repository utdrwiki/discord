<?php

use MediaWiki\Config\ServiceOptions;
use MediaWiki\Extension\Discord\ExtensionConfig;
use MediaWiki\Extension\Discord\DiscordUtils;
use MediaWiki\MediaWikiServices;

return [
	ExtensionConfig::SERVICE_NAME => fn (
		MediaWikiServices $services,
	): ExtensionConfig => new ExtensionConfig(
		new ServiceOptions(
			ExtensionConfig::CONSTRUCTOR_OPTIONS,
			$services->getMainConfig(),
		),
	),
	DiscordUtils::SERVICE_NAME => fn (
		MediaWikiServices $services,
	): DiscordUtils => new DiscordUtils(
		$services->getService( ExtensionConfig::SERVICE_NAME ),
		$services->getHttpRequestFactory(),
		$services->getJobQueueGroup(),
		$services->getRevisionLookup(),
		$services->getUserFactory(),
	),
];
