<?php

namespace MediaWiki\Extension\Discord;

use MediaWiki\Auth\Hook\LocalUserCreatedHook;
use MediaWiki\Block\DatabaseBlock;
use MediaWiki\Context\RequestContext;
use MediaWiki\Extension\AbuseFilter\FilterLookup;
use MediaWiki\Extension\AbuseFilter\SpecsFormatter;
use MediaWiki\FileRepo\File\File;
use MediaWiki\FileRepo\File\LocalFile;
use MediaWiki\Hook\AfterImportPageHook;
use MediaWiki\Hook\ArticleMergeCompleteHook;
use MediaWiki\Hook\ArticleRevisionVisibilitySetHook;
use MediaWiki\Hook\BlockIpCompleteHook;
use MediaWiki\Hook\FileDeleteCompleteHook;
use MediaWiki\Hook\FileUndeleteCompleteHook;
use MediaWiki\Hook\FileUploadHook;
use MediaWiki\Hook\ManualLogEntryBeforePublishHook;
use MediaWiki\Hook\PageMoveCompleteHook;
use MediaWiki\Hook\RecentChange_saveHook;
use MediaWiki\Hook\UnblockUserCompleteHook;
use MediaWiki\Linker\LinkTarget;
use MediaWiki\Logging\ManualLogEntry;
use MediaWiki\MediaWikiServices;
use MediaWiki\Page\Hook\ArticleProtectCompleteHook;
use MediaWiki\Page\Hook\PageDeleteCompleteHook;
use MediaWiki\Page\Hook\PageUndeleteCompleteHook;
use MediaWiki\Page\ImagePage;
use MediaWiki\Page\ProperPageIdentity;
use MediaWiki\Page\WikiFilePage;
use MediaWiki\Page\WikiPage;
use MediaWiki\Parser\Parser;
use MediaWiki\Permissions\Authority;
use MediaWiki\RecentChanges\RecentChange;
use MediaWiki\RenameUser\Hook\RenameUserCompleteHook;
use MediaWiki\Revision\RevisionLookup;
use MediaWiki\Revision\RevisionRecord;
use MediaWiki\SpecialPage\SpecialPage;
use MediaWiki\Storage\EditResult;
use MediaWiki\Storage\Hook\PageSaveCompleteHook;
use MediaWiki\Title\ForeignTitle;
use MediaWiki\Title\Title;
use MediaWiki\Title\TitleFactory;
use MediaWiki\Upload\UploadBase;
use MediaWiki\User\Hook\UserGroupsChangedHook;
use MediaWiki\User\User;
use MediaWiki\User\UserGroupMembership;
use MediaWiki\User\UserIdentity;
use function count;
use function strlen;

/**
 * Hooks for the Discord extension
 *
 * @file
 * @ingroup Extensions
 */
