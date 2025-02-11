<?php
/**
 * Special Pages for Drafts extension
 *
 * @file
 * @ingroup Extensions
 */

class SpecialDraftsToApprove extends SpecialPage {
	public function __construct() {
		parent::__construct( 'DraftsToApprove' );
	}

	public function doesWrites() {
		return true;
	}

	/**
	 * Executes special page rendering and data processing
	 *
	 * @param string|null $sub MediaWiki supplied sub-page path
	 * @throws PermissionsError
	 */
	public function execute( $sub ) {
		global $egDraftsLifeSpan;

		$out = $this->getOutput();
		$user = $this->getUser();
		$request = $this->getRequest();

		// Begin output
		$this->setHeaders();

		// Make sure the user is logged in
		$this->requireLogin();
        if (!$user->isAllowed('drafts-approve')) {
			$out->addWikiMsg("drafts-view-approve-permissions-error");
            return;
        }

		// Handle discarding
		$draft = Draft::newFromID( $request->getInt( 'refuse', 0 ) );
		if ( $draft->exists() ) {
			// Discard draft
			$draft->refuse();
			$out->redirect(SpecialPage::getTitleFor( 'DraftsToApprove' )->getFullURL());
		}

		$count = Drafts::num(null, true, 'proposed');
		if ( $count === 0 ) {
			$out->addWikiMsg( 'drafts-view-nonesaved' );
		} else {
			// Add a summary
			$out->wrapWikiMsg(
				'<div class="mw-drafts-summary">$1</div>',
				[
					'drafts-view-summary',
					$this->getLanguage()->formatNum( $egDraftsLifeSpan )
				]
			);
			$out->addHTML( Drafts::display(null, true, 'proposed', true) );
		}
	}

	/** @inheritDoc */
	protected function getGroupName() {
		return 'pagetools';
	}
}
