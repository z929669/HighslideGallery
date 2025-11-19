# HighslideGallery (MediaWiki Extension)

HighslideGallery adds simple highslide-style image/galleries and YouTube popups to MediaWiki pages.

> **Status**: actively maintained  
> **Tested**:
>> v2.0.0 &rarr; Mediawiki 1.39  
>> v1.3.0 &rarr; Mediawiki 1.39  
>> v1.1.0 &rarr; Mediawiki 1.22  
>> v1.0.0 &rarr; Mediawiki 1.17

---

## Features
- `[[File:...|...]]` thumbnails open in a highslide overlay.
- Grouped “lightbox” galleries with captions.
- Parser function `#hsimg` for external images.
- `<hsyoutube>` tag for embedded YouTube overlays.
- Loads core script early, rest via ResourceLoader.

---

## Installation
Note: If you customized `$wgExtensionAssetsPath`, update `hs.graphicsDir` in `modules/highslide.cfg.js` accordingly.

### A) Composer (recommended)
Using MediaWiki’s `composer.local.json`, add:

    {
      "repositories": [
        { "type": "vcs", "url": "https://gitlab.com/z929669/highslidegallery" }
      ],
      "require": {
        "z929669/highslidegallery": "^2.0"
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

(For a fixed ZIP dist, point a `dist.url` at a tag archive such as `v1.3.0`.)

### B) Manual install
1) Download a tagged release (e.g., `v2.0.0`) and extract to:

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
- **Extension code**: GPL-2.0-or-later  
- **Bundled library (`modules/highslide.js`)**: MIT (license notice kept in-file)

Both licenses are compatible; shipping MIT code within a GPL-2.0 project is allowed.

