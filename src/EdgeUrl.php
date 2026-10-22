<?php
/**
 * Variants resized at the edge, by URL.
 *
 * @package   SugarCommerce\ImageVariants
 * @copyright Copyright (c) 2026, ArrayPress Limited
 * @license   GPL-2.0-or-later
 * @since     1.1.0
 */

declare( strict_types=1 );

namespace SugarCommerce\ImageVariants;

/**
 * Class EdgeUrl
 *
 * The other way to get a variant: not by making one here and storing it, but
 * by asking a CDN to make it from the original on the way past. An image this
 * application does not own — one a seller uploaded to Stripe, say, which
 * Stripe serves back unchanged at up to 2 MB — cannot be stored resized
 * without becoming a second copy that drifts from the first. A resizing URL
 * has nothing to keep in step.
 *
 * Cloudflare's is `/cdn-cgi/image/{options}/{source}` on any zone with Image
 * Resizing switched on: no code, no CPU and no storage here.
 *
 * Anything it cannot improve passes straight through, including an empty
 * string — a template deciding whether to render an image should not also
 * have to decide whether resizing was configured. Only `https://` sources are
 * rewritten: a resizer fetching plain HTTP would serve an image over TLS that
 * anyone on the path could have swapped. A source with whitespace, a
 * backslash or a `..` segment passes through too: the source sits in the
 * resizing URL's path, where a browser would resolve `../` against the
 * zone's own paths and send the request somewhere other than the resizer.
 *
 * @since 1.1.0
 */
final readonly class EdgeUrl {

	/**
	 * A Cloudflare Image Resizing URL for a source image.
	 *
	 * @since 1.1.0
	 *
	 * @param string      $host    The zone that serves the resizer, e.g.
	 *                             `shop.example.com`. '' for none, which
	 *                             returns the source unchanged.
	 * @param string      $url     The source image, absolute https.
	 * @param int         $width   Width to ask for, in pixels. The resizer
	 *                             never enlarges past the source.
	 * @param int         $quality 1–100. 82 is about where the file starts
	 *                             growing faster than anybody can see.
	 * @param Fit         $fit     Contain inside the width, or cover it.
	 * @param Format|null $format  An output format, or null to let the edge
	 *                             pick per browser (`format=auto`) — which
	 *                             is almost always right.
	 *
	 * @return string The resizing URL, or `$url` as given.
	 *
	 * @throws \InvalidArgumentException For a format the resizer cannot
	 *                                   write (PNG, GIF).
	 */
	public static function cloudflare(
		string $host,
		string $url,
		int $width,
		int $quality = 82,
		Fit $fit = Fit::Cover,
		?Format $format = null,
	): string {
		if ( ! self::is_source( $url ) || ! self::is_host( $host ) ) {
			return $url;
		}

		return sprintf(
			'https://%s/cdn-cgi/image/width=%d,quality=%d,fit=%s,format=%s/%s',
			strtolower( $host ),
			max( 1, $width ),
			min( 100, max( 1, $quality ) ),
			Fit::Cover === $fit ? 'cover' : 'contain',
			self::format( $format ),
			$url
		);
	}

	/**
	 * Whether a source URL can be put in a resizing URL's path as it is.
	 *
	 * It must be absolute `https://` with a host, and carry nothing a
	 * browser would rewrite on the way: no whitespace or control character,
	 * no backslash (read as a slash), and no `.` or `..` segment, plain or
	 * percent-encoded — `…/https://x/../../../account` resolves to the
	 * zone's `/account`, not to the resizer.
	 *
	 * @since 1.2.0
	 *
	 * @param string $url Candidate.
	 *
	 * @return bool
	 */
	private static function is_source( string $url ): bool {
		if ( ! str_starts_with( $url, 'https://' ) || 1 === preg_match( '/[\x00-\x20\x7F\\\\]/', $url ) ) {
			return false;
		}

		if ( 1 === preg_match( '#/(?:\.|%2e){1,2}(?:[/?\#]|$)#i', $url ) ) {
			return false;
		}

		$host = parse_url( $url, PHP_URL_HOST );

		return is_string( $host ) && '' !== $host;
	}

	/**
	 * Whether a value is a bare host name, optionally with a port.
	 *
	 * Anything else — a scheme, a path, a space — would land in the middle
	 * of a URL somebody's browser then fetches.
	 *
	 * @since 1.1.0
	 *
	 * @param string $host Candidate.
	 *
	 * @return bool
	 */
	private static function is_host( string $host ): bool {
		return 1 === preg_match( '/^[A-Za-z0-9](?:[A-Za-z0-9.\-]*[A-Za-z0-9])?(?::\d{1,5})?$/D', $host );
	}

	/**
	 * The resizer's name for an output format.
	 *
	 * @since 1.1.0
	 *
	 * @param Format|null $format Output format, or null for automatic.
	 *
	 * @return string
	 *
	 * @throws \InvalidArgumentException For PNG and GIF, which the resizer
	 *                                   does not write.
	 */
	private static function format( ?Format $format ): string {
		return match ( $format ) {
			null         => 'auto',
			Format::Webp => 'webp',
			Format::Avif => 'avif',
			Format::Jpeg => 'jpeg',
			default      => throw new \InvalidArgumentException( 'Cloudflare cannot resize to ' . $format->value . '; use null for automatic, or webp, avif or jpeg.' ),
		};
	}
}
