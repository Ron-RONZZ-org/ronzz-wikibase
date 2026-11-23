<?php

declare( strict_types = 1 );

namespace Tests\Unit\Law;

use EmbeddableContent\Law\LegiParser;
use PHPUnit\Framework\TestCase;

/**
 * Fixtures mirror the real DILA LEGI article XML (verified against the
 * 2026-11-22 increment): META/META_ARTICLE, CONTEXTE/TEXTE, nested TM
 * section path, BLOC_TEXTUEL/CONTENU, VERSIONS.
 *
 * @license GPL-2.0-or-later
 */
class LegiParserTest extends TestCase {

	private const ARTICLE = <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<ARTICLE>
<META>
<META_COMMUN>
<ID>LEGIARTI000006525713</ID>
<ORIGINE>LEGI</ORIGINE>
<NATURE>Article</NATURE>
</META_COMMUN>
<META_SPEC>
<META_ARTICLE>
<NUM>D111-1</NUM>
<ETAT>VIGUEUR</ETAT>
<DATE_DEBUT>2006-07-29</DATE_DEBUT>
<DATE_FIN>2999-01-01</DATE_FIN>
<TYPE>AUTONOME</TYPE>
</META_ARTICLE>
</META_SPEC>
</META>
<CONTEXTE>
<TEXTE autorite="" cid="JORFTEXT000000000001" date_publi="2006-07-29" nature="CODE" nor="MENE0600001D" num="2006-1">
<TITRE_TXT c_titre_court="Code de l'éducation" debut="2006-07-29" fin="2999-01-01" id_txt="LEGITEXT000006071191">Code de l'éducation</TITRE_TXT>
<TM>
<TITRE_TM debut="2006-07-29" fin="2999-01-01" id="LEGISCTA000006095806">Partie réglementaire</TITRE_TM>
<TM>
<TITRE_TM debut="2006-07-29" fin="2999-01-01" id="LEGISCTA000006120397">Livre Ier : Principes généraux</TITRE_TM>
<TM>
<TITRE_TM debut="2006-07-29" fin="2999-01-01" id="LEGISCTA000006166752">Section unique</TITRE_TM>
</TM>
</TM>
</TM>
</TEXTE>
</CONTEXTE>
<VERSIONS>
<VERSION etat="VIGUEUR">
<LIEN_ART debut="2006-07-29" etat="VIGUEUR" fin="2999-01-01" id="LEGIARTI000006525713" num="D111-1" origine="LEGI"/>
</VERSION>
</VERSIONS>
<BLOC_TEXTUEL>
<CONTENU>
<p>Les parents des élèves nouvellement inscrits sont réunis.<br/>Ils sont informés.</p>
<p>&#171; Instruction obligatoire &#187; &amp; autres.</p>
</CONTENU>
</BLOC_TEXTUEL>
</ARTICLE>
XML;

	public function testParsesLawSectionAndArticle(): void {
		$parsed = ( new LegiParser() )->parseArticle( self::ARTICLE );
		$this->assertNotNull( $parsed );

		$this->assertSame( 'LEGITEXT000006071191', $parsed['law']['law_key'] );
		$this->assertSame( "Code de l'éducation", $parsed['law']['title'] );
		$this->assertSame( 'CODE', $parsed['law']['nature'] );
		$this->assertSame( 'FR', $parsed['law']['jurisdiction'] );
		$this->assertSame( 'in_force', $parsed['law']['status'] );
		$this->assertStringContainsString( 'LEGITEXT000006071191', (string)$parsed['law']['source_url'] );

		$this->assertCount( 3, $parsed['sections'] );
		$this->assertSame( 'LEGISCTA000006166752', $parsed['sections'][2]['section_id'] );
		$this->assertSame( 'LEGISCTA000006120397', $parsed['sections'][2]['parent_id'] );
		$this->assertSame(
			'Partie réglementaire > Livre Ier : Principes généraux > Section unique',
			$parsed['sections'][2]['path']
		);

		$this->assertSame( 'D111-1', $parsed['article']['reference_code'] );
		$this->assertSame( 'LEGIARTI000006525713', $parsed['article']['article_id'] );
		$this->assertSame( 'VIGUEUR', $parsed['article']['status'] );
		$this->assertSame( 'LEGISCTA000006166752', $parsed['article']['section_id'] );
		$this->assertSame(
			'Partie réglementaire > Livre Ier : Principes généraux > Section unique',
			$parsed['article']['section_path']
		);
		$this->assertSame( 'fr', $parsed['article']['language'] );
		$this->assertSame( '2006-07-29', $parsed['article']['version_start'] );
		$this->assertNull( $parsed['article']['version_end'] );
	}

	public function testPlainTextStripsMarkupAndPreservesParagraphs(): void {
		$text = LegiParser::plainText(
			'<CONTENU><p>Premier <em>alinéa</em>.<br/>Suite.</p><p>Deuxième &amp; fin.</p></CONTENU>'
		);
		$this->assertStringContainsString( 'Premier alinéa.', $text );
		$this->assertStringContainsString( "Suite.\n", $text );
		$this->assertStringContainsString( 'Deuxième & fin.', $text );
		$this->assertStringNotContainsString( '<', $text );
	}

	public function testSkipsArticleWithoutReference(): void {
		$xml = str_replace( '<NUM>D111-1</NUM>', '<NUM></NUM>', self::ARTICLE );
		$this->assertNull( ( new LegiParser() )->parseArticle( $xml ) );
	}

	public function testParsesSuppressionList(): void {
		$contents = "legi/global/code_et_TNC_en_vigueur/TNC_en_vigueur/JORF/TEXT/00/00/29/33/08/JORFTEXT000029330832/article/LEGI/ARTI/00/00/29/33/34/LEGIARTI000029333496.xml D\n";
		$out = ( new LegiParser() )->parseSuppressions( $contents );
		$this->assertSame( [ [ 'type' => 'article', 'id' => 'LEGIARTI000029333496' ] ], $out );
	}
}
