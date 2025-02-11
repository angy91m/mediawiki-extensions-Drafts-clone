<?php
/**
 * Hooks for Drafts extension
 *
 * @file
 * @ingroup Extensions
 */

use MediaWiki\MediaWikiServices;
use MediaWiki\User\UserIdentity;

class DraftHooks {
	private static $draftApprover = null;
	/**
	 * Enable the Drafts preference by default for new user accounts (as well as
	 * old ones that haven't explicitly disabled Drafts).
	 *
	 * @param array &$defaultOptions
	 */
	public static function onUserGetDefaultOptions( &$defaultOptions ) {
		$defaultOptions['extensionDrafts_enable'] = true;
	}

	/**
	 * Register the preference to enable/disable Drafts on a per-user basis.
	 *
	 * @param User $user
	 * @param array &$preferences
	 */
	public static function onGetPreferences( User $user, array &$preferences ) {
		$preferences['extensionDrafts_enable'] = [
			'type' => 'toggle',
			'label-message' => 'drafts-enable',
			'section' => 'editing/extension-drafts'
		];
	}

	/**
	 * Apply the database schema updates when the sysadmin re-runs
	 * MediaWiki's core updater script, maintenance/update.php.
	 *
	 * @param DatabaseUpdater $updater
	 */
	public static function schema( $updater ) {
		$sqlDir = __DIR__ . '/../sql';
		$updater->addExtensionTable( 'drafts', $sqlDir . '/Drafts.sql' );
		if ( $updater->getDB()->getType() !== 'sqlite' ) {
			$updater->modifyExtensionField( 'drafts', 'draft_token',
				$sqlDir . '/patch-draft_token.sql' );
		}
		$updater->addExtensionIndex( 'drafts', 'draft_title', $sqlDir . '/patch-titlensindex.sql' );
	}

	/**
	 * SpecialMovepageAfterMove hook
	 *
	 * @param MovePageForm $mp
	 * @param Title $ot
	 * @param Title $nt
	 */
	public static function onSpecialMovepageAfterMove( $mp, $ot, $nt ) {
		// Update all drafts of old article to new article for all users
		Drafts::move( $ot, $nt );
	}

	/**
	 * PageSaveComplete hook - does two things:
	 * 1) If the save occurred from a draft, discards the draft
	 * 2) Updates DB entries in the drafts table on page creation (T21837)
	 *
	 * @param WikiPage $wikiPage
	 * @param UserIdentity $user
	 */
	public static function onPageSaveComplete( WikiPage $wikiPage, UserIdentity $user ) {
		global $wgRequest;

		$draft = false;
		if (self::$draftApprover) {
			$user = self::$draftApprover;
			$draft = Draft::newFromID( $wgRequest->getInt( 'wpDraftApprove', 0 ) );
			self::$draftApprover = null;
		} else {
			$draft = Draft::newFromID( $wgRequest->getInt( 'wpDraftID', 0 ) );
		}
		if ( $user->isAllowed('drafts-approve') ) {
			if ( $draft->exists() ) {
				$draft->discard(User::newFromId($draft->getUserID()));
			}
		}
		// // Check if the save occurred from a draft
		// $draft = Draft::newFromID( $wgRequest->getInt( 'wpDraftID', 0 ) );
		// if ( $draft->exists() ) {
		// 	if ($user->isAllowed('drafts-approve')) {
		// 		$draft->discard($draft->getUserID());
		// 	} else {
		// 		// Discard the draft
		// 		$draft->discard( $user );
		// 	}
		// }

		// When a page is created, associate the page ID with any drafts that might exist
		$title = $wikiPage->getTitle();
		$dbw = MediaWikiServices::getInstance()->getDBLoadBalancer()->getConnection( DB_PRIMARY );
		$dbw->update(
			'drafts',
			[ 'draft_page' => $title->getArticleID() ],
			[
				'draft_namespace' => $title->getNamespace(),
				'draft_title' => $title->getDBkey()
			],
			__METHOD__
		);
	}

