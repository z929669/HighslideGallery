<?php
/*
 * HighslideGallery (HSG) delivers Highslide JS-powered overlays/slideshows for images and YouTube videos
 * displayed as thumbnails or inline links on the page with titles and captions. Clicking a HSG thumbnail
 * or link opens the image or video in an interactive overlay auto-sized to the viewport with ability to
 * expand to full size with panning. See [this example](https://stepmodifications.org/wikidev/Template:Hsg).
 * 
 * @Copyright (C) 2012 Brian McCloskey, David Van Winkle, Step Modifications, Inc.
 *
 * This program is free software; you can redistribute it and/or modify it under the terms of the
 * GNU General Public License as published by the Free Software Foundation; either version 2 of the
 * License, or (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful, but WITHOUT ANY WARRANTY;
 * without even the implied warranty of MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.
 * See the GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License along with this program; if not, see
 * < https://www.gnu.org/licenses/ >.
 */

if ( !defined( 'MEDIAWIKI' ) ) {
	die( 'This is a MediaWiki extension, and must be run from within MediaWiki.' );
}

class HighslideGallery {
	// HSG-owned gallery/group identity
	private static $hsgId; // current slideshow group id
	private static $isHsgGroupMember = false;
	private static $hsgLabel; // optional display label, derived from hsgid
	private static $lastHsgGroupId; // last slideshow group rendered on this page
	// Canonical default thumb width for ALL HSG thumbs (images + YT) when width is unset.
	private const DEFAULT_THUMB_WIDTH = 210;
	// Magic numbers for proxy thumbnail positioning (used to hide off-screen proxy thumbs)
	private const PROXY_THUMB_OFF_SCREEN_PX = -9999;
	private const PROXY_THUMB_SIZE_PX = 1;

	private static function getControlsPreset(): string {
		// Prefer explicit global override (LocalSettings.php).
		if ( isset( $GLOBALS['wgHSGControlsPreset'] ) && is_string( $GLOBALS['wgHSGControlsPreset'] ) ) {
			$val = trim( $GLOBALS['wgHSGControlsPreset'] );
			if ( $val !== '' ) {
				return $val;
			}
		}

		// Fall back to registered config (extension.json default is "classic").
		$config = \MediaWiki\MediaWikiServices::getInstance()->getMainConfig();
		$val = $config->get( 'wgHSGControlsPreset' );
		return is_string( $val ) ? $val : 'classic';
	}

	public static function AddResources( OutputPage &$out, Skin &$skin ): void {
		// Reset per-page state
		self::$hsgId = null;
		self::$isHsgGroupMember = false;
		self::$hsgLabel = null;
		self::$lastHsgGroupId = null;

		// Push the controls preset to mw.config on every page view.
		$preset = self::getControlsPreset();
		$out->addJsConfigVars( 'wgHSGControlsPreset', $preset );

		// Let ResourceLoader handle highslide.js + cfg + CSS.
		$out->addModules( 'ext.highslideGallery' );
		$out->addModuleStyles( 'ext.highslideGallery' );
	}

	// Parser hooks are non-abortable; return true. Use [self::class, 'method'].
	public static function AddHooks( Parser $parser ): bool {
		// -----------------------------------------------------------------
		// Tags
		// -----------------------------------------------------------------
		// Legacy tag - kept for backward compatibility
		$parser->setHook( 'hsyoutube', [ self::class, 'onTagHsYouTube' ] );

		// Canonical HSG tag for YouTube (ytb = all things YouTube)
		$parser->setHook( 'hsgytb', [ self::class, 'onTagHsgYtb' ] );

		// -----------------------------------------------------------------
		// Parser functions
		// -----------------------------------------------------------------
		$parser->setFunctionHook( 'hsgimg', [ self::class, 'onFunctionHsgImg' ] );
		$parser->setFunctionHook( 'hsgytb', [ self::class, 'onFunctionHsgYtb' ] );

		return true;
	}

	/**
	 * Tag <hsyoutube>…</hsyoutube> (legacy).
	 */
	public static function onTagHsYouTube(
		$source,
		array $attributes,
		Parser $parser,
		?PPFrame $frame = null
	) {
		return self::renderYouTubeHtml( $source, $attributes, $parser, $frame, false );
	}

	/**
	 * Tag <hsgytb>…</hsgytb> (canonical).
	 */
	public static function onTagHsgYtb(
		$source,
		array $attributes,
		Parser $parser,
		?PPFrame $frame = null
	) {
		return self::renderYouTubeHtml( $source, $attributes, $parser, $frame, false );
	}

	/**
	 * Expose selected config values to the client via mw.config.
	 */
	public static function onResourceLoaderGetConfigVars(
		array &$vars,
		string $skin,
		Config $config
	): bool {
		$vars['wgHSGControlsPreset'] = self::getControlsPreset();
		
		return true;
	}

	// =====================================================================
	// Parser function plumbing
	// =====================================================================

