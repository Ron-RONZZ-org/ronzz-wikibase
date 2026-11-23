<?php

declare( strict_types = 1 );

namespace Tests\Unit\Law;

use EmbeddableContent\Law\CassParser;
use PHPUnit\Framework\TestCase;

/**
 * Fixture mirrors the real DILA CASS XML (2026-11-08 stock):
 * META_JURI + META_JURI_JUDI (NUMEROS_AFFAIRES) and the full decision in
 * BLOC_TEXTUEL/CONTENU.
 *
 * @license GPL-2.0-or-later
 */
class CassParserTest extends TestCase {

	private const DECISION = <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<TEXTE_JURI_JUDI>
<META>
<META_COMMUN>
<ID>JURITEXT000019425557</ID>
<ORIGINE>JURI</ORIGINE>
<NATURE>ARRET</NATURE>
</META_COMMUN>
<META_SPEC>
<META_JURI>
<TITRE>Cour de cassation, chambre sociale, 12 juin 2019, 17-20.139, Publié au bulletin</TITRE>
<DATE_DEC>2019-06-12</DATE_DEC>
<JURIDICTION>Cour de cassation</JURIDICTION>
<NUMERO>F1720139</NUMERO>
<SOLUTION>Rejet</SOLUTION>
</META_JURI>
<META_JURI_JUDI>
<NUMEROS_AFFAIRES>
<NUMERO_AFFAIRE>17-20.139</NUMERO_AFFAIRE>
</NUMEROS_AFFAIRES>
<PUBLI_BULL publie="oui"/>
<FORMATION>Chambre sociale</FORMATION>
</META_JURI_JUDI>
</META_SPEC>
</META>
<TEXTE>
<BLOC_TEXTUEL>
<CONTENU><p>Attendu que la salariée fait grief à l'arrêt...</p><p>Mais attendu que le moyen n'est pas fondé ;</p><p>REJETTE le pourvoi.</p></CONTENU>
</BLOC_TEXTUEL>
</TEXTE>
</TEXTE_JURI_JUDI>
XML;

	public function testParsesTheWholeDecisionAsTheCitableUnit(): void {
		$unit = ( new CassParser() )->parse( self::DECISION, 'cass/global/JURI/TEXT/00/JURITEXT000019425557.xml' );
		$this->assertNotNull( $unit );
		$law = $unit['law'];
		$this->assertSame( 'JURITEXT000019425557', $law['law_key'] );
		$this->assertSame( '17-20.139', $law['number'] );
		$this->assertSame( 'Cour de cassation', $law['court'] );
		$this->assertSame( 'cass', $law['source'] );
		$this->assertSame( 'ARRET', $law['nature'] );
		$this->assertStringContainsString( 'Cour de cassation', $law['short_title'] );

		$article = $unit['article'];
		$this->assertSame( 'arrêt', $article['reference_code'] );
		$this->assertSame( 'JURITEXT000019425557', $article['article_id'] );
		$this->assertStringContainsString( 'REJETTE le pourvoi', $article['text'] );
	}

	public function testAcceptsOnlyCassPaths(): void {
		$parser = new CassParser();
		$this->assertTrue( $parser->accepts( 'x/JURI/TEXT/00/JURITEXT1.xml' ) );
		$this->assertFalse( $parser->accepts( 'x/CETA/TEXT/00/CETATEXT1.xml' ) );
	}
}