	/**
	 * EditPage::showEditForm:initial hook
	 * Load draft...
	 *
	 * @param EditPage $editpage
	 * @return void
	 */
	public static function loadForm( EditPage $editpage ) {
		$context = $editpage->getArticle()->getContext();
		$userOptionsManager = MediaWikiServices::getInstance()->getUserOptionsManager();
		$user = $context->getUser();

		if ( !$userOptionsManager->getOption( $user, 'extensionDrafts_enable', 'true' ) ) {
			return;
		}
		// Check permissions
		$request = $context->getRequest();
		if ( $user->isAllowed( 'edit' ) && $user->isRegistered() ) {
			if(!empty($request->getText( 'wpDraftPropose' ))) {
				$out = $context->getOutput();
				$out->clearHTML();
				$out->redirect(SpecialPage::getTitleFor('Drafts')->getFullURL('proposed=1'));
			}
			// Get draft
			$draft = Draft::newFromID( $request->getInt( 'draft', 0 ) );
			// Load form values
			if ( $draft->exists() ) {
				// Override initial values in the form with draft data
				$editpage->textbox1 = $draft->getText();
				$editpage->summary = $draft->getSummary();

				// $editpage->scrolltop = $draft->getScrollTop();
				// $editpage->minoredit = $draft->getMinorEdit() ? true : false;

				// EditPage::$scrolltop & EditPage::$minoredit made private in MW 1.38 :-(
				$request->setVal( 'wpScrolltop', $draft->getScrollTop() );
				$request->setVal( 'wpMinoredit', $draft->getMinorEdit() ? true : false );
			}

			$draftTitle = $request->getRawVal( 'wpDraftTitle' );
			// Try harder.
			if ( $draftTitle === null ) {
				$draftTitle = $request->getRawVal( 'title' );
			}

			// OK, now would be a *great* time to panic!
			if ( !$draftTitle ) {
				throw new MWException(
					"Can't get a Title, neither from 'wpDraftTitle' nor from 'title'!"
				);
			}

			// Save draft on non-save submission
			// (I guess this means the "Show changes" button? And also preview, apparently.)
			if (
				$request->getRawVal( 'action' ) === 'submit' &&
				$user->matchEditToken( $request->getText( 'wpEditToken' ) ) &&
				( $request->getRawVal( 'wpPreview' ) || $request->getRawVal( 'wpDiff' ) )
			) {
				$text = $request->getText( 'wpTextbox1' );
				// If the draft wasn't specified in the url, try using a
				// form-submitted one
				if ( !$draft->exists() ) {
					$draft = Draft::newFromID(
						$request->getInt( 'wpDraftID', 0 )
					);
				}
				if (!$draft->exists() || $draft->getUserID() === $user->getId()) {
					// Load draft with info
					// @todo FIXME: newFromText() *can* still return null and make Draft#save barf!
					$draft->setTitle( Title::newFromText( $draftTitle ) );
					$draft->setSection( $request->getInt( 'wpSection' ) );
					$draft->setStartTime( $request->getText( 'wpStarttime' ) );
					$draft->setEditTime( $request->getText( 'wpEdittime' ) );
					$draft->setSaveTime( wfTimestampNow() );
					$draft->setScrollTop( $request->getInt( 'wpScrolltop' ) );
					$draft->setText( $text );
					$draft->setSummary( $request->getText( 'wpSummary' ) );
					$draft->setMinorEdit( $request->getBool( 'wpMinoredit' ) );
					// Save draft (but only if it makes sense -- T21737)
					if ( $text ) {
						$draft->save();
						// Use the new draft id
						$request->setVal( 'draft', $draft->getID() );
					}
				}
			}
		}

		$out = $context->getOutput();
		$draft = Draft::newFromID( $request->getInt( 'draft', 0 ) );
		$isDraftOwner = intval(!$draft->exists() || $draft->getUserID() === $user->getId());
		$out->addHTML(Xml::element('input', ['id' => 'drafts-approve-is-draft-owner', 'type'=> 'hidden', 'name' => 'is-draft-owner', 'value' => "$isDraftOwner"]));
		if ($request->getInt('wpApproveView') === 1 && $user->isAllowed('drafts-approve')) {
			$numDrafts = Drafts::num( $context->getTitle(), true, 'proposed' );
		} else {
			$numDrafts = Drafts::num( $context->getTitle() );
		}
		// Show list of drafts
		if ( $numDrafts > 0 ) {
			if ( $request->getRawVal( 'action' ) !== 'submit' ) {
				$out->addHTML( Xml::openElement(
					'div', [ 'id' => 'drafts-list-box' ] )
				);
				$out->addHTML( Xml::element(
					'h3', null, $context->msg( 'drafts-view-existing' )->text() )
				);
				if ($request->getInt('wpApproveView') === 1 && $user->isAllowed('drafts-approve')) {
					$out->addHTML( Drafts::display( $context->getTitle(), true, 'proposed', true ) );
				} else {
					$out->addHTML( Drafts::display( $context->getTitle() ) );
				}
				$out->addHTML( Xml::closeElement( 'div' ) );
			} else {
				$link = Xml::element( 'a',
					[
						'href' => $context->getTitle()->getFullURL( 'action=edit' ),
						'class' => 'mw-discard-draft-link'
					],
					$context->msg( 'drafts-view-notice-link' )->numParams( $numDrafts )->text()
				);
				$out->addHTML( $context->msg( 'drafts-view-notice' )->rawParams( $link )->escaped() );
			}
		}
	}

