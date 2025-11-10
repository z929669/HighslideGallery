# HighslideGallery (MediaWiki Extension)

HighslideGallery adds simple highslide-style image/galleries and YouTube popups to MediaWiki pages.

> **Status**: actively maintained  
> **Tested**: MediaWiki 1.39–1.41 (and later should work)  
> **License**: Extension code is CC BY-NC 3.0. The bundled Highslide JS library is CC BY-NC 2.5 (non-commercial). See COPYING.md.

---

## Features
- `[[File:...|...]]` thumbnails open in a highslide overlay.
- Grouped “lightbox” galleries with captions.
- Parser function `#hsimg` for external images.
- `<hsyoutube>` tag for embedded YouTube overlays.
- Loads core script early, rest via ResourceLoader.

---

## Installation

### A) Composer (recommended)

If you use MediaWiki’s `composer.local.json`, add:

    {
      "repositories": [
        { "type": "vcs", "url": "https://gitlab.com/z929669/highslidegallery" }
      ],
      "require": {
        "z929669/highslidegallery": "^1.3"
      },
      "extra": {
        "merge-plugin": {
          "include": [
            "extensions/*/composer.json"
          ]
        }
      }
    }

Then run:

    composer update z929669/highslidegallery -W

This installs into `extensions/HighslideGallery/`.

(If you prefer a fixed ZIP dist, you can point a `dist.url` at a tag archive such as `v1.3.0`.)

### B) Manual install

1) Download a tagged release (e.g., `v1.3.0`) and extract to:

    extensions/HighslideGallery/

2) Ensure the directory contains `extension.json`, `modules/`, and the PHP sources.

### Post-install

In `LocalSettings.php`:

    wfLoadExtension( 'HighslideGallery' );

The extension registers:
- `BeforePageDisplay` (injects `highslide.js` and RL modules)
- `ImageBeforeProduceHTML` (thumbnail handling)
- `ParserFirstCallInit` (tag/parser functions)

---

## Usage

### Images / galleries

Basic thumbnail (caption auto-extracted):

    [[File:MyImage.jpg|thumb|My caption here]]

Grouped gallery via “highslide=” marker in the caption:

    [[File:Pic1.jpg|180px|highslide=Gallery1:First picture]]
    [[File:Pic2.jpg|180px|highslide=Gallery1:Second picture]]

External image with width and caption:

    {{#hsimg:GALLERYMyGroup|200|My Caption|https://example.com/pic.jpg}}

### YouTube

    <hsyoutube title="Trailer">https://www.youtube.com/watch?v=VIDEO_ID</hsyoutube>

Autoplay:

    <hsyoutube title="Trailer" autoplay>https://www.youtube.com/watch?v=VIDEO_ID</hsyoutube>

---

## Configuration
None required for defaults. To tweak look/feel:
- `modules/highslide.cfg.js` (overlay behavior)
- `modules/highslide.override.css` (styling)
- Skin/global CSS for theme integration

---

## Contributing
Merge requests and issues:  
https://gitlab.com/z929669/highslidegallery

---

## License
- **Extension code**: CC BY-NC 3.0 — see COPYING.md.
- **Highslide JS** (`modules/highslide.js`): CC BY-NC 2.5 (non-commercial). See COPYING.md for attribution.
===== END README.md =====
