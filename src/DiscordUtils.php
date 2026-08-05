<?php

namespace MediaWiki\Extension\Discord;

use MediaWiki\Block\DatabaseBlock;
use MediaWiki\Context\RequestContext;
use MediaWiki\Http\HttpRequestFactory;
use MediaWiki\JobQueue\JobQueueGroup;
use MediaWiki\Revision\RevisionLookup;
use MediaWiki\Revision\RevisionRecord;
use MediaWiki\SpecialPage\SpecialPage;
use MediaWiki\User\User;
use MediaWiki\User\UserFactory;
use MediaWiki\Title\Title;
use MediaWiki\User\UserIdentity;
use function count;
use function in_array;
use function is_string;
use function sprintf;

class DiscordUtils {
	public const SERVICE_NAME = 'Discord.Utils';

	private RequestContext $context;

	public function __construct(
		private readonly ExtensionConfig $config,
		private readonly HttpRequestFactory $httpRequestFactory,
		private readonly JobQueueGroup $jobQueueGroup,
		private readonly RevisionLookup $revisionLookup,
		private readonly UserFactory $userFactory,
	) {
		$this->context = RequestContext::getMain();
	}

	private function msg( string $key, string ...$params ): string {
		return $this->context->msg( $key, ...$params )->inContentLanguage()->plain();
	}

	/**
	 * Checks if criteria is met for this action to be cancelled
	 */
	public function isDisabled( int|null $ns = null, UserIdentity|null $user = null ): bool {
		$disabledNamespaces = $this->config->getDisabledNamespaces();
		$disabledUsers = $this->config->getDisabledUsers();
		if ( $ns !== null ) {
			$ns = (int)$ns;
			if ( in_array( $ns, $disabledNamespaces ) ) {
				return true;
			}
		}
		if ( $user instanceof UserIdentity ) {
			if ( in_array( $user->getName(), $disabledUsers ) ) {
				return true;
			}
			if ( $this->userFactory->newFromUserIdentity( $user )->isBot() ) {
				return true;
			}
		}
		return false;
	}

	public function getBlockTarget( DatabaseBlock $block ): User|string {
		$target = $block->getTargetUserIdentity();
		if ( $target === null ) {
			return $block->getTargetName();
		} else {
			return $this->userFactory->newFromUserIdentity( $target );
		}
	}

	/**
	 * Queues a job for sending a message to Discord.
	 */
	public function send( string $msg, string ...$params ): void {
		$urls = $this->config->getWebhookURL();
		if ( empty( $urls ) ) {
			return;
		}
		$message = $this->context->msg( "discord-msg-$msg", ...$params );
		if ( $message->isDisabled() ) {
			return;
		}

		$content = $message->inContentLanguage()->plain();
		$content = preg_replace( '/\s+/', ' ', $content );

		$emoji = $this->config->getEmoji( $msg );
		if ( $emoji !== null ) {
			$content = "$emoji $content";
		}

		$this->jobQueueGroup->lazyPush( NotifyJob::newSpec( $urls, $content ) );
	}

	/**
	 * Creates a formatted markdown link based on text and given URL
	 */
	public function formatLink( string $text, string|Title $url ): string {
		if ( $url instanceof Title ) {
			$url = $url->getFullURL( '', false, PROTO_CANONICAL );
		}
		$url = str_replace( ' ', '%20', $url );
		$url = str_replace( '(', '%28', $url );
		$url = str_replace( ')', '%29', $url );
		if ( str_ends_with( $url, '%25' ) ) {
			$url = "{$url}_";
		}
		return "[$text](<$url>)";
	}

	/**
	 * Creates links for a specific MediaWiki User object
	 */
	public function formatUser( UserIdentity|string $user ): string {
		if ( is_string( $user ) ) {
			return $this->msg( 'discord-userlinks', $user, 'n/a', 'n/a' );
		}
		$user = $this->userFactory->newFromUserIdentity( $user );
		return $this->msg(
			'discord-userlinks',
			$this->formatLink( $user->getName(), $user->getUserPage() ),
			$this->formatLink( $this->msg( 'discord-talk' ), $user->getTalkPage() ),
			$this->formatLink( $this->msg( 'discord-contribs' ), SpecialPage::getTitleFor( 'Contributions', $user->getName() ) ),
		);
	}

	/**
	 * Creates formatted text for a specific Revision object
	 */
	public function formatRevision( RevisionRecord $revision ): string {
		$diff = $this->formatLink(
			$this->msg( 'discord-diff' ),
			Title::newFromLinkTarget( $revision->getPageAsLinkTarget() )->getFullURL( [
				'diff' => 'prev',
				'oldid' => $revision->getId(),
				'ref' => 'discord',
			], false, PROTO_CANONICAL )
		);
		$minor = $revision->isMinor() ? $this->msg( 'discord-minor' ) : '';
		$size = '';
		$parentId = $revision->getParentId();
		if ( $parentId ) {
			$parent = $this->revisionLookup->getRevisionById( $parentId );
			if ( $parent ) {
				$diffSize = $revision->getSize() - $parent->getSize();
				$size = $this->msg( 'discord-size', sprintf( "%+d", $diffSize ) );
				if ( $diffSize > 500 || $diffSize < -500 ) {
					$size = "**$size**";
				}
			}
		}
		if ( $size === '' ) {
			$revSize = $revision->getSize();
			$size = $this->msg( 'discord-size', sprintf( "%d", $revSize ) );
			if ( $revSize > 500 || $revSize < -500 ) {
				$size = "**$size**";
			}
		}
		return $this->msg( 'discord-revisionlinks', $diff, $minor, $size );
	}

	/**
	 * Formats bytes to a string representing B, KB, MB, GB, TB
	 */
	public function formatBytes($bytes, $precision = 2 ): string {
		$units = [ 'B', 'KB', 'MB', 'GB', 'TB' ];
		$bytes = max( $bytes, 0 );
		$pow = floor( ( $bytes ? log( $bytes ) : 0 ) / log( 1024 ) );
		$pow = min( $pow, count( $units ) - 1 );
		$bytes /= 1 << ( 10 * $pow );
		return round( $bytes, $precision ) . ' ' . $units[$pow];
	}

	/**
	 * Formats summaries (edit summaries, log reasons, etc.) as inline code.
	 */
	public function formatSummary( string|null $text ): string {
		if ( empty( $text ) ) {
			return '';
		}
		$text = str_replace( '`', '', $text );
		return "`$text`";
	}
}
