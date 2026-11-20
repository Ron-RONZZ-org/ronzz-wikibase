<?php

declare( strict_types = 1 );

namespace EmbeddableContent\Upload;

use EmbeddableContent\EmbeddableContentConfig;
use MediaWiki\Content\TextContent;
use MediaWiki\Content\WikitextContent;
use MediaWiki\MediaWikiServices;
use MediaWiki\Title\Title;
use MediaWiki\User\User;

/**
 * Applies the human-readable rights-basis notice to a File: page.
 *
 * The image item carries the machine-readable `rights basis` statement (and
 * the server-written `additional license information` sentence); this helper
 * appends the template that renders the statutory notice and the audit
 * category. It is idempotent: an existing template or category is left
 * alone. Only the citation-exception and author-permission bases produce a
 * notice; a licence, public-domain or own-work basis has nothing extra to
 * say beyond the licence template.
 *
 * @license GPL-2.0-or-later
 */
final class RightsBasisNotice {

	private const CITATION_TEMPLATE = 'Citation exception';
	private const CITATION_CATEGORY = 'Citation exception reproductions';
	private const PERMISSION_TEMPLATE = 'Author permission';
	private const PERMISSION_CATEGORY = 'Author permission reproductions';

	/**
	 * Append the notice template + category to the File page for the given
	 * rights-basis item id. No-op for other bases, a missing page or a
	 * non-wikitext content model.
	 */
	public static function apply(
		EmbeddableContentConfig $config,
		Title $title,
		string $rightsBasis,
		User $user
	): void {
		$keys = $config->rightsBasisKeys();
		if ( ( $keys['citationException'] ?? null ) === $rightsBasis ) {
			$template = self::CITATION_TEMPLATE;
			$category = self::CITATION_CATEGORY;
		} elseif ( ( $keys['authorPermission'] ?? null ) === $rightsBasis ) {
			$template = self::PERMISSION_TEMPLATE;
			$category = self::PERMISSION_CATEGORY;
		} else {
			return;
		}

		if ( $title->getNamespace() !== NS_FILE ) {
			return;
		}
		$page = MediaWikiServices::getInstance()->getWikiPageFactory()->newFromTitle( $title );
		// At UploadComplete time the existence cache may still be negative;
		// the API path runs after the page exists. Only edit a page whose
		// content we can actually read, so a race cannot clobber the upload
		// description.
		if ( !$page->exists() ) {
			return;
		}
		$content = $page->getContent();
		if ( !$content instanceof TextContent ) {
			return;
		}

		$text = $content->getText();
		$addition = '';
		if ( !str_contains( $text, '{{' . $template . '}}' ) ) {
			$addition .= "\n\n{{" . $template . '}}';
		}
		$line = '[[Category:' . $category . ']]';
		if ( !str_contains( $text, $line ) ) {
			$addition .= "\n" . $line;
		}
		if ( $addition === '' ) {
			return;
		}

		$status = $page->doUserEditContent(
			new WikitextContent( rtrim( $text ) . $addition . "\n" ),
			$user,
			'Rights basis notice',
			EDIT_UPDATE,
			$page->getLatest() ?: false
		);
		if ( !$status->isOK() ) {
			throw new \RuntimeException(
				'The rights-basis notice could not be added: ' . $status->getMessage()->text()
			);
		}
	}
}
