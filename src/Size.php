<?php
/**
 * A named output size.
 *
 * @package   SugarMill\ImageVariants
 * @copyright Copyright (c) 2026, ArrayPress Limited
 * @license   GPL-2.0-or-later
 * @since     1.0.0
 */

declare( strict_types=1 );

namespace SugarMill\ImageVariants;

/**
 * Class Size
 *
 * @since 1.0.0
 */
final readonly class Size {

	/**
	 * @since 1.0.0
	 *
	 * @param string   $name   Key the variant is returned under.
	 * @param int      $width  Target width in pixels.
	 * @param int|null $height Target height; derived from the aspect
	 *                         ratio when null.
	 * @param Fit      $fit    How to fit the source into the box.
	 *
	 * @throws \InvalidArgumentException On a non-positive dimension.
	 */
	public function __construct(
		public string $name,
		public int $width,
		public ?int $height = null,
		public Fit $fit = Fit::Contain,
	) {
		if ( $width < 1 || ( null !== $height && $height < 1 ) ) {
			throw new \InvalidArgumentException( 'A size must have positive dimensions: ' . $name );
		}

		if ( Fit::Cover === $fit && null === $height ) {
			throw new \InvalidArgumentException( 'A cover fit needs an explicit height: ' . $name );
		}
	}

	/**
	 * The conventional set: thumbnail, card and full.
	 *
	 * @since 1.0.0
	 *
	 * @return self[]
	 */
	public static function defaults(): array {
		return array(
			new self( 'thumb', 300 ),
			new self( 'card', 600 ),
			new self( 'full', 1200 ),
		);
	}

	/**
	 * Widths for a responsive `srcset`.
	 *
	 * @since 1.0.0
	 *
	 * @param int[] $widths Pixel widths.
	 *
	 * @return self[]
	 */
	public static function widths( array $widths ): array {
		return array_map( static fn( int $w ): self => new self( (string) $w, $w ), $widths );
	}
}
