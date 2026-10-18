<?php

declare( strict_types = 1 );

namespace EmbeddableContent\Spec;

/**
 * Display-title parts for a Userspace subpage.
 *
 * A page such as `User:Rongzhou/Pretty good Linux` renders its H1 as
 * "Pretty good Linux by @Rongzhou": the root segment is the author's user
 * name, the rest is the page title. The split is pure so the behaviour is
 * unit-tested independently of MediaWiki's Title service.
 *
 * @license GPL-2.0-or-later
 */
final class UserPageTitle {

	/**
	 * @param string $pageText the title text WITHOUT the namespace
	 *  (e.g. "Rongzhou/Pretty good Linux/A")
	 * @return array{user:string,title:string}|null null when the text is not
	 *  a subpage (no `/`, a leading `/`, or an empty remainder)
	 */
	public static function split( string $pageText ): ?array {
		$slash = strpos( $pageText, '/' );
		if ( $slash === false || $slash === 0 || $slash === strlen( $pageText ) - 1 ) {
			return null;
		}
		return [
			'user' => substr( $pageText, 0, $slash ),
			'title' => substr( $pageText, $slash + 1 ),
		];
	}

}
