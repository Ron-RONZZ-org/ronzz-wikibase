<?php

declare( strict_types = 1 );

namespace Tests\Unit\Law;

use EmbeddableContent\Law\ConstitParser;
use PHPUnit\Framework\TestCase;

/**
 * Fixture mirrors the real DILA CONSTIT decision XML (verified against the
 * 2026-11-08 stock): META_JURI + META_JURI_CONSTIT and one CONTENU holding
 * the numbered considérants.
 *
 * @license GPL-2.0-or-later
 */
class ConstitParserTest extends TestCase {

	private const DECISION = <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<TEXTE_JURI_CONSTIT>
<META>
<META_COMMUN>
<ID>CONSTEXT000017667855</ID>
<ORIGINE>CONSTIT</ORIGINE>
<NATURE>DC</NATURE>
</META_COMMUN>
<META_SPEC>
<META_JURI>
<TITRE>Loi sur l'évolution de la Nouvelle-Calédonie </TITRE>
<DATE_DEC>1985-08-23</DATE_DEC>
<JURIDICTION>Conseil constitutionnel</JURIDICTION>
<NUMERO>85-197</NUMERO>
<SOLUTION>Conformité</SOLUTION>
</META_JURI>
<META_JURI_CONSTIT>
<NOR/>
<NATURE_QUALIFIEE>DC04</NATURE_QUALIFIEE>
<URL_CC>http://www.conseil-constitutionnel.fr/decision/1985/85197DC.htm</URL_CC>
<ECLI>ECLI:FR:CC:1985:85.197.DC</ECLI>
</META_JURI_CONSTIT>
</META_SPEC>
</META>
<TEXTE>
<BLOC_TEXTUEL>
<CONTENU>Le Conseil constitutionnel,<br/>Vu la Constitution ;<br/>1. Considérant que la procédure est régulière ;<br/>2. Considérant que le moyen n'est pas fondé ;<br/>Décide :<br/>Article premier : La loi est déclarée conforme à la Constitution.<br/></CONTENU>
</BLOC_TEXTUEL>
</TEXTE>
</TEXTE_JURI_CONSTIT>
XML;

	public function testParsesTheDecisionAndItsConsiderants(): void {
		$unit = ( new ConstitParser() )->parse( self::DECISION, 'constit/global/CONS/TEXT/00/CONSTEXT000017667855.xml' );
		$this->assertNotNull( $unit );
		$law = $unit['law'];
		$this->assertSame( 'CONSTEXT000017667855', $law['law_key'] );
		$this->assertSame( 'Conseil constitutionnel, décision n° 85-197 DC du 23 août 1985', $law['title'] );
		$this->assertSame( '85-197', $law['number'] );
		$this->assertSame( 'Conseil constitutionnel', $law['court'] );
		$this->assertSame( 'constit', $law['source'] );
		$this->assertSame( "Loi sur l'évolution de la Nouvelle-Calédonie", $law['short_title'] );

		$refs = array_column( $unit['articles'], 'reference_code' );
		$this->assertSame( [ 'visas', '1', '2', 'dispositif' ], $refs );
		$byRef = [];
		foreach ( $unit['articles'] as $article ) {
			$byRef[$article['reference_code']] = $article;
		}
		$this->assertStringContainsString( 'procédure est régulière', $byRef['1']['text'] );
		$this->assertStringContainsString( 'déclarée conforme', $byRef['dispositif']['text'] );
		$this->assertSame( 'CONSTEXT000017667855#1', $byRef['1']['article_id'] );
		$this->assertSame( 'CONSTEXT000017667855#dispositif', $byRef['dispositif']['article_id'] );
	}

	public function testAcceptsOnlyConstitPaths(): void {
		$parser = new ConstitParser();
		$this->assertTrue( $parser->accepts( 'x/CONS/TEXT/00/CONSTEXT1.xml' ) );
		$this->assertFalse( $parser->accepts( 'x/JURI/TEXT/00/JURITEXT1.xml' ) );
	}
}
