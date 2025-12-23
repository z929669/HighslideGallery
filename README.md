# HighslideGallery (MediaWiki Extension)

HighslideGallery (HSG) delivers Highslide JS-powered overlays/slideshows for images and YouTube videos displayed as thumbnails or inline links on the page with titles and captions. Clicking a HSG thumbnail or link opens the image or video in an interactive overlay auto-sized to the viewport with ability to expand to full size with panning. See [this example](https://stepmodifications.org/wikidev/Template:Hsg).

> **Status**: actively maintained  
> **Tested**:  
>> v2.1.0 → MediaWiki 1.39  
>> v2.0.0 → MediaWiki 1.39  
>> v1.3.0 → MediaWiki 1.39  
>> v1.1.0 → MediaWiki 1.22  
>> v1.0.0 → MediaWiki 1.17

 
## What HSG Does (User Perspective)

- **Unified gallery system** for three content types: internal wiki files (`File:` thumbnails), external images (arbitrary URLs), and YouTube videos
- **Click-to-expand overlays** with dimmed background, centered frame, and instant open/close (no slow fade transitions by default)
- **Galleries** can mix all three content types into one slideshow group (same `hsgid=` parameter)
- **Navigation & close** via explicit controls, Esc key, or clicking the dimmed margin; **image clicks do not close** the overlay (prevents accidental exits)
- **Responsive layout**: overlays fit the viewport with proper top/bottom margins; images can be zoomed full-size with panning
- **Gallery index** ("1 of #") displayed inside the overlay so viewers know their position in the slideshow
- **No duplicate captions** under thumbnails—HSG enforces clean markup by smart caption handling

 
## Requirements / Compatibility
- MediaWiki 1.39+ (ResourceLoader-only, modern hooks). Older MW versions are not supported in 2.1.0.

 
## Features (2.0.0)
- Thumbnails displayed by default at 210px with ability to size as needed, but inline links are optional using `inline=1`
- Zoom toggle (full/fit) with pan inside the HSG
- Group images and YouTube videos into a single HSG gallery via `hsgid`.
- Support for opotional thumbnail layouts including justified tiling and clean arrangement in ordered/unordered lists
- Robust data model provides unique styling classes, maximizing compatibility with Mediawiki core media-handling and other media extensions.
- Thumb captions are default but optional with graceful caption fallbacks also used for image `alt` text.
- ResourceLoader-exposed i18n strings for hover instructions, control tooltips, loading text, and member counter
- Custom `LocalSettings.php` parameter allows positioning controls at bottom of viewport (default) or over the image expander (optional) inside the HSG overlay.
- Mobile (touch/tap) support with responsive layouts

 
## Installation
If `$wgExtensionAssetsPath` is custom, update `hs.graphicsDir` in `modules/highslide.cfg.js` to match this path.

### Composer (recommended)
Add to `composer.local.json`:

```json
{
  "require": {
    "mediawikiext/highslidegallery": "^2.0"
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
composer update mediawikiext/highslidegallery -W
```

This installs into `extensions/HighslideGallery/` from Packagist.

### Manual (archive)
1) Download a tagged release (e.g., `v2.0.0`) and extract to `extensions/HighslideGallery/`.  
2) Ensure `extension.json`, `modules/`, and PHP sources are present.

### Registered hooks ###
- `BeforePageDisplay` (injects Highslide core + RL modules)
- `ImageBeforeProduceHTML` (thumbnail handling)
- `ParserFirstCallInit` (tag/parser functions)
- `ResourceLoaderGetConfigVars` (JS config)

### Post-install
In `LocalSettings.php`:

```php
wfLoadExtension( 'HighslideGallery' );
//$wgHSGControlsPreset = 'stacked-top'; # optional overlay config
```

#### If pages still have old CSS/JS after installing or updating ####
- Purge individual pages via ?action=purge
- Clear MediaWiki cache: php maintenance/rebuildLocalisationCache.php
- Clear browser cache (Ctrl+Shift+R or Cmd+Shift+R)


## Usage

### Parser functions (modern)
- Image (`#hsgimg`):  
  `{{#hsgimg:source=File:Pic.jpg|hsgid=GalleryA|width=210|title=Title|caption=Caption|inline=0|nocaption=0|linktext=Inline link text}}`
