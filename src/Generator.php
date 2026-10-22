<?php
/**
 * Variant generation.
 *
 * @package   SugarCommerce\ImageVariants
 * @copyright Copyright (c) 2026, ArrayPress Limited
 * @license   GPL-2.0-or-later
 * @since     1.0.0
 */

declare( strict_types=1 );

namespace SugarCommerce\ImageVariants;

/**
 * Class Generator
 *
 * Takes image bytes and returns resized copies.
 *
 * @since 1.0.0
 */
final readonly class Generator {

	/**
	 * A generator with its output format and limits.
	 *
	 * @since 1.0.0
	 *
	 * @param Format $format      Output format. WebP is the default: it
	 *                            is 25-35% smaller than JPEG at
	 *                            equivalent quality, supports
	 *                            transparency, and has had universal
	 *                            browser support for years.
	 * @param int    $quality     Encoder quality, 1-100.
	 * @param int    $max_pixels  Largest source accepted, in pixels. A
	 *                            decoded image costs about four bytes per
	 *                            pixel, so this is the real memory bound
	 *                            — and the defence against a decompression
	 *                            bomb, where a 12 KB PNG expands to
	 *                            gigabytes. Variants are held to it
	 *                            too, so an upscaled size cannot become
	 *                            the bomb instead.
	 * @param int    $max_bytes   Largest source accepted, in bytes.
	 * @param bool   $upscale     Whether to enlarge a source smaller than
	 *                            the target. Off, because enlarging adds
	 *                            no detail and costs bandwidth.
	 *
	 * @throws \InvalidArgumentException For a quality outside 1–100, or a
	 *                                   limit below one.
	 */
	public function __construct(
		public Format $format = Format::Webp,
		public int $quality = 82,
		public int $max_pixels = 50_000_000,
		public int $max_bytes = 33_554_432,
		public bool $upscale = false,
	) {
		if ( $quality < 1 || $quality > 100 ) {
			throw new \InvalidArgumentException( 'Quality must be between 1 and 100.' );
		}

		if ( $max_pixels < 1 || $max_bytes < 1 ) {
			throw new \InvalidArgumentException( 'The limits must be at least one.' );
		}
	}

	/**
	 * Generate variants from image bytes.
	 *
	 * @since 1.0.0
	 *
	 * @param string     $bytes Image data.
	 * @param list<Size> $sizes Requested sizes; the defaults when empty.
	 *
	 * @return array<string, Variant> Keyed by size name. A size larger
	 *                                than the source is absent unless
	 *                                upscaling is on, and one beyond the
	 *                                pixel limit is always absent.
	 *
	 * @throws \InvalidArgumentException When the image cannot be used.
	 */
	public function generate( string $bytes, array $sizes = array() ): array {
		$source   = $this->decode( $bytes );
		$variants = array();

		foreach ( array() === $sizes ? Size::defaults() : $sizes as $size ) {
			$variant = $this->resize( $source, $size );

			if ( null !== $variant ) {
				$variants[ $size->name ] = $variant;
			}
		}

		return $variants;
	}

	/**
	 * Decode and normalise image bytes.
	 *
	 * @since 1.0.0
	 *
	 * @param string $bytes Image data.
	 *
	 * @return Source
	 *
	 * @throws \InvalidArgumentException When unusable.
	 */
	public function decode( string $bytes ): Source {
		if ( '' === $bytes ) {
			throw new \InvalidArgumentException( 'The image is empty.' );
		}

		if ( strlen( $bytes ) > $this->max_bytes ) {
			throw new \InvalidArgumentException(
				'The image is larger than the ' . $this->max_bytes . ' byte limit.'
			);
		}

		$format = Format::detect( $bytes );

		if ( null === $format ) {
			// Includes SVG, which is not a raster format and can carry
			// script — never accepted here.
			throw new \InvalidArgumentException( 'Unrecognised or unsupported image format.' );
		}

		if ( ! $format->can_decode() ) {
			throw new \InvalidArgumentException( 'This build of GD cannot decode ' . $format->value . '.' );
		}

		// Read the dimensions from the header before decoding, so a
		// decompression bomb is refused rather than allocated.
		$info = @getimagesizefromstring( $bytes );

		if ( false === $info ) {
			throw new \InvalidArgumentException( 'The image header could not be read.' );
		}

		[ $width, $height ] = $info;

		if ( $width * $height > $this->max_pixels ) {
			throw new \InvalidArgumentException(
				'The image is ' . $width . '×' . $height . ' pixels, beyond the ' . $this->max_pixels . ' pixel limit.'
			);
		}

		$image = @imagecreatefromstring( $bytes );

		if ( false === $image ) {
			throw new \InvalidArgumentException( 'The image could not be decoded.' );
		}

		$image = $this->apply_orientation( $image, $bytes, $format );

		return new Source( $image, $format, imagesx( $image ), imagesy( $image ) );
	}

	/**
	 * Rotate an image to match its EXIF orientation.
	 *
	 * A phone does not rotate the sensor data; it records which way up
	 * the camera was and leaves the pixels alone. Browsers honour that
	 * tag when displaying the original — and GD does not, so a resized
	 * copy comes out sideways while the original looks fine. This is the
	 * single most common "why is my thumbnail rotated" bug.
	 *
	 * Re-encoding also drops the EXIF block, which is a privacy
	 * improvement in itself: photographs routinely carry GPS coordinates,
	 * and publishing a customer's uploaded image publishes where they
	 * took it.
	 *
	 * @since 1.0.0
	 *
	 * @param \GdImage $image  Decoded image.
	 * @param string   $bytes  Original bytes.
	 * @param Format   $format Source format.
	 *
	 * @return \GdImage
	 */
	private function apply_orientation( \GdImage $image, string $bytes, Format $format ): \GdImage {
		// Only JPEG carries the tag in practice, and only ext-exif reads it.
		if ( Format::Jpeg !== $format || ! function_exists( 'exif_read_data' ) ) {
			return $image;
		}

		$stream = fopen( 'php://memory', 'r+' );

		if ( false === $stream ) {
			return $image;
		}

		fwrite( $stream, $bytes );
		rewind( $stream );

		$exif = @exif_read_data( $stream );
		fclose( $stream );

		$orientation = is_array( $exif ) && is_int( $exif['Orientation'] ?? null ) ? $exif['Orientation'] : 1;

		$rotated = match ( $orientation ) {
			3       => imagerotate( $image, 180, 0 ),
			6       => imagerotate( $image, -90, 0 ),
			8       => imagerotate( $image, 90, 0 ),
			default => null,
		};

		if ( $rotated instanceof \GdImage ) {
			return $rotated;
		}

		return $image;
	}

	/**
	 * Produce one variant.
	 *
	 * @since 1.0.0
	 *
	 * @param Source $source Decoded source.
	 * @param Size   $size   Target size.
	 *
	 * @return Variant|null Null when the target is larger than the source
	 *                      and upscaling is off, or larger than the pixel
	 *                      limit at all.
	 */
	private function resize( Source $source, Size $size ): ?Variant {
		[ $width, $height, $crop ] = $this->target( $source, $size );

		if ( ! $this->upscale && ( $width > $source->width || $height > $source->height ) ) {
			return null;
		}

		// The canvas is allocated like any decoded image, so it answers to
		// the same limit — an upscaled size from a request is a bomb too.
		if ( $width < 1 || $height < 1 || $width * $height > $this->max_pixels ) {
			return null;
		}

		$canvas = imagecreatetruecolor( $width, $height );

		if ( false === $canvas ) {
			return null;
		}

		$this->preserve_transparency( $canvas );

		imagecopyresampled(
			$canvas,
			$source->image,
			0, 0,
			$crop['x'], $crop['y'],
			$width, $height,
			$crop['width'], $crop['height']
		);

		$encoded = $this->encode( $canvas );

		if ( null === $encoded ) {
			return null;
		}

		return new Variant( $size->name, $encoded, $width, $height, $this->format );
	}

	/**
	 * Work out the output box and the region of the source to sample.
	 *
	 * @since 1.0.0
	 *
	 * @param Source $source Source.
	 * @param Size   $size   Requested size.
	 *
	 * @return array{0: int, 1: int, 2: array{x: int, y: int, width: int, height: int}}
	 */
	private function target( Source $source, Size $size ): array {
		$whole = array( 'x' => 0, 'y' => 0, 'width' => $source->width, 'height' => $source->height );

		if ( Fit::Contain === $size->fit ) {
			$scale = null === $size->height
				? $size->width / $source->width
				: min( $size->width / $source->width, $size->height / $source->height );

			return array(
				max( 1, (int) round( $source->width * $scale ) ),
				max( 1, (int) round( $source->height * $scale ) ),
				$whole,
			);
		}

		// Cover: fill the box, then take the middle of whatever overflows.
		$height = (int) $size->height;
		$scale  = max( $size->width / $source->width, $height / $source->height );

		$sample_width  = min( $source->width, (int) round( $size->width / $scale ) );
		$sample_height = min( $source->height, (int) round( $height / $scale ) );

		return array(
			$size->width,
			$height,
			array(
				'x'      => max( 0, (int) round( ( $source->width - $sample_width ) / 2 ) ),
				'y'      => max( 0, (int) round( ( $source->height - $sample_height ) / 2 ) ),
				'width'  => $sample_width,
				'height' => $sample_height,
			),
		);
	}

	/**
	 * Keep transparency through the resample.
	 *
	 * Without this a true-colour canvas starts opaque black, and a
	 * transparent PNG resizes to a logo on a black rectangle.
	 *
	 * @since 1.0.0
	 *
	 * @param \GdImage $canvas Destination.
	 *
	 * @return void
	 */
	private function preserve_transparency( \GdImage $canvas ): void {
		if ( ! $this->format->supports_transparency() ) {
			// Flattening to JPEG: fill white rather than leaving black,
			// since a transparent area is nearly always meant to be paper.
			$white = imagecolorallocate( $canvas, 255, 255, 255 );

			if ( false !== $white ) {
				imagefilledrectangle( $canvas, 0, 0, imagesx( $canvas ) - 1, imagesy( $canvas ) - 1, $white );
			}

			return;
		}

		imagealphablending( $canvas, false );
		imagesavealpha( $canvas, true );

		$transparent = imagecolorallocatealpha( $canvas, 0, 0, 0, 127 );

		if ( false !== $transparent ) {
			imagefilledrectangle( $canvas, 0, 0, imagesx( $canvas ) - 1, imagesy( $canvas ) - 1, $transparent );
		}
	}

	/**
	 * Encode a canvas in the output format.
	 *
	 * @since 1.0.0
	 *
	 * @param \GdImage $canvas Image.
	 *
	 * @return string|null Null when encoding fails.
	 */
	private function encode( \GdImage $canvas ): ?string {
		if ( ! $this->format->can_encode() ) {
			return null;
		}

		ob_start();

		$ok = match ( $this->format ) {
			Format::Webp => imagewebp( $canvas, null, $this->quality ),
			Format::Avif => imageavif( $canvas, null, $this->quality ),
			Format::Jpeg => imagejpeg( $canvas, null, $this->quality ),
			// PNG takes a 0-9 compression level, not a quality.
			Format::Png  => imagepng( $canvas, null, (int) round( ( 100 - $this->quality ) / 11.2 ) ),
			Format::Gif  => imagegif( $canvas ),
		};

		$bytes = (string) ob_get_clean();

		return $ok && '' !== $bytes ? $bytes : null;
	}
}
