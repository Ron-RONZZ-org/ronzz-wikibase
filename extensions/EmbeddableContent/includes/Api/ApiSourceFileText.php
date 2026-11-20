<?php

declare( strict_types = 1 );

namespace EmbeddableContent\Api;

use EmbeddableContent\Pdf\PdfTextExtractor;
use EmbeddableContent\Spec\InternalFile;
use MediaWiki\Api\ApiBase;
use MediaWiki\Language\RawMessage;
use MediaWiki\Title\Title;

/**
 * api.php?action=sourcefiletext — the precomputed Markdown extraction of a
 * PDF File page (Apache Tika, OCR when needed).
 *
 * Access follows the source file: public PDFs are readable by anyone;
 * internal-use PDFs require a logged-in session (the MCP writer uses its bot
 * credentials). This is the fast path for LLMs: no PDF download, no OCR on
 * the client.
 *
 * @license GPL-2.0-or-later
 */
class ApiSourceFileText extends ApiBase {

	public function execute() {
		$params = $this->extractRequestParams();
		$title = Title::newFromText( (string)$params['file'], NS_FILE );
		if ( $title === null || $title->getNamespace() !== NS_FILE ) {
			$this->dieWithError( new RawMessage( 'file must name a File: page.' ), 'invalid_filename' );
		}
		if ( !$title->exists() ) {
			$this->dieWithError(
				new RawMessage( "File page \"{$title->getPrefixedText()}\" does not exist." ),
				'not_found'
			);
		}
		if ( !PdfTextExtractor::isPdf( $title ) ) {
			$this->dieWithError(
				new RawMessage( "File \"{$title->getPrefixedText()}\" is not a PDF." ),
				'not_a_pdf'
			);
		}
		if ( InternalFile::isInternal( $title ) && !$this->getUser()->isRegistered() ) {
			$this->dieWithError(
				new RawMessage( 'Internal-use files require a logged-in session.' ),
				'permissiondenied'
			);
		}
		$extracted = PdfTextExtractor::read( $title );
		if ( $extracted === null ) {
			$this->dieWithError(
				new RawMessage(
					'No extracted text exists for this PDF yet. An operator can run'
					. ' maintenance/extractPdfText.php --file=' . $title->getPrefixedText() . '.'
				),
				'no_extracted_text'
			);
		}
		$mdTitle = PdfTextExtractor::extractedTitle( $title );
		$this->getResult()->addValue( null, 'sourcefiletext', [
			'file' => $title->getPrefixedText(),
			'extractedFrom' => $mdTitle !== null ? $mdTitle->getPrefixedText() : '',
			'text' => $extracted['text'],
			'chars' => mb_strlen( $extracted['text'] ),
			'updated' => $extracted['updated'],
			'partial' => $extracted['partial'] ? '1' : '',
		] );
	}

	public function getAllowedParams() {
		return [
			'file' => [
				self::PARAM_TYPE => 'string',
				self::PARAM_REQUIRED => true,
				self::PARAM_MAX_BYTES => 255,
			],
		];
	}

	public function isWriteMode() {
		return false;
	}

	public function mustBePosted() {
		return false;
	}

	public function getModuleDescription() {
		return 'Returns the precomputed Markdown text of a PDF File: page (Apache Tika extraction, OCR where needed). Public files are readable by anyone; internal-use files require a logged-in session. Returns no_extracted_text when the companion .md does not exist yet.';
	}
}