- YouTube (`#hsgytb`):  
  `{{#hsgytb:source=VIDEO_ID|hsgid=GalleryA|width=210|title=Title|caption=Caption|inline=0|autoplay=0}}`

Parameters (named; positional `source` is also accepted):
- `source` (required): `File:Title` or external URL (image) / YouTube ID or URL (video).
- `hsgid` (optional): slideshow gallery ID; alphanumeric with optional dashes, underscores, and forward slashes (e.g., `GalleryA`, `Gallery-1`, `Section_1/Subsection`). IDs must not contain spaces or other special characters. Multiple items with the same `hsgid` form a slideshow group.
- `width` (optional): max thumb width in px (default 210).
- `tile` (optional): truthy → enforce horizontal tiling (adds `hsg-tiles-horizontal` to the auto wrapper).
- `title` (optional): overlay title text.
- `caption` (optional): overlay caption text.
- `nocaption` (optional): truthy → hides thumb caption (overlay caption still shown).
- `inline` (optional): truthy → inline link; falsy/omitted → thumbnail (default).
- `linktext` (optional): inline link text (inline only).
- `autoplay` (YouTube only): truthy → enables autoplay (muted).

### Examples using the raw parser functions directly
- Inline image with custom link text:  
  `{{#hsgimg:source=File:Pic.jpg|hsgid=Some_gallery|inline=1|linktext=this image|title=Some title|caption=Some caption}}`
- Mixed gallery (images + video) with captions and default thumbs:  
  `{{#hsgimg:source=File:Pic1.jpg|hsgid=GalleryMixed|title=Title1|caption=Caption1}}`  
  `{{#hsgimg:source=File:Pic2.jpg|hsgid=GalleryMixed|title=Title2|caption=Caption2}}`  
  `{{#hsgytb:source=VIDEO_ID|hsgid=GalleryMixed|title=Clip|caption=Some video}}`

### Example template leveraging all features
```
<includeonly>
{{#if: {{{ytb|}}}
| <!-- YOUTUBE BRANCH -->
{{#hsgytb:
| source={{{ytb|}}}
| hsgid={{{hsgid|}}}
| tile={{{tile|0}}}
| width={{{width|}}}
| title={{{title|}}}
| caption={{{caption|}}}
| nocaption={{{nocaption|0}}}
| inline={{{inline|0}}}
| linktext={{{linktext|}}}
| autoplay={{{autoplay|}}}
}}
| <!-- IMAGE BRANCH (default when no ytb) -->
{{#hsgimg:
| source={{{img|}}}
| hsgid={{{hsgid|}}}
| tile={{{tile|0}}}
| width={{{width|}}}
| title={{{title|}}}
| caption={{{caption|}}}
| nocaption={{nocaption|0}}}
| inline={{{inline|0}}}
| linktext={{{linktext|}}}
}} }}
</includeonly>
```

### Understanding Content Types & Galleries

HSG supports three types of content, all of which can coexist in a single slideshow gallery:

#### Internal Wiki Files (`[[File:…]]` or `[[Image:…]]`)
Use the `{{#hsgimg:…}}` parser function to integrate internal wiki files into HSG galleries. While MediaWiki's native `[[File:Example.jpg|thumb|…]]` syntax still works, it renders standard MediaWiki thumbnails without HSG functionality. To use HSG with internal files, pass the file title to `{{#hsgimg:source=File:Example.jpg|hsgid=GalleryA|…}}` instead. Internal files, external images, and YouTube videos can exist in the same slideshow by using the same `hsgid=`.

#### External Images (`{{#hsgimg:…}}`)
Use the parser function for images not stored on the wiki (arbitrary external URLs). These can be grouped with the same `hsgid=` as internal files or YouTube videos to create mixed-content galleries.

#### YouTube Videos (`{{#hsgytb:…}}` or `<hsgytb>…</hsgytb>`)
Embed videos either as inline text links or thumbnail previews (using the YouTube preview image from `img.youtube.com`). The `inline=` parameter controls this. Videos can be grouped into the same `hsgid=` as images.

