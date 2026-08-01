<?php
/**
 * One generated image.
 *
 * @package   SugarMill\ImageVariants
 * @copyright Copyright (c) 2026, ArrayPress Limited
 * @license   GPL-2.0-or-later
 * @since     1.0.0
 */

declare( strict_types=1 );

namespace SugarMill\ImageVariants;

/**
 * Class Variant
 *
 * Bytes, not a file.
 *
 * The library deliberately does not write anything. Where an image ends
 * up — local disk, S3, R2, a database — is the application's decision,
 * and a library that picked one would have to be worked around by
 * everybody who picked differently.
 *
 * @since 1.0.0
 */
final readonly class Variant {

	/**
	 * @since 1.0.0
	 *
	 * @param string $name   The size name this was generated for.
	 * @param string $bytes  Encoded image data.
	 * @param int    $width  Actual width.
	 * @param int    $height Actual height.
	 * @param Format $format Encoded format.
	 */
	public function __construct(
		public string $name,
		public string $bytes,
		public int $width,
		public int $height,
		public Format $format,
	) {}

	/**
	 * Size of the encoded image.
	 *
	 * @since 1.0.0
	 *
	 * @return int Bytes.
	 */
	public function size(): int {
		return strlen( $this->bytes );
	}

	/**
	 * A filename for this variant.
	 *
	 * @since 1.0.0
	 *
	 * @param string $base Basename without extension.
	 *
	 * @return string e.g. `photo-600.webp`.
	 */
	public function filename( string $base ): string {
		return $base . '-' . $this->width . '.' . $this->format->extension();
	}

	/**
	 * A `data:` URI, for inlining something very small.
	 *
	 * Only worth it for a placeholder — base64 inflates by a third, and
	 * an inlined image cannot be cached separately from the page.
	 *
	 * @since 1.0.0
	 *
	 * @return string
	 */
	public function data_uri(): string {
		return 'data:' . $this->format->mime() . ';base64,' . base64_encode( $this->bytes );
	}
}
