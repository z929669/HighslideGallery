# HighslideGallery (MediaWiki Extension)

HighslideGallery delivers Highslide JS-powered overlays for images and YouTube videos: thumbnails or inline links, slideshow grouping via `hsgid`, and MediaWiki-style captions inside an interactive overlay.

> **Status**: actively maintained  
> **Tested**:  
>> v2.0.0 → MediaWiki 1.39  
>> v1.3.0 → MediaWiki 1.39  
>> v1.1.0 → MediaWiki 1.22  
>> v1.0.0 → MediaWiki 1.17

---

## Requirements / Compatibility
- MediaWiki 1.39+ (ResourceLoader-only, modern hooks). Older MW versions are not supported in 2.0.0.

---

## Features (2.0.0)
- Thumbnails by default; inline links remain opt-in (`inline=1`).
- Group images and YouTube videos in the same HSG via `hsgid`.
- Robust list handling (ordered/unordered/description and mixed/multi-level lists).
- Caption spans/classes for gallery/title/caption; consistent `data-hsg-caption`/`alt` (`title | caption`).
- Optional `nocaption=1` to hide thumb captions; improved caption fallback (`Image` instead of raw path).
- Zoom toggle (fit/1:1) with pan; `f` key uses the same toggle; SVG controls with disabled-state dimming.
- ResourceLoader-exposed i18n strings for hover instructions, control tooltips, loading text, and member counter.

---

## Installation
If you customized `$wgExtensionAssetsPath`, update `hs.graphicsDir` in `modules/highslide.cfg.js` to match your path.

### Composer (recommended)
Add to `composer.local.json`:

```json
{
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
```

Then run:

```
composer update z929669/highslidegallery -W
```

This installs into `extensions/HighslideGallery/` from Packagist.

### Manual (archive)
1) Download a tagged release (e.g., `v2.0.0`) and extract to `extensions/HighslideGallery/`.  
2) Ensure `extension.json`, `modules/`, and PHP sources are present.

### Post-install
In `LocalSettings.php`:

```php
wfLoadExtension( 'HighslideGallery' );
```

Registered hooks:
- `BeforePageDisplay` (injects Highslide core + RL modules)
- `ImageBeforeProduceHTML` (thumbnail handling)
- `ParserFirstCallInit` (tag/parser functions)
- `ResourceLoaderGetConfigVars` (JS config)

---

## Usage

### Parser functions (modern)
- Image (`#hsgimg`):  
  `{{#hsgimg:source=File:Pic.jpg|hsgid=GalleryA|width=210|title=Title|caption=Caption|inline=0|nocaption=0|linktext=Inline link text}}`
- YouTube (`#hsgytb`):  
  `{{#hsgytb:source=VIDEO_ID|hsgid=GalleryA|width=210|title=Title|caption=Caption|inline=0|autoplay=0}}`

Parameters (named; positional `source` is also accepted):
- `source` (required): `File:Title` or external URL (image) / YouTube ID or URL (video).
- `hsgid`/`id` (optional): slideshow group id; lets images/videos coexist in one overlay set.
- `width` (optional): max thumb width in px (default 210; applies to thumbs only).
- `title` (optional): overlay title text.
- `caption` (optional): overlay caption text.
- `nocaption` (optional): truthy hides thumb caption (overlay caption still shown).
- `inline` (optional): truthy → inline link; falsy/omitted → thumbnail (default).
- `linktext` (optional): inline link text (inline only).
- `autoplay` (YouTube only): truthy enables autoplay (muted).

### Examples using the raw parser functions directly
- Inline image with custom link text:  
  `{{#hsgimg:source=File:Pic.jpg|hsgid=GalleryA|inline=1|linktext=See image|title=Sunset|caption=Lake view}}`
- Mixed gallery (images + video) with captions and default thumbs:  
  `{{#hsgimg:source=File:Pic1.jpg|hsgid=GalleryMixed|title=Title1|caption=Caption1}}`  
  `{{#hsgimg:source=File:Pic2.jpg|hsgid=GalleryMixed|title=Title2|caption=Caption2}}`  
  `{{#hsgytb:source=VIDEO_ID|hsgid=GalleryMixed|title=Clip|caption=Gameplay}}`

### Example template leveraging all features
```
<includeonly>
{{#if: {{{ytb|}}}
| <!-- YOUTUBE BRANCH -->
{{#hsgytb:
| source={{{ytb|}}}
| hsgid={{{hsgid|}}}
| width={{{width|}}}
| title={{{title|}}}
| caption={{{caption|}}}
| linktext={{{linktext|}}}
| inline={{{inline|}}}
| autoplay={{{autoplay|}}}
| nocaption={{{nocaption|}}}
}}
| <!-- IMAGE BRANCH (default when no ytb) -->
{{#hsgimg:
| source={{{img|}}}
| hsgid={{{hsgid|}}}
| width={{{width|}}}
| title={{{title|}}}
| caption={{{caption|}}}
| linktext={{{linktext|}}}
| inline={{{inline|}}}
| nocaption={{{nocaption|}}}
}} }}
</includeonly>
```

### Legacy tags (still parsed)
- YouTube tag: `<hsyoutube title="Trailer">https://www.youtube.com/watch?v=VIDEO_ID</hsyoutube>` (defaults to inline). Prefer `#hsgytb` for modern usage.

---

## Configuration
- `$wgHSGControlsPreset = 'stacked-top';` in `LocalSettings.php` for alternate control/thumbstrip positioning. Comment or set to `classic` for default positioning below media.
- `modules/highslide.cfg.js` for overlay behavior (margins, zoom, controls).
- `modules/highslide.override.css` for styling (SVG controls, thumbstrip, captions).

Defaults require no manual changes.

---

## Contributing
Merge requests and issues:  
https://gitlab.com/z929669/highslidegallery

---

## License
- **Extension code**: GPL-2.0-or-later  
- **Bundled library (`modules/highslide-full.js`/assets)**: MIT (license notice retained)

MIT code shipped within a GPL-2.0 project is compatible.