#### Gallery Grouping
When multiple media items share the same `hsgid=`, they form a single slideshow group. The viewer opens one item and can navigate to others in that group using the slideshow controls. This works across content types. I.e., a gallery works with internal images, external images, and YouTube videos all grouped under `hsgid=MyGallery`.

---

### Tiling layouts (opt-in)
- Default thumbs are resizable using custom `width=#` (px). To tile responsively (i.e., 'justified' thumbs), set `tile=1` on all thumbs that declare a common `hsgid`; the auto wrapper gets `hsg-tiles-horizontal`. Control sizing with CSS variables, `--hsg-tile-min`  and `--hsg-tile-gap`, in `highslide.override.css`.
- Inline `width=` values on thumbs are otherwise overridden inside the tiling wrappers.

### Legacy tags (still supported)
- YouTube tag: `<hsyoutube title="Trailer">https://www.youtube.com/watch?v=VIDEO_ID</hsyoutube>` (defaults to inline). Prefer `#hsgytb` for modern usage.
- **Note**: The extension no longer accepts `[[File:…|hsgid=…]]` syntax to invoke HSG; use `{{#hsgimg:source=File:…|hsgid=…}}` instead. Legacy `hsgid=/highslide=` prefixes in captions are parsed for backward compatibility but do not activate HSG on native file links.

 
## What You Can Rely On (Guarantees)

