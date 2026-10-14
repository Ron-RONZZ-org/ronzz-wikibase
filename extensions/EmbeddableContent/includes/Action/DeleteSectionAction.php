<?php

declare( strict_types = 1 );

namespace EmbeddableContent\Action;

use MediaWiki\Actions\FormAction;
use MediaWiki\Content\ContentHandler;
use MediaWiki\Content\TextContent;
use MediaWiki\HTMLForm\HTMLForm;
use MediaWiki\Html\Html;
use MediaWiki\Logger\LoggerFactory;
use MediaWiki\Message\Message;

/**
 * The per-section "Delete section" page action (`?action=deletesection&
 * section=<n>`) on content pages. Presents a confirmation form showing the
 * section's wikitext, then removes the whole section (its heading and every
 * nested subsection) by replacing it with empty text through core's
 * `Content::replaceSection`, saved with `WikiPage::doUserEditContent` — so
 * edit filters, AbuseFilter, the page-save hooks and read-only checks all
 * apply. The permission gate is the core `edit` right (`getRestriction()`).
 *
 * @license GPL-2.0-or-later
 */
class DeleteSectionAction extends FormAction {

	public function getName() {
		return 'deletesection';
	}

	public function getRestriction() {
		return 'edit';
	}

	/** The target section index (1-based; 0 is not deletable). */
	private function section(): int {
		$request = $this->getRequest();
		// GET `section` on the initial render; the `wpsection` POST field on a
		// validation-error re-render.
		$value = $request->getInt( 'section', 0 );
		if ( $value <= 0 ) {
			$value = $request->getInt( 'wpsection', 0 );
		}
		return max( 0, $value );
	}

	/** @return Message */
	protected function getPageTitle() {
		return $this->msg(
			'embeddablecontent-deletesection-title',
			$this->getTitle()->getPrefixedText()
		);
	}

	protected function getDescription() {
		return $this->msg( 'embeddablecontent-deletesection-desc' )->escaped();
	}

	/**
	 * The section's wikitext, escaped, shown above the form so the
	 * contributor can confirm what is being removed.
	 */
	protected function preText() {
		$text = $this->sectionText();
		if ( $text === null ) {
			return Html::element(
				'p',
				[ 'class' => 'error' ],
				$this->msg( 'embeddablecontent-deletesection-missing' )->text()
			);
		}
		return Html::element(
			'p',
			[ 'class' => 'wb-deletesection-heading' ],
			$this->msg( 'embeddablecontent-deletesection-heading' )->text()
		) . Html::element(
			'pre',
			[ 'class' => 'wb-deletesection-preview' ],
			$text
		);
	}

	/** The target section's wikitext, or null when it does not exist. */
	private function sectionText(): ?string {
		$content = $this->getWikiPage()->getContent();
		if ( !$content instanceof TextContent || $this->section() <= 0
			|| !$this->getWikiPage()->supportsSections()
		) {
			return null;
		}
		$sectionContent = $content->getSection( $this->section() );
		if ( !$sectionContent instanceof TextContent ) {
			return null;
		}
		return $sectionContent->getText();
	}

	protected function getFormFields() {
		return [
			// Carries the target section across the POST (the form action URL
			// drops the query string).
			'section' => [
				'type' => 'hidden',
				'default' => (string)$this->section(),
			],
		];
	}

	protected function alterForm( HTMLForm $form ) {
		$form->setWrapperLegendMsg( 'embeddablecontent-deletesection-legend' );
		$form->setSubmitTextMsg( 'embeddablecontent-deletesection-confirm' );
		$form->setSubmitID( 'wb-delete-section-submit' );
		$form->setSubmitName( 'wpDeleteSection' );
		$form->setSubmitDestructive();
		$form->showCancel( true );
		$form->setCancelTarget( $this->getTitle()->getFullURL() );
	}

	/**
	 * @param array $data
	 * @return bool|string true on success, an error string otherwise
	 */
	public function onSubmit( $data ) {
		$section = max( 0, (int)( $data['section'] ?? 0 ) );
		if ( $section <= 0 ) {
			return $this->msg( 'embeddablecontent-deletesection-missing' )->text();
		}

		$title = $this->getTitle();
		try {
			$wikiPage = $this->getWikiPage();
			$current = $wikiPage->getContent();
			if ( !$current instanceof TextContent || !$wikiPage->supportsSections() ) {
				return $this->msg( 'embeddablecontent-deletesection-error' )->text();
			}
			$sectionContent = $current->getSection( $section );
			if ( !$sectionContent instanceof TextContent ) {
				return $this->msg( 'embeddablecontent-deletesection-missing' )->text();
			}
			$empty = ContentHandler::makeContent( '', $title );
			$newContent = $current->replaceSection( $section, $empty );
			if ( !$newContent instanceof TextContent ) {
				return $this->msg( 'embeddablecontent-deletesection-error' )->text();
			}
			$summary = $this->msg( 'embeddablecontent-deletesection-summary' )
				->inContentLanguage()->text();
			$status = $wikiPage->doUserEditContent(
				$newContent,
				$this->getAuthority(),
				$summary,
				EDIT_UPDATE
			);
			if ( !$status->isOK() ) {
				return $this->msg( 'embeddablecontent-deletesection-error' )->text();
			}
		} catch ( \Throwable $e ) {
			LoggerFactory::getInstance( 'EmbeddableContent' )->warning(
				'DeleteSectionAction save failed on {title}: {error}',
				[ 'title' => $title->getPrefixedText(), 'error' => $e->getMessage() ]
			);
			return $this->msg( 'embeddablecontent-deletesection-error' )->text();
		}

		return true;
	}

	public function onSuccess() {
		$this->getOutput()->redirect( $this->getTitle()->getFullURL() );
	}
}
