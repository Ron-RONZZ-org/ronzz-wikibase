<?php

declare( strict_types = 1 );

namespace WikibaseCitation;

/**
 * Serializes CSL-JSON into a BibTeX entry. Pure PHP — no dependencies, so it
 * is unit-testable standalone. Deterministic; unknown CSL types map to
 * @misc (issue #6 §7: BibTeX is one of the five citation styles).
 *
 * @license GPL-2.0-or-later
 */
class BibtexSerializer {

	private const TYPE_MAP = [
		'article' => 'article',
		'article-journal' => 'article',
		'article-magazine' => 'article',
		'article-newspaper' => 'article',
		'book' => 'book',
		'chapter' => 'incollection',
		'paper-conference' => 'inproceedings',
		'webpage' => 'misc',
		'software' => 'software',
		'thesis' => 'phdthesis',
		'report' => 'techreport',
	];

	public function serialize( array $csl ): string {
		$type = $this->entryType( $csl['type'] ?? 'misc' );
		$key = $this->entryKey( $csl );

		$fields = [];
		if ( isset( $csl['author'] ) && is_array( $csl['author'] ) ) {
			$names = [];
			foreach ( $csl['author'] as $author ) {
				$names[] = $this->formatName( $author );
			}
			if ( $names !== [] ) {
				$fields['author'] = implode( ' and ', $names );
			}
		}
		if ( isset( $csl['title'] ) && $csl['title'] !== '' ) {
			$fields['title'] = (string)$csl['title'];
		}
		if ( isset( $csl['container-title'] ) && $csl['container-title'] !== '' ) {
			$fields['journal'] = (string)$csl['container-title'];
		}
		$year = $this->year( $csl );
		if ( $year !== null ) {
			$fields['year'] = (string)$year;
		}
		if ( isset( $csl['URL'] ) && $csl['URL'] !== '' ) {
			$fields['url'] = (string)$csl['URL'];
		}

		$lines = [ "@$type{" . $key . ',' ];
		foreach ( $fields as $name => $value ) {
			$lines[] = '  ' . $name . ' = {' . $this->brace( $value ) . '},';
		}
		$lines[] = '}';
		return implode( "\n", $lines );
	}

	private function entryType( string $cslType ): string {
		return self::TYPE_MAP[$cslType] ?? 'misc';
	}

	private function entryKey( array $csl ): string {
		$parts = [];
		if ( isset( $csl['author'][0]['literal'] ) ) {
			$parts[] = preg_replace( '/[^A-Za-z]/', '', (string)$csl['author'][0]['literal'] );
		} elseif ( isset( $csl['author'][0]['family'] ) ) {
			$parts[] = preg_replace( '/[^A-Za-z]/', '', (string)$csl['author'][0]['family'] );
		}
		$year = $this->year( $csl );
		if ( $year !== null ) {
			$parts[] = (string)$year;
		}
		if ( isset( $csl['title'] ) ) {
			$parts[] = substr( preg_replace( '/[^A-Za-z0-9]/', '', (string)$csl['title'] ), 0, 20 );
		}
		$key = implode( '', $parts );
		return $key === '' ? 'item' : $key;
	}

	private function formatName( array $author ): string {
		if ( isset( $author['literal'] ) ) {
			return $this->brace( (string)$author['literal'] );
		}
		$family = (string)( $author['family'] ?? '' );
		$given = (string)( $author['given'] ?? '' );
		if ( $family !== '' && $given !== '' ) {
			return $this->brace( "$family, $given" );
		}
		return $this->brace( $family ?: $given );
	}

	private function year( array $csl ): ?int {
		if ( isset( $csl['issued']['date-parts'][0][0] ) ) {
			return (int)$csl['issued']['date-parts'][0][0];
		}
		return null;
	}

	/**
	 * Protects braces inside field values (BibTeX brace balancing).
	 */
	private function brace( string $value ): string {
		return str_replace( [ '{', '}' ], [ '\{', '\}' ], $value );
	}
}
