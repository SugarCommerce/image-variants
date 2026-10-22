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

namespace SugarCommerce\ImageVariants\Tests;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SugarCommerce\ImageVariants\Fit;
use SugarCommerce\ImageVariants\Format;
use SugarCommerce\ImageVariants\Generator;
use SugarCommerce\ImageVariants\Size;
use SugarCommerce\ImageVariants\Source;
use SugarCommerce\ImageVariants\Variant;

#[CoversClass( Generator::class )]
#[CoversClass( Format::class )]
#[CoversClass( Size::class )]
#[CoversClass( Variant::class )]
#[CoversClass( Source::class )]
final class VariantsTest extends TestCase {

	/**
	 * A real encoded image of the given dimensions.
	 */
	private function image( int $width, int $height, Format $format = Format::Jpeg, bool $transparent = false ): string {
		$canvas = imagecreatetruecolor( $width, $height );

		if ( $transparent ) {
			imagealphablending( $canvas, false );
			imagesavealpha( $canvas, true );
			imagefilledrectangle( $canvas, 0, 0, $width - 1, $height - 1, imagecolorallocatealpha( $canvas, 0, 0, 0, 127 ) );
		} else {
			imagefilledrectangle( $canvas, 0, 0, $width - 1, $height - 1, imagecolorallocate( $canvas, 30, 120, 200 ) );
			imagefilledellipse( $canvas, (int) ( $width / 2 ), (int) ( $height / 2 ), (int) ( $width / 2 ), (int) ( $height / 2 ), imagecolorallocate( $canvas, 250, 200, 40 ) );
		}

		ob_start();

		match ( $format ) {
			Format::Jpeg => imagejpeg( $canvas, null, 90 ),
			Format::Png  => imagepng( $canvas ),
			Format::Gif  => imagegif( $canvas ),
			Format::Webp => imagewebp( $canvas ),
			Format::Avif => imageavif( $canvas ),
		};

		return (string) ob_get_clean();
	}

	/* ─── Format detection ──────────────────────────────────────────── */

	#[DataProvider( 'detectable_formats' )]
	public function test_formats_are_detected_from_content( Format $format ): void {
		if ( ! $format->can_encode() ) {
			$this->markTestSkipped( 'This build cannot encode ' . $format->value . '.' );
		}

		$this->assertSame( $format, Format::detect( $this->image( 60, 40, $format ) ) );
	}

	/**
	 * @return array<string, array{0: Format}>
	 */
	public static function detectable_formats(): array {
		return array(
			'jpeg' => array( Format::Jpeg ),
			'png'  => array( Format::Png ),
			'gif'  => array( Format::Gif ),
			'webp' => array( Format::Webp ),
			'avif' => array( Format::Avif ),
		);
	}

	/**
	 * The declared type is chosen by whoever uploaded the file.
	 */
	public function test_detection_ignores_what_the_caller_claims(): void {
		$png = $this->image( 40, 40, Format::Png );

		$this->assertSame( Format::Png, Format::detect( $png ), 'Detection reads the bytes, not a filename or header.' );
	}

	#[DataProvider( 'undetectable_content' )]
	public function test_non_images_are_not_detected( string $bytes ): void {
		$this->assertNull( Format::detect( $bytes ) );
	}

	/**
	 * @return array<string, array{0: string}>
	 */
	public static function undetectable_content(): array {
		return array(
			'empty'      => array( '' ),
			'short'      => array( 'abc' ),
			'text'       => array( 'this is definitely not an image at all' ),
			// Not a raster format, and it can carry <script>.
			'svg'        => array( '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>' ),
			'html'       => array( '<!doctype html><html><body>hello</body></html>' ),
			'php'        => array( '<?php echo "hi"; ?>' . str_repeat( ' ', 20 ) ),
		);
	}

	public function test_format_metadata(): void {
		$this->assertSame( 'image/jpeg', Format::Jpeg->mime() );
		$this->assertSame( 'jpg', Format::Jpeg->extension() );
		$this->assertSame( 'webp', Format::Webp->extension() );
		$this->assertFalse( Format::Jpeg->supports_transparency() );
		$this->assertTrue( Format::Png->supports_transparency() );
		$this->assertTrue( Format::Webp->supports_transparency() );
	}

	/* ─── Generation ────────────────────────────────────────────────── */

	public function test_the_default_sizes_are_produced(): void {
		$variants = ( new Generator() )->generate( $this->image( 1600, 900 ) );

		$this->assertSame( array( 'thumb', 'card', 'full' ), array_keys( $variants ) );
		$this->assertSame( 300, $variants['thumb']->width );
		$this->assertSame( 600, $variants['card']->width );
		$this->assertSame( 1200, $variants['full']->width );
	}

