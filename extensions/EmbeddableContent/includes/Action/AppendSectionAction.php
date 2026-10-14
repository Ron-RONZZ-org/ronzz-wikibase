<?php

declare( strict_types = 1 );

namespace EmbeddableContent\Action;

use EmbeddableContent\Spec\AppendSectionText;
use MediaWiki\Actions\FormAction;
use MediaWiki\Content\ContentHandler;
use MediaWiki\Content\TextContent;
use MediaWiki\HTMLForm\HTMLForm;
use MediaWiki\Logger\LoggerFactory;
use MediaWiki\MediaWikiServices;
use MediaWiki\Message\Message;
/**
 * The "Add section" page action (`?action=appendsection`) on content pages.
 *
 * Core's `section=new` editor adds a new `== Subject ==` heading derived from
 * its subject field; this action instead presents the ordinary edit surface
 * (Content + Summary + Watch, no minor edit, no subject) and APPENDS the
 * submitted wikitext to the END of the page's current content — no heading.
 * The save runs through the normal `WikiPage::doUserEditContent` pipeline, so
 * edit filters, AbuseFilter, the page-save hooks and read-only checks all
 * apply; the permission gate is the core `edit` right
 * (`getRestriction()`).
 *
 * Registered in `extension.json` under `Actions` (the name matches
 * `Hooks::addSectionAction`'s link target).
 *
 * @license GPL-2.0-or-later
 */
class AppendSectionAction extends FormAction {

	public function getName() {
		return 'appendsection';
	}

	protected function usesOOUI() {
		return true;
	}

	/**
	 * The write permission this action requires — `checkCanExecute()` throws a
	 * PermissionsError for a user without it.
	 */
	public function getRestriction() {
		return 'edit';
	}

	/** @return Message */
	protected function getPageTitle() {
		return $this->msg(
			'embeddablecontent-appendsection-title',
			$this->getTitle()->getPrefixedText()
		);
	}

	protected function getDescription() {
		return $this->msg( 'embeddablecontent-appendsection-desc' )->escaped();
	}

	protected function getFormFields() {
		return [
			'content' => [
				'type' => 'textarea',
				'label-message' => 'embeddablecontent-appendsection-content',
				'rows' => 12,
				'required' => true,
			],
			'summary' => [
				'type' => 'text',
				'label-message' => 'embeddablecontent-appendsection-summary',
				'maxlength' => 500,
			],
			'watch' => [
				'type' => 'check',
				'label-message' => 'embeddablecontent-appendsection-watch',
			],
		];
	}

	protected function alterForm( HTMLForm $form ) {
		$form->setWrapperLegendMsg( 'embeddablecontent-appendsection-legend' );
		$form->setSubmitTextMsg( 'embeddablecontent-appendsection-submit' );
		$form->setSubmitID( 'wb-append-section-submit' );
		$form->setSubmitName( 'wpAppendSection' );
		$form->showCancel( true );
		$form->setCancelTarget( $this->getTitle()->getFullURL() );
	}

	/**
	 * @param array $data
	 * @return bool|string true on success (show() then calls onSuccess()),
	 *  an error string otherwise
	 */
	public function onSubmit( $data ) {
		$content = trim( (string)( $data['content'] ?? '' ) );
		if ( $content === '' ) {
			return $this->msg( 'embeddablecontent-appendsection-required' )->text();
		}
		$summary = trim( (string)( $data['summary'] ?? '' ) );
		if ( $summary === '' ) {
			$summary = $this->msg( 'embeddablecontent-appendsection-summary-default' )
				->inContentLanguage()->text();
		}

		$title = $this->getTitle();
		try {
			$wikiPage = $this->getWikiPage();
			$current = $wikiPage->getContent();
			if ( !$current instanceof TextContent ) {
				return $this->msg( 'embeddablecontent-appendsection-error' )->text();
			}
			$newText = AppendSectionText::append( $current->getText(), $content );
			$newContent = ContentHandler::makeContent( $newText, $title );
			$status = $wikiPage->doUserEditContent(
				$newContent,
				$this->getAuthority(),
				$summary,
				EDIT_UPDATE
			);
			if ( !$status->isOK() ) {
				return $this->msg( 'embeddablecontent-appendsection-error' )->text();
			}
		} catch ( \Throwable $e ) {
			// Never swallow silently: log the cause, show the contributor a
			// generic form error.
			LoggerFactory::getInstance( 'EmbeddableContent' )->warning(
				'AppendSectionAction save failed on {title}: {error}',
				[ 'title' => $title->getPrefixedText(), 'error' => $e->getMessage() ]
			);
			return $this->msg( 'embeddablecontent-appendsection-error' )->text();
		}

		if ( !empty( $data['watch'] ) ) {
			MediaWikiServices::getInstance()->getWatchlistManager()
				->setWatch( true, $this->getAuthority(), $title );
		}

		return true;
	}

	public function onSuccess() {
		$this->getOutput()->redirect( $this->getTitle()->getFullURL() );
	}
}
