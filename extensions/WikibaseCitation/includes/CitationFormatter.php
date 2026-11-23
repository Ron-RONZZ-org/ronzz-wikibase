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
		// citeproc-php 2.7.1 throws "Call to a member function filter() on
		// null" for the legal CSL types without an author/issued sort key.
		// Render the legal citation ourselves for the citeproc styles; json
		// and the native BibTeX/RIS serializers keep the structured path.
		if ( ( $style === 'apa' || $style === 'vancouver' )
			&& in_array( (string)( $csl['type'] ?? '' ), [ 'legislation', 'legal_case' ], true )
		) {
			return $this->formatLegal( $csl, $format );
		}
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

	/** French long-date month names, used by the legal-case citation. */
	private const MONTHS = [
		1 => 'janvier', 'février', 'mars', 'avril', 'mai', 'juin',
		'juillet', 'août', 'septembre', 'octobre', 'novembre', 'décembre',
	];

	/**
	 * A deterministic legal citation. Legislation keeps the provision/law
	 * shape; a court decision uses the French case citation
	 * (`Conseil constitutionnel, n° 85-197, du 23 août 1985`). A corpus
	 * item's label is already a full citation (it names the court), so it is
	 * rendered as-is instead of duplicating the authority.
	 *
	 * @param array<string,mixed> $csl
	 */
	private function formatLegal( array $csl, string $format ): string {
		$text = ( $csl['type'] ?? '' ) === 'legal_case'
			? $this->formatLegalCase( $csl )
			: $this->formatLegislation( $csl );
		return $format === 'html'
			? '<span class="wikibasecitation-legal">' . htmlspecialchars( $text, ENT_QUOTES, 'UTF-8' ) . '</span>'
			: $text;
	}

	/**
	 * @param array<string,mixed> $csl
	 */
	private function formatLegislation( array $csl ): string {
		$parts = [];
		foreach ( [ 'title', 'container-title', 'authority', 'number' ] as $field ) {
			$value = $csl[$field] ?? '';
			if ( is_string( $value ) && trim( $value ) !== '' ) {
				$parts[] = $field === 'container-title' ? 'in ' . trim( $value ) : trim( $value );
			}
		}
		$text = $parts === [] ? 'Legal source' : implode( ', ', $parts );
		return $this->withPeriod( $text );
	}

	/**
	 * @param array<string,mixed> $csl
	 */
	private function formatLegalCase( array $csl ): string {
		$title = trim( (string)( $csl['title'] ?? '' ) );
		$authority = trim( (string)( $csl['authority'] ?? '' ) );
		if ( $title !== '' && $authority !== '' && mb_stripos( $title, $authority ) !== false ) {
			return $this->withPeriod( $title );
		}
		$parts = [];
		if ( $authority !== '' ) {
			$parts[] = $authority;
		}
		$number = trim( (string)( $csl['number'] ?? '' ) );
		if ( $number !== '' ) {
			$parts[] = preg_match( '/^(n°|no\.?|N°)/u', $number ) === 1 ? $number : 'n° ' . $number;
		}
		$date = $csl['issued']['date-parts'][0] ?? null;
		$dateText = is_array( $date ) ? $this->frenchDate( $date ) : '';
		if ( $dateText !== '' ) {
			$parts[] = 'du ' . $dateText;
		}
		if ( $title !== '' ) {
			$parts[] = $title;
		}
		$text = $parts === [] ? 'Legal source' : implode( ', ', $parts );
		return $this->withPeriod( $text );
	}

	/**
	 * @param int[] $parts CSL date-parts
	 */
	private function frenchDate( array $parts ): string {
		$year = (int)( $parts[0] ?? 0 );
		if ( $year <= 0 ) {
			return '';
		}
		$month = (int)( $parts[1] ?? 0 );
		$day = (int)( $parts[2] ?? 0 );
		if ( $month < 1 || $month > 12 || $day < 1 ) {
			return (string)$year;
		}
		return $day . ' ' . ( self::MONTHS[$month] ?? (string)$month ) . ' ' . $year;
	}

	private function withPeriod( string $text ): string {
		$text = rtrim( trim( $text ) );
		return str_ends_with( $text, '.' ) ? $text : $text . '.';
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
