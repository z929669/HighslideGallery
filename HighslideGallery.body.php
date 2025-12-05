<?php
/*
 * HighslideGallery adds simple highslide-style image/galleries and YouTube popups to MediaWiki pages.
 * @Copyright (C) 2012  Brian McCloskey, David Van Winkle, Step Modifications, Inc.
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
	private static $hsgId;               // current slideshow group id
	private static $isHsgGroupMember = false;
	private static $hsgLabel;           // optional display label, derived from hsgid
	private static $lastHsgGroupId;     // last slideshow group rendered on this page

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
		self::$hsgId            = null;
		self::$isHsgGroupMember = false;
		self::$hsgLabel         = null;
		self::$lastHsgGroupId   = null;

		// Push the controls preset to mw.config on every page view.
		$preset = self::getControlsPreset();
		$out->addJsConfigVars( 'wgHSGControlsPreset', $preset );

		// Let ResourceLoader handle highslide.js + cfg + CSS.
		$out->addModules( 'ext.highslideGallery' );
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
		$parser->setFunctionHook( 'hsglink', [ self::class, 'onFunctionHsgLink' ] );
		$parser->setFunctionHook( 'hsgimg', [ self::class, 'onFunctionHsgImg' ] );
		$parser->setFunctionHook( 'hsgytb', [ self::class, 'onFunctionHsgYtb' ] );

		return true;
	}

	/**
	 * Tag <hsyoutube>…</hsyoutube> (legacy).
	 */
	public static function onTagHsYouTube(
		$content,
		array $attributes,
		Parser $parser,
		?PPFrame $frame = null
	) {
		return self::renderYouTubeHtml( $content, $attributes, $parser, $frame, false );
	}

	/**
	 * Tag <hsgytb>…</hsgytb> (canonical).
	 */
	public static function onTagHsgYtb(
		$content,
		array $attributes,
		Parser $parser,
		?PPFrame $frame = null
	) {
		return self::renderYouTubeHtml( $content, $attributes, $parser, $frame, false );
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
	 *   - First non-empty param without '=' → primary value (unless overridden).
	 *   - Or explicit "$primaryKey=..." → primary value.
	 *   - Remaining "key=value" → attributes[key] = value.
	 *   - Remaining bare tokens → attributes[token] = true (flags).
	 *
	 * @param string[] $params
	 * @param string   $primaryKey  e.g. 'src' (images) or 'content' (YouTube)
	 * @return array{0:string,1:array} [ $primary, $attributes ]
	 */
	private static function parseParserFunctionArgs( array $params, string $primaryKey ): array {
		$primary   = null;
		$attributes = [];

		foreach ( $params as $raw ) {
			$raw = trim( (string)$raw );
			if ( $raw === '' ) {
				continue;
			}

			$pos = strpos( $raw, '=' );
			if ( $pos !== false ) {
				$key   = trim( substr( $raw, 0, $pos ) );
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
	 * Inline text link opener for images (File: or external URL).
	 *
	 * Usage (positional):
	 *   {{#hsglink: File:Example.jpg | hsgid=MyGallery | caption=Nice image | linktext=Click here }}
	 *
	 * Usage (named):
	 *   {{#hsglink: src=File:Example.jpg | hsgid=MyGallery | caption=Nice image | linktext=Click here }}
	 */
	public static function onFunctionHsgLink( Parser $parser, ...$params ) {
		[ $content, $attributes ] = self::parseParserFunctionArgs( $params, 'src' );

		if ( $content === '' ) {
			return '';
		}

		$linkText = $attributes['linktext']
			?? $attributes['title']
			?? $attributes['caption']
			?? 'Image';
		$linkTextEsc = htmlspecialchars( $linkText, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8' );

		$caption = $attributes['caption']
			?? $attributes['title']
			?? $linkText;
		$captionEsc = htmlspecialchars( $caption, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8' );

		$groupId = $attributes['hsgid'] ?? $attributes['id'] ?? null;
		$groupId = $groupId !== null ? trim( (string)$groupId ) : null;
		if ( $groupId === '' ) {
			$groupId = null;
		}

		$titleObj = null;
		$fileObj  = null;
		$href     = $content;
		$thumbId  = uniqid( 'hsg-thumb-', true );

		$titleObj = Title::newFromText( $content );
		if ( $titleObj instanceof Title && $titleObj->inNamespace( NS_FILE ) ) {
			$fileObj = \MediaWiki\MediaWikiServices::getInstance()
				->getRepoGroup()
				->findFile( $titleObj );
			if ( $fileObj ) {
				$href = $fileObj->getUrl();
			}
		}

		// If no explicit group id, make a unique one.
		if ( $groupId === null ) {
			$groupId = uniqid( 'hsg-', true );
		}

		$captionHtml = $captionEsc;
		if ( $titleObj instanceof Title ) {
			$url = $titleObj->getLocalURL();
			$captionHtml = "<a href='" .
				htmlspecialchars( $url, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8' ) .
				"' class='internal'>" . $captionHtml . '</a>';
		}

		$opts = [
			'slideshowGroup' => (string)$groupId,
			'captionText'    => $captionHtml,
			'thumbnailId'    => $thumbId,
		];

		$optsJson = htmlspecialchars(
			FormatJson::encode( $opts ),
			ENT_QUOTES | ENT_SUBSTITUTE,
			'UTF-8'
		);

		$s  = '<a class="highslide hsg-inline hsg-thumb"';
		$s .= ' id="' . htmlspecialchars( (string)$groupId, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8' ) . '"';
		$s .= ' href="' . htmlspecialchars( $href, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8' ) . '"';
		$s .= ' onclick="return hs.expand(this, ' . $optsJson . ');"';
		$s .= ' title="' . $captionEsc . '"';
		$s .= ' data-hsg-caption="' . $captionEsc . '"';
		$s .= '>' . $linkTextEsc;
		$s .= '<img id="' . htmlspecialchars( $thumbId, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8' ) . '"';
		$s .= ' class="hsg-inline-thumb-proxy"';
		$s .= ' src="' . htmlspecialchars( $href, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8' ) . '"';
		$s .= ' alt=""';
		$s .= ' style="position:absolute;left:-9999px;top:-9999px;width:1px;height:1px;" />';
		$s .= '</a>';

		return [
			$s,
			'noparse' => true,
			'isHTML'  => true,
		];
	}

	/**
	 * External/image helper behind:
	 *   - {{#hsgimg: ...}}  (canonical)
	 *
	 * Usage (positional):
	 *   {{#hsgimg: https://example.com/foo.png | hsgid=MyGallery | width=208 | caption=Nice image }}
	 *
	 * Usage (named):
	 *   {{#hsgimg: src=https://example.com/foo.png | hsgid=MyGallery | width=208 | caption=Nice image }}
	 *
	 * Recognized attributes:
	 *   - src     : URL or File: title (primary if not given positionally)
	 *   - hsgid   : slideshow group id (id also accepted as synonym)
	 *   - width   : max width in px (default 208)
	 *   - caption : caption/title text (title accepted as fallback)
	 */
	public static function onFunctionHsgImg( Parser $parser, ...$params ) {
		[ $content, $attributes ] = self::parseParserFunctionArgs( $params, 'src' );

		if ( $content === '' ) {
			return '';
		}

		$hsgIdParam = $attributes['hsgid'] ?? $attributes['id'] ?? '';
		$width      = isset( $attributes['width'] ) && $attributes['width'] !== ''
			? (int)$attributes['width']
			: 208;
		$title      = $attributes['caption'] ?? $attributes['title'] ?? '';

		$caption = $title ?: $content;

		$hrefEsc    = htmlspecialchars( $content, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8' );
		$captionEsc = htmlspecialchars( $caption, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8' );

		$hs    = '<a href="' . $hrefEsc . '" class="image highslide-link" title="' . $captionEsc . '">';
		$hsimg = '<img class="hsimg" src="' . $hrefEsc . '" alt="' . $captionEsc . '"';

		$w = (int)$width;
		if ( $w > 0 ) {
			$hsimg .= ' style="max-width: ' . $w . 'px !important; height: auto; width: auto;"';
		}

		if ( $hsgIdParam !== '' ) {
			self::$hsgId    = $hsgIdParam;
			self::$hsgLabel = $hsgIdParam;
		} else {
			self::$hsgId    = null;
			self::$hsgLabel = null;
		}

		$s = $hs . $hsimg . ' /></a>';
		self::AddHighslide( $s, null, $caption, null );

		$thumbStyle = '';
		if ( $w > 0 ) {
			$thumbStyle = ' style="width: ' . ( $w + 2 ) . 'px;"';
		}

		$captionHtml = '';
		if ( $caption !== '' ) {
			$captionHtml = '<div class="thumbcaption hsg-caption">' . $captionEsc . '</div>';
		}

		$s = '<div class="thumb hsg-thumb hsg-thumb-normalized"><div class="thumbinner hsg-thumb"' . $thumbStyle . '>' .
			$s . $captionHtml . '</div></div>';

		return [ $s, 'isHTML' => true ];
	}

	/**
	 * Parser function wrapper for YouTube:
	 *
	 *   {{#hsgytb: https://www.youtube.com/watch?v=CODE | title=... | caption=... | autoplay }}
	 *   {{#hsgytb: CODE | title=... | caption=... | autoplay }}
	 *   {{#hsgytb: content=CODE | title=... | caption=... | width=300 | autoplay }}
	 *
	 * Rules:
	 *   - primary = first non-empty param without '=' OR content=...
	 *   - Remaining params as key=value attributes; bare tokens are flags.
	 */
	public static function onFunctionHsgYtb( Parser $parser, ...$params ) {
		[ $content, $attributes ] = self::parseParserFunctionArgs( $params, 'content' );

		if ( $content === '' ) {
			return '';
		}

		$html = self::renderYouTubeHtml( $content, $attributes, $parser, null, true );

		return [
			$html,
			'noparse' => true,
			'isHTML'  => true
		];
	}

	/**
	 * Shared builder for YouTube links/thumbnails.
	 *
	 * Used by:
	 *   - onTagHsYouTube()   → <hsyoutube>…</hsyoutube>
	 *   - onTagHsgYtb()      → <hsgytb>…</hsgytb>
	 *   - onFunctionHsgYtb() → {{#hsgytb: …}}
	 */
	private static function renderYouTubeHtml(
		$content,
		array $attributes,
		Parser $parser,
		?PPFrame $frame = null,
		bool $fromParserFunc = false
	) {
		$raw  = trim( (string)$content );
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
			if ( preg_match( '/^[A-Za-z0-9_-]+$/', $raw ) ) {
				$code = $raw;
			} else {
				return '';
			}
		}

		// Normalise title / caption / linktext (unescaped).
		$titleRaw   = isset( $attributes['title'] ) ? trim( (string)$attributes['title'] ) : '';
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
		//   - "Title | Caption" if both present
		//   - else Caption → Title → LinkText
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

		$titleEsc          = htmlspecialchars( $titleForAttr, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8' );
		$linkTextEsc       = htmlspecialchars( $linkTextRaw, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8' );
		$captionDisplayEsc = htmlspecialchars( $captionDisplay, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8' );
		$dataCaption       = ' data-hsg-caption="' . $captionDisplayEsc . '"';

		// Autoplay flag: presence of "autoplay" or "autoplay=..." is enough.
		$autoplayOn = array_key_exists( 'autoplay', $attributes ) &&
			$attributes['autoplay'] !== '' &&
			$attributes['autoplay'] !== '0' &&
			$attributes['autoplay'] !== 0 &&
			$attributes['autoplay'] !== false;

		// Width (used only for thumbnails).
		$width = 0;
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
			$val = is_string( $val ) ? strtolower( trim( $val ) ) : $val;

			$inlineMode = (
				$val === '' ||
				$val === true ||
				$val === '1' ||
				$val === 'yes' ||
				$val === 'true'
			);

			// Don't leak "inline" into anything else.
			unset( $attributes['inline'] );
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

		// Optional gallery id so YT tiles can join image galleries (slideshowGroup analogue).
		$groupId = null;
		if ( isset( $attributes['hsgid'] ) && $attributes['hsgid'] !== '' ) {
			$groupIdCandidate = trim( (string)$attributes['hsgid'] );
			if ( preg_match( '/^[A-Za-z0-9_\/-]+$/', $groupIdCandidate ) ) {
				$groupId = $groupIdCandidate;
			}
			unset( $attributes['hsgid'] );
		} elseif ( isset( $attributes['id'] ) && $attributes['id'] !== '' ) {
			// Legacy/short name.
			$groupIdCandidate = trim( (string)$attributes['id'] );
			if ( preg_match( '/^[A-Za-z0-9_\/-]+$/', $groupIdCandidate ) ) {
				$groupId = $groupIdCandidate;
			}
			unset( $attributes['id'] );
		}

		$dataGroup = $groupId !== null
			? ' data-hsgid="' . htmlspecialchars( $groupId, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8' ) . '"'
			: '';

		if ( $inlineMode ) {
			// INLINE TEXT LINK - always invoke Highslide via inline onclick.
			$s  = '<a class="highslide link-youtube hsg-thumb"';
			$s .= ' title="' . $titleEsc . '"';
			$s .= ' href="' . htmlspecialchars( $href, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8' ) . '"';
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
			$style    = '';

			if ( $width > 0 ) {
				$style = ' style="max-width: ' . $width . 'px; height: auto; width: auto;"';
			}

			$innerStyle = $width > 0 ? ' style="width: ' . ( $width + 2 ) . 'px;"' : '';

			$anchor  = '<a class="highslide link-youtube hsg-ytb-thumb hsg-thumb"';
			$anchor .= ' title="' . $titleEsc . '"';
			$anchor .= ' href="' . htmlspecialchars( $href, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8' ) . '"';
			$anchor .= ' onclick="return (window.hsgOpenYouTube || hs.htmlExpand)(this, window.hsgVideoOptions || {});"';
			$anchor .= $dataCaption;
			if ( $dataGroup !== '' ) {
				$anchor .= $dataGroup;
			}
			$anchor .= '>';
			$anchor .= '<img class="hsimg hsg-ytb-thumb-img" src="' .
				htmlspecialchars( $thumbUrl, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8' ) .
				'" alt="' . $captionDisplayEsc . '"' . $style . ' />';
			$anchor .= '</a>';

			$captionHtml = $captionDisplayEsc !== ''
				? '<div class="thumbcaption hsg-caption">' . $captionDisplayEsc . '</div>'
				: '';

			$s  = '<div class="thumb hsg-thumb hsg-thumb-normalized">';
			$s .= '<div class="thumbinner hsg-thumb"' . $innerStyle . '>';
			$s .= $anchor . $captionHtml;
			$s .= '</div></div>';
		}

		return $s;
	}

	public static function MakeImageLink(
		&$dummy,
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
			// hsgid=Foo-Bar/Baz:Caption  OR  highslide=Foo-Bar/Baz:Caption
			preg_match(
				'/^(hsgid|highslide)=(?!:)([A-Za-z0-9_\/-]+)/i',
				$frameParams['caption'],
				$galleryIdMatches
			);

			if ( !empty( $galleryIdMatches[2] ) ) {
				// Explicit group id
				self::$hsgId    = $galleryIdMatches[2];
				self::$hsgLabel = $galleryIdMatches[2]; // label starts as the explicit id
			} else {
				// No id after = → treat as “no group”
				self::$hsgId    = null;
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
	private static function AddHighslide( &$s, $file, $caption, $title ) {
		// Derive base label:
		//   - if we have an explicit hsgLabel (id), use it;
		//   - else if there's a File object, use its name;
		//   - else fall back to the raw caption or a generic label.
		if ( self::$hsgLabel !== null && self::$hsgLabel !== '' ) {
			$label = self::$hsgLabel;
		} elseif ( $file ) {
			$label = $file->getName();
		} elseif ( $caption !== '' ) {
			$label = $caption;
		} else {
			$label = 'Member';
		}

		// Compute the display string used for the Highslide caption and alt text.
		if ( $caption !== '' ) {
			$display = $label . ' | ' . $caption;
		} else {
			$display = $label;
		}

		// Escape for HTML/attribute context.
		$displayEsc = htmlspecialchars( $display, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8' );

		// Build HTML caption text (which Highslide will treat as HTML).
		$captionHtml = $displayEsc;

		// For File:... thumbs, make the *caption text* a link to the file page,
		// but don't inject an extra tiny thumbnail icon.
		if ( $title instanceof Title ) {
			$url = $title->getLocalURL();

			$captionHtml = "<a href='" .
				htmlspecialchars( $url, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8' ) .
				"' class='internal'>" . $captionHtml . '</a>';
		}

		// Ensure gallery/group id and decide whether this is a multi-image gallery member.
		if ( self::$hsgId === null ) {
			// No explicit id: each call gets its own unique group → single-member galleries.
			self::$hsgId            = uniqid( 'hsg-', true );
			self::$isHsgGroupMember = false;
		} else {
			// Explicit id: if it's the same as the last group, this is a gallery member;
			// if it's new, treat this as the first image in a new gallery.
			if ( self::$lastHsgGroupId !== null && self::$lastHsgGroupId === self::$hsgId ) {
				self::$isHsgGroupMember = true;   // same group as previous
			} else {
				self::$isHsgGroupMember = false;  // first in this id's group
			}
		}
		// Remember this group for next call on the page.
		self::$lastHsgGroupId = self::$hsgId;

		// Prepare JS options safely using JSON (avoids breaking on quotes).
		$opts = [
			'slideshowGroup' => (string)self::$hsgId,
			// Already HTML; Highslide treats caption as HTML fragment.
			'captionText'    => $captionHtml,
		];
		// Let Highslide's global hs.numberPosition decide where the index appears
		// (we set it to 'caption' in highslide.cfg.js).
		$optsJson = htmlspecialchars(
			FormatJson::encode( $opts ),
			ENT_QUOTES | ENT_SUBSTITUTE,
			'UTF-8'
		);

		$captionData = htmlspecialchars( $display, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8' );
		$prefix = 'id="' . htmlspecialchars( (string)self::$hsgId, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8' ) .
			'" data-hsgid="' . htmlspecialchars( (string)self::$hsgId, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8' ) .
			'" data-hsg-caption="' . $captionData .
			'" onclick="return hs.expand(this, ' . $optsJson . ')" href';

		// If this is a multi-image gallery member, ensure both:
		//   - legacy `.wikigallery` (BC for old CSS)
		//   - canonical `.hsg-gallery` (HSG-specific hook)
		if ( self::$isHsgGroupMember ) {
			if ( preg_match( '/<img[^>]*\sclass="/i', $s ) ) {
				$s = preg_replace(
					'/(<img[^>]*\sclass=")([^"]*)"/i',
					'\\1hsg-gallery wikigallery \\2"',
					$s,
					1
				);
			} else {
				$s = preg_replace(
					'/<img /i',
					'<img class="hsg-gallery wikigallery" ',
					$s,
					1
				);
			}
		}

		// Tell MediaViewer (and friends) to stay out of HSG thumbs.
		// MediaViewer checks `$thumb.closest('.noviewer')` — so ensure the outer link has `noviewer`.
		// Also add a stable `hsg-thumb` marker for any future "no overlay" gadgets.
		if ( preg_match( '/<a[^>]*\sclass="/i', $s ) ) {
			$s = preg_replace(
				'/(<a[^>]*\sclass=")([^"]*)"/i',
				'\\1noviewer hsg-thumb \\2"',
				$s,
				1
			);
		} else {
			$s = preg_replace(
				'/<a /i',
				'<a class="noviewer hsg-thumb" ',
				$s,
				1
			);
		}

		// Update the img alt attribute to match the final label|caption string.
		$s = preg_replace(
			'/\balt="[^"]*"/i',
			'alt="' . $displayEsc . '"',
			$s,
			1
		);

		// Inject id/onclick before first href=
		$s = preg_replace( '/href/i', $prefix, $s, 1 );

		// For File: thumbs, make the href target the full file URL.
		if ( $file ) {
			$url = $file->getUrl();
			$s = preg_replace(
				'/href="[^"]*"/i',
				'href="' . htmlspecialchars( $url, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8' ) . '"',
				$s,
				1
			);
		}

		// Reset per-call gallery flag.
		self::$isHsgGroupMember = false;
	}
}
