<?php

declare( strict_types = 1 );

namespace EmbeddableContent\Spec;

/**
 * Whether a request's Origin (or Referer) names the same host as the wiki.
 *
 * Used by the edit-page automatic CSRF-token retry: core forces a PREVIEW
 * when a save's edit token is stale, which also defends against off-site
 * form posts. Auto-resubmitting that preview must therefore be limited to
 * requests that came from this wiki. The host is the security boundary —
 * scheme is not compared (a reverse proxy may terminate TLS) and a port
 * difference on the same host does not cross an origin the attacker can
 * control. A missing, malformed or `null` origin never matches, so those
 * requests keep core's safe preview.
 *
 * Pure static — no MediaWiki runtime, unit-tested.
 *
 * @license GPL-2.0-or-later
 */
final class SameOrigin {

	/**
	 * @param string $origin the request's Origin header, or its Referer
	 * @param string $selfUrl this wiki's request URL
	 * @return bool whether the origin's host matches the wiki's host
	 */
	public static function matches( string $origin, string $selfUrl ): bool {
		$originHost = parse_url( $origin, PHP_URL_HOST );
		$selfHost = parse_url( $selfUrl, PHP_URL_HOST );
		return is_string( $originHost ) && $originHost !== ''
			&& is_string( $selfHost ) && $originHost === $selfHost;
	}

}