	public static function onVisualEditorApiVisualEditorEditPreSave( $page, $user, $wikitext, &$params, $pluginData, &$apiResponse ) {
		if ( !$user->isAllowed('drafts-approve') ) {
			$apiResponse['message'] = [ 'apierror-approvedrafts-permissions'];
			return false;
		}
	}

	/**
	 * This is attached to the MediaWiki 'EditPage::attemptSave' hook.
	 *
	 * @param EditPage $editPage
	 */
	public static function onEditPage__attemptSave( $editPage ) {
		$article = $editPage->getArticle();
		$ctx = $article->getContext();
		$user = $ctx->getUser();
		$request = $ctx->getRequest();
		if (
			!$user->isAllowed('drafts-approve')
			&& empty($request->getText('wpDraftPropose'))
			&& empty($request->getText('wpDraftSave'))
		) {
			$ctx->getOutput()->showErrorPage(
				"drafts-page-save-error",
				'apierror-approvedrafts-permissions'
			);
			return;
		}
		if ($request->getText('wpSave') !== '' && $request->getInt('wpDraftApprove', 0) && $user->isAllowed('drafts-approve')) {
			$draft = Draft::newFromID($request->getInt('wpDraftApprove', 0));
			if ($draft->exists()) {
				$user = User::newFromId($draft->getUserID());
				$user->loadFromId();
				self::$draftApprover = $editPage->getContext()->getUser();
				$editPage->getContext()->setUser(User::newFromId($draft->getUserID()));
				$editpage->textbox1 = $draft->getText();
			}
		}
	}
	/**
	 * This is attached to the MediaWiki 'EditPage::attemptSave:after' hook.
	 * This method handles clicks on the "Save draft" button when the user has
	 * JavaScript disabled.
	 *
	 * @todo FIXME: The guts of this method kinda duplicate the loadForm() method's
	 * internals, but such is life.
	 *
	 * @param EditPage $editPage
	 * @param Status $status
	 * @param array $resultDetails
	 */
	// phpcs:ignore MediaWiki.NamingConventions.LowerCamelFunctionsName.FunctionName
	public static function onEditPage__attemptSave_after( $editPage, $status, $resultDetails ) {
		$article = $editPage->getArticle();
		$ctx = $article->getContext();
		$user = $ctx->getUser();
		$request = $ctx->getRequest();

		if ( !$user->isRegistered() ) {
			return;
		}

		// This is a no-JS endpoint, no need to do anything here for users w/
		// JS enabled.
		if ( $request->getBool( 'wpDraftJSEnabled' ) && empty($request->getText( 'wpDraftPropose' ))) {
			return;
		}

		// We could check if the user preference is enabled for the user but that's
		// not strictly needed IMHO isnce this is basically an internal endpoint
		// that's normally accessible only if the user has JS disabled and they
		// hit the "Save Draft" button, and said button is shown only if they have the
		// preference option enabled, sooooo...

		$draftTitle = $request->getRawVal( 'wpDraftTitle' );
		// Try harder.
		if ( $draftTitle === null ) {
			$draftTitle = $request->getRawVal( 'title' );
		}

		$text = $request->getText( 'wpTextbox1' );

		$draft = Draft::newFromID( $request->getInt( 'wpDraftID', 0 ) );
		if ($draft->exists()) {
			if ($draft->getUserID() !== $user->getId()) {
				$status->fatal('apierror-must-be-draft-owner');
				return;
			} else if ($draft->getStatus() === 'proposed') {
				$status->fatal("apierror-savedrafts-status-proposed");
				return;
			}
		}
		$draft->setToken( $request->getRawVal( 'wpDraftToken' ) ?? '' );
		// @todo FIXME: newFromText() *can* still return null and make Draft#save barf!
		$draft->setTitle( Title::newFromText( $draftTitle ) );
		$draft->setSection( $request->getInt( 'wpSection' ) );
		$draft->setStartTime( $request->getText( 'wpStarttime' ) );
		$draft->setEditTime( $request->getText( 'wpEdittime' ) );
		$draft->setSaveTime( wfTimestampNow() );
		$draft->setScrollTop( $request->getInt( 'wpScrolltop' ) );
		$draft->setText( $text );
		$draft->setSummary( $request->getText( 'wpSummary' ) );
		$draft->setMinorEdit( $request->getBool( 'wpMinoredit' ) );
		$draft->setStatus($request->getText( 'wpDraftPropose' ) !== '' ? 'proposed' : 'editing');

		// Save draft (but only if it makes sense -- T21737)
		if ( $text !== '' ) {
			$draft->save();
		}
	}

