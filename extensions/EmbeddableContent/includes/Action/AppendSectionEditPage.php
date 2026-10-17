<?php

declare( strict_types = 1 );

namespace EmbeddableContent\Action;

use EmbeddableContent\Spec\AppendSectionText;
use EmbeddableContent\Spec\SectionSummary;
use MediaWiki\Content\ContentHandler;
use MediaWiki\Content\TextContent;
use MediaWiki\EditPage\EditPage;
use MediaWiki\EditPage\NotDirectlyEditableException;
use MediaWiki\Logger\LoggerFactory;
use MediaWiki\MediaWikiServices;
use MediaWiki\Page\Article;
use MediaWiki\Title\Title;

/**
 * The editor behind `?action=appendsection` — core's own `EditPage`, so the
 * "Add section" / "Add section below" flows render the EXACT built-in edit
 * surface (wikitext toolbar, server-rendered Preview and Show changes,
 * summary, watch, edit-conflict handling, AbuseFilter/edit filters). The only
 * intentional difference from a normal edit is the missing "This is a minor
 * edit" checkbox.
 *
 * Append semantics are supplied through two focused overrides:
 *  - `getContentObject()` returns an EMPTY content object, so the text box
 *    starts blank (the contributor types only the new block);
 *  - `toEditContent()` assembles the page the editor diffs and saves — the
 *    current wikitext with the typed block appended to the page end, or to
 *    the end of the target section. The PREVIEW deliberately sees only the
 *    typed block (the section being added), matching core's section editor.
 *
 * The target section rides the `wbsection` request parameter (not core's
 * `section`, which must stay empty so `EditPage` treats this as a whole-page
 * edit); `getActionURL()` carries it across the POST.
 *
 * @license GPL-2.0-or-later
 */
class AppendSectionEditPage extends EditPage {

	/** The section to append BELOW (0 = the end of the page). */
	private int $targetSection;

	public function __construct( Article $article, int $targetSection ) {
		parent::__construct( $article );
		$this->targetSection = max( 0, $targetSection );
	}

	/**
	 * The content box starts EMPTY: the full page text is assembled lazily in
	 * `toEditContent()` (used by preview, diff and save), never shown.
	 *
	 * @param mixed $defaultContent
	 * @return \MediaWiki\Content\Content
	 */
	protected function getContentObject( $defaultContent = null ) {
		$handler = MediaWikiServices::getInstance()
			->getContentHandlerFactory()
			->getContentHandler( $this->contentModel );
		return $handler->makeEmptyContent();
	}

	/**
	 * The edit content every render/save path reads. `EditPage` calls this
	 * for the Preview, the Show-changes diff and the save (with
	 * `$this->section` empty, core's `replaceSectionAtRev( '' )` passes the
	 * assembled text straight through).
	 *
	 * PREVIEW shows ONLY the typed block — the section being added — never
	 * the assembled whole page. Core's section editor previews the edited
	 * section alone for the same reason; showing the full article here made
	 * "Show preview" on the page-level "Add section" button (and on every
	 * per-heading "Add section below" control) render the entire page.
	 *
	 * The diff and the save still receive the assembled page: the save must
	 * append the block, and the diff must compare against the current page.
	 *
	 * @param string|false|null $text the text box content
	 * @return \MediaWiki\Content\Content|false|null
	 */
	protected function toEditContent( $text ) {
		if ( $text === false || $text === null ) {
			return $text;
		}
		if ( $this->preview && !$this->diff ) {
			return parent::toEditContent( (string)$text );
		}
		$current = $this->getCurrentContent();
		if ( !$current instanceof TextContent ) {
			throw new NotDirectlyEditableException(
				'the section append editor requires a text content model'
			);
		}
		return parent::toEditContent( $this->assemble( $current, (string)$text ) );
	}

	/**
	 * The built-in edit form minus the "This is a minor edit" checkbox — the
	 * single intended UX difference from an ordinary edit.
	 */
	public function getCheckboxesDefinition( $values ) {
		$checkboxes = parent::getCheckboxesDefinition( $values );
		unset( $checkboxes['wpMinoredit'] );
		return $checkboxes;
	}

	/**
	 * Post back to the append action (core's `EditPage` posts to
	 * `action=submit`), carrying the target section under `wbsection` so it is
	 * preserved across the POST without colliding with core's own `section`.
	 */
	protected function getActionURL( Title $title ) {
		$params = [ 'action' => 'appendsection' ];
		if ( $this->targetSection > 0 ) {
			$params['wbsection'] = (string)$this->targetSection;
		}
		return $title->getLocalURL( $params );
	}

	/** Label the flow "Add section" / "Add section below" instead of "Editing". */
	public function setHeaders() {
		parent::setHeaders();
		$this->getContext()->getOutput()->setPageTitleMsg(
			$this->getContext()->msg(
				$this->targetSection > 0
					? 'embeddablecontent-appendsection-below-title'
					: 'embeddablecontent-appendsection-title',
				$this->getTitle()->getPrefixedText()
			)
		);
	}

	/**
	 * Keep the auto-inferred Summary: with a blank Summary the revision
	 * comment defaults to the section-edit convention built from the topmost
	 * heading of the typed block (see Spec\SectionSummary).
	 */
	public function attemptSave( &$resultDetails = false ) {
		if ( trim( $this->summary ) === '' ) {
			$this->summary = SectionSummary::infer( $this->textbox1 )
				?? $this->getContext()
					->msg( 'embeddablecontent-appendsection-summary-default' )
					->inContentLanguage()
					->text();
		}
		return parent::attemptSave( $resultDetails );
	}

	/**
	 * The new full-page wikitext: append to the page end, or to the end of
	 * the target section (below its heading and any nested subsections). A
	 * target section that vanished while the form was open (a racing edit)
	 * degrades to a page-end append, with a warning.
	 */
	private function assemble( TextContent $current, string $added ): string {
		if ( $this->targetSection <= 0 || !$this->getArticle()->getPage()->supportsSections() ) {
			return AppendSectionText::append( $current->getText(), $added );
		}
		$section = $current->getSection( $this->targetSection );
		if ( !$section instanceof TextContent ) {
			LoggerFactory::getInstance( 'EmbeddableContent' )->warning(
				'AppendSectionEditPage: section {section} vanished on {title}; appending to the page end',
				[
					'section' => $this->targetSection,
					'title' => $this->getTitle()->getPrefixedText(),
				]
			);
			return AppendSectionText::append( $current->getText(), $added );
		}
		$with = ContentHandler::makeContent(
			AppendSectionText::append( $section->getText(), $added ),
			$this->getTitle()
		);
		$replaced = $current->replaceSection( $this->targetSection, $with );
		if ( !$replaced instanceof TextContent ) {
			return AppendSectionText::append( $current->getText(), $added );
		}
		return $replaced->getText();
	}
}
