<?php

declare( strict_types = 1 );

namespace EmbeddableContent\Spec;

use EmbeddableContent\Pdf\PdfTextExtractor;
use MediaWiki\Html\Html;
use MediaWiki\SpecialPage\SpecialPage;
use MediaWiki\Title\Title;

/**
 * Special:SourceText — the human-readable view of a PDF's Markdown
 * extraction. The same access rule as the source file: public PDFs are
 * readable by anyone; internal-use PDFs require login.
 *
 * @license GPL-2.0-or-later
 */
class SpecialSourceText extends SpecialPage {

	public function __construct() {
		parent::__construct( 'SourceText' );
	}

	public function execute( $subPage ) {
		$this->setHeaders();
		$request = $this->getRequest();
		$fileParam = trim( (string)( $request->getVal( 'file' ) ?: $subPage ) );
		$title = Title::newFromText( $fileParam, NS_FILE );
		if ( $title === null || $title->getNamespace() !== NS_FILE || !$title->exists() ) {
			$this->getOutput()->addHTML(
				Html::errorBox( $this->msg( 'embeddablecontent-sourcefile-invalidfile', $fileParam ?: '?' )->escaped() )
			);
			return;
		}
		if ( !PdfTextExtractor::isPdf( $title ) ) {
			$this->getOutput()->addHTML(
				Html::errorBox( $this->msg( 'embeddablecontent-sourcefile-invalidfile', $title->getPrefixedText() )->escaped() )
			);
			return;
		}
		$this->getOutput()->setPageTitle(
			$this->msg( 'embeddablecontent-source-text-title', $title->getText() )->text()
		);
		if ( InternalFile::isInternal( $title ) && $this->getUser()->isAnon() ) {
			$login = SpecialPage::getTitleFor( 'UserLogin' )->getFullURL( [
				'returnto' => $this->getPageTitle()->getPrefixedText(),
				'returntoquery' => $request->getRawQueryString(),
			] );
			$this->getOutput()->addHTML( Html::warningBox(
				$this->msg( 'embeddablecontent-internal-file-loginrequired' )->escaped()
				. ' ' . Html::element( 'a', [ 'href' => $login ], $this->msg( 'login' )->text() )
			) );
			return;
		}
		$extracted = PdfTextExtractor::read( $title );
		if ( $extracted === null ) {
			$this->getOutput()->addHTML( Html::errorBox(
				$this->msg( 'embeddablecontent-source-text-missing', $title->getPrefixedText() )->escaped()
			) );
			return;
		}
		$mdTitle = PdfTextExtractor::extractedTitle( $title );
		$this->getOutput()->addModules( 'ext.embeddableContent.sourcefiletext' );
		$this->getOutput()->addHTML( Html::warningBox(
			$this->msg( 'embeddablecontent-source-text-notice' )->escaped()
			. ( $extracted['partial'] ? ' ' . $this->msg( 'embeddablecontent-source-text-partial' )->escaped() : '' )
			. ' ' . Html::element(
				'a',
				[ 'href' => $title->getLocalURL() ],
				$this->msg( 'embeddablecontent-source-text-pdf' )->text()
			)
			. ( $mdTitle !== null ? ' · ' . Html::element(
				'a',
				[ 'href' => $mdTitle->getLocalURL() ],
				$mdTitle->getPrefixedText()
			) : '' )
		) );
		$this->getOutput()->addHTML(
			'<div class="wb-source-text-toolbar">'
			. Html::element( 'button', [
				'type' => 'button',
				'class' => 'wb-source-text-copy mw-ui-button',
			], $this->msg( 'embeddablecontent-source-text-copy' )->text() )
			. '</div>'
			. '<pre class="wb-source-text">' . htmlspecialchars( $extracted['text'] ) . '</pre>'
		);
	}
}
