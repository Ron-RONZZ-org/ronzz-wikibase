<?php

declare( strict_types = 1 );

namespace Tests\Unit\Law;

use EmbeddableContent\Law\JadeParser;
use PHPUnit\Framework\TestCase;

/**
 * Fixture mirrors the real DILA JADE XML (2026-11-20 increment):
 * META_JURI + META_JURI_ADMIN (FORMATION, TYPE_REC).
 *
 * @license GPL-2.0-or-later
 */
class JadeParserTest extends TestCase {

	private const DECISION = <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<TEXTE_JURI_ADMIN>
<META>
<META_COMMUN>
<ID>CETATEXT000055088351</ID>
<ORIGINE>CETAT</ORIGINE>
<NATURE>Texte</NATURE>
</META_COMMUN>
<META_SPEC>
<META_JURI>
<TITRE>Conseil d'État, 5ème chambre, 12/11/2026, 25TL00924, Inédit au recueil Lebon</TITRE>
<DATE_DEC>2026-11-12</DATE_DEC>
<JURIDICTION>Conseil d'État</JURIDICTION>
<NUMERO>25TL00924</NUMERO>
<SOLUTION>Rejet</SOLUTION>
</META_JURI>
<META_JURI_ADMIN>
<FORMATION>5ème chambre</FORMATION>
<TYPE_REC>excès de pouvoir</TYPE_REC>
<PUBLI_RECUEIL>C</PUBLI_RECUEIL>
</META_JURI_ADMIN>
</META_SPEC>
</META>
<TEXTE>
<BLOC_TEXTUEL>
<CONTENU>Vu la requête...<br/><br/>1. Considérant que...<br/><br/>DECIDE :<br/>Article 1er : La requête est rejetée.</CONTENU>
</BLOC_TEXTUEL>
</TEXTE>
</TEXTE_JURI_ADMIN>
XML;

	public function testParsesTheAdministrativeDecision(): void {
		$unit = ( new JadeParser() )->parse( self::DECISION, 'jade/global/publie/CETA/TEXT/00/CETATEXT000055088351.xml' );
		$this->assertNotNull( $unit );
		$law = $unit['law'];
		$this->assertSame( 'CETATEXT000055088351', $law['law_key'] );
		$this->assertSame( '25TL00924', $law['number'] );
		$this->assertSame( 'Conseil d\'État', $law['court'] );
		$this->assertSame( 'jade', $law['source'] );
		$this->assertSame( 'excès de pouvoir', $law['nature'] );

		$article = $unit['article'];
		$this->assertSame( 'décision', $article['reference_code'] );
		$this->assertStringContainsString( 'La requête est rejetée', $article['text'] );
	}

	public function testAcceptsOnlyJadePaths(): void {
		$parser = new JadeParser();
		$this->assertTrue( $parser->accepts( 'x/CETA/TEXT/00/CETATEXT1.xml' ) );
		$this->assertFalse( $parser->accepts( 'x/JURI/TEXT/00/JURITEXT1.xml' ) );
	}
}
