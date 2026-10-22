<?php
/**
 * A decoded source image.
 *
 * @package   SugarCommerce\ImageVariants
 * @copyright Copyright (c) 2026, ArrayPress Limited
 * @license   GPL-2.0-or-later
 * @since     1.0.0
 */

declare( strict_types=1 );

namespace SugarCommerce\ImageVariants;

/**
 * Class Source
 *
 * Wraps a decoded image and the facts about it.
 *
 * There is no `release()`. Since PHP 8.0 a decoded image is a `GdImage`
 * object freed by refcounting when the last reference goes, and
 * `imagedestroy()` has been a no-op ever since — deprecated outright in
 * 8.5. Dropping the reference is the whole of the cleanup.
 *
 * @since 1.0.0
 */
final class Source {

	/**
	 * A decoded image and what is known about it.
	 *
	 * @since 1.0.0
	 *
	 * @param \GdImage $image  Decoded image.
	 * @param Format   $format Detected source format.
	 * @param int      $width  Width after orientation is applied.
	 * @param int      $height Height after orientation is applied.
	 */
	public function __construct(
		public readonly \GdImage $image,
		public readonly Format $format,
		public readonly int $width,
		public readonly int $height,
	) {}

	/**
	 * The aspect ratio.
	 *
	 * @since 1.0.0
	 *
	 * @return float
	 */
	public function ratio(): float {
		return 0 === $this->height ? 1.0 : $this->width / $this->height;
	}

	/**
	 * Whether the image is wider than it is tall.
	 *
	 * @since 1.0.0
	 *
	 * @return bool
	 */
	public function is_landscape(): bool {
		return $this->width > $this->height;
	}

	/**
	 * Total pixels.
	 *
	 * @since 1.0.0
	 *
	 * @return int
	 */
	public function pixels(): int {
		return $this->width * $this->height;
	}

}
