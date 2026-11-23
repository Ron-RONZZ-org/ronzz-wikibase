<?php

declare( strict_types = 1 );

namespace EmbeddableContent\Law;

/**
 * Parses a DILA JADE decision (administrative case law:
 * Conseil d'État, cours administratives d'appel, tribunaux administratifs).
 *
 * One XML file is one decision; the whole decision is the citable unit
 * (`reference_code` = `décision`). The published (`publie`) and unpublished
 * (`inedit`) trees share this format — the importer is pointed at the tree
 * to ingest.
 *
 * @license GPL-2.0-or-later
 */
final class JadeParser extends LawXmlParser {

	public function accepts( string $path ): bool {
		return str_ends_with( $path, '.xml' ) && str_contains( $path, '/CETA/TEXT/' );
	}

	public function parse( string $xml, string $path ): ?array {
		$doc = $this->load( $xml );
		if ( $doc->getName() !== 'TEXTE_JURI_ADMIN' ) {
			return null;
		}
		$id = $this->text( $doc->META->META_COMMUN->ID ?? null );
		$meta = $doc->META->META_SPEC->META_JURI ?? null;
		$admin = $doc->META->META_SPEC->META_JURI_ADMIN ?? null;
		if ( $id === '' || $meta === null ) {
			throw new LawParseException( 'missing decision id or META_JURI' );
		}
		$title = $this->text( $meta->TITRE );
		$date = $this->text( $meta->DATE_DEC );
		$numero = $this->text( $meta->NUMERO );
		$juridiction = $this->text( $meta->JURIDICTION );
		$typeRec = $this->text( $admin?->TYPE_REC );
		$nature = $this->text( $meta->NATURE );
		$sourceUrl = 'https://www.legifrance.gouv.fr/juri/id/' . rawurlencode( $id );
		$law = [
			'law_key' => $id,
			'jurisdiction' => 'FR',
			'title' => $title !== '' ? $title : ( $juridiction . ', ' . $this->frenchDate( $date ) ),
			'short_title' => $title,
			'nature' => $typeRec !== '' ? $typeRec : ( $nature !== '' ? $nature : null ),
			'nor' => null,
			'number' => $numero !== '' ? $numero : null,
			'court' => $juridiction !== '' ? $juridiction : null,
			'source_url' => $sourceUrl,
			'source' => 'jade',
			'language' => 'fr',
			'version_start' => $this->date( $date ),
			'version_end' => null,
			'status' => 'in_force',
		];

		$content = isset( $doc->TEXTE->BLOC_TEXTUEL->CONTENU )
			? (string)$doc->TEXTE->BLOC_TEXTUEL->CONTENU->asXML()
			: '';
		$text = $this->plainText( $content );
		if ( $text === '' ) {
			return null;
		}
		$article = [
			'law_key' => $id,
			'reference_code' => 'décision',
			'language' => 'fr',
			'article_id' => $id,
			'section_id' => null,
			'section_path' => null,
			'text' => $text,
			'status' => 'in_force',
			'version_start' => $this->date( $date ),
			'version_end' => null,
			'source_url' => $sourceUrl,
			'search_text' => trim( 'décision ' . mb_substr( $text, 0, 500 ) ),
		];
		return [ 'law' => $law, 'sections' => [], 'article' => $article ];
	}

	private function date( string $value ): ?string {
		return preg_match( '/^\d{4}-\d{2}-\d{2}$/', $value ) === 1 ? $value : null;
	}
}
