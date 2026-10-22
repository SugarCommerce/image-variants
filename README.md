# Image Variants

Resized image variants for responsive delivery. Returns bytes rather than writing files, corrects EXIF orientation, strips metadata, never upscales, and refuses decompression bombs. Zero dependencies.

## Why

Serving a 4 MB phone photograph as a 300px thumbnail is the most common performance problem on any site that accepts uploads, and the fix is mechanical. The parts that are *not* mechanical, and that hand-rolled versions almost always miss:

- **Thumbnails come out sideways.** A phone does not rotate the sensor data — it records which way up the camera was and leaves the pixels alone. Browsers honour that tag on the original; GD does not. So the original looks right and every thumbnail is rotated.
- **Transparent PNGs get a black box.** A true-colour canvas starts opaque black, so a resized logo lands on a black rectangle.
- **A 12 KB PNG can allocate gigabytes.** Dimensions live in the header; a decompression bomb declares 30,000 × 30,000 and costs about 3.6 GB the moment you decode it.
- **Uploads carry GPS coordinates.** Publishing a customer's photograph publishes where they took it.

## Features

- 📦 **Returns bytes, writes nothing** — store to disk, S3, R2 or a database; the library has no opinion.
- 🔄 **EXIF orientation corrected** — thumbnails match the original.
- 🛡️ **Decompression bombs refused** — dimensions checked from the header, before allocating.
- 🕵️ **Format sniffed from content** — never from a filename or a `Content-Type` the uploader chose.
- 🚫 **SVG rejected** — not a raster format, and it can carry `<script>`.
- 🖼️ **Transparency preserved**, or flattened to white rather than black.
- 📐 **Contain and cover** fits, and never upscales unless asked.
- 🗜️ **WebP, AVIF, JPEG, PNG, GIF** output, with graceful capability probing.
- 🌐 **Edge resizing URLs** — Cloudflare's `/cdn-cgi/image/`, for images you do not own and should not copy.

## Requirements

PHP 8.5+, `ext-gd` (`ext-exif` recommended, for orientation)

## Installation

```bash
composer require sugarcommerce/image-variants
```

## Usage

```php
use SugarCommerce\ImageVariants\Generator;

$variants = ( new Generator() )->generate( $bytes );

foreach ( $variants as $name => $variant ) {
    $storage->put( $variant->filename( 'photo' ), $variant->bytes );
    // photo-300.webp   300×169
    // photo-600.webp   600×338
    // photo-1200.webp  1200×675
}
```

`generate()` throws `InvalidArgumentException` for anything it will not process — not an image, too large, too many pixels. Catch it and tell the uploader.

### Sizes

```php
use SugarCommerce\ImageVariants\{Fit, Size};

$generator->generate( $bytes, [
    new Size( 'thumb',  300 ),                       // width only, height follows
    new Size( 'boxed',  400, 400 ),                  // fits inside 400×400
    new Size( 'square', 400, 400, Fit::Cover ),      // fills 400×400, centre-cropped
] );

Size::defaults();                 // thumb 300, card 600, full 1200
Size::widths( [400, 800, 1200] ); // for a srcset
```

`Contain` never removes anything from the picture, so it is the default. `Cover` gives you exactly the requested box, which grid tiles need — but be careful with photographs of people, because a centre crop takes the top off heads in portrait shots.

**Sizes larger than the source are skipped**, so a 700px upload returns `thumb` and `card` but no `full`. Check what you got rather than assuming all three:

```php
$srcset = implode( ', ', array_map(
    fn( $v ) => $storage->url( $v->filename( $base ) ) . ' ' . $v->width . 'w',
    $variants
) );
```

Enlarging adds no detail and costs bandwidth, but you can ask:

```php
new Generator( upscale: true );
```

### Output format

```php
use SugarCommerce\ImageVariants\Format;

new Generator( format: Format::Avif, quality: 70 );

Format::Avif->can_encode();   // probe before committing
```

WebP is the default: 25–35% smaller than JPEG at equivalent quality, supports transparency, and universally supported for years. AVIF is smaller again — roughly 20% below WebP in the suite here — but encoding is markedly slower and older builds of GD lack it, so probe first.

### Limits

```php
new Generator(
    max_bytes:  8 * 1024 * 1024,   // reject the file outright
    max_pixels: 25_000_000,        // reject before decoding
);
```

The pixel limit is the one that matters. A decoded image costs about four bytes per pixel, so 25 megapixels is roughly 100 MB of memory — and the byte limit will not catch a bomb, because the whole point of one is being small on disk. Dimensions are read from the header first, so an oversized image is refused rather than allocated. Each variant is held to the same pixel limit, so an upscaled size — say, a width taken from a request — is skipped rather than allocated. Quality must be 1–100 and both limits at least one, or the constructor throws.

## Resizing at the edge

Some images should not be copied at all. One a seller uploaded to Stripe is served back unchanged at up to 2 MB, and a resized copy stored here would drift from the original the day they replace it. A CDN can resize it from the original on the way past instead:

```php
use SugarCommerce\ImageVariants\{EdgeUrl, Fit, Format};

EdgeUrl::cloudflare( 'shop.example.com', $stripe_image, 640 );
// https://shop.example.com/cdn-cgi/image/width=640,quality=82,fit=cover,format=auto/https://files.stripe.com/…

EdgeUrl::cloudflare( 'shop.example.com', $url, 300, 70, Fit::Contain, Format::Avif );
```

It needs Image Resizing switched on for the zone. Anything it cannot improve comes back unchanged — an empty URL, no host configured, a source that is not `https://`, or that carries whitespace, a backslash or a `..` segment a browser would resolve against the zone's own paths, a host that is not a bare host name — so a template never has to ask whether resizing is on. PNG and GIF are refused, because the resizer cannot write them; leave the format `null` and the edge picks per browser.

## Storing the results

```php
$stored = [];

foreach ( ( new Generator() )->generate( $bytes ) as $name => $variant ) {
    $key = "media/{$id}/" . $variant->filename( $slug );

    $r2->put( $key, $variant->bytes, $variant->format->mime() );

    $stored[ $name ] = [ 'key' => $key, 'width' => $variant->width, 'height' => $variant->height ];
}

$db->update( 'media', [ 'variants' => json_encode( $stored ) ], [ 'id' => $id ] );
```

Storing the dimensions alongside the key is worth the column: it lets you emit `width`/`height` attributes and avoid layout shift without touching storage at render time.

## Security

See [SECURITY.md](SECURITY.md). Specific to images:

**The declared type is worthless.** A `Content-Type` header or a `$_FILES` entry is chosen by whoever uploaded the file. Formats here are identified from magic bytes.

**SVG is not supported and will not be.** It cannot be resampled, and it can contain `<script>` — serving an uploaded SVG inline from your own origin is stored cross-site scripting. If you must accept them, serve them from a separate origin with `Content-Disposition: attachment`, and do not put them through an image pipeline.

**Re-encoding strips metadata**, which removes the EXIF block along with any GPS coordinates in it. That is a privacy improvement you get for free, but note it applies to the *variants* — if you also serve the original, it still carries everything.

**Refuse before you allocate.** The header check exists because by the time `imagecreatefromstring()` has returned, the memory is already gone.

## Testing

```bash
composer install
composer test
```

69 tests, mostly against real encoded images rather than fixtures — format detection across all five formats, aspect-ratio preservation, contain versus cover, the no-upscale rule, transparency surviving into PNG and flattening to white in JPEG, and both limits including a small-file-many-pixels bomb and an upscaled one, settings out of range, and edge URLs that pass through everything they cannot safely rewrite.

## License

GPL-2.0-or-later
