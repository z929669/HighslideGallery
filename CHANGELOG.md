# Changelog
All notable changes to this project will be documented in this file.
The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/)
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]
		
## [1.3.0-repack] - 2025-11-11
### Fixed (packaging only)
- Remove JSON comment from `extension.json` so Composer doesn’t break.
- No code changes vs 1.3.0.

## [1.3.0] - 2025-07-12
### Added
- Load strategy: inject `highslide.js` via `BeforePageDisplay` to guarantee global `hs`.
- YouTube embeds: `mute=1` for reliable autoplay; loop via `playlist={code}` + `loop=1`.

### Changed
- ResourceLoader now ships only config (`highslide.cfg.js`) and styles; avoids double-exposing `hs`.
- Parser hooks modernized to array callables; non-abortable hooks return `true`.

### Fixed
- `extension.json`: use `"Hooks"` (plural) and include `BeforePageDisplay`, `ImageBeforeProduceHTML`, `ParserFirstCallInit`.
- General formatting/cleanup in `HighslideGallery.php`.

### Ops / Upgrade Notes
- After deploy, purge MediaWiki/ResourceLoader caches (and Cloudflare, if applicable) to pick up changes.

## [1.1.1] - 2023-12-11
### Changed
- Bundles Highslide JS
  - Distro includes `highslide.js` and `highslide.css` to pensure compatibility and "just works" for end user

## [1.1.0] - 2020-11-18
### Added
- YouTube mute fix and other minor improvements.

## [1.0.0] - 2014-07-14
- Initial release.