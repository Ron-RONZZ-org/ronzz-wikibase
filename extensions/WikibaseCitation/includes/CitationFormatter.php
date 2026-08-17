<?php

declare( strict_types = 1 );

namespace WikibaseCitation;

use RuntimeException;
use Seboettg\CiteProc\CiteProc;

/**
 * Formats CSL-JSON into the five supported citation styles (issue #6 §7):
 * - json: the CSL-JSON itself (no processor needed)
 * - apa / vancouver: citeproc-php with vendored CSL styles (in-process — the
 *   original Node sidecar was dropped, see issue #6 update)
 * - bibtex / ris: native serializers
 *
 * @license GPL-2.0-or-later
 */
class CitationFormatter {

	public const STYLES = [ 'json', 'apa', 'vancouver', 'bibtex', 'ris' ];

	/** @var string */
	private $styleDir;

	/** @var BibtexSerializer */
	private $bibtexSerializer;

	/** @var RisSerializer */
	private $risSerializer;

	public function __construct( string $styleDir, ?BibtexSerializer $bibtexSerializer = null, ?RisSerializer $risSerializer = null ) {
		$this->styleDir = rtrim( $styleDir, '/' );
		$this->bibtexSerializer = $bibtexSerializer ?? new BibtexSerializer();
		$this->risSerializer = $risSerializer ?? new RisSerializer();
	}

	/**
	 * @param array<string,mixed> $csl CSL-JSON
	 */
	public function format( array $csl, string $style, string $format = 'text' ): string {
		switch ( $style ) {
			case 'json':
				return $format === 'html'
					? '<pre>' . htmlspecialchars( $this->toJson( $csl ), ENT_QUOTES, 'UTF-8' ) . '</pre>'
					: $this->toJson( $csl );

			case 'apa':
			case 'vancouver':
				return $this->renderCslStyle( $csl, $style, $format );

			case 'bibtex':
				return $this->wrap( $this->bibtexSerializer->serialize( $csl ), $format );

			case 'ris':
				return $this->wrap( $this->risSerializer->serialize( $csl ), $format );
		}
		throw new RuntimeException( "Unsupported citation style '$style'" );
	}

	private function renderCslStyle( array $csl, string $style, string $format ): string {
		$styleFile = $this->styleDir . '/' . $style . '.csl';
		$styleXml = file_get_contents( $styleFile );
		if ( $styleXml === false ) {
			throw new RuntimeException( "CSL style file not found: $styleFile" );
		}

		$citeProc = new CiteProc( $styleXml, 'en-US' );
		// citeproc-php expects an array of stdClass items (DataList spread).
		$cslTree = json_decode( (string)json_encode( [ $csl ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) );
		$html = $citeProc->render( $cslTree, 'bibliography' );

		if ( $format === 'html' ) {
			return $html;
		}
		// Plain text: strip markup, collapse whitespace.
		$text = html_entity_decode( strip_tags( $html ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		return trim( preg_replace( '/\s+/u', ' ', $text ) ?? $text );
	}

	private function wrap( string $text, string $format ): string {
		if ( $format === 'html' ) {
			return '<pre>' . htmlspecialchars( $text, ENT_QUOTES, 'UTF-8' ) . '</pre>';
		}
		return $text;
	}

	private function toJson( array $csl ): string {
		return (string)json_encode( $csl, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
	}
}