	public function test_the_aspect_ratio_is_preserved(): void {
		$variants = ( new Generator() )->generate( $this->image( 1600, 900 ) );

		foreach ( $variants as $variant ) {
			$this->assertEqualsWithDelta( 16 / 9, $variant->width / $variant->height, 0.02 );
		}
	}

	/**
	 * Enlarging adds no detail and costs bandwidth.
	 */
	public function test_a_small_source_is_never_upscaled(): void {
		$variants = ( new Generator() )->generate( $this->image( 200, 200 ) );

		$this->assertSame( array(), $variants );
	}

	public function test_only_the_sizes_that_fit_are_produced(): void {
		$variants = ( new Generator() )->generate( $this->image( 700, 700 ) );

		$this->assertSame( array( 'thumb', 'card' ), array_keys( $variants ) );
	}

	public function test_upscaling_can_be_enabled(): void {
		$variants = ( new Generator( upscale: true ) )->generate( $this->image( 200, 200 ), array( new Size( 'big', 800 ) ) );

		$this->assertSame( 800, $variants['big']->width );
	}

	public function test_a_contain_fit_stays_inside_the_box(): void {
		$variants = ( new Generator() )->generate(
			$this->image( 1600, 900 ),
			array( new Size( 'box', 400, 400 ) )
		);

		$this->assertLessThanOrEqual( 400, $variants['box']->width );
		$this->assertLessThanOrEqual( 400, $variants['box']->height );
		$this->assertSame( 400, $variants['box']->width, 'The limiting dimension should be met exactly.' );
	}

	public function test_a_cover_fit_fills_the_box_exactly(): void {
		$variants = ( new Generator() )->generate(
			$this->image( 1600, 900 ),
			array( new Size( 'square', 400, 400, Fit::Cover ) )
		);

		$this->assertSame( 400, $variants['square']->width );
		$this->assertSame( 400, $variants['square']->height );
	}

	public function test_a_cover_size_requires_a_height(): void {
		$this->expectException( InvalidArgumentException::class );

		new Size( 'bad', 400, null, Fit::Cover );
	}

	public function test_sizes_reject_non_positive_dimensions(): void {
		$this->expectException( InvalidArgumentException::class );

		new Size( 'bad', 0 );
	}

	public function test_srcset_widths_are_a_convenience(): void {
		$sizes = Size::widths( array( 400, 800 ) );

		$this->assertCount( 2, $sizes );
		$this->assertSame( '400', $sizes[0]->name );
		$this->assertSame( 800, $sizes[1]->width );
	}

	/* ─── Output formats ────────────────────────────────────────────── */

	#[DataProvider( 'detectable_formats' )]
	public function test_output_is_encoded_in_the_requested_format( Format $format ): void {
		if ( ! $format->can_encode() ) {
			$this->markTestSkipped( 'This build cannot encode ' . $format->value . '.' );
		}

		$variants = ( new Generator( format: $format ) )->generate(
			$this->image( 800, 600 ),
			array( new Size( 'x', 200 ) )
		);

		$this->assertSame( $format, $variants['x']->format );
		$this->assertSame( $format, Format::detect( $variants['x']->bytes ), 'The bytes should really be that format.' );
	}

	/**
	 * The reason WebP is the default.
	 */
	public function test_webp_is_smaller_than_jpeg_at_the_same_size(): void {
		$source = $this->image( 1200, 800 );
		$size   = array( new Size( 'x', 600 ) );

		$webp = ( new Generator( format: Format::Webp ) )->generate( $source, $size )['x'];
		$jpeg = ( new Generator( format: Format::Jpeg ) )->generate( $source, $size )['x'];

		$this->assertLessThan( $jpeg->size(), $webp->size() );
	}

	public function test_quality_changes_the_output_size(): void {
		$source = $this->image( 1200, 800 );
		$size   = array( new Size( 'x', 600 ) );

		$low  = ( new Generator( quality: 20 ) )->generate( $source, $size )['x'];
		$high = ( new Generator( quality: 95 ) )->generate( $source, $size )['x'];

		$this->assertLessThan( $high->size(), $low->size() );
	}

	/**
	 * Without an alpha-aware canvas a transparent PNG resizes onto black.
	 */
	public function test_transparency_survives_into_a_format_that_supports_it(): void {
		$variants = ( new Generator( format: Format::Png ) )->generate(
			$this->image( 400, 400, Format::Png, transparent: true ),
			array( new Size( 'x', 100 ) )
		);

		$decoded = imagecreatefromstring( $variants['x']->bytes );
		$colour  = imagecolorat( $decoded, 50, 50 );
		$alpha   = ( $colour >> 24 ) & 0x7F;

		$this->assertGreaterThan( 100, $alpha, 'The centre pixel should still be transparent.' );
	}

