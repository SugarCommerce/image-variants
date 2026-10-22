<?php
/**
 * Raster image formats.
 *
 * @package   SugarCommerce\ImageVariants
 * @copyright Copyright (c) 2026, ArrayPress Limited
 * @license   GPL-2.0-or-later
 * @since     1.0.0
 */

declare( strict_types=1 );

namespace SugarCommerce\ImageVariants;

/**
 * Enum Format
 *
 * Note the absence of SVG. It is not a raster format, it cannot be
 * resampled, and — the actual reason — it can carry `<script>`. Serving
 * an uploaded SVG inline from your own origin is stored cross-site
 * scripting, so it has no place in an upload pipeline that produces
 * `<img>` sources.
 *
 * @since 1.0.0
 */
enum Format: string {

	/**
	 * JPEG: photographs, no transparency.
	 */
	case Jpeg = 'jpeg';

	/**
	 * PNG: lossless, with transparency.
	 */
	case Png = 'png';

	/**
	 * GIF: a palette of 256 colours; only the first frame is read.
	 */
	case Gif = 'gif';

	/**
	 * WebP: the default output — smaller than JPEG, with transparency.
	 */
	case Webp = 'webp';

	/**
	 * AVIF: smaller again, slower to encode, missing from older GD builds.
	 */
	case Avif = 'avif';

	/**
	 * The canonical MIME type.
	 *
	 * @since 1.0.0
	 *
	 * @return string
	 */
	public function mime(): string {
		return 'image/' . $this->value;
	}

	/**
	 * The usual file extension.
	 *
	 * @since 1.0.0
	 *
	 * @return string
	 */
	public function extension(): string {
		return match ( $this ) {
			self::Jpeg => 'jpg',
			default    => $this->value,
		};
	}

	/**
	 * Whether this format can carry transparency.
	 *
	 * Matters when choosing an output format: flattening a transparent
	 * PNG to JPEG puts a black box behind the logo.
	 *
	 * @since 1.0.0
	 *
	 * @return bool
	 */
	public function supports_transparency(): bool {
		return match ( $this ) {
			self::Png, self::Webp, self::Avif, self::Gif => true,
			self::Jpeg => false,
		};
	}

	/**
	 * Identify a format from the leading bytes.
	 *
	 * Content sniffing, not the declared type. A `Content-Type` header or
	 * a `$_FILES` entry is chosen by whoever uploaded the file, and a
	 * `.png` that is really something else is the oldest trick there is.
	 *
	 * @since 1.0.0
	 *
	 * @param string $bytes Image data.
	 *
	 * @return self|null Null when unrecognised.
	 */
	public static function detect( string $bytes ): ?self {
		if ( strlen( $bytes ) < 12 ) {
			return null;
		}

		return match ( true ) {
			str_starts_with( $bytes, "\xFF\xD8\xFF" )              => self::Jpeg,
			str_starts_with( $bytes, "\x89PNG\r\n\x1A\n" )         => self::Png,
			str_starts_with( $bytes, 'GIF87a' ),
			str_starts_with( $bytes, 'GIF89a' )                    => self::Gif,
			// RIFF container: "RIFF" then four size bytes then "WEBP".
			str_starts_with( $bytes, 'RIFF' ) && 'WEBP' === substr( $bytes, 8, 4 ) => self::Webp,
			// ISO-BMFF: a box length, then "ftyp", then a brand.
			'ftyp' === substr( $bytes, 4, 4 ) && self::is_avif_brand( substr( $bytes, 8, 4 ) ) => self::Avif,
			default => null,
		};
	}

	/**
	 * Whether an ISO-BMFF brand denotes AVIF.
	 *
	 * @since 1.0.0
	 *
	 * @param string $brand Four-character brand.
	 *
	 * @return bool
	 */
	private static function is_avif_brand( string $brand ): bool {
		return in_array( $brand, array( 'avif', 'avis' ), true );
	}

	/**
	 * Whether this build of GD can decode this format.
	 *
	 * @since 1.0.0
	 *
	 * @return bool
	 */
	public function can_decode(): bool {
		return match ( $this ) {
			self::Jpeg => function_exists( 'imagecreatefromjpeg' ),
			self::Png  => function_exists( 'imagecreatefrompng' ),
			self::Gif  => function_exists( 'imagecreatefromgif' ),
			self::Webp => function_exists( 'imagecreatefromwebp' ),
			self::Avif => function_exists( 'imagecreatefromavif' ),
		};
	}

	/**
	 * Whether this build of GD can encode this format.
	 *
	 * @since 1.0.0
	 *
	 * @return bool
	 */
	public function can_encode(): bool {
		return match ( $this ) {
			self::Jpeg => function_exists( 'imagejpeg' ),
			self::Png  => function_exists( 'imagepng' ),
			self::Gif  => function_exists( 'imagegif' ),
			self::Webp => function_exists( 'imagewebp' ),
			self::Avif => function_exists( 'imageavif' ),
		};
	}
}