class DiscordHooks implements
	PageSaveCompleteHook,
	PageDeleteCompleteHook,
	ArticleRevisionVisibilitySetHook,
	ArticleProtectCompleteHook,
	PageMoveCompleteHook,
	LocalUserCreatedHook,
	BlockIpCompleteHook,
	UnblockUserCompleteHook,
	UserGroupsChangedHook,
	FileUploadHook,
	FileDeleteCompleteHook,
	FileUndeleteCompleteHook,
	AfterImportPageHook,
	ArticleMergeCompleteHook,
	RenameUserCompleteHook,
	ManualLogEntryBeforePublishHook,
	RecentChange_saveHook,
	PageUndeleteCompleteHook
{
	private RequestContext $context;

	public function __construct(
		private readonly DiscordUtils $utils,
		private readonly TitleFactory $titleFactory,
		private readonly RevisionLookup $revisionLookup,
	) {
		$this->context = RequestContext::getMain();
	}

	private function msg( string $key, string ...$params ): string {
		return $this->context->msg( $key, ...$params )->inContentLanguage()->plain();
	}

	/**
	 * @param WikiPage $wikiPage
	 * @param UserIdentity $userIdentity
	 * @param string $summary
	 * @param int $flags
	 * @param RevisionRecord $revisionRecord
	 * @param EditResult $editResult
	 * @return bool
	 */
	public function onPageSaveComplete( $wikiPage, $userIdentity, $summary, $flags, $revision, $editResult ): void {
		$title = $wikiPage->getTitle();
		$isNew = $editResult->isNew();
		if (
			$this->utils->isDisabled( $title->getNamespace(), $userIdentity ) ||
			$editResult->isNullEdit() ||
			( $isNew && $title->inNamespace( NS_FILE ) )
		) {
			return;
		}

		if ( $title->inNamespace( NS_FILE ) && $isNew ) {
			// Don't continue, it's a new file which onUploadComplete will handle instead
			return;
		}

		$this->utils->send(
			$isNew ? 'page-created' : 'page-edited',
			$this->utils->formatUser( $userIdentity ),
			$this->utils->formatLink( $title, $title ),
			$this->utils->formatRevision( $revision ),
			$this->utils->formatSummary( $summary )
		);
	}

	public function onPageDeleteComplete(
		ProperPageIdentity $page,
		Authority $deleter,
		string $reason,
		int $pageID,
		RevisionRecord $deletedRev,
		ManualLogEntry $logEntry,
		int $archivedRevisionCount
	): void {
		$title = $this->titleFactory->newFromPageIdentity( $page );

		if ( $this->utils->isDisabled( $title->getNamespace(), $deleter->getUser() ) ) {
			return;
		}

		$this->utils->send(
			'page-deleted',
			$this->utils->formatUser( $deleter->getUser() ),
			$this->utils->formatLink( $title, $title ),
			$this->utils->formatSummary( $reason ),
			$archivedRevisionCount
		);
	}

	public function onPageUndeleteComplete(
		ProperPageIdentity $page,
		Authority $restorer,
		string $reason,
		RevisionRecord $restoredRev,
		ManualLogEntry $logEntry,
		int $restoredRevisionCount,
		bool $created,
		array $restoredPageIds
	): void {
		$title = $this->titleFactory->newFromPageIdentity( $page );

		if ( $this->utils->isDisabled( $title->getNamespace(), $restorer->getUser() ) ) {
			return;
		}

		$this->utils->send(
			'page-undeleted',
			$this->utils->formatUser( $restorer->getUser() ),
			$created ? '' : $this->msg( 'discord-msg-page-undeleted-revs' ),
			$this->utils->formatLink( $title, $title ),
			$this->utils->formatSummary( $reason )
		);
	}

	/**
	 * @param Title $title
	 * @param int[] $ids
	 * @param array $visibilityChangeMap
	 * @return bool
	 */
	public function onArticleRevisionVisibilitySet( $title, $ids, $visibilityChangeMap ): void {
		$user = $this->context->getUser();

		if ( $this->utils->isDisabled( $title->getNamespace(), $user ) ) {
			return;
		}

		$this->utils->send(
			'rev-visibility-changed',
			$this->utils->formatUser( $user ),
			count( $visibilityChangeMap ),
			$this->utils->formatLink( $title, $title )
		);
	}

	/**
	 * @param WikiPage $wikiPage
	 * @param User $user
	 * @param array $protect
	 * @param string $reason
	 * @return bool
	 */
	public function onArticleProtectComplete( $wikiPage, $user, $protect, $reason ): void {
		$title = $wikiPage->getTitle();

		if ( $this->utils->isDisabled( $title->getNamespace(), $user ) ) {
			return;
		}

		$protection = implode( ', ', array_filter( $protect, 'strlen' ) );
		$this->utils->send(
			( $protection === '' ) ? 'page-unprotect' : 'page-protect',
			$this->utils->formatUser( $user ),
			$this->utils->formatLink( $title, $title ),
			$this->utils->formatSummary( $reason ),
			$protection
		);
	}

	/**
	 * @param LinkTarget $old
	 * @param LinkTarget $new
	 * @param UserIdentity $userIdentity
	 * @param int $pageid
	 * @param int $redirid
	 * @param string $reason
	 * @param RevisionRecord $revision
	 * @return bool
	 */
	public function onPageMoveComplete(
		$old,
		$new,
		$userIdentity,
		$pageid,
		$redirid,
		$reason,
		$revision
	): void {
		if ( $this->utils->isDisabled( $old->getNamespace(), $userIdentity ) ) {
			return;
		}

		$this->utils->send(
			'page-moved',
			$this->utils->formatUser( $userIdentity ),
			$this->utils->formatLink( $old, Title::castFromLinkTarget( $old ) ),
			$this->utils->formatLink( $new, Title::castFromLinkTarget( $new ) ),
			$this->utils->formatSummary( $reason ),
			$this->utils->formatRevision( $revision )
		);
	}

	/**
	 * @param User $user
	 * @param bool $autocreated
	 * @return bool
	 */
	public function onLocalUserCreated( $user, $autocreated ): void {
		if ( $this->utils->isDisabled( null, $user ) ) {
			return;
		}

		$this->utils->send(
			'user-registered',
			$this->utils->formatUser( $user )
		);
	}

	/**
	 * @param DatabaseBlock $block
	 * @param User $user
	 * @param ?DatabaseBlock $priorBlock
	 * @return bool
	 */
	public function onBlockIpComplete( $block, $user, $priorBlock ): void {
		if ( $this->utils->isDisabled( null, $user ) ) {
			return;
		}

		$expiry = $block->getExpiry();
		$expires = strtotime( $expiry );
		$expiryMsg = $expires ?
			date( $this->msg( 'discord-msg-user-block-timeformat' ), $expires ) :
			$expiry;

		$reason = $block->getReasonComment()->text;
		$target = $this->utils->getBlockTarget( $block );
		$this->utils->send(
			$priorBlock ?
				'user-change-block' :
				( $block->isSitewide() ?
					'user-block' :
					'user-block-partial' ),
			$this->utils->formatUser( $user ),
			$this->utils->formatUser( $target ),
			$this->utils->formatSummary( $reason ),
			$expiryMsg
		);
	}

	/**
	 * @param DatabaseBlock $block
	 * @param User $user
	 * @return bool
	 */
	public function onUnblockUserComplete( $block, $user ): void {
		if ( $this->utils->isDisabled( null, $user ) ) {
			return;
		}

		$target = $this->utils->getBlockTarget( $block );
		$this->utils->send(
			'user-unblock',
			$this->utils->formatUser( $user ),
			$this->utils->formatUser( $target ),
		);
	}

	/**
	 * @param User|UserIdentity $user
	 * @param string[] $added
	 * @param string[] $removed
	 * @param User|false $performer
	 * @param string|false $reason
	 * @param UserGroupMembership[] $oldUGMs
	 * @param UserGroupMembership[] $newUGMs
	 * @return bool
	 */
	public function onUserGroupsChanged( $user, $added, $removed, $performer, $reason, $oldUGMs, $newUGMs ): void {
		if ( $this->utils->isDisabled( null, $performer ) ) {
			return;
		}

		if ( $performer === false ) {
			// Rights were changed by autopromotion, do nothing
			return;
		}

		$this->utils->send(
			'user-groups-changed',
			$this->utils->formatUser( $performer ),
			$this->utils->formatUser( $user ),
			$this->utils->formatSummary( $reason ),
			( count( $added ) > 0 ) ? ( '+ ' . join( ', ', $added ) ) : '',
			( count( $removed ) > 0 ) ? ( '- ' . join( ', ', $removed ) ) : '',
		);
	}

	/**
	 * @param File $file
	 * @param bool $reupload
	 */
	public function onFileUpload( $file, $reupload, $hasDescription ): void {
		if ( $this->utils->isDisabled( NS_FILE, $file->getUploader() ) ) {
			return;
		}

		$comment = $file->getDescription();
		$this->utils->send(
			'file-upload',
			$this->utils->formatUser( $file->getUploader() ),
			$reupload ? $this->msg( 'discord-msg-file-upload-new' ) : '',
			$this->utils->formatLink( $file->getName(), $file->getTitle() ),
			$this->utils->formatSummary( $comment ),
			$this->utils->formatBytes( $file->getSize() ),
			$file->getWidth(),
			$file->getHeight(),
			$file->getMimeType(),
		);
	}

	/**
	 * @param LocalFile $file
	 * @param string|null $oldimage
	 * @param WikiFilePage|null $article
	 * @param User $user
	 * @param string $reason
	 * @return bool
	 */
	public function onFileDeleteComplete( $file, $oldimage, $article, $user, $reason ): void {
		if ( $this->utils->isDisabled( NS_FILE, $user ) ) {
			return;
		}

		if ( $article ) {
			// Entire page was deleted, onArticleDeleteComplete will handle this
			return;
		}

		$this->utils->send(
			'file-delete',
			$this->utils->formatUser( $user ),
			$this->utils->formatLink( $file->getName(), $file->getTitle() ),
			$this->utils->formatSummary( $reason ),
		);
	}

	/**
	 * @param Title $title
	 * @param int[] $fileVersions
	 * @param User $user
	 * @param string $reason
	 * @return bool
	 */
	public function onFileUndeleteComplete( $title, $fileVersions, $user, $reason ): void {
		if ( $this->utils->isDisabled( NS_FILE, $user ) ) {
			return;
		}

		$this->utils->send(
			'file-undelete',
			$this->utils->formatUser( $user ),
			$this->utils->formatLink( $title, $title ),
			$this->utils->formatSummary( $reason ),
		);
	}

	/**
	 * @param Title $title
	 * @param ForeignTitle $foreignTitle
	 * @param int $revCount
	 * @param int $sRevCount
	 * @param array $pageInfo
	 * @return bool
	 */
	public function onAfterImportPage( $title, $foreignTitle, $revCount, $sRevCount, $pageInfo ): void {
		$user = $this->context->getUser();

		if ( $this->utils->isDisabled( $title->getNamespace(), $user ) ) {
			return;
		}

		if ( $sRevCount === 0 ) {
			// Don't continue, no revisions were imported
			return;
		}

		$this->utils->send(
			'import',
			$this->utils->formatUser( $user ),
			$this->utils->formatLink( $title, $title ),
			$revCount,
			$sRevCount,
		);
	}

	/**
	 * @param Title $targetTitle
	 * @param Title $destTitle
	 * @return bool
	 */
	public function onArticleMergeComplete( $targetTitle, $destTitle ): void {
		$user = $this->context->getUser();

		if ( $this->utils->isDisabled( $destTitle->getNamespace(), $user ) ) {
			return;
		}

		$this->utils->send(
			'page-merge',
			$this->utils->formatUser( $user ),
			$this->utils->formatLink( $targetTitle, $targetTitle ),
			$this->utils->formatLink( $destTitle, $destTitle ),
		);
	}

	/**
	 * Called when a revision is approved (Approved Revs extension)
	 * @see https://github.com/wikimedia/mediawiki-extensions-ApprovedRevs/blob/REL1_34/includes/ApprovedRevs_body.php
	 */
	public function onApprovedRevsRevisionApproved( $output, $title, $rev_id, $content ): void {
		$user = $this->context->getUser();

		if ( $this->utils->isDisabled( $title->getNamespace(), $user ) ) {
			return;
		}

		// Get the revision being approved here
		$rev = $this->revisionLookup->getRevisionByTitle( $title, $rev_id );
		$revLink = $title;
		$revAuthor = $this->utils->formatUser( $rev->getUser( RevisionRecord::RAW ) );

		$this->utils->send(
			'ext-approvedrevs-approved',
			$this->utils->formatUser( $user ),
			$this->utils->formatLink( $title, $title ),
			$this->utils->formatLink( $rev_id, $revLink ),
			$revAuthor,
		);
	}

	/**
	 * @param Title $title
	 * @param mixed $content
	 * @return bool
	 * @see https://github.com/wikimedia/mediawiki-extensions-ApprovedRevs/blob/REL1_45/includes/ApprovedRevs.php#L712
	 */
	public function onApprovedRevsRevisionUnapproved( $output, $title, $content ): void {
		$user = $this->context->getUser();

		if ( $this->utils->isDisabled( $title->getNamespace(), $user ) ) {
			return;
		}

		$this->utils->send(
			'ext-approvedrevs-unapproved',
			$this->utils->formatUser( $user ),
			$this->utils->formatLink( $title, $title ),
		);
	}

	/**
	 * @param Parser $parser
	 * @param Title $title
	 * @param string $timestamp
	 * @param string $sha1
	 * @return bool
	 * @see https://github.com/wikimedia/mediawiki-extensions-ApprovedRevs/blob/REL1_45/includes/ApprovedRevs.php#L827
	 */
	public function onApprovedRevsFileRevisionApproved( $parser, $title, $timestamp, $sha1 ): void {
		$user = $this->context->getUser();

		if ( $this->utils->isDisabled( $title->getNamespace(), $user ) ) {
			return;
		}

		/** @var ImagePage */
		$imagepage = ImagePage::newFromID( $title->getArticleID() );
		$displayedFile = $imagepage->getDisplayedFile();

		$this->utils->send(
			'ext-approvedrevs-approved-file',
			$this->utils->formatUser( $user ),
			$this->utils->formatLink( $title, $title ),
			// getFullURL doesn't work quite the same on File classes
			$this->utils->formatLink( 'direct', $displayedFile->getCanonicalUrl() ),
			$this->utils->formatUser( $displayedFile->getUploader() ),
		);
	}

	/**
	 * @param Parser $parser
	 * @param Title $title
	 * @return bool
	 * @see https://github.com/wikimedia/mediawiki-extensions-ApprovedRevs/blob/REL1_45/includes/ApprovedRevs.php#L865
	 */
	public function onApprovedRevsFileRevisionUnapproved( $parser, $title ): void {
		$user = $this->context->getUser();

		if ( $this->utils->isDisabled( $title->getNamespace(), $user ) ) {
			return;
		}

		$this->utils->send(
			'ext-approvedrevs-unapproved-file',
			$this->utils->formatUser( $user ),
			$this->utils->formatLink( $title, $title ),
		);
	}

	/**
	 * @param int $uid
	 * @param string $old
	 * @param string $new
	 * @return void
	 */
	public function onRenameUserComplete( $uid, $old, $new ): void {
		$performer = $this->context->getUser();

		$this->utils->send(
			'user-rename',
			$this->utils->formatUser( $performer ),
			"*$old*",
			$this->utils->formatLink( $new, Title::newFromText( $new, NS_USER ) )
		);
	}

	/**
	 * Called before a log entry is published
	 * @param ManualLogEntry $entry
	 */
	public function onManualLogEntryBeforePublish( $entry ): void {
		$params = $entry->getParameters();
		switch ( $entry->getType() ) {
			case 'abusefilter':
				if ( $this->utils->isDisabled( null, $entry->getPerformerIdentity() ) ) {
					return;
				}
				$subtype = $entry->getSubtype();
				if ( $subtype !== 'create' && $subtype !== 'modify' ) {
					return;
				}
				$filterId = $params['newId'];
				$historyId = $params['historyId'];
				/** @var string */
				$filterName = MediaWikiServices::getInstance()
					->getService( FilterLookup::SERVICE_NAME )
					->getFilter( $filterId, false )
					->getName();
				$filterPage = SpecialPage::getTitleFor( 'AbuseFilter', (string)$filterId );
				$diffPage = SpecialPage::getTitleFor( 'AbuseFilter', "history/$filterId/diff/prev/$historyId" );
				$this->utils->send(
					"ext-abusefilter-$subtype",
					$this->utils->formatUser( $entry->getPerformerIdentity() ),
					$this->utils->formatLink( $filterName, $filterPage ),
					$this->utils->formatLink( $this->msg( 'discord-msg-ext-abusefilter-details' ), $diffPage )
				);
				break;
		}
	}

	/**
	 * @param RecentChange $recentChange
	 */
	public function onRecentChange_save( $recentChange ) {
		if ( $this->utils->isDisabled( null, $recentChange->getPerformerIdentity() ) ) {
			return;
		}
		if ( $recentChange->getAttribute( 'rc_source' ) != RecentChange::SRC_LOG ) {
			return;
		}
		$logType = $recentChange->getAttribute( 'rc_log_type' );
		$logAction = $recentChange->getAttribute( 'rc_log_action' );
		if ( $logType !== 'abusefilter' || $logAction !== 'hit' ) {
			return;
		}
		$params = $recentChange->parseParams();
		$target = Title::castFromPageReference( $recentChange->getPage() );
		$services = MediaWikiServices::getInstance();
		/** @var FilterLookup */
		$lookup = $services->getService( FilterLookup::SERVICE_NAME );
		/** @var SpecsFormatter */
		$specsFormatter = $services->getService( SpecsFormatter::SERVICE_NAME );
		$filterId = (int)$params['filter'];
		$filter = $lookup->getFilter( $filterId, false );
		$filterName = $filter->getName();
		$actions = trim( $params['actions'] );
		if ( strlen( $actions ) === 0 ) {
			$actionsTaken = $this->context->msg( 'abusefilter-log-noactions' );
		} else {
			$actionsTakenArray = [];
			$specsFormatter->setMessageLocalizer( $this->context );
			foreach ( explode( ',', $actions ) as $action ) {
				$actionsTakenArray[] = $specsFormatter->getActionDisplay( $action );
			}
			$actionsTaken = $this->context->getLanguage()->commaList( $actionsTakenArray );
		}
		$this->utils->send(
			'ext-abusefilter-hit',
			$this->utils->formatUser( $recentChange->getPerformerIdentity() ),
			$this->utils->formatLink(
				$filterName,
				SpecialPage::getTitleFor( 'AbuseFilter', (string)$filterId )
			),
			$params['action'],
			$this->utils->formatLink( $target, $target ),
			$actionsTaken,
			$this->utils->formatLink(
				$this->msg( 'discord-msg-ext-abusefilter-details' ),
				SpecialPage::getTitleFor( 'AbuseLog', $params['log'] )
			)
		);
	}
}
