<?php

namespace MediaWiki\Extension\Discord;

use MediaWiki\Http\HttpRequestFactory;
use MediaWiki\JobQueue\Job;
use MediaWiki\JobQueue\JobQueueGroup;
use MediaWiki\JobQueue\JobSpecification;
use MediaWiki\Logger\LoggerFactory;
use Psr\Log\LoggerInterface;
use Wikimedia\Http\MultiHttpClient;
use function count;
use function strlen;

class NotifyJob extends Job {
	public const USER_AGENT = 'mw-discord/2.0 (github.com/utdrwiki/discord)';
	public const JOB_NAME = 'DiscordNotify';

	private MultiHttpClient $client;
	private LoggerInterface $logger;

	public static function newSpec(
		array $urls,
		string $content,
		int $errorCount = 0,
		int $delay = 0,
	): JobSpecification {
		return new JobSpecification(
			self::JOB_NAME,
			[
				'urls' => $urls,
				'content' => $content,
				'errorCount' => $errorCount,
				'jobReleaseTimestamp' => ( $delay > 0 ) ? time() + $delay : null,
			]
		);
	}

	/** @inheritDoc */
	public function __construct(
		array $params,
		HttpRequestFactory $httpRequestFactory,
		private readonly JobQueueGroup $jobQueueGroup,
	) {
		parent::__construct( self::JOB_NAME, $params );
		$this->client = $httpRequestFactory->createMultiClient( [
			'connTimeout' => 10,
			'followRedirects' => true,
			'headers' => [
				'Content-Type' => 'application/json',
			],
			'reqTimeout' => 10,
			'userAgent' => self::USER_AGENT,
		] );
		$this->logger = LoggerFactory::getInstance( ExtensionConfig::LOG_CHANNEL );
	}

	/** @inheritDoc */
	public function run(): bool {
		$urls = $this->params['urls'];
		$content = $this->params['content'];

		$jobsToPop = 0;
		$contentLength = strlen( $content );
		// We need to calculate how many jobs we can pop without exceeding the
		// 2000 character limit for Discord messages, because pushing back a job
		// after popping it can lead to duplicate jobs.
		foreach ( $this->jobQueueGroup->get( self::JOB_NAME )->getAllQueuedJobs() as $job ) {
			$params = $job->getParams();
			$contentLength += strlen( $params['content'] ) + 1;
			if ( $contentLength > 2000 ) {
				break;
			}
			++$jobsToPop;
		}

		for ( $i = 0; $i < $jobsToPop; $i++ ) {
			$job = $this->jobQueueGroup->pop( self::JOB_NAME );
			if ( $job === false ) {
				$this->logger->warning( "Mismatch in the number of expected Discord jobs, received false. Check your job queue configuration." );
				break;
			}
			$params = $job->getParams();
			$moreContent = $params['content'];
			if ( strlen( $content ) + strlen( $moreContent ) + 1 <= 2000 ) {
				$content .= "\n$moreContent";
				$this->jobQueueGroup->ack( $job );
			} else {
				$this->jobQueueGroup->push( $job );
				$this->logger->warning( "Mismatch in the Discord message content length. Check your job queue configuration." );
				break;
			}
		}

		$errorCount = $this->params['errorCount'] ?? 0;

		$this->logger->debug( "Sending Discord webhook with content: $content" );

		$json = json_encode( [
			'content' => $content,
			'allowed_mentions' => [
				'parse' => []
			]
		] );
		$requests = $this->client->runMulti( array_map( fn( $url ) => [
			'method' => 'POST',
			'url' => $url,
			'body' => $json,
		], $urls ) );

		$requeueUrls = [];
		$retryAfter = 5;
		foreach ( $requests as $req ) {
			[ $code, $reason, $headers, $body, $error ] = $req['response'];
			if ( $code >= 200 && $code < 300 ) {
				$this->logger->debug( "Discord webhook sent successfully with response: $body" );
			} elseif ( $code >= 500 && $code < 600 ) {
				$this->logger->warning( "Discord webhook failed with server error $code $reason: $error. Retrying in 5 seconds." );
				$requeueUrls[] = $req['url'];
			} elseif ( $code === 429 ) {
				$retryAfter = isset( $headers['retry-after'] ) ? (int)$headers['retry-after'] : 5;
				$this->logger->warning( "Discord webhook rate limited: $error. Retrying in $retryAfter seconds." );
				$requeueUrls[] = $req['url'];
			} else {
				$response = $body ?? $error;
				$this->logger->error( "Discord webhook failed with status code $code $reason: $response" );
			}
		}
		if ( count( $requeueUrls ) > 0 ) {
			if ( $errorCount >= 3 ) {
				$this->logger->error( "Discord webhook failed after 3 attempts. The following content was not sent: $content" );
				return true;
			}
			$this->jobQueueGroup->push( NotifyJob::newSpec(
				$requeueUrls,
				$content,
				$errorCount + 1,
				$retryAfter,
			) );
		}
		return true;
	}
}
