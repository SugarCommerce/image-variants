<?php
/**
 * Variants resized at the edge.
 *
 * @package   SugarCommerce\ImageVariants
 * @copyright Copyright (c) 2026, ArrayPress Limited
 * @license   GPL-2.0-or-later
 * @since     1.1.0
 */

declare( strict_types=1 );

namespace SugarCommerce\ImageVariants\Tests;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SugarCommerce\ImageVariants\{EdgeUrl, Fit, Format};

#[CoversClass( EdgeUrl::class )]
final class EdgeUrlTest extends TestCase {

	private const string IMAGE = 'https://files.stripe.com/links/abc123';

	/* ─── Cloudflare ───────────────────────────────────────────────── */

	public function test_a_source_becomes_a_resizing_url(): void {
		$this->assertSame(
			'https://shop.example.com/cdn-cgi/image/width=640,quality=82,fit=cover,format=auto/' . self::IMAGE,
			EdgeUrl::cloudflare( 'shop.example.com', self::IMAGE, 640 )
		);
	}

	public function test_every_option_can_be_chosen(): void {
		$this->assertSame(
			'https://cdn.example.com/cdn-cgi/image/width=300,quality=70,fit=contain,format=avif/' . self::IMAGE,
			EdgeUrl::cloudflare( 'cdn.example.com', self::IMAGE, 300, 70, Fit::Contain, Format::Avif )
		);
	}

	public function test_webp_and_jpeg_are_named_as_the_resizer_names_them(): void {
		$this->assertStringContainsString( 'format=webp/', EdgeUrl::cloudflare( 'a.test', self::IMAGE, 10, format: Format::Webp ) );
		$this->assertStringContainsString( 'format=jpeg/', EdgeUrl::cloudflare( 'a.test', self::IMAGE, 10, format: Format::Jpeg ) );
	}

	#[DataProvider( 'unwritable' )]
	public function test_a_format_the_resizer_cannot_write_is_refused( Format $format ): void {
		$this->expectException( \InvalidArgumentException::class );

		EdgeUrl::cloudflare( 'a.test', self::IMAGE, 10, format: $format );
	}

	public static function unwritable(): array {
		return array( 'png' => array( Format::Png ), 'gif' => array( Format::Gif ) );
	}

	public function test_width_and_quality_stay_in_range(): void {
		$url = EdgeUrl::cloudflare( 'a.test', self::IMAGE, 0, 500 );

		$this->assertStringContainsString( 'width=1,quality=100,', $url );
		$this->assertStringContainsString( 'quality=1,', EdgeUrl::cloudflare( 'a.test', self::IMAGE, 10, -5 ) );
	}

	public function test_dots_inside_a_name_are_not_segments(): void {
		$this->assertStringEndsWith( '/https://x.test/a..b/..png', EdgeUrl::cloudflare( 'a.test', 'https://x.test/a..b/..png', 10 ) );
	}

	public function test_the_host_is_lower_cased_and_may_carry_a_port(): void {
		$this->assertStringStartsWith( 'https://shop.example.com:8443/cdn-cgi/', EdgeUrl::cloudflare( 'Shop.Example.com:8443', self::IMAGE, 10 ) );
	}

	/* ─── Passing through ──────────────────────────────────────────── */

	#[DataProvider( 'untouched' )]
	public function test_what_cannot_be_improved_passes_through( string $host, string $url ): void {
		$this->assertSame( $url, EdgeUrl::cloudflare( $host, $url, 640 ) );
	}

	public static function untouched(): array {
		return array(
			'no image'          => array( 'shop.example.com', '' ),
			'no resizer'        => array( '', self::IMAGE ),
			'plain http'        => array( 'shop.example.com', 'http://files.example.com/a.png' ),
			'data URI'          => array( 'shop.example.com', 'data:image/png;base64,AAAA' ),
			'relative'          => array( 'shop.example.com', '/images/a.png' ),
			'host with scheme'  => array( 'https://shop.example.com', self::IMAGE ),
			'host with path'    => array( 'shop.example.com/evil', self::IMAGE ),
			'host with space'   => array( 'shop example.com', self::IMAGE ),
			'host with userinfo' => array( 'user@shop.example.com', self::IMAGE ),
			// 1.2.0: a source a browser would rewrite into the zone's own paths.
			'dot segments'      => array( 'shop.example.com', 'https://x.test/../../../../account/delete' ),
			'encoded dot segments' => array( 'shop.example.com', 'https://x.test/%2e%2E/%2e./.%2e/account' ),
			'a single dot'      => array( 'shop.example.com', 'https://x.test/./a.png' ),
			'a backslash'       => array( 'shop.example.com', 'https://x.test\\..\\..\\account' ),
			'whitespace'        => array( 'shop.example.com', "https://x.test/a.png\n" ),
			'no host'           => array( 'shop.example.com', 'https:///a.png' ),
		);
	}
}
