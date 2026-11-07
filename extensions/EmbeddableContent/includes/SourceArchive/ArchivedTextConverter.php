<?php

declare( strict_types = 1 );

namespace EmbeddableContent\SourceArchive;

/**
 * HTML → wikitext for an archived source page:
 *
 *  1. {@see HtmlPreparer} strips chrome and turns `<img>` into placeholders;
 *  2. the injectable Pandoc callable converts the fragment
 *     (`-f html -t mediawiki`);
 *  3. the image placeholders become the media note + caption;
 *  4. {@see WikitextScrubber} removes `{{…}}` and unwraps `[[…]]`.
 *
 * The Pandoc callable is injectable so the pipeline is unit-testable without
 * the binary. The default shells out through MediaWiki's Shell framework,
 * the same way the vendored Diagrams extension invokes its renderers.
 *
 * @license GPL-2.0-or-later
 */
final class ArchivedTextConverter {

	/** @var callable(string):string */
	private $pandoc;

	/** @var HtmlPreparer */
	private $preparer;

	/** @var WikitextScrubber */
	private $scrubber;

	public function __construct(
		?callable $pandoc = null,
		?HtmlPreparer $preparer = null,
		?WikitextScrubber $scrubber = null
	) {
		$this->pandoc = $pandoc ?? [ self::class, 'runPandoc' ];
		$this->preparer = $preparer ?? new HtmlPreparer();
		$this->scrubber = $scrubber ?? new WikitextScrubber();
	}

	/**
	 * @return string|null the scrubbed wikitext, or null when the page
	 *   produced no text
	 * @throws ArchivedTextException when the converter fails
	 */
	public function convert( string $html, string $sourceUrl, string $mediaNote ): ?string {
		$prepared = $this->preparer->prepare( $html );
		$wikitext = ( $this->pandoc )( $prepared['html'] );
		if ( !is_string( $wikitext ) ) {
			throw new ArchivedTextException( 'The HTML converter returned no text.' );
		}
		$wikitext = $this->restoreImages( $wikitext, $prepared['images'], $sourceUrl, $mediaNote );
		$wikitext = $this->restoreRefs( $wikitext, $prepared['refs'] );
		$wikitext = $this->scrubber->scrub( $wikitext, $sourceUrl, $mediaNote );
		return $wikitext === '' ? null : $wikitext;
	}

	/** @param string[] $refs */
	private function restoreRefs( string $wikitext, array $refs ): string {
		foreach ( $refs as $index => $ref ) {
			$wikitext = str_replace( sprintf( HtmlPreparer::REF_TOKEN, $index ), $ref, $wikitext );
		}
		return $wikitext;
	}

	/** @param list<array{src:string, alt:string}> $images */
	private function restoreImages( string $wikitext, array $images, string $sourceUrl, string $mediaNote ): string {
		$note = $this->scrubber->mediaNoteFor( $sourceUrl, $mediaNote );
		foreach ( $images as $index => $image ) {
			$caption = trim( (string)$image['alt'] );
			$replacement = $note . ( $caption !== '' ? ' ' . $caption : '' );
			$wikitext = str_replace( sprintf( HtmlPreparer::IMAGE_TOKEN, $index ), $replacement, $wikitext );
		}
		return $wikitext;
	}

	/**
	 * The default converter: Pandoc over stdin/stdout, through MediaWiki's
	 * Shell framework (default sandbox restrictions; Pandoc needs no network
	 * for an HTML → wikitext conversion).
	 */
	public static function runPandoc( string $html ): string {
		$result = \MediaWiki\Shell\Shell::command(
			'pandoc', '-f', 'html', '-t', 'mediawiki', '--wrap=none'
		)->input( $html )->execute();

		if ( $result->getExitCode() !== 0 ) {
			$error = trim( (string)$result->getStderr() );
			if ( $error === '' ) {
				$error = trim( (string)$result->getStdout() );
			}
			throw new ArchivedTextException( 'Pandoc failed: ' . $error );
		}

		return (string)$result->getStdout();
	}
}
