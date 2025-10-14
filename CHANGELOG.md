# Changelog
All notable changes to this project will be documented in this file.
The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/)
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

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
- After deploy, purge MediaWiki/ResourceLoader caches (and Cloudflare, if applicable) to pick up RL changes.
- If Redis is used for parser cache/session/object caching, expect a brief warm-up period.
