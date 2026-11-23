<?php

declare( strict_types = 1 );

namespace EmbeddableContent\Law;

use SimpleXMLElement;

/**
 * Shared XML helpers for the DILA jurisprudence sources (CONSTIT, CASS,
 * JADE). The LEGI parser predates this class and keeps its own loader.
 *
 * @license GPL-2.0-or-later
 */
abstract class LawXmlParser implements LawSourceParser {

	protected const MONTHS = [
		1 => 'janvier', 'février', 'mars', 'avril', 'mai', 'juin',
		'juillet', 'août', 'septembre', 'octobre', 'novembre', 'décembre',
	];

	/**
	 * @throws LawParseException
	 */
	protected function load( string $xml ): SimpleXMLElement {
		$previous = libxml_use_internal_errors( true );
		$doc = simplexml_load_string( $xml, SimpleXMLElement::class, LIBXML_NONET | LIBXML_NOCDATA );
		$errors = libxml_get_errors();
		libxml_clear_errors();
		libxml_use_internal_errors( $previous );
		if ( $doc === false ) {
			throw new LawParseException( trim( $errors[0]->message ?? 'invalid XML' ) );
		}
		return $doc;
	}

	/**
	 * A `YYYY-MM-DD` date as a French long date (`23 août 1985`).
	 */
	protected function frenchDate( string $date ): string {
		if ( preg_match( '/^(\d{4})-(\d{2})-(\d{2})$/', trim( $date ), $m ) !== 1 ) {
			return trim( $date );
		}
		$month = (int)$m[2];
		return (int)$m[3] . ' ' . ( self::MONTHS[$month] ?? $m[2] ) . ' ' . $m[1];
	}

	protected function text( ?SimpleXMLElement $node ): string {
		return $node === null ? '' : trim( (string)$node );
	}

	/**
	 * Normalises the DILA jurisprudence HTML fragment to plain text.
	 */
	protected function plainText( string $html ): string {
		return LegiParser::plainText( $html );
	}

	/**
	 * A stable per-row id: the decision id for a whole decision, or
	 * `{id}#{reference}` for one paragraph of it (the `article_id` column is
	 * unique, so the paragraph suffix is required).
	 */
	protected function rowId( string $decisionId, string $reference ): string {
		return $reference === '' || $reference === 'décision'
			? $decisionId
			: $decisionId . '#' . preg_replace( '/[^A-Za-z0-9]+/', '-', $reference );
	}
}