	/**
	 * Flattening to JPEG should give paper, not a black box behind a logo.
	 */
	public function test_transparency_flattens_to_white_not_black(): void {
		$variants = ( new Generator( format: Format::Jpeg ) )->generate(
			$this->image( 400, 400, Format::Png, transparent: true ),
			array( new Size( 'x', 100 ) )
		);

		$decoded = imagecreatefromstring( $variants['x']->bytes );
		$colour  = imagecolorsforindex( $decoded, imagecolorat( $decoded, 50, 50 ) );

		$this->assertGreaterThan( 200, $colour['red'] );
		$this->assertGreaterThan( 200, $colour['green'] );
		$this->assertGreaterThan( 200, $colour['blue'] );
	}

	/* ─── Limits ────────────────────────────────────────────────────── */

	#[DataProvider( 'unusable_input' )]
	public function test_unusable_input_is_refused( string $bytes ): void {
		$this->expectException( InvalidArgumentException::class );

		( new Generator() )->generate( $bytes );
	}

	/**
	 * @return array<string, array{0: string}>
	 */
	public static function unusable_input(): array {
		return array(
			'empty'    => array( '' ),
			'text'     => array( 'not an image' ),
			'svg'      => array( '<svg xmlns="http://www.w3.org/2000/svg" width="10" height="10"></svg>' ),
			'truncated' => array( "\xFF\xD8\xFF" ),
		);
	}

	public function test_an_oversized_file_is_refused(): void {
		$this->expectException( InvalidArgumentException::class );
		$this->expectExceptionMessage( 'byte limit' );

		( new Generator( max_bytes: 100 ) )->generate( $this->image( 400, 400 ) );
	}

	/**
	 * A decoded image costs about four bytes per pixel, so the pixel
	 * count is the real memory bound — and the defence against a small
	 * file that expands to gigabytes.
	 */
	public function test_a_decompression_bomb_is_refused_before_decoding(): void {
		$this->expectException( InvalidArgumentException::class );
		$this->expectExceptionMessage( 'pixel limit' );

		( new Generator( max_pixels: 1000 ) )->generate( $this->image( 400, 400 ) );
	}

	public function test_the_pixel_limit_is_checked_from_the_header(): void {
		// 400x400 = 160,000 pixels but only a few KB on disk: the byte
		// limit would not have caught it.
		$bytes = $this->image( 400, 400, Format::Png );

		$this->assertLessThan( 160000, strlen( $bytes ) );
		$this->expectException( InvalidArgumentException::class );

		( new Generator( max_pixels: 1000 ) )->generate( $bytes );
	}

	/* ─── Variant ───────────────────────────────────────────────────── */

	/**
	 * An upscaled size allocates its canvas like a decoded image does, so a
	 * width taken from a request must not be a way round the pixel limit.
	 */
	public function test_an_upscaled_variant_is_held_to_the_pixel_limit(): void {
		$variants = ( new Generator( max_pixels: 100_000, upscale: true ) )->generate(
			$this->image( 100, 100 ),
			array( new Size( 'ok', 300 ), new Size( 'bomb', 50_000 ), new Size( 'tile', 20_000, 20_000, Fit::Cover ) )
		);

		$this->assertSame( array( 'ok' ), array_keys( $variants ) );
	}

	/**
	 * @return array<string, array{0: int, 1: int, 2: int}>
	 */
	public static function bad_settings(): array {
		return array(
			'quality zero'    => array( 0, 1, 1 ),
			'quality over'    => array( 101, 1, 1 ),
			'no pixels'       => array( 82, 0, 1 ),
			'negative bytes'  => array( 82, 1, -1 ),
		);
	}

	#[DataProvider( 'bad_settings' )]
	public function test_settings_out_of_range_are_refused( int $quality, int $pixels, int $bytes ): void {
		$this->expectException( \InvalidArgumentException::class );

		new Generator( quality: $quality, max_pixels: $pixels, max_bytes: $bytes );
	}

	public function test_variant_metadata(): void {
		$variant = ( new Generator() )->generate( $this->image( 1600, 900 ) )['card'];

		$this->assertSame( 'card', $variant->name );
		$this->assertSame( strlen( $variant->bytes ), $variant->size() );
		$this->assertSame( 'photo-600.webp', $variant->filename( 'photo' ) );
		$this->assertStringStartsWith( 'data:image/webp;base64,', $variant->data_uri() );
	}

	public function test_source_geometry(): void {
		$source = ( new Generator() )->decode( $this->image( 1600, 900 ) );

		$this->assertSame( 1600, $source->width );
		$this->assertSame( 900, $source->height );
		$this->assertTrue( $source->is_landscape() );
		$this->assertSame( 1440000, $source->pixels() );
		$this->assertEqualsWithDelta( 16 / 9, $source->ratio(), 0.001 );
	}
}
