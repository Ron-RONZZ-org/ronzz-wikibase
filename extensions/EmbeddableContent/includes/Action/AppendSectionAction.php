<?php

declare( strict_types = 1 );

namespace EmbeddableContent\Action;

use EmbeddableContent\Spec\AppendSectionText;
use EmbeddableContent\Spec\SectionSummary;
use MediaWiki\Actions\FormAction;
use MediaWiki\Content\ContentHandler;
use MediaWiki\Content\TextContent;
use MediaWiki\HTMLForm\HTMLForm;
use MediaWiki\Logger\LoggerFactory;
use MediaWiki\MediaWikiServices;
use MediaWiki\Message\Message;
use MediaWiki\Registration\ExtensionRegistry;

/**
 * The "Add section" page action (`?action=appendsection`) on content pages,
 * and — with a `section=<n>` parameter (the per-heading "Add section below"
 * control) — the same editor targeting one existing section.
 *
 * Core's `section=new` editor adds a new `== Subject ==` heading derived from
 * its subject field; this action instead presents the ordinary edit surface
 * (Content + Summary + Watch, no minor edit, no subject) and APPENDS the
 * submitted wikitext:
 *  - without `section`: to the END of the page (no heading);
 *  - with `section`: to the END of that section (below its heading and any
 *    nested subsections), via core's `Content::getSection`/`replaceSection`.
 *
 * The Content textarea carries the stable id `wpTextbox1` so WikiEditor's own
 * bootstrap (`ext.wikiEditor`, loaded when the user has the `usebetatoolbar`
 * preference and the extension is installed) attaches the same wikitext
 * toolbar as the ordinary edit page. The `ext.embeddableContent.sectionform`
 * module adds the "Preview" button (rendered through `action=parse`).
 *
 * The save runs through the normal `WikiPage::doUserEditContent` pipeline, so
 * edit filters, AbuseFilter, the page-save hooks and read-only checks all
 * apply; the permission gate is the core `edit` right (`getRestriction()`).
 * When the Summary is blank it defaults to the topmost heading of the Content
 * (the section-edit comment convention, see Spec\SectionSummary).
 *
 * @license GPL-2.0-or-later
 */
class AppendSectionAction extends FormAction {

	public function getName() {
		return 'appendsection';
	}

	/**
	 * Plain HTMLForm, deliberately: a plain textarea keeps the explicit
	 * `wpTextbox1` id (the OOUI field would move it onto the wrapper and
	 * WikiEditor targets `textarea#wpTextbox1`).
	 */
	protected function usesOOUI() {
		return false;
	}

	/**
	 * The write permission this action requires — `checkCanExecute()` throws a
	 * PermissionsError for a user without it.
	 */
	public function getRestriction() {
		return 'edit';
	}

	/** The target section index, or 0 to append at the end of the page. */
	private function section(): int {
		$request = $this->getRequest();
		// GET `section` on the initial render; the `wpsection` POST field on a
		// validation-error re-render (so the title/legend stay section-scoped).
		$value = $request->getInt( 'section', 0 );
		if ( $value <= 0 ) {
			$value = $request->getInt( 'wpsection', 0 );
		}
		return max( 0, $value );
	}

	/** @return Message */
	protected function getPageTitle() {
		$key = $this->section() > 0
			? 'embeddablecontent-appendsection-below-title'
			: 'embeddablecontent-appendsection-title';
		return $this->msg( $key, $this->getTitle()->getPrefixedText() );
	}

	protected function getDescription() {
		$key = $this->section() > 0
			? 'embeddablecontent-appendsection-below-desc'
			: 'embeddablecontent-appendsection-desc';
		return $this->msg( $key )->escaped();
	}

	protected function getFormFields() {
		return [
			// Carries the target section across the POST (FormAction's form
			// action URL drops the query string).
			'section' => [
				'type' => 'hidden',
				'default' => (string)$this->section(),
			],
			'content' => [
				'type' => 'textarea',
				'label-message' => 'embeddablecontent-appendsection-content',
				'rows' => 12,
				'required' => true,
				// The id WikiEditor's bootstrap attaches to.
				'id' => 'wpTextbox1',
			],
			'summary' => [
				'type' => 'text',
				'label-message' => 'embeddablecontent-appendsection-summary',
				'maxlength' => SectionSummary::COMMENT_LIMIT,
			],
			'watch' => [
				'type' => 'check',
				'label-message' => 'embeddablecontent-appendsection-watch',
			],
		];
	}

	protected function alterForm( HTMLForm $form ) {
		$legend = $this->section() > 0
			? 'embeddablecontent-appendsection-below-legend'
			: 'embeddablecontent-appendsection-legend';
		$form->setWrapperLegendMsg( $legend );
		$form->setSubmitTextMsg( 'embeddablecontent-appendsection-submit' );
		$form->setSubmitID( 'wb-append-section-submit' );
		$form->setSubmitName( 'wpAppendSection' );
		$form->showCancel( true );
		$form->setCancelTarget( $this->getTitle()->getFullURL() );

		$this->wireEditorModules();
	}

	/**
	 * Load WikiEditor (the same wikitext toolbar as the ordinary edit page)
	 * when the user enabled it and the extension is installed, plus the
	 * Preview-button module.
	 */
	private function wireEditorModules(): void {
		$out = $this->getOutput();
		$out->addModules( 'ext.embeddableContent.sectionform' );
		if ( !ExtensionRegistry::getInstance()->isLoaded( 'WikiEditor' ) ) {
			return;
		}
		$userOptions = MediaWikiServices::getInstance()->getUserOptionsLookup();
		if ( !$userOptions->getBoolOption( $this->getUser(), 'usebetatoolbar' ) ) {
			return;
		}
		$out->addModuleStyles( 'ext.wikiEditor.styles' );
		$out->addModules( 'ext.wikiEditor' );
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
		$section = max( 0, (int)( $data['section'] ?? 0 ) );
		$summary = trim( (string)( $data['summary'] ?? '' ) );
		if ( $summary === '' ) {
			$summary = SectionSummary::infer( $content )
				?? $this->msg( 'embeddablecontent-appendsection-summary-default' )
					->inContentLanguage()->text();
		}

		$title = $this->getTitle();
		try {
			$wikiPage = $this->getWikiPage();
			$current = $wikiPage->getContent();
			if ( !$current instanceof TextContent ) {
				return $this->msg( 'embeddablecontent-appendsection-error' )->text();
			}
			$newText = $this->assemble( $current, $section, $content );
			if ( $newText === null ) {
				return $section > 0
					? $this->msg( 'embeddablecontent-appendsection-missing-section' )->text()
					: $this->msg( 'embeddablecontent-appendsection-error' )->text();
			}
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

	/**
	 * The new full-page wikitext: append to the page end (section 0), or to
	 * the end of the target section. Returns null when the section does not
	 * exist (or the content model does not support sections).
	 */
	private function assemble( TextContent $current, int $section, string $added ): ?string {
		if ( $section <= 0 ) {
			return AppendSectionText::append( $current->getText(), $added );
		}
		if ( !$this->getWikiPage()->supportsSections() ) {
			return null;
		}
		$sectionContent = $current->getSection( $section );
		if ( !$sectionContent instanceof TextContent ) {
			return null;
		}
		$with = ContentHandler::makeContent(
			AppendSectionText::append( $sectionContent->getText(), $added ),
			$this->getTitle()
		);
		$replaced = $current->replaceSection( $section, $with );
		return $replaced instanceof TextContent ? $replaced->getText() : null;
	}

	public function onSuccess() {
		$this->getOutput()->redirect( $this->getTitle()->getFullURL() );
	}
}
