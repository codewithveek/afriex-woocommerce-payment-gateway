<?php
/**
 * Makes the wp-env site reachable through a public tunnel.
 *
 * Local test environment only — this directory is mounted into mu-plugins by
 * .wp-env.json and never ships.
 *
 * The problem it solves: siteurl and home are stored as http://localhost:8888.
 * When a request arrives with a different Host, WordPress's canonical redirect
 * rebuilds the URL from the incoming host but keeps the stored port, sending
 * the visitor to https://your-tunnel-host:8888/ — a port the tunnel does not
 * serve. The browser then hangs on a host that will never answer.
 *
 * Rather than hard-pinning siteurl to the tunnel (which breaks localhost), this
 * resolves both URLs per request, so http://localhost:8888 and the tunnel both
 * keep working.
 *
 * Set the tunnel hostname by putting it in tests/mu-plugins/tunnel-host.txt:
 *
 *   echo wp-afriex.example.com > tests/mu-plugins/tunnel-host.txt
 *
 * A file rather than a wp-config constant because wp-env does not reliably
 * write custom constants on restart, and this directory is already mounted —
 * so a change takes effect on the next request with no restart at all.
 *
 * @package Afriex_Gateway_For_WooCommerce
 */

defined( 'ABSPATH' ) || exit;

/**
 * The configured tunnel hostname, or '' when none is set.
 */
function afriex_dev_tunnel_host(): string {
	if ( defined( 'AFRIEX_DEV_TUNNEL_HOST' ) && AFRIEX_DEV_TUNNEL_HOST ) {
		return strtolower( (string) AFRIEX_DEV_TUNNEL_HOST );
	}

	$file = __DIR__ . '/tunnel-host.txt';

	if ( ! file_exists( $file ) ) {
		return '';
	}

	// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
	return strtolower( trim( (string) file_get_contents( $file ) ) );
}

/**
 * The host this request came in on, but only if we recognise it.
 *
 * Deliberately an allowlist. Deriving site URLs from an unchecked Host header
 * is how host-header injection turns into poisoned password-reset links, and
 * this plugin's own webhook URL is built from these options — so a spoofed host
 * here would be shown to a merchant as the address to hand Afriex.
 *
 * @return string|null Host, or null when it is not one we know.
 */
function afriex_dev_trusted_host(): ?string {
	$host = isset( $_SERVER['HTTP_HOST'] ) ? strtolower( wp_unslash( $_SERVER['HTTP_HOST'] ) ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput

	if ( '' === $host ) {
		return null;
	}

	$allowed = array( 'localhost:8888', 'localhost', '127.0.0.1:8888' );

	$tunnel_host = afriex_dev_tunnel_host();

	if ( '' !== $tunnel_host ) {
		$allowed[] = $tunnel_host;
	}

	return in_array( $host, $allowed, true ) ? $host : null;
}

/**
 * Whether this request reached the tunnel over HTTPS.
 *
 * Cloudflare terminates TLS and forwards plain HTTP to the container, so
 * without this WordPress believes the request is insecure, redirects to the
 * https canonical URL, and Cloudflare forwards the retry as HTTP again — a
 * redirect loop rather than a page.
 */
function afriex_dev_request_is_https(): bool {
	if ( ! empty( $_SERVER['HTTP_X_FORWARDED_PROTO'] ) ) {
		$proto = strtolower( wp_unslash( $_SERVER['HTTP_X_FORWARDED_PROTO'] ) ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		return 'https' === $proto;
	}

	return ! empty( $_SERVER['HTTPS'] ) && 'off' !== $_SERVER['HTTPS'];
}

$afriex_dev_host = afriex_dev_trusted_host();

if ( null !== $afriex_dev_host ) {
	if ( afriex_dev_request_is_https() ) {
		// Must be set before anything calls is_ssl(), or the canonical redirect
		// and every generated URL disagree with how the request actually came in.
		$_SERVER['HTTPS'] = 'on';
	}

	$afriex_dev_url = ( afriex_dev_request_is_https() ? 'https://' : 'http://' ) . $afriex_dev_host;

	add_filter(
		'option_home',
		function () use ( $afriex_dev_url ) {
			return $afriex_dev_url;
		}
	);

	add_filter(
		'option_siteurl',
		function () use ( $afriex_dev_url ) {
			return $afriex_dev_url;
		}
	);
}
