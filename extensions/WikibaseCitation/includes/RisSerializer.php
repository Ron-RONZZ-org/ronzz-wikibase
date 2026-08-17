<?php

declare( strict_types = 1 );

namespace WikibaseCitation;

/**
 * Serializes CSL-JSON into a RIS entry. Pure PHP — no dependencies, so it is
 * unit-testable standalone. Deterministic (issue #6 §7: RIS is one of the five
 * citation styles).
 *
 * @license GPL-2.0-or-later
 */
class RisSerializer {

	private const TYPE_MAP = [
		'article' => 'JOUR',
		'article-journal' => 'JOUR',
		'article-magazine' => 'MGZN',
		'article-newspaper' => 'NEWS',
		'book' => 'BOOK',
		'chapter' => 'CHAP',
		'paper-conference' => 'CONF',
		'webpage' => 'ELEC',
		'software' => 'COMP',
		'thesis' => 'THES',
		'report' => 'RPRT',
	];

	public function serialize( array $csl ): string {
		$lines = [ 'TY  - ' . $this->entryType( $csl['type'] ?? 'article' ) ];

		if ( isset( $csl['author'] ) && is_array( $csl['author'] ) ) {
			foreach ( $csl['author'] as $author ) {
				$lines[] = 'AU  - ' . $this->formatName( $author );
			}
		}
		if ( isset( $csl['title'] ) && $csl['title'] !== '' ) {
			$lines[] = 'TI  - ' . (string)$csl['title'];
		}
		if ( isset( $csl['container-title'] ) && $csl['container-title'] !== '' ) {
			$lines[] = 'JO  - ' . (string)$csl['container-title'];
		}
		if ( isset( $csl['issued']['date-parts'][0][0] ) ) {
			$lines[] = 'PY  - ' . (string)$csl['issued']['date-parts'][0][0];
		} elseif ( isset( $csl['issued']['literal'] ) ) {
			$lines[] = 'PY  - ' . (string)$csl['issued']['literal'];
		}
		if ( isset( $csl['URL'] ) && $csl['URL'] !== '' ) {
			$lines[] = 'UR  - ' . (string)$csl['URL'];
		}

		$lines[] = 'ER  - ';
		return implode( "\n", $lines );
	}

	private function entryType( string $cslType ): string {
		return self::TYPE_MAP[$cslType] ?? 'GEN';
	}

	private function formatName( array $author ): string {
		if ( isset( $author['literal'] ) ) {
			return (string)$author['literal'];
		}
		$family = (string)( $author['family'] ?? '' );
		$given = (string)( $author['given'] ?? '' );
		if ( $family !== '' && $given !== '' ) {
			return "$family, $given";
		}
		return $family ?: $given;
	}
}
