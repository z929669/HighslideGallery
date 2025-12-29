# AI Coding Instructions for HighslideGallery

HighslideGallery is a **MediaWiki 1.39+ extension** that provides interactive Highslide JS-powered galleries for images (internal wiki files and external URLs) and YouTube videos, displayed as thumbnails or inline links with titles, captions, and slideshow grouping.

## Architecture & Data Flow

### Server-Side (PHP) → Client-Side (JS) Pipeline

**[HighslideGallery.body.php](../HighslideGallery.body.php)** (single-class entry point, ~1141 lines)

- **MediaWiki hooks**: `BeforePageDisplay` (inject resources), `ImageBeforeProduceHTML` (thumbnail interception), `ParserFirstCallInit` (register parser functions/tags)
- **Parser functions**: `{{#hsgimg:…}}` (external images), `{{#hsgytb:…}}` (YouTube)
- **Parser tags**: `<hsgytb>…</hsgytb>` (canonical YouTube), `<hsyoutube>…</hsyoutube>` (legacy)
- **Core logic**:
  - Parse and validate arguments via `parseParserFunctionArgs()` (splits `key=value` params and extracts primary value)
  - Build `<a>` + `<img>` HTML with Highslide metadata (gallery ID, caption, data attributes)
  - Inject `onclick="return hs.expand(this, {…})"` for JS interop
  - Apply grouping: explicit `hsgid` creates multi-image slideshows; singletons get unique IDs

**[highslide.cfg.js](../modules/highslide.cfg.js)** (~1173 lines)