	/*
	 * Generic parser for parser function arguments.
	 *
	 * Pattern:
	 * - First non-empty param without '=' → primary value (unless overridden).
	 * - Or explicit "$primaryKey=..." → primary value.
	 * - Remaining "key=value" → attributes[key] = value.
	 * - Remaining bare tokens → attributes[token] = true (flags).
	 *
	 * @param string[] $params
	 * @param string $primaryKey e.g. 'source'
	 * @return array{0:string,1:array} [ $primary, $attributes ]
	 */
	private static function parseParserFunctionArgs( array $params, string $primaryKey ): array {
		$primary = null;
		$attributes = [];

		foreach ( $params as $raw ) {
			$raw = trim( (string)$raw );
			if ( $raw === '' ) {
				continue;
			}

			$pos = strpos( $raw, '=' );
			if ( $pos !== false ) {
				$key = trim( substr( $raw, 0, $pos ) );
				$value = trim( substr( $raw, $pos + 1 ) );

				if ( $key === '' ) {
					continue;
				}

				if ( $key === $primaryKey ) {
					$primary = $value;
				} else {
					$attributes[$key] = $value;
				}
				continue;
			}

			// No '=' in this param
			if ( $primary === null ) {
				$primary = $raw;
			} else {
				// Bare flag (e.g. "autoplay")
				$attributes[$raw] = true;
			}
		}

		if ( $primary === null && array_key_exists( $primaryKey, $attributes ) ) {
			$primary = $attributes[$primaryKey];
		}

		return [ $primary ?? '', $attributes ];
	}

	/**
	 * Interpret common truthy values for flag attributes.
	 *
	 * @param mixed $val
	 */
	private static function isTruthy( $val ): bool {
		if ( $val === true ) {
			return true;
		}
		if ( $val === false || $val === null ) {
			return false;
		}

		$normalized = strtolower( trim( (string)$val ) );

		return in_array( $normalized, [ '1', 'true', 'yes', 'y' ], true );
	}

