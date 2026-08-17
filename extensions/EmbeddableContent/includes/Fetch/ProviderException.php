<?php

declare( strict_types = 1 );

namespace EmbeddableContent\Fetch;

/**
 * Error in the external-provider fetch layer: network failure, non-2xx HTTP
 * status, invalid JSON, response oversize, timeout, or allowlist (SSRF)
 * rejection. Never swallowed silently — ProviderClient surfaces per-provider
 * failures as warnings on ProviderResult.
 *
 * @license GPL-2.0-or-later
 */
class ProviderException extends \RuntimeException {
}