	/**
	 * EditFilter hook
	 * Intercept the saving of an article to detect if the submission was from
	 * the non-JavaScript save draft button
	 *
	 * @param EditPage $editor
	 * @param string $text
	 * @param string $section
	 * @param string &$error
	 */
	public static function onEditFilter( EditPage $editor, $text, $section, &$error ) {
		// Don't save if the save draft button caused the submit
		$request = $editor->getArticle()->getContext()->getRequest();
		if ( $request->getText( 'wpDraftSave' ) !== '' || $request->getText( 'wpDraftPropose' ) !== '' ) {
			// Modify the error so it's clear we want to remain in edit mode
			$error = ' ';
		}
	}

	/**
	 * EditPageBeforeEditButtons hook
	 * Add draft saving controls
	 *
	 * @param EditPage $editpage
	 * @param array &$buttons
	 * @param int &$tabindex
	 */
	public static function onEditPageBeforeEditButtons( EditPage $editpage, &$buttons, &$tabindex ) {
		$context = $editpage->getArticle()->getContext();
		$userOptionsManager = MediaWikiServices::getInstance()->getUserOptionsManager();
		$user = $context->getUser();

		if ( !$userOptionsManager->getOption( $user, 'extensionDrafts_enable', 'true' ) ) {
			return;
		}

		// Check permissions
		if ( $user->isAllowed( 'edit' ) && $user->isRegistered() ) {
			$request = $context->getRequest();
			$context->getOutput()->addModules( 'ext.Drafts' );
			$draft = Draft::newFromID($request->getInt( 'draft' ));
			if (!$user->isAllowed('drafts-approve')) {
				$buttons['save'] = new OOUI\ButtonInputWidget( [
					'name' => 'wpDraftPropose',
					'tabIndex' => 4,
					'id' => 'wpSaveWidget',
					'inputId' => 'wpDraftPropose',
					'useInputTag' => true,
					'flags' => [ 'progressive', 'primary' ],
					'label' => $context->msg( 'drafts-view-propose' )->text(),
					'infusable' => true,
					'type' => 'submit',
					// Messages used: tooltip-save, tooltip-publish
					'title' => Linker::titleAttrib( 's' ),
					// Messages used: accesskey-save, accesskey-publish
					'accessKey' => Linker::accesskey( 's' ),
				] );
			}
			if(!$draft->exists() || $draft->getUserID() === $user->getId()) {
				$buttons['savedraft'] = new OOUI\ButtonInputWidget(
					[
						'name' => 'wpDraftSave',
						'tabIndex' => ++$tabindex,
						'id' => 'wpDraftWidget',
						'inputId' => 'wpDraftSave',
						'useInputTag' => true,
						'flags' => [ 'progressive' ],
						'label' => $context->msg( 'drafts-save-save' )->text(),
						'infusable' => true,
						'type' => 'submit',
						'title' => Linker::titleAttrib( 'drafts-save' ),
						'accessKey' => Linker::accesskey( 'drafts-save' ),
						// Do NOT change this to true! This HAS to be false
						// so that no-JS users can save drafts manually.
						'disabled' => false
					]
				);
				$buttons['savedraft'] .= new OOUI\HiddenInputWidget(
					[
						'name' => 'wpDraftToken',
						'value' => MWCryptRand::generateHex( 32 )
					]
				);
				$buttons['savedraft'] .= new OOUI\HiddenInputWidget(
					[
						'name' => 'wpDraftID',
						'value' => $request->getInt( 'draft' )
					]
				);
				$buttons['savedraft'] .= new OOUI\HiddenInputWidget(
					[
						'name' => 'wpDraftTitle',
						'value' => $context->getTitle()->getPrefixedText()
					]
				);
				// Will be used by the onEditPage__attemptSave_after hook handler to
				// avoid unnecessary processing. The main JS file flips this to true
				// for people who have JS enabled.
				$buttons['savedraft'] .= new OOUI\HiddenInputWidget(
					[
						'name' => 'wpDraftJSEnabled',
						'value' => false
					]
				);
			} else {
				$buttons['save']->setLabel($context->msg("drafts-view-approve")->text());
				$buttons['save'] .= new OOUI\HiddenInputWidget(
					[
						'name' => 'wpDraftApprove',
						'value' => $request->getInt( 'draft' )
					]
				);
			}
		}
	}