	/**
	 * Escape a string for use in HTML attributes consistently.
	 */
	private static function escAttr( $val ): string {
		return htmlspecialchars( (string)$val, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8' );
	}

	/**
	 * Generate a cryptographically-secure id segment, with safe fallbacks.
	 */
	private static function generateId( int $bytes = 8 ): string {
		try {
			return bin2hex( random_bytes( $bytes ) );
		} catch ( \Throwable $e ) {
			try {
				if ( function_exists( 'openssl_random_pseudo_bytes' ) ) {
					return bin2hex( openssl_random_pseudo_bytes( $bytes ) );
				}
			} catch ( \Throwable $e2 ) { }
			// Last resort: deterministic but unlikely collision for modest usage.
			return substr( hash( 'sha256', uniqid( (string)microtime( true ), true ) ), 0, $bytes * 2 );
		}
	}

	/**
	 * REFACTOR HELPER: Parse inline mode flag from user input.
	 * Returns true for yes/1/true, false for no/0/false, null to keep default.
	 *
	 * @param mixed $val Input value to parse
	 * @return ?bool true=inline, false=thumbnail, null=use default
	 */
	private static function parseInlineMode( $val ): ?bool {
		if ( is_string( $val ) ) {
			$val = strtolower( trim( $val ) );
		}

		if ( $val === '' || $val === null ) {
			return null; // Empty param: use default
		}

		if ( $val === false || $val === 0 || $val === '0' || $val === 'no' || $val === 'false' ) {
			return false;
		}
		if ( $val === true || $val === 1 || $val === '1' || $val === 'yes' || $val === 'true' ) {
			return true;
		}

		return null; // Unrecognized: use default
	}

	/**
	 * REFACTOR HELPER: Extract and validate a slideshow group ID from user input.
	 *
	 * @param ?string $candidate Raw ID candidate
	 * @return ?string Valid ID or null if empty/invalid
	 */
	private static function extractGroupId( ?string $candidate ): ?string {
		if ( $candidate === null ) {
			return null;
		}
		$candidate = trim( (string)$candidate );
		if ( $candidate === '' ) {
			return null;
		}
		// Validate format: alphanumeric, dash, underscore, forward-slash
		if ( preg_match( '/^[A-Za-z0-9_\/-]+$/', $candidate ) ) {
			return $candidate;
		}
		return null;
	}

	/**
	 * REFACTOR HELPER: Build data-hsg-caption and data-hsg-html attributes.
	 *
	 * @param string $captionPlain Plain-text caption (no gallery id)
	 * @param string $captionHtml HTML fragment for overlay (pre-escaped spans)
	 * @param ?string $groupId Optional slideshow group id
	 * @return string Concatenated data attributes (with leading space)
	 */
	private static function buildDataAttributes( string $captionPlain, string $captionHtml, ?string $groupId ): string {
		$parts = [];

		// Always include plain-text caption
		$parts[] = ' data-hsg-caption="' . self::escAttr( $captionPlain ) . '"';

		// Include HTML when present (e.g. styled spans)
		if ( $captionHtml !== '' ) {
			$parts[] = ' data-hsg-html="' . self::escAttr( $captionHtml ) . '"';
		}

		// Include group id when present
		if ( $groupId !== null ) {
			$parts[] = ' data-hsgid="' . self::escAttr( $groupId ) . '"';
		}

		return implode( '', $parts );
	}

	/**
	 * REFACTOR HELPER: Create an HTML span with class and escaped text.
	 *
	 * @param string $text Text content to escape and wrap
	 * @param string $class CSS class name (must be hardcoded/safe)
	 * @return string HTML span element
	 */
	private static function makeCaptionSpan( string $text, string $class ): string {
		return '<span class="' . $class . '">' .
			htmlspecialchars( $text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8' ) .
			'</span>';
	}

	/**
	 * Build plain-text and HTML caption fragments with styling hooks.
	 *
	 * @param ?string $hsgid Gallery id / label
	 * @param ?string $titleText Title portion (optional)
	 * @param ?string $bodyText Caption portion (optional)
	 * @param bool $includeHsgId Whether to include hsgid in the output parts.
	 * @param ?Title $linkTitle Optional Title for linking the HTML
	 * @return array{0:string,1:string} [ plainText, html ]
	 */
	private static function buildCaptionFragments(
		?string $hsgId,
		?string $titleText,
		?string $bodyText,
		bool $includeHsgId = true,
		?Title $linkTitle = null
	): array {
		$partsPlain = [];
		$partsHtml = [];

		// REFACTOR: Use makeCaptionSpan helper to avoid code duplication in caption building
		$add = static function ( ?string $raw, string $class ) use ( &$partsPlain, &$partsHtml ) {
			$raw = $raw ?? '';
			$trimmed = trim( $raw );
			if ( $trimmed === '' ) {
				return;
			}
			$partsPlain[] = $trimmed;
			// Use makeCaptionSpan would be ideal, but it's a static method so we inline here to stay consistent:
			$partsHtml[] = '<span class="' . $class . '">' .
				htmlspecialchars( $trimmed, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8' ) .
				'</span>';
		};

		if ( $includeHsgId ) {
			$add( $hsgId, 'hsg-caption-gallery' );
		}
		$add( $titleText, 'hsg-caption-title' );
		$add( $bodyText, 'hsg-caption-caption' );

		if ( $partsPlain === [] ) {
			return [ '', '' ];
		}

		$plain = implode( ' | ', $partsPlain );
		$html = implode( ' | ', $partsHtml );

		if ( $linkTitle instanceof Title ) {
			$url = $linkTitle->getLocalURL();
			$href = self::escAttr( $url );
			$html = "<a href='" . $href . "' class='internal'>" . $html . '</a>';
		}

		return [ $plain, $html ];
	}

	/**
	 * Assemble overlay and no-id caption parts with shared fallbacks.
	 *
	 * @param ?string $hsgId Gallery id / label (for overlay if allowed)
	 * @param ?string $titleText Title portion
	 * @param ?string $bodyText Caption/body portion
	 * @param bool $includeHsgIdInOverlay Whether to include hsgid in overlay output
	 * @param ?Title $linkTitle Optional Title for link-wrapped overlay HTML
	 * @param string $fallbackText Plain fallback when parts are empty
	 * @return array{0:string,1:string,2:string,3:string} [ overlayPlain, overlayHtml, noIdPlain, noIdHtml ]
	 */
	private static function assembleCaptionBundle(
		?string $hsgId,
		?string $titleText,
		?string $bodyText,
		bool $includeHsgIdInOverlay,
		?Title $linkTitle,
		string $fallbackText
	): array {
		[ $overlayPlain, $overlayHtml ] = self::buildCaptionFragments(
			$hsgId,
			$titleText,
			$bodyText,
			$includeHsgIdInOverlay,
			$linkTitle
		);
		[ $noIdPlain, $noIdHtml ] = self::buildCaptionFragments(
			null,
			$titleText,
			$bodyText,
			false,
			null
		);

		if ( $overlayPlain === '' && $fallbackText !== '' ) {
			$overlayPlain = $fallbackText;
			// FIX: Wrap fallback in span for consistent styling (was: just escaped text)
			$overlayHtml = self::makeCaptionSpan( $fallbackText, 'hsg-caption-text' );
		}

		if ( $noIdPlain === '' ) {
			$noIdPlain = $overlayPlain !== '' ? $overlayPlain : $fallbackText;
			$noIdHtml = $overlayHtml !== '' ? $overlayHtml :
				( $fallbackText !== '' ? self::makeCaptionSpan( $fallbackText, 'hsg-caption-text' ) : '' );
		}

		return [ $overlayPlain, $overlayHtml, $noIdPlain, $noIdHtml ];
	}

	/**
	 * Image helper behind:
	 * - {{#hsgimg: ...}} (canonical)
	 *
	 * Inline vs thumbnail is controlled by the "inline" flag:
	 * - inline=1 / yes / true → inline text link opener
	 * - otherwise → thumbnail with MW-like thumb wrapper (empty inline param ignored)
	 *
	 * Usage (positional):
	 * {{#hsgimg: File:Example.jpg | hsgid=MyGallery | width=208 | caption=Nice image }}
	 *
	 * Usage (named):
	 * {{#hsgimg: source=File:Example.jpg | hsgid=MyGallery | width=208 | caption=Nice image }}
	 *
	 * Recognized attributes:
	 * - source : URL or File: title (primary if not given positionally)
	 * - hsgid/id : slideshow group id
	 * - width : max width in px (thumbs only; templates can provide defaults)
	 * - caption : descriptive caption text
	 * - title : heading/label text
	 * - linktext : inline link text (inline variant only)
	 * - inline : flag as above
	 */
	public static function onFunctionHsgImg( Parser $parser, ...$params ) {
		[ $content, $attributes ] = self::parseParserFunctionArgs( $params, 'source' );

		if ( $content === '' ) {
			return '';
		}

		// -----------------------------------------------------------------
		// 1. Inline vs thumb (same semantics as renderYouTubeHtml)
		// -----------------------------------------------------------------
		$inlineMode = false;
		if ( array_key_exists( 'inline', $attributes ) ) {
			$val = $attributes['inline'];
			unset( $attributes['inline'] );

			$parsed = self::parseInlineMode( $val );
			if ( $parsed !== null ) {
				$inlineMode = $parsed;
			}
		}

		$hideCaptionFlag = false;
		if ( array_key_exists( 'nocaption', $attributes ) ) {
			$hideCaptionFlag = self::isTruthy( $attributes['nocaption'] );
			unset( $attributes['nocaption'] );
		} elseif ( array_key_exists( 'hidecaption', $attributes ) ) {
			$hideCaptionFlag = self::isTruthy( $attributes['hidecaption'] );
			unset( $attributes['hidecaption'] );
		}

		$tileFlag = false;
		foreach ( $attributes as $k => $v ) {
			$kLower = strtolower( (string)$k );
			if ( $kLower === 'tile' || $kLower === 'tiles' || $kLower === 'tiling' ) {
				$tileFlag = self::isTruthy( $v );
				unset( $attributes[$k] );
				break;
			}
		}

		// -----------------------------------------------------------------
		// 2. Normalise title / caption / linktext (unescaped)
		// -----------------------------------------------------------------
		$titleRaw = isset( $attributes['title'] ) ? trim( (string)$attributes['title'] ) : '';
		$captionRaw = isset( $attributes['caption'] ) ? trim( (string)$attributes['caption'] ) : '';

		// Combined caption used for alt / thumb caption / overlay:
		// - "Title | Caption" if both present
		// - else Caption → Title → Source
		if ( $titleRaw !== '' && $captionRaw !== '' ) {
			$captionDisplay = $titleRaw . ' | ' . $captionRaw;
		} elseif ( $captionRaw !== '' ) {
			$captionDisplay = $captionRaw;
		} elseif ( $titleRaw !== '' ) {
			$captionDisplay = $titleRaw;
		} else {
			$captionDisplay = 'Image';
		}

		// Visible link text for INLINE variant.
		$linkTextRaw = isset( $attributes['linktext'] ) ? trim( (string)$attributes['linktext'] ) : '';
		if ( $linkTextRaw === '' ) {
			if ( $titleRaw !== '' ) {
				$linkTextRaw = $titleRaw;
			} elseif ( $captionRaw !== '' ) {
				$linkTextRaw = $captionRaw;
			} else {
				$linkTextRaw = 'Image';
			}
		}

		$captionEsc = self::escAttr( $captionDisplay );
		$linkTextEsc = self::escAttr( $linkTextRaw );

		// -----------------------------------------------------------------
		// 3. Resolve target: external URL vs File:
		// -----------------------------------------------------------------
		$href = $content;
		$titleObj = Title::newFromText( $content );
		$fileObj = null;

		if ( $titleObj instanceof Title && $titleObj->inNamespace( NS_FILE ) ) {
			try {
				$fileObj = \MediaWiki\MediaWikiServices::getInstance()
					->getRepoGroup()
					->findFile( $titleObj );
				if ( $fileObj ) {
					$href = $fileObj->getUrl();
				}
			} catch ( Exception $e ) {
				// If repository lookup fails, fall back to raw content URL.
				$fileObj = null;
			}
		}

		$hrefEsc = self::escAttr( $href );

		// -----------------------------------------------------------------
		// 4. Gallery id (optional)
		// -----------------------------------------------------------------
		$groupId = self::extractGroupId( 
			isset( $attributes['hsgid'] ) ? $attributes['hsgid'] 
			: ( isset( $attributes['id'] ) ? $attributes['id'] : null )
		);

		// -----------------------------------------------------------------
		// 5. INLINE VARIANT (text link opener)
		// -----------------------------------------------------------------
		if ( $inlineMode ) {
			if ( $groupId === null ) {
				$groupId = 'hsg-' . self::generateId( 8 );
			}

				$thumbId = 'hsg-thumb-' . self::generateId( 8 );

			[ $captionPlain, $captionHtml, $captionPlainNoId ] = self::assembleCaptionBundle(
				$groupId,
				$titleRaw,
				$captionRaw !== '' ? $captionRaw : $captionDisplay,
				true,
				$titleObj,
				$captionDisplay
			);
			$captionEsc = self::escAttr( $captionPlainNoId );

			$opts = [
				'slideshowGroup' => (string)$groupId,
				'captionText' => $captionHtml,
				'thumbnailId' => $thumbId,
			];

			$optsJson = self::escAttr( FormatJson::encode( $opts ) );

			$s = '<a class="highslide hsg-inline hsg-thumb"';
			$s .= ' id="' . self::escAttr( (string)$groupId ) . '"';
			$s .= ' href="' . $hrefEsc . '"';
			$s .= ' onclick="return hs.expand(this, ' . $optsJson . ');"';
			$s .= ' title="' . $captionEsc . '"';
			$s .= ' data-hsg-caption="' . self::escAttr( $captionPlainNoId ) . '"';
			if ( $captionHtml !== '' ) {
				$s .= ' data-hsg-html="' . self::escAttr( $captionHtml ) . '"';
			}
			$s .= '>' . $linkTextEsc;
			$s .= '<img id="' . self::escAttr( $thumbId ) . '"';
			$s .= ' class="hsg-inline-thumb-proxy"';
			$s .= ' src="' . $hrefEsc . '"';
			$s .= ' alt=""';
			$s .= ' style="position:absolute;left:' . self::PROXY_THUMB_OFF_SCREEN_PX . 'px;top:' . self::PROXY_THUMB_OFF_SCREEN_PX . 'px;width:' . self::PROXY_THUMB_SIZE_PX . 'px;height:' . self::PROXY_THUMB_SIZE_PX . 'px;" />';
			$s .= '</a>';

			return [
				$s,
				'noparse' => true,
				'isHTML' => true,
			];
		}

		// -----------------------------------------------------------------
		// 6. THUMBNAIL VARIANT (MW-like thumb structure)
		// -----------------------------------------------------------------
		$width = isset( $attributes['width'] ) && $attributes['width'] !== ''
			? (int)$attributes['width']
			: self::DEFAULT_THUMB_WIDTH; // canonical PHP-side default; templates can change this

		$captionBodyForParts = $captionRaw;
		// Overlay caption may include hsgid; on-page data/alt should not.
		// Note: $groupId is non-null only if extractGroupId() returned valid; if non-null, it's guaranteed non-empty.
		$hasExplicitHsgId = $groupId !== null;
		[
			$captionPlainOverlay,
			$captionHtmlOverlay,
			$captionPlainThumb,
			$captionHtmlThumb
		] = self::assembleCaptionBundle(
			$groupId,
			$titleRaw,
			$captionBodyForParts,
			$hasExplicitHsgId,
			$titleObj,
			$captionDisplay
		);
		$autoHideThumbCaption = ( $captionRaw === '' && $titleRaw === '' );
		$suppressThumbCaption = $hideCaptionFlag || $autoHideThumbCaption;

		$captionEsc = self::escAttr( $captionPlainThumb );

		if ( $groupId !== null ) {
			self::$hsgId = $groupId;
			self::$hsgLabel = $groupId;
		} else {
			self::$hsgId = null;
			self::$hsgLabel = null;
		}

		$hsg = '<a href="' . $hrefEsc . '" class="image highslide-link" title="' . $captionEsc . '">';
		$hsgimg = '<img class="hsgimg" src="' . $hrefEsc . '" alt="' . $captionEsc . '"';

		$w = (int)$width;
		if ( $w > 0 ) {
			$hsgimg .= ' style="max-width: ' . $w . 'px !important; height: auto; width: auto;"';
		}

		$thumbTileAttr = $tileFlag ? ' data-hsg-tile="1"' : '';

		$s = $hsg . $hsgimg . ' /></a>';

		// For hsgimg thumbs we keep the original behaviour: no File object/title
		// is passed into AddHighslide (only MakeImageLink does that).
		self::AddHighslide( $s, null, $captionDisplay, null, $titleRaw, $captionBodyForParts, $groupId, $hasExplicitHsgId );

		$thumbStyle = '';
		if ( $w > 0 ) {
			$thumbStyle = ' style="width: ' . ( $w + 2 ) . 'px;"';
		}

		$captionHtml = '';
		if ( !$suppressThumbCaption && $captionPlainThumb !== '' ) {
			$captionHtml = '<div class="thumbcaption hsg-caption">' . $captionHtmlThumb . '</div>';
		}

		$s = '<div class="thumb hsg-thumb hsg-thumb-normalized"' . $thumbTileAttr . '><div class="thumbinner hsg-thumb"' . $thumbStyle . '>' .
			$s . $captionHtml . '</div></div>';

		return [ $s, 'isHTML' => true ];
	}

	/**
	 * Parser function wrapper for YouTube:
	 *
	 * By default, tags are inline and parser functions are thumbs; the inline flag overrides this:
	 * - inline=1 / yes / true → inline text link opener
	 * - otherwise → thumbnail with MW-like thumb wrapper (empty inline param ignored)
	 *
	 * Usage (positional):
	 * {{#hsgytb: https://www.youtube.com/watch?v=CODE | title=... | caption=... | autoplay }}
	 * {{#hsgytb: CODE | title=... | caption=... | autoplay }}
	 *
	 * Usage (named):
	 * {{#hsgytb: source=CODE | title=... | caption=... | width=300 | autoplay }}
	 *
	 * Rules:
	 * - primary = first non-empty param without '=' OR source=...
	 * - Remaining params as key=value attributes; bare tokens are flags.
	 */
	public static function onFunctionHsgYtb( Parser $parser, ...$params ) {
		[ $source, $attributes ] = self::parseParserFunctionArgs( $params, 'source' );

		if ( $source === '' ) {
			return '';
		}

		$html = self::renderYouTubeHtml( $source, $attributes, $parser, null, true );

		return [
			$html,
			'noparse' => true,
			'isHTML' => true
		];
	}

	/**
	 * Shared builder for YouTube links/thumbnails.
	 *
	 * Used by:
	 * - onTagHsYouTube() → <hsyoutube>…</hsyoutube>
	 * - onTagHsgYtb() → <hsgytb>…</hsgytb>
	 * - onFunctionHsgYtb() → {{#hsgytb: …}}
	 */
	private static function renderYouTubeHtml(
		$source,
		array $attributes,
		Parser $parser,
		?PPFrame $frame = null,
		bool $fromParserFunc = false
	) {
		// -----------------------------------------------------------------
		// 1. Extract video code from $source
		// -----------------------------------------------------------------
		$raw = trim( (string)$source );
		$code = '';

		// 1) Try normal URL shapes first.
		if ( preg_match( '/[?&]v=([^?&]+)/', $raw, $m ) ) {
			// https://www.youtube.com/watch?v=CODE
			$code = $m[1];
		} elseif ( preg_match( '#youtu\.be/([^?]+)#', $raw, $m ) ) {
			// https://youtu.be/CODE
			$code = $m[1];
		} elseif ( preg_match( '#/embed/([^?]+)#', $raw, $m ) ) {
			// https://www.youtube.com/embed/CODE
			$code = $m[1];
		} else {
			// 2) Fallback: treat as bare video ID if it looks like a sane token.
			// YouTube IDs are typically 11 characters; require that shape here.
			if ( preg_match( '/^[A-Za-z0-9_-]{11}$/', $raw ) ) {
				$code = $raw;
			} else {
				return '';
			}
		}

		// -----------------------------------------------------------------
		// 2. Normalise title / caption / linktext (unescaped)
		// -----------------------------------------------------------------
		$titleRaw = isset( $attributes['title'] ) ? trim( (string)$attributes['title'] ) : '';
		$captionRaw = isset( $attributes['caption'] ) ? trim( (string)$attributes['caption'] ) : '';

		$linkTextRaw = $attributes['linktext'] ?? '';
		$linkTextRaw = is_string( $linkTextRaw ) ? trim( $linkTextRaw ) : '';

		// Visible link text (used only for INLINE links).
		if ( $linkTextRaw === '' ) {
			if ( $titleRaw !== '' ) {
				$linkTextRaw = $titleRaw;
			} elseif ( $captionRaw !== '' ) {
				$linkTextRaw = $captionRaw;
			} else {
				$linkTextRaw = 'YouTube Video';
			}
		}

		// Combined caption string for alt / caption / overlay:
		// - "Title | Caption" if both present
		// - else Caption → Title → LinkText
		if ( $titleRaw !== '' && $captionRaw !== '' ) {
			$captionDisplay = $titleRaw . ' | ' . $captionRaw;
		} elseif ( $captionRaw !== '' ) {
			$captionDisplay = $captionRaw;
		} elseif ( $titleRaw !== '' ) {
			$captionDisplay = $titleRaw;
		} else {
			$captionDisplay = $linkTextRaw;
		}

		// Title attribute prefers title, else the combined caption.
		$titleForAttr = $titleRaw !== '' ? $titleRaw : $captionDisplay;

		// -----------------------------------------------------------------
		// 3. Autoplay / width / inline mode
		// -----------------------------------------------------------------
		$autoplayOn = array_key_exists( 'autoplay', $attributes ) &&
			$attributes['autoplay'] !== '' &&
			$attributes['autoplay'] !== '0' &&
			$attributes['autoplay'] !== 0 &&
			$attributes['autoplay'] !== false;

		// Width (used only for thumbnails).
		$width = self::DEFAULT_THUMB_WIDTH;
		if ( isset( $attributes['width'] ) && $attributes['width'] !== '' ) {
			$width = (int)$attributes['width'];
			unset( $attributes['width'] );
		}
		
		// Determine inline mode:
		// - Tags (<hsyoutube>, <hsgytb>): default inline text
		// - Parser function (#hsgytb): default thumbnail
		$inlineMode = !$fromParserFunc;

		if ( array_key_exists( 'inline', $attributes ) ) {
			$val = $attributes['inline'];
			unset( $attributes['inline'] );

			$parsed = self::parseInlineMode( $val );
			if ( $parsed !== null ) {
				$inlineMode = $parsed;
			}
		}

		$hideCaptionFlag = false;
		if ( array_key_exists( 'nocaption', $attributes ) ) {
			$hideCaptionFlag = self::isTruthy( $attributes['nocaption'] );
			unset( $attributes['nocaption'] );
		} elseif ( array_key_exists( 'hidecaption', $attributes ) ) {
			$hideCaptionFlag = self::isTruthy( $attributes['hidecaption'] );
			unset( $attributes['hidecaption'] );
		}

		$tileFlag = false;
		foreach ( $attributes as $k => $v ) {
			$kLower = strtolower( (string)$k );
			if ( $kLower === 'tile' || $kLower === 'tiles' || $kLower === 'tiling' ) {
				$tileFlag = self::isTruthy( $v );
				unset( $attributes[$k] );
				break;
			}
		}

		// Build player URL with query flags.
		$query = [];
		if ( $autoplayOn ) {
			$query[] = 'autoplay=1';
		}
		$query[] = 'mute=1';
		$query[] = 'autohide=1';
		$query[] = 'playlist=' . rawurlencode( $code );
		$query[] = 'loop=1';

		$href = 'https://www.youtube.com/embed/' . rawurlencode( $code ) . '?' . implode( '&', $query );

		// -----------------------------------------------------------------
		// 4. Optional gallery id so YT tiles can join image galleries (slideshowGroup analogue).
		// -----------------------------------------------------------------
		$groupId = null;
		$hasExplicitHsgId = false;
		if ( isset( $attributes['hsgid'] ) && $attributes['hsgid'] !== '' ) {
			$groupId = self::extractGroupId( $attributes['hsgid'] );
			if ( $groupId !== null ) {
				$hasExplicitHsgId = true;
			}
			unset( $attributes['hsgid'] );
		} elseif ( isset( $attributes['id'] ) && $attributes['id'] !== '' ) {
			// Legacy/short name.
			$groupId = self::extractGroupId( $attributes['id'] );
			if ( $groupId !== null ) {
				$hasExplicitHsgId = true;
			}
			unset( $attributes['id'] );
		}

		// -----------------------------------------------------------------
		// 4b. Caption assembly (overlay can include hsgid; data/alt should not)
		// -----------------------------------------------------------------
		$captionBodyForParts = $captionRaw;
		[
			$captionPlain,
			$captionHtml,
			$captionPlainNoId,
			$captionHtmlNoId
		] = self::assembleCaptionBundle(
			$groupId,
			$titleRaw,
			$captionBodyForParts,
			$hasExplicitHsgId,
			null,
			$captionDisplay
		);

		$autoHideThumbCaption = ( $captionRaw === '' && $titleRaw === '' );
		$suppressThumbCaption = $hideCaptionFlag || $autoHideThumbCaption;

		$titleEsc = self::escAttr( $captionPlainNoId !== '' ? $captionPlainNoId : $titleForAttr );
		$linkTextEsc = self::escAttr( $linkTextRaw );
		$captionPlainEsc = self::escAttr( $captionPlainNoId );
		// Expose plain-text caption (no-id) and, when present, overlay HTML via data-hsg-html
		$dataCaption = ' data-hsg-caption="' . self::escAttr( $captionPlainNoId ) . '"';
		if ( $captionHtml !== '' ) {
			$dataCaption .= ' data-hsg-html="' . self::escAttr( $captionHtml ) . '"';
		}

		$dataGroup = $groupId !== null
			? ' data-hsgid="' . self::escAttr( $groupId ) . '"'
			: '';

		// -----------------------------------------------------------------
		// 5. INLINE vs THUMBNAIL output
		// -----------------------------------------------------------------
		if ( $inlineMode ) {
			// INLINE TEXT LINK - always invoke Highslide via inline onclick.
			$s = '<a class="highslide link-youtube hsg-thumb"';
			$s .= ' title="' . $titleEsc . '"';
			$s .= ' href="' . self::escAttr( $href ) . '"';
			$s .= ' onclick="return (window.hsgOpenYouTube || hs.htmlExpand)(this, window.hsgVideoOptions || {});"';
			$s .= $dataCaption;
			if ( $dataGroup !== '' ) {
				$s .= $dataGroup;
			}
			$s .= '>';
			$s .= $linkTextEsc . '</a>';

			return $s;
		} else {
			// THUMBNAIL LINK (MW-like thumb structure)
			$thumbUrl = 'https://img.youtube.com/vi/' . rawurlencode( $code ) . '/hqdefault.jpg';
			$style = '';

			if ( $width > 0 ) {
				$style = ' style="max-width: ' . $width . 'px; height: auto; width: auto;"';
			}

			$innerStyle = $width > 0 ? ' style="width: ' . ( $width + 2 ) . 'px;"' : '';
			$thumbTileAttr = $tileFlag ? ' data-hsg-tile="1"' : '';

			$anchor = '<a class="highslide link-youtube hsg-ytb-thumb hsg-thumb"';
			$anchor .= ' title="' . $titleEsc . '"';
			$anchor .= ' href="' . self::escAttr( $href ) . '"';
			$anchor .= ' onclick="return (window.hsgOpenYouTube || hs.htmlExpand)(this, window.hsgVideoOptions || {});"';
			$anchor .= $dataCaption;
			if ( $dataGroup !== '' ) {
				$anchor .= $dataGroup;
			}
			$anchor .= '>';
			$anchor .= '<img class="hsgimg hsg-ytb-thumb-img" src="' .
				self::escAttr( $thumbUrl ) .
				'" alt="' . $captionPlainEsc . '"' . $style . ' />';
			$anchor .= '</a>';

			$captionHtmlBlock = ( !$suppressThumbCaption && $captionPlainNoId !== '' )
				? '<div class="thumbcaption hsg-caption">' .
					// If captionHtmlNoId is plain text, escape it.
					( strpos( $captionHtmlNoId, '<span' ) === false
						? htmlspecialchars( $captionHtmlNoId, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8' )
						: $captionHtmlNoId ) .
					'</div>'
				: '';

			$s = '<div class="thumb hsg-thumb hsg-thumb-normalized"' . $thumbTileAttr . '>';
			$s .= '<div class="thumbinner hsg-thumb"' . $innerStyle . '>';
			$s .= $anchor . $captionHtmlBlock;
			$s .= '</div></div>';
		}

		return $s;
	}

	public static function MakeImageLink(
		&$dummy, // Hook signature requires this; not used in function body
		&$title,
		&$file,
		&$frameParams,
		&$handlerParams,
		&$time,
		&$res
	) {
		$galleryIdMatches = [];

		if (
			isset( $frameParams['caption'] ) &&
			preg_match( '/^(hsgid|highslide)[^:]*:/i', $frameParams['caption'] )
		) {
			// hsgid=Foo-Bar/Baz:Caption OR highslide=Foo-Bar/Baz:Caption
			preg_match(
				'/^(hsgid|highslide)=(?!:)([A-Za-z0-9_\/-]+)/i',
				$frameParams['caption'],
				$galleryIdMatches
			);

			if ( !empty( $galleryIdMatches[2] ) ) {
				// Explicit group id
				self::$hsgId = $galleryIdMatches[2];
				self::$hsgLabel = $galleryIdMatches[2]; // label starts as the explicit id
			} else {
				// No id after = → treat as “no group”
				self::$hsgId = null;
				self::$hsgLabel = null;
			}

			// Strip the hsgid=/highslide= prefix from caption/alt/title
			$frameParams['caption'] = preg_replace(
				'/^(hsgid|highslide)[^:]*:/i',
				'',
				$frameParams['caption']
			);
			if ( isset( $frameParams['alt'] ) ) {
				$frameParams['alt'] = preg_replace(
					'/^(hsgid|highslide)[^:]*:/i',
					'',
					$frameParams['alt']
				);
			}
			if ( isset( $frameParams['title'] ) ) {
				$frameParams['title'] = preg_replace(
					'/^(hsgid|highslide)[^:]*:/i',
					'',
					$frameParams['title']
				);
			}

			$res = $dummy->MakeThumbLink2( $title, $file, $frameParams, $handlerParams );
			self::AddHighslide( $res, $file, $frameParams['caption'], $title );
			return false;
		}

		return true;
	}

	/**
	 * Core decorator: take an <a><img></a> thumb string and attach Highslide
	 * semantics (slideshowGroup, caption text, MediaViewer shielding, etc.).
	 */
	private static function AddHighslide(
		&$s,
		$file,
		$caption,
		$title,
		?string $titleText = null,
		?string $captionText = null,
		?string $hsgIdText = null,
		bool $includeHsgIdInOverlay = true
	) {
		// Derive base label:
		// - if we have an explicit hsgLabel (id), use it;
		// - else if there's a File object, use its name;
		// - else fall back to the raw caption or a generic label.
		// Note: hsgLabel is non-null only when set with an explicit id; if non-null, it's guaranteed non-empty.
		if ( self::$hsgLabel !== null ) {
			$label = self::$hsgLabel;
		} elseif ( $file ) {
			$label = $file->getName();
		} elseif ( $caption !== '' ) {
			$label = $caption;
		} else {
			$label = 'Member';
		}

		$titleText = $titleText !== null ? trim( $titleText ) : '';
		$captionText = $captionText !== null ? trim( $captionText ) : trim( (string)$caption );
		$hsgIdText = $hsgIdText !== null ? trim( $hsgIdText ) : $label;

		// Overlay caption (with optional hsgid) and plain text (no hsgid) for data/alt.
		[
			$displayWithId,
			$captionHtml,
			$displayNoId
		] = self::assembleCaptionBundle(
			$hsgIdText,
			$titleText,
			$captionText,
			$includeHsgIdInOverlay,
			$title instanceof Title ? $title : null,
			$label
		);

		$display = $displayNoId !== '' ? $displayNoId : $displayWithId;
		if ( $display === '' ) {
			$display = $label;
		}

		// Escape for HTML/attribute context (data/alt should use no-id string).
		$displayEsc = self::escAttr( $display );

		// Ensure gallery/group id and decide whether this is a multi-image gallery member.
		if ( self::$hsgId === null ) {
			// No explicit id: each call gets its own unique group → single-member galleries.
			self::$hsgId = 'hsg-' . self::generateId( 8 );
			self::$isHsgGroupMember = false;
		} else {
			// Explicit id: if it's the same as the last group, this is a gallery member;
			// if it's new, treat this as the first image in a new gallery.
			if ( self::$lastHsgGroupId !== null && self::$lastHsgGroupId === self::$hsgId ) {
				self::$isHsgGroupMember = true; // same group as previous
			} else {
				self::$isHsgGroupMember = false; // first in this id's group
			}
		}
		// Remember this group for next call on the page.
		self::$lastHsgGroupId = self::$hsgId;

			// Prepare JS options safely using JSON (avoids breaking on quotes).
			$opts = [
				'slideshowGroup' => (string)self::$hsgId,
				// Already HTML; Highslide treats caption as HTML fragment.
				'captionText' => $captionHtml,
			];
		// Let Highslide's global hs.numberPosition decide where the index appears
		// (we set it to 'caption' in highslide.cfg.js).
		$optsJson = FormatJson::encode( $opts );

		// Data attribute and alt should use the no-id plain display
		$displayEsc = self::escAttr( $display );

		// Use DOMDocument to robustly inject attributes/classes instead of fragile regexes.
		// PERFORMANCE NOTE: Creates a DOMDocument per thumbnail. For high-volume galleries (100+ items),
		// consider caching or using string manipulation if profiling shows this as a bottleneck.
		if ( !extension_loaded( 'dom' ) ) {
			// DOM extension missing; skip DOM-based injection (rare on MediaWiki hosts).
			return;
		}

		$wrapper = '<div id="hsg-wrapper">' . $s . '</div>';
		$dom = new \DOMDocument();
		libxml_use_internal_errors( true );
		$dom->loadHTML( '<?xml encoding="utf-8" ?>' . $wrapper );
		libxml_clear_errors();

		$root = $dom->getElementById( 'hsg-wrapper' );
		if ( $root ) {
			$anchors = $root->getElementsByTagName( 'a' );
			if ( $anchors->length > 0 ) {
				$a = $anchors->item( 0 );
				$existingClass = $a->getAttribute( 'class' );
				$classes = array_filter( array_map( 'trim', explode( ' ', $existingClass ) ) );
				array_unshift( $classes, 'noviewer', 'hsg-thumb' );
				$a->setAttribute( 'class', implode( ' ', array_unique( $classes ) ) );

				$a->setAttribute( 'id', (string)self::$hsgId );
				$a->setAttribute( 'data-hsgid', (string)self::$hsgId );
				$a->setAttribute( 'data-hsg-caption', $displayEsc );
				if ( $captionHtml !== '' ) {
					$a->setAttribute( 'data-hsg-html', self::escAttr( $captionHtml ) );
				}
				$a->setAttribute( 'onclick', 'return hs.expand(this, ' . $optsJson . ')' );

				if ( $file ) {
					try {
						$url = $file->getUrl();
						$a->setAttribute( 'href', (string)$url );
					} catch ( Exception $e ) { }
				}
			}

			$imgs = $root->getElementsByTagName( 'img' );
			if ( $imgs->length > 0 ) {
				$img = $imgs->item( 0 );
				$img->setAttribute( 'alt', $displayEsc );
				if ( self::$isHsgGroupMember ) {
					$imgClass = $img->getAttribute( 'class' );
					$imgClasses = array_filter( array_map( 'trim', explode( ' ', $imgClass ) ) );
					array_unshift( $imgClasses, 'hsg-gallery', 'wikigallery' );
					$img->setAttribute( 'class', implode( ' ', array_unique( $imgClasses ) ) );
				}
			}

			$newHtml = '';
			foreach ( $root->childNodes as $child ) {
				$newHtml .= $dom->saveHTML( $child );
			}
			$s = $newHtml;
		}

		// Reset per-call gallery flag.
		self::$isHsgGroupMember = false;
	}
}
