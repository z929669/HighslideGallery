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
	private static $hgID;
	private static $isGallery = false;
	private static $hgLabel; // optional display label, derived from hsgid
	private static $lastGroupID;  // last slideshow group rendered on this page

	public static function AddResources( OutputPage &$out, Skin &$skin ): void {
		self::$hgID    = null;
		self::$isGallery = false;
		self::$hgLabel   = null;

		// Let ResourceLoader handle highslide.js + cfg + CSS.
		$out->addModules( 'ext.highslideGallery' );
	}

	// Parser hooks are non-abortable; return true. Use [self::class, 'method'].
	public static function AddHooks( Parser $parser ): bool {
		// Old HTML tag form: <hsyoutube>…</hsyoutube>
		$parser->setHook( 'hsyoutube', [ self::class, 'MakeYouTubeLink' ] );
	
		// Existing image parser function: {{#hsimg: … }}
		$parser->setFunctionHook( 'hsimg', [ self::class, 'MakeExtLink' ] );
	
		// New YouTube parser function: {{#hsytb: url | title=… | caption=… | autoplay }}
		$parser->setFunctionHook( 'hsytb', [ self::class, 'MakeYouTubeParserFunc' ] );
	
		return true;
	}

	public static function MakeImageLink( &$dummy, &$title, &$file, &$frameParams, &$handlerParams, &$time, &$res ) {
		$galleryID = [];
	
		if ( isset( $frameParams['caption'] )
			&& preg_match( '/^(hsgid|highslide)[^:]*:/i', $frameParams['caption'] )
		) {
			// hsgid=Foo-Bar/Baz:Caption  OR  highslide=Foo-Bar/Baz:Caption
			preg_match(
				'/^(hsgid|highslide)=(?!:)([A-Za-z0-9_\/-]+)/i',
				$frameParams['caption'],
				$galleryID
			);
	
			if ( !empty( $galleryID[2] ) ) {
				// explicit group id
				self::$hgID    = $galleryID[2];
				self::$hgLabel = $galleryID[2]; // label starts as the explicit id
			} else {
				// no id after = → treat as “no group”
				self::$hgID    = null;
				self::$hgLabel = null;
			}
	
			// strip the hsgid=/highslide= prefix from caption/alt/title
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

	public static function MakeYouTubeLink(
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

		$title    = $attributes['title'] ?? 'YouTube Video';
		$titleEsc = htmlspecialchars( $title, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8' );

		// Autoplay flag: presence of "autoplay" or "autoplay=..." is enough.
		$autoplayOn = array_key_exists( 'autoplay', $attributes );

		// Width (used only for thumbnails).
		$width = 0;
		if ( isset( $attributes['width'] ) && $attributes['width'] !== '' ) {
			$width = (int)$attributes['width'];
			unset( $attributes['width'] );
		}

		// Caption → store in data attribute so JS can feed hs.captionText.
		$caption   = $attributes['caption'] ?? '';
		$captionEsc = $caption !== ''
			? htmlspecialchars( $caption, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8' )
			: '';
		$dataCaptionAttr = $captionEsc !== ''
			? ' data-hsg-caption="' . $captionEsc . '"'
			: '';

		// Determine inline mode:
		// - <hsyoutube> (fromParserFunc = false) → default inline text
		// - #hsytb      (fromParserFunc = true)  → default thumbnail
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

		$query = [];
		if ( $autoplayOn ) {
			$query[] = 'autoplay=1';
		}
		$query[] = 'mute=1';
		$query[] = 'autohide=1';
		$query[] = 'playlist=' . rawurlencode( $code );
		$query[] = 'loop=1';

		$href = 'https://www.youtube.com/embed/' . rawurlencode( $code ) . '?' . implode( '&', $query );

		if ( $inlineMode ) {
			// INLINE TEXT LINK – always invoke Highslide via inline onclick.
			$s  = '<a class="highslide link-youtube"';
			$s .= ' title="' . $titleEsc . '"';
			$s .= ' href="' . htmlspecialchars( $href, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8' ) . '"';
			$s .= ' onclick="return hs.htmlExpand(this, window.videoOptions || {});"';
			$s .= $dataCaptionAttr . '>';
			$s .= $titleEsc . '</a>';
		} else {
			// THUMBNAIL LINK
			$thumbUrl = 'https://img.youtube.com/vi/' . rawurlencode( $code ) . '/hqdefault.jpg';
			$style    = '';
	
			if ( $width > 0 ) {
				$style = ' style="max-width: ' . $width . 'px; height: auto; width: auto;"';
			}
	
			$s  = '<a class="highslide link-youtube hsg-yt-thumb"';
			$s .= ' title="' . $titleEsc . '"';
			$s .= ' href="' . htmlspecialchars( $href, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8' ) . '"';
			$s .= ' onclick="return hs.htmlExpand(this, window.videoOptions || {});"';
			$s .= $dataCaptionAttr . '>';
			$s .= '<img class="hsimg hsg-yt-thumb-img" src="' .
				htmlspecialchars( $thumbUrl, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8' ) .
				'" alt="' . $titleEsc . '"' . $style . ' />';
			$s .= '</a>';
		}

		// We no longer output a <div class="highslide-caption"> here;
		// the caption will be injected via hs.captionText in JS.
		return $s;
	}
	
	/**
	 * Parser function wrapper for YouTube:
	 *   {{#hsytb: https://www.youtube.com/watch?v=CODE | title=... | caption=... | autoplay }}
	 *   {{#hsytb: CODE | title=... | caption=... | autoplay }}
	 *
	 * First non-empty param = URL/content (even if it contains '=').
	 * Remaining params:
	 *   - "key=value" → $attributes[key] = value
	 *   - "autoplay"  → $attributes['autoplay'] = true
	 */
	public static function MakeYouTubeParserFunc( Parser $parser, ...$params ) {
		$content    = '';
		$attributes = [];
	
		foreach ( $params as $raw ) {
			$raw = trim( (string)$raw );
			if ( $raw === '' ) {
				continue;
			}
	
			if ( $content === '' ) {
				// First non-empty param is always the URL/content, even if it has '='.
				$content = $raw;
				continue;
			}
	
			$pos = strpos( $raw, '=' );
	
			if ( $pos !== false ) {
				$key   = trim( substr( $raw, 0, $pos ) );
				$value = trim( substr( $raw, $pos + 1 ) );
	
				if ( $key !== '' ) {
					$attributes[$key] = $value;
				}
			} else {
				// Bare flag (e.g. "autoplay").
				$attributes[$raw] = true;
			}
		}
	
		if ( $content === '' ) {
			return '';
		}
	
		// Note the final "true" → called from parser function
		$html = self::MakeYouTubeLink( $content, $attributes, $parser, null, true );
	
		return [
			$html,
			'noparse' => true,
			'isHTML'  => true,
		];
	}

	public static function MakeExtLink( $parser, $hsid, $width, $title, $content ) {
		// Accept either ordered params or named params; keep old behavior.
		if ( $content === '' && $title === '' ) {
			return false;
		}
		if ( $content === '' ) {
			$content = $title;
		}
	
		$caption = $title ?: $content;
	
		$hrefEsc = htmlspecialchars( $content, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8' );
		// We'll override alt text later in AddHighslide to match label | caption.
		$captionEsc = htmlspecialchars( $caption, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8' );
	
		$hs    = '<a href="' . $hrefEsc . '" class="image highslide-link" title="' . $captionEsc . '">';
		$hsimg = '<img class="hsimg" src="' . $hrefEsc . '" alt="' . $captionEsc . '"';
	
		$w = (int) $width;
		if ( $w > 0 ) {
			// Make caller width authoritative over any theme CSS.
			$hsimg .= ' style="max-width: ' . $w . 'px !important; height: auto; width: auto;"';
		}
	
		if ( $hsid !== '' ) {
			// Explicit group id → multi-image gallery if reused.
			self::$hgID    = $hsid;
			self::$hgLabel = $hsid;
		} else {
			// No id → force a fresh per-image group in AddHighslide().
			self::$hgID    = null;
			self::$hgLabel = null;
		}
	
		$s = $hs . $hsimg . ' /></a>';
		self::AddHighslide( $s, null, $caption, null );
		return [ $s, 'isHTML' => true ];
	}

	private static function AddHighslide( &$s, $file, $caption, $title ) {
		// Derive base label:
		//   - if we have an explicit hgLabel (id), use it;
		//   - else if there's a File object, use its name;
		//   - else fall back to the raw caption or a generic label.
		if ( self::$hgLabel !== null && self::$hgLabel !== '' ) {
			$label = self::$hgLabel;
		} elseif ( $file ) {
			$label = $file->getName();
		} elseif ( $caption !== '' ) {
			$label = $caption;
		} else {
			$label = 'Image';
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
		if ( self::$hgID === null ) {
			// No explicit id: each call gets its own unique group → single-member galleries.
			self::$hgID      = uniqid( 'hsg-', true );
			self::$isGallery = false;
		} else {
			// Explicit id: if it's the same as the last group, this is a gallery member;
			// if it's new, treat this as the first image in a new gallery.
			if ( self::$lastGroupID !== null && self::$lastGroupID === self::$hgID ) {
				self::$isGallery = true;   // same group as previous
			} else {
				self::$isGallery = false;  // first in this id's group
			}
		}
		// Remember this group for next call on the page.
		self::$lastGroupID = self::$hgID;

		// Prepare JS options safely using JSON (avoids breaking on quotes).
		$opts = [
			'slideshowGroup' => (string) self::$hgID,
			'captionText'    => $captionHtml, // already HTML; Highslide treats caption as HTML fragment
		];
		// Let Highslide's global hs.numberPosition decide where the index appears
		// (we set it to 'caption' in highslide.cfg.js).
		$optsJson = htmlspecialchars( FormatJson::encode( $opts ), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8' );

		$prefix = 'id="' . htmlspecialchars( (string) self::$hgID, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8' ) .
			'" onclick="return hs.expand(this, ' . $optsJson . ')" href';

		// If this is a multi-image gallery member, ensure wikigallery class is present on the <img>.
		if ( self::$isGallery ) {
			if ( preg_match( '/<img[^>]*\sclass="/i', $s ) ) {
				$s = preg_replace(
					'/(<img[^>]*\sclass=")([^"]*)"/i',
					'\\1wikigallery \\2"',
					$s,
					1
				);
			} else {
				$s = preg_replace( '/<img /i', '<img class="wikigallery" ', $s, 1 );
			}
		}

		// === NEW: tell MediaViewer (and friends) to stay out of HSG thumbs ===
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
		self::$isGallery = false;
	}
}