	/**
	 * Hook for ResourceLoaderGetConfigVars
	 *
	 * @param array &$vars
	 */
	public static function onResourceLoaderGetConfigVars( &$vars ) {
		global $egDraftsAutoSaveWait, $egDraftsAutoSaveTimeout,
			   $egDraftsAutoSaveInputBased;
		$vars['wgDraftAutoSaveWait'] = $egDraftsAutoSaveWait;
		$vars['wgDraftAutoSaveTimeout'] = $egDraftsAutoSaveTimeout;
		$vars['wgDraftAutoSaveInputBased'] = $egDraftsAutoSaveInputBased;
	}

	/**
	 * ArticleUndelete hook
	 *
	 * If an article is undeleted, update the page ID we have stored internally
	 *
	 * @see https://phabricator.wikimedia.org/T21734
	 * @todo FIXME: switch to the PageUndeleteComplete hook for MW 1.40+
	 *
	 * @param Title $title
	 * @param bool $create
	 * @return void
	 */
	public static function onArticleUndelete( $title, $create ) {
		if ( !$create ) {
			// Only for restored pages
			return;
		}

		$dbw = MediaWikiServices::getInstance()->getDBLoadBalancer()->getConnection( DB_PRIMARY );
		$dbw->update(
			'drafts',
			[ 'draft_page' => $title->getArticleID() ],
			[
				'draft_namespace' => $title->getNamespace(),
				'draft_title' => $title->getDBkey()
			],
			__METHOD__
		);
	}
}