- **Image clicks do not close overlays** — Viewers must use the close button, press Esc, or click the dimmed margin to close. This prevents accidental exits when interacting with the image.
- **Clean HTML** — HSG prevents malformed captions and ensures consistent markup. External and internal images both get proper `alt` text and title attributes.
- **Responsive overlays** — Overlays auto-fit to the viewport with sensible margins (configurable at the top pf `highslide.cfg.js), and images can be zoomed to full size with panning.
- **Gallery index** — When viewing a multi-image gallery, the overlay displays "1 of N" so viewers know their position.

 
## Known Limitations & Future Enhancements

- **Zoom UI controls** — The zoom helper exists and works programmatically, but manual UI buttons (zoom in/zoom out) are not wired into the overlay, nor are there plans to do so unless the need arises. Possible workaround: use keyboard or browser zoom.
- **Caption HTML detection** — HSG uses a simple heuristic to detect HTML in captions. If captions have unusual formats, they may not render as expected. For complex HTML, consider using the template approach.

 
## Configuration
- `$wgHSGControlsPreset = 'stacked-top';` in `LocalSettings.php` for alternate control/thumbstrip positioning. Comment or set to `classic` for default positioning below media.
- `modules/highslide.cfg.js` for overlay behavior (margins, zoom, controls).
- `modules/highslide.override.css` for styling (SVG controls, thumbstrip, captions).

Defaults require no manual changes.

 
## Compatibility with Other MediaWiki Features

- **MediaViewer** — HSG thumbnails are automatically marked with the `noviewer` class to prevent MediaViewer from interfering with HSG overlays. HSG completely replaces MediaViewer for images created via `{{#hsgimg:…}}`.
- **Standard MediaWiki `[[File:…]]` syntax** — Native MediaWiki file links continue to work as usual and are not affected by HSG. Use `{{#hsgimg:…}}` to enable HSG popups for internal files.
- **Other galleries** (`<gallery>` tag, etc.) — HSG does not interfere with other gallery implementations; the extension targets only media created via its parser functions.

 
## Customization via JS and CSS Variables

HSG exposes several convenience variables and CSS custom properties (CSS variables) that you can adjust in your custom styles or LocalSettings without modifying extension code. This prevents conflicts and makes updates safe.

### JavaScript Configuration (`modules/highslide.cfg.js`)

The following `hs.*` properties are exposed and can be overridden in your custom JS:

**Overlay behavior:**
- `hs.align` (default: `'center'`) — Horizontal alignment of the overlay
- `hs.marginTop`, `hs.marginBottom`, `hs.marginLeft`, `hs.marginRight` (defaults: 40, 40, 30, 30 px) — Viewport margins around overlay
- `hs.dimmingOpacity` (default: 0.75) — Opacity of the background dimmer (0–1)
- `hs.closeOnClick` (default: false) — Whether clicking the image closes the overlay (HSG forces false to prevent accidental closes)
- `hs.allowSizeReduction` (default: true) — Allow shrinking overlays to fit viewport
- `hs.showCredits` (default: false) — Show Highslide credits in overlay
- `hs.outlineType` (default: null) — Outline style; HSG uses CSS-driven styling

**Transitions (can be toggled for different effects):**
- `hs.fadeInOut` (default: false) — Enable fade transitions
- `hs.expandDuration`, `hs.restoreDuration` (default: 0 ms) — Animation timing
- `hs.transitions` (default: []) — Array of transition effects; try `['expand', 'crossfade']` for classic behavior

**Gallery display:**
- `hs.numberPosition` (default: `'caption'`) — Where "1 of N" appears (`'caption'`, `'top-left'`, `'bottom-left'`, etc.)
- `hs.wrapperClassName` (default: `'hsg-frame floating-caption'`) — CSS classes applied to the overlay wrapper

**To override these**, add JavaScript to your LocalSettings.php or custom JS file *after* HighslideGallery is loaded:
```javascript
// Example: Increase margins, enable fade transitions
if ( typeof hs !== 'undefined' ) {
    hs.marginTop = 60;
    hs.marginBottom = 60;
    hs.fadeInOut = true;
    hs.expandDuration = 200;
}
```

### CSS Custom Properties (`modules/highslide.override.css`)

The following CSS variables are defined in `:root` and can be overridden in your custom CSS stylesheet:

**Colors:**
- `--hsg-caption-number` (default: #777) — Gallery index text ("1 of 5") color
- `--hsg-caption-gallery` (default: #b0b0b0) — Gallery title color
- `--hsg-caption-title` (default: #b0b0b0) — Overlay title color
- `--hsg-caption-caption` (default: #ff9012) — Overlay caption text color
- `--hsg-caption-text` (default: #777) — General overlay text color
- `--hsg-caption-thumb-title`, `--hsg-caption-thumb-caption`, `--hsg-caption-thumb-text` — Thumbnail caption colors

**Frame and layout:**
- `--hsg-dimmer` (default: rgba(0, 0, 0, 0.75)) — Background dimmer color and opacity
- `--hsg-frame-bg` (default: #000) — Overlay frame background color
- `--hsg-frame-border` (default: #888) — Frame border color
- `--hsg-frame-shadow` (default: rgba(0, 0, 0, 0.9)) — Frame shadow color
- `--hsg-ytb-bg` (default: #000) — YouTube embed background

**Responsive tiling:**
- `--hsg-tile-min` (default: 210px) — Minimum thumbnail width in responsive tiling layouts (when `tile=1`)
- `--hsg-tile-gap` (default: 12px) — Gap between tiled thumbnails

**Thumbnails and controls:**
- `--hsg-ts-border` (default: #ff9012) — Thumbstrip (slideshow navigation) border color
- `--hsg-ts-border-hover` (default: #aaa) — Thumbstrip border color on hover

**To override these**, add CSS to your custom stylesheet:
```css
:root {
    --hsg-dimmer: rgba(0, 0, 0, 0.5);  /* Lighter dimmer */
    --hsg-caption-caption: #ffcc00;     /* Yellow captions */
    --hsg-frame-bg: #1a1a1a;            /* Dark gray frame */
    --hsg-tile-min: 150px;              /* Smaller tiles */
}
```

### What Variables Should NOT Be Modified

- `hs.graphicsDir` — Set this in `highslide.cfg.js` if `$wgExtensionAssetsPath` is custom (see Installation section)
- `hs.wrapperClassName` — HSG requires `'hsg-frame'` for proper styling; modify CSS instead of this property
- `hs.closeOnClick` — HSG forces this to `false` for UX; modifying it will break expected behavior
- Any vendor properties from `modules/highslide-full.js` not listed above — these are internal to Highslide and changes may cause unexpected behavior

 
## Contributing
Merge requests and issues:  
https://gitlab.com/z929669/highslidegallery

 
## License
- **Extension code**: GPL-2.0  
- **Bundled library (`modules/highslide-full.js`/assets)**: MIT (license notice retained; MIT code shipped within a GPL-2.0 project is compatible).