- Configure Highslide library behavior (margins, dimming, transitions, controls)
- **Key config**:
  - `hs.closeOnClick = false` (images don't close overlay)
  - `hs.numberPosition = 'caption'` (gallery counter "1 of N" in caption)
  - `hs.transitions = ['fade', 'crossfade']` + instant timing (`transitionDuration = 0`)
  - `hs.wrapperClassName = 'hsg-frame floating-caption'` (CSS-driven styling)
  - Cancel default click-to-close: `hs.Expander.prototype.onImageClick = () => false`
- **Highslide-specific behavior**: slideshow controls, thumbstrip, YouTube iframe handling
- **Vendor core files**: `highslide-full.js` (upstream library) and `highslide.css` (vendor styles); all customization goes into `highslide.cfg.js` unless new core functionality is needed.

**[highslide.css + highslide.override.css](../modules/)**

- `highslide.css`: vendor Highslide styles (untouched)
- `highslide.override.css`: HSG theming (captions, controls, thumbnails, list integration)

## Critical Nomenclature & Conventions

**Always use these prefixes** to signal extension ownership:

- `hsg-*` / `data-hsg-*`: HighslideGallery constructs (thumbnails, frames, captions)
- `ytb-*` / `data-ytb-*`: YouTube-specific

**Parser function & tag names**

- Canonical: `{{#hsgimg:…}}`, `{{#hsgytb:…}}`, `<hsgytb>…</hsgytb>`
- Legacy aliases: `{{#hsimg:…}}`, `<hsyoutube>…</hsyoutube>` (supported for backward compat; don't add new features here)

**CSS classes**

- `.hsg-thumb`: any HSG thumbnail anchor
- `.hsg-gallery-member`: images part of multi-image slideshow
- `.hsg-ytb-thumb` / `.hsg-ytb-thumb-img`: YouTube thumbnails
- `.noviewer`: blocks MediaViewer hijacking
- `.hsg-frame`: applied to Highslide overlay wrapper

**Data attributes**

- `data-hsg-caption`: plain-text overlay caption
- `data-hsg-html`: optional HTML fragment for overlay (paired with caption)
- `data-hsgid`: gallery grouping ID (explicit or auto-generated)

## Common Patterns & Code Organization

### Argument Parsing

```php
// Parser function: {{#hsgimg:source=File.jpg|width=210|hsgid=GalleryA|caption=My caption}}
[ $source, $attrs ] = self::parseParserFunctionArgs( $params, 'source' );
// → $source = "File.jpg", $attrs = [ 'width' => '210', 'hsgid' => 'GalleryA', 'caption' => '…' ]
```

### Truthy Values

Use `self::isTruthy()` for flag attributes (`inline`, `autoplay`, `nocaption`, `tile`):

```php
if ( self::isTruthy( $attrs['inline'] ?? false ) ) { /* inline link mode */ }
```

### HTML Escaping

Always use `self::escAttr()` for HTML attribute values:

```php
$html .= ' alt="' . self::escAttr( $caption ) . '"';
```

**Two-tier escaping strategy**:

- `escAttr()` for HTML attributes (ENT_QUOTES)
- `FormatJson::encode()` for inline `onclick` JSON options (prevents quote conflicts)

### Gallery Grouping & State Management

- **Static state**: `$hsgId` (current), `$lastHsgGroupId` (previous), `$isHsgGroupMember` (flag), `$hsgLabel` (display label)
- Explicit `hsgid=MyGallery` → reuse ID across multiple items → slideshow with nav controls
- Omitted `hsgid` → unique ID via `generateId(8)` → standalone image
- Multi-image detection: if `$lastHsgGroupId === $hsgId`, set `$isHsgGroupMember = true` to add `.hsg-gallery-member` class
- **Reset on new page**: `AddResources()` clears all static state

### Caption Building

Helper functions handle caption composition (title | caption | gallery label):

```php
[ $overlayPlain, $overlayHtml, $noIdPlain, $noIdHtml ] =
    self::assembleCaptionBundle( $hsgId, $titleText, $bodyText, $includeHsgIdInOverlay, $linkTitle, $fallbackText );
// overlayHtml includes styled spans (.hsg-caption-gallery, .hsg-caption-title, .hsg-caption-caption)
// noIdPlain/Html used for alt/data attributes (no hsgid contamination)
```

### Inline vs Thumbnail Mode

Both images and YouTube distinguish via `inline` parameter:

- `inline=1/yes/true` → text link opener (no thumbnail wrapper, hidden proxy image for Highslide)
- Otherwise → thumb with `.thumb.hsg-thumb-normalized` wrapper
- Default varies: **tags default inline**, **parser functions default thumbnail**

### List Integration

When HSG markup appears inside ordered/unordered/description lists (JS-handled in `highslide.cfg.js`):

- Apply `.hsg-thumb-list-item` / `.hsg-inline-list-item` to the `<li>` / `<dt>` for styling
- Drop `mw-empty-elt` class (prevents list rendering issues)
- Merge adjacent lists of same type via `hsgMergeAdjacentLists()` to preserve numbering/bullets
- Support up to 3 levels of mixed ol/ul/dl nesting via `hsgRelocateThumbsIntoLists()`
- Relocate orphan wraps back into nearby list items via `hsgRelocateWrapsIntoLists()`
- Post-parse relocate orphan inline HSGs via `hsgRelocateInlineIntoLists()`

### YouTube Integration

**URL parsing** (in `renderYouTubeHtml()`):

```php
// Three accepted formats → extract video code
if ( preg_match( '/[?&]v=([^?&]+)/', $raw, $m ) )        // https://www.youtube.com/watch?v=CODE
if ( preg_match( '#youtu\.be/([^?]+)#', $raw, $m ) )     // https://youtu.be/CODE
if ( preg_match( '#/embed/([^?]+)#', $raw, $m ) )        // https://www.youtube.com/embed/CODE
// Fallback: bare code if 11 chars alphanumeric + dash/underscore
if ( preg_match( '/^[A-Za-z0-9_-]{11}$/', $raw ) ) { $code = $raw; }
```

**Iframe sizing** (in `highslide.cfg.js` `hsgOpenYouTube()`):

- Calculate 16:9 aspect ratio fit within viewport minus HSG margins
- `maxW = Math.min(320, pageWidth - marginLeft - marginRight - 20)`
- `maxH = Math.min(180, pageHeight - marginTop - marginBottom - 20)`
- `fitW = Math.min(maxW, Math.floor(maxH * 16/9))`; `fitH = Math.min(maxH, Math.floor(fitW / 16*9))`
- Autoplay always mutes via `playlist={code}&loop=1&mute=1&autoplay=1` query params

### Backward Compatibility

**Parameter aliases** (still supported, not promoted in docs):

- `id=` → maps to canonical `hsgid=` (internal alias only)
- `highslide=` (legacy v1.x) → now **removed** in v2.0+; migration complete
- `{{#hsimg:…}}` → deprecated alias for `{{#hsgimg:…}}`; tag `<hsyoutube>` → alias for `<hsgytb>`

## Project-Specific Dev Workflows

**No formal test suite or build tool** (as of v2.1.0). Instead:

- **Manual testing**: Install extension in local MediaWiki 1.39+, use parser functions/tags in articles
- **Cache clearing**: `php maintenance/rebuildLocalisationCache.php` (clears i18n cache if adding messages)
- **Browser cache**: Ctrl+Shift+R (MediaWiki ResourceLoader auto-versions via `extension.json` `"version"` field)

**Versioning & Releases**:

- Semantic versioning in `extension.json` `"version"` field (e.g., `"2.1.0"`)
- Update [CHANGELOG.md](../CHANGELOG.md) with Added/Changed/Fixed sections (Keep a Changelog format)
- ResourceLoader version: `"version": "20251223.1"` in `extension.json` (date + serial for cache-busting)

## Key Files & Line Ranges

| File                                                        | Purpose                           | Key Sections                                                                                                |
| ----------------------------------------------------------- | --------------------------------- | ----------------------------------------------------------------------------------------------------------- |
| [HighslideGallery.body.php](../HighslideGallery.body.php)   | Parser hooks, output generation   | `AddHooks()` (line 69), `parseParserFunctionArgs()` (line 133), `onFunctionHsgImg()` / `onFunctionHsgYtb()` |
| [highslide.cfg.js](../modules/highslide.cfg.js)             | Highslide config, client behavior | Core config (line ~30–60), overlays/controls (line ~240+), YouTube iframe sizing                            |
| [highslide.override.css](../modules/highslide.override.css) | Theming, layout                   | Thumbnails, captions, controls, list item markers                                                           |
| [extension.json](../extension.json)                         | MediaWiki registration            | Hooks, ResourceModules, i18n messages, config vars                                                          |
| [i18n/en.json](../i18n/en.json)                             | English i18n strings              | Hover instructions, control tooltips, loading text                                                          |

## Testing & Validation Checklist

Before committing changes:

1. **Parser function syntax**: Does `{{#hsgimg:…}}` parse without PHP errors?
2. **Thumbnail rendering**: Do images display with correct captions and aspect ratios?
3. **Gallery grouping**: Do multiple items with same `hsgid` form a slideshow with nav controls?
4. **List integration**: If HSG markup is inside `<ul>` / `<ol>`, does list structure stay clean?
5. **YouTube handling**: Do `{{#hsgytb:…}}` embeds open in overlay and autoplay mute work?
6. **MediaViewer blocking**: Do thumbnails have `.noviewer` class (check in browser DevTools)?
7. **i18n strings**: Are new UI messages defined in both `en.json` (string) and `qqq.json` (description)?

**i18n coverage requirements**:

- **Must translate** (core UI): All strings in `extension.json` `"messages"` array (hover tooltips, control labels, loading text)
- **Optional** (template-specific): Strings in templates outside this extension (translators handle those separately)
- **Process**: Add to `i18n/en.json` with key, then add description to `i18n/qqq.json` for translators
- Fetch at runtime via `mw.message( 'hsg-key' ).text()` in JavaScript; PHP uses `wfMessage()` (not used in HSG currently)

## Behavior You'll Encounter

**Known limitations & quirks**:

- Highslide core (`highslide-full.js`) does not support modern touch gestures; pinch-to-zoom is not part of the UX (v5.5.0 limitation)
- Adjacent `<ul>` tags inside HSG list contexts are auto-merged to preserve list semantics
- `data-hsgid` is set on overlay anchors but NOT on `<img>` tags (avoids breaking core MW image markup)
- YouTube autoplay always mutes (browser autoplay policy) even if not explicitly requested

## Key Implementation Patterns to Follow

**When adding new HSG features**:

1. **Use DOMDocument for robust HTML injection** – See `AddHighslide()` for example; avoid fragile regex-based string manipulation
2. **Keep static state clean** – Reset `$hsgId`, `$lastHsgGroupId`, `$isHsgGroupMember` in `AddResources()` at page load
3. **Prefer helpers over inline logic** – `parseInlineMode()`, `extractGroupId()`, `buildDataAttributes()`, `makeCaptionSpan()` reduce duplication
4. **Escape consistently** – Use `escAttr()` for attributes, `FormatJson::encode()` for JSON in `onclick`, `htmlspecialchars()` for plain HTML content
5. **Test caption fallbacks** – When `title` and `caption` are both missing, output should gracefully use `"Image"` or file name, not an empty string

**When modifying CSS**:

1. Work in `highslide.override.css` **only** – never edit vendor `highslide.css`
2. Prefix all new classes with `.hsg-` to avoid conflicts with MediaWiki core or Highslide vendor styles
3. Test responsive layouts with touch devices (iOS/Android) since mobile support is now first-class

**When changing JavaScript**:

1. Avoid modifying Highslide core (`highslide-full.js`) – extend via `hs.Expander.prototype` patches if needed
2. All HSG helper functions should start with `hsg` prefix (e.g., `hsgNormalizeListGalleries()`, `hsgOpenYouTube()`)
3. Use MediaWiki hook API (`mw.hook('wikipage.content')`) for DOM mutations, not `DOMContentLoaded` alone

## Agent Permissions & Git Workflow

**What agents MAY do:**

- ✅ Read Git status and diffs
- ✅ Analyze code and suggest changes
- ✅ Create commits locally for preview/testing (read-only preview only; **no push**)
- ✅ Run CLI tools for linting, formatting, and testing

**What agents MUST NOT do:**

- ❌ Push to origin or any remote without explicit user approval
- ❌ Commit to the repository without user interaction (recommendations only)
- ❌ Delete branches or tags
- ❌ Modify `.meta/` directory (reserved for agent context)

**Gate Rule:** All commits must be reviewed and approved by the human user via Git UI (GitKraken, VS Code Git, or CLI) before pushing.

## CLI Tools for Automation

Agents have access to the following CLI commands for linting, formatting, and code quality checks:

**JavaScript/CSS Linting:**

```bash
npm run lint:check           # Check ESLint + Stylelint (exit code 0 if clean)
npm run lint:eslint:fix      # Auto-fix ESLint violations
npm run lint:stylelint:fix   # Auto-fix Stylelint violations
npm run format               # Format JSON, Markdown (Prettier)
```

**PHP Linting (via Composer):**

```bash
./vendor/bin/phpcs HighslideGallery.body.php --standard=MediaWiki      # Check for violations
./vendor/bin/phpcbf HighslideGallery.body.php --standard=MediaWiki     # Auto-fix violations
```

**Result Interpretation:**

- Exit code `0` = no violations
- Exit code `1` = violations found (check output)
- Agents should report results to user and ask for approval before committing fixes

## VS Code Extensions for Agent Leverage

The following VS Code extensions enhance agent efficiency through real-time feedback and context:

### **Linting & Formatting**

- **ESLint** (Microsoft) — Real-time JavaScript issue detection; use `npm run lint:eslint:fix` for batch fixes
- **Stylelint** (Stylelint) — Real-time CSS validation; use `npm run lint:stylelint:fix` for batch fixes
- **EditorConfig** (EditorConfig) — Enforces `.editorconfig` rules across languages (indentation, line endings)
- **Prettier** (Prettier) — JSON/Markdown formatting on save; use `npm run format` to batch-format

**Agent Hint:** Check linting output before suggesting changes; violations appear in VS Code's Problems panel for quick reference.

### **Git Context & History**

- **GitLens** (GitKraken) — Inline blame, commit details, and code lens; hover over code to see authorship and history
- **Git History** (Don Jayamanne) — Dedicated timeline view for exploring repository evolution

**Agent Hint:** Use GitLens inline context to understand code intent and previous decisions. Reference commit messages when explaining code behavior.

### **DOM Inspection & Debugging**

- **Microsoft Edge Tools** — Inspect rendered HTML/CSS directly in VS Code without DevTools copy-paste friction
- **Debugger for Firefox** — Browser debugging (optional; Edge Tools recommended for HSG)

**Agent Hint:** When analyzing gallery rendering, list item markup, or CSS layout issues, use Edge Tools to inspect live DOM and share exact element selectors/styles with the user.

## Questions for Clarification

If you encounter ambiguity, ask:

1. **Backward compat**: Should we preserve a deprecated parameter, or is it safe to remove?
2. **CSS scope**: Should styling be confined to `highslide.override.css`, or can we modify core `highslide.css`?
3. **i18n coverage**: Which languages should get translated messages beyond English?
4. **Git operations**: Is this change significant enough to warrant a commit?
5. **DOM inspection**: Should I use Edge Tools to show visual evidence of layout/rendering issues?
