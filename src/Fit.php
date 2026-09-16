<?php
/**
 * How a source is fitted to a target box.
 *
 * @package   SugarCommerce\ImageVariants
 * @copyright Copyright (c) 2026, ArrayPress Limited
 * @license   GPL-2.0-or-later
 * @since     1.0.0
 */

declare( strict_types=1 );

namespace SugarCommerce\ImageVariants;

/**
 * Enum Fit
 *
 * @since 1.0.0
 */
enum Fit {

	/**
	 * Scale until the whole image fits inside the box. Aspect ratio is
	 * kept and the result may be smaller than the box in one dimension.
	 *
	 * The default, because it never removes anything from the picture.
	 */
	case Contain;

	/**
	 * Scale until the box is filled, then crop the overflow from the
	 * centre. The result is exactly the requested size.
	 *
	 * For grid tiles that must line up. Be careful with photographs of
	 * people — a centre crop takes the top off heads in portrait shots.
	 */
	case Cover;
}
