<?php

declare( strict_types = 1 );

namespace EmbeddableContent\Law;

/**
 * Parses a DILA CONSTIT decision
 * (https://echanges.dila.gouv.fr/OPENDATA/CONSTIT/).
 *
 * One XML file is one Conseil constitutionnel decision. The decision body is
 * split into its numbered considérants, so a citation can pinpoint one
 * (`law-get CONSTEXT… article=20`); the unnumbered opening (visas) and the
 * `Décide` part become their own rows.
 *
 * @license GPL-2.0-or-later
 */
final class ConstitParser extends LawXmlParser {

	public function accepts( string $path ): bool {
		return str_ends_with( $path, '.xml' ) && str_contains( $path, '/CONS/TEXT/' );
	}

	public function parse( string $xml, string $path ): ?array {
		$doc = $this->load( $xml );
		if ( $doc->getName() !== 'TEXTE_JURI_CONSTIT' ) {
			return null;
		}
		$id = $this->text( $doc->META->META_COMMUN->ID ?? null );
		$meta = $doc->META->META_SPEC->META_JURI ?? null;
		$constit = $doc->META->META_SPEC->META_JURI_CONSTIT ?? null;
		if ( $id === '' || $meta === null ) {
			throw new LawParseException( 'missing decision id or META_JURI' );
		}
		$title = $this->text( $meta->TITRE );
		$date = $this->text( $meta->DATE_DEC );
		$numero = $this->text( $meta->NUMERO );
		$nature = $this->text( $doc->META->META_COMMUN->NATURE )
			?: $this->text( $constit?->NATURE_QUALIFIEE );
		$nor = $this->text( $constit?->NOR );
		$sourceUrl = 'https://www.legifrance.gouv.fr/juri/id/' . rawurlencode( $id );

		$citation = 'Conseil constitutionnel, décision n° ' . $numero
			. ( $nature !== '' ? ' ' . $nature : '' )
			. ( $date !== '' ? ' du ' . $this->frenchDate( $date ) : '' );
		$law = [
			'law_key' => $id,
			'jurisdiction' => 'FR',
			'title' => $numero !== '' ? $citation : ( $title !== '' ? $title : $citation ),
			'short_title' => $title,
			'nature' => $nature !== '' ? $nature : null,
			'nor' => $nor !== '' ? $nor : null,
			'number' => $numero !== '' ? $numero : null,
			'court' => 'Conseil constitutionnel',
			'source_url' => $sourceUrl,
			'source' => 'constit',
			'language' => 'fr',
			'version_start' => $this->date( $date ),
			'version_end' => null,
			'status' => 'in_force',
		];

		$content = isset( $doc->TEXTE->BLOC_TEXTUEL->CONTENU )
			? (string)$doc->TEXTE->BLOC_TEXTUEL->CONTENU->asXML()
			: '';
		$blocks = $this->splitConsiderants( $this->plainText( $content ) );
		if ( $blocks === [] ) {
			return null;
		}

		$sections = [];
		$articles = [];
		foreach ( $blocks as $block ) {
			$reference = $block['reference'];
			$text = trim( $block['text'] );
			if ( $text === '' ) {
				continue;
			}
			$articles[] = [
				'law_key' => $id,
				'reference_code' => $reference,
				'language' => 'fr',
				'article_id' => $this->rowId( $id, $reference ),
				'section_id' => null,
				'section_path' => $block['section'],
				'text' => $text,
				'status' => 'in_force',
				'version_start' => $this->date( $date ),
				'version_end' => null,
				'source_url' => $sourceUrl,
				'search_text' => trim( $reference . ' ' . mb_substr( $text, 0, 500 ) ),
			];
		}
		if ( $articles === [] ) {
			return null;
		}
		// The schema has one article per law/reference pair; the decision is
		// returned as a unit whose article list the importer flattens.
		return [
			'law' => $law,
			'sections' => $sections,
			'article' => $articles[0],
			'articles' => $articles,
		];
	}

	/**
	 * Splits the `CONTENU` text into numbered considérants plus the opening
	 * visas and the `Décide` dispositif.
	 *
	 * @return array<int,array{reference:string,text:string,section:string}>
	 */
	public function splitConsiderants( string $text ): array {
		$blocks = [];
		$current = null;
		foreach ( preg_split( '/\R/', $text ) ?: [] as $line ) {
			$line = trim( $line );
			if ( $line === '' ) {
				continue;
			}
			if ( preg_match( '/^(\d{1,4})\.\s+(.*)$/u', $line, $m ) === 1 ) {
				if ( $current !== null ) {
					$blocks[] = $current;
				}
				$current = [ 'reference' => $m[1], 'text' => $m[2], 'section' => 'Considérants' ];
				continue;
			}
			if ( preg_match( '/^(Décide|DECIDE)\s*:?\s*$/u', $line ) === 1 ) {
				if ( $current !== null ) {
					$blocks[] = $current;
				}
				$current = [ 'reference' => 'dispositif', 'text' => '', 'section' => 'Dispositif' ];
				continue;
			}
			if ( $current === null ) {
				$current = [ 'reference' => 'visas', 'text' => '', 'section' => 'Visas' ];
			}
			$current['text'] .= ( $current['text'] === '' ? '' : "\n" ) . $line;
		}
		if ( $current !== null ) {
			$blocks[] = $current;
		}
		// Merge repeated references (a second `visas` run) and fall back to a
		// single `décision` row when the decision has no numbered reasoning.
		$merged = [];
		foreach ( $blocks as $block ) {
			$ref = $block['reference'];
			if ( isset( $merged[$ref] ) ) {
				$merged[$ref]['text'] .= "\n" . $block['text'];
				continue;
			}
			$merged[$ref] = $block;
		}
		if ( count( $merged ) === 1 && isset( $merged['visas'] ) ) {
			$merged = [ [ 'reference' => 'décision', 'text' => $merged['visas']['text'], 'section' => '' ] ];
		}
		return array_values( $merged );
	}

	private function date( string $value ): ?string {
		return preg_match( '/^\d{4}-\d{2}-\d{2}$/', $value ) === 1 ? $value : null;
	}
}
