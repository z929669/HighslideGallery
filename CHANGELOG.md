# Changelog
All notable changes are documented here.
The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [UNRELEASED-2.0.0]
### 2025-11-17 Fixed
- Added 'noviewer' class to prevent MediaViewer from hijacking galleries (HSGs)
- Exposed previously-obscured HSG index label
- Exposed previously-obscured HSG controls
- Improved x-browser compatibility

### 2025-11-17 Added
- New parser functions for YouTube (YT) i-frame and thumbnail (text-only YT still supported)
- Bundles Highslide JS core v5.5.0 (`highslide-full.js` with CSS and graphics assets)
- New label synonym, 'hsgid' (i.e., HighSlideGallery ID) for wiki template maintainers - 'highslide' label still supported

### 2025-11-17 Changed
- Improved YT embed functionality with new parser function and autoplay muting
- Gallery grouping is reliable and more intuitive, allowing YT and images to coexist inside a single HSG
- Modernized codebase - min required MediaWiki is now v1.39
- Modernized extension registration (Mediawiki 1.39+)
- Migrated resource loading to ResourceLoader (Mediawiki 1.39+)
- Removed deprecated PHP files (Mediawiki 1.39+)
- Reascribed GPL-2.0 license for extension, due to unportability of GPL to CC license

## [1.3.0-repack] - 2025-11-11
### Fixed (packaging only)
- Remove JSON comment from `extension.json` so Composer doesn’t break.

### 2025-11-09 Added
- Composer/Packagist support and repository metadata (`composer.json`)
- Modernize registration (`extension.json`) and tighten hooks
- Clean repo (remove stray `*.patch` files)

### 2025-11-09 Changed
- Documentation: README updated with Composer/Packagist and manual install instructions
- Updated COPYING to COPYING.md; merged INSTALL + README into README.md

## [1.3.0] - 2025-10-13
### Fixed
- `extension.json`: use `"Hooks"` (plural) and include `BeforePageDisplay`, `ImageBeforeProduceHTML`, `ParserFirstCallInit`
- General formatting/cleanup in `HighslideGallery.php`

### Added
- Load strategy: inject `highslide.js` via `BeforePageDisplay` to guarantee global `hs`
- YouTube embeds: `mute=1` for reliable autoplay; loop via `playlist={code}` + `loop=1`

### Changed
- ResourceLoader now mediates only config JS and styles
- Parser hooks modernized

## [UNRELEASED-1.1.1] - 2023-12-11
### Changed
- Bundles Highslide JS (`highslide.js` and `highslide.css`) to ensure compatibility and "just works"

## [1.1.0] - 2020-10-28
### Added
- YouTube mute fix and other minor improvements

## [1.0.0] - 2012-10-22
- Initial release