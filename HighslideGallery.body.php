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

	public static function AddResources( OutputPage &$out, Skin &$skin ): void {
		// Reset per-page state
		self::$hsgId            = null;
		self::$isHsgGroupMember = false;
		self::$hsgLabel         = null;
		self::$lastHsgGroupId   = null;

		// Let ResourceLoader handle highslide.js + cfg + CSS.
		$out->addModules( 'ext.highslideGallery' );
	}

	// Parser hooks are non-abortable; return true. Use [self::class, 'method'].
	public static function AddHooks( Parser $parser ): bool {
		// -----------------------------------------------------------------
		// Tags
		// -----------------------------------------------------------------
		// Legacy tag – kept for backward compatibility
		$parser->setHook( 'hsyoutube', [ self::class, 'MakeYouTubeLink' ] );

		// Canonical HSG tag for YouTube (ytb = all things YouTube)
		$parser->setHook( 'hsgytb', [ self::class, 'MakeYouTubeLink' ] );

		// -----------------------------------------------------------------
		// Parser functions
		// -----------------------------------------------------------------
		$parser->setFunctionHook( 'hsgimg', [ self::class, 'MakeExternalImageLink' ] );
		$parser->setFunctionHook( 'hsgytb', [ self::class, 'MakeYouTubeParserFunction' ] );

		return true;
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
	 * Shared builder for YouTube links.
	 *
	 * Used by:
	 *   - <hsyoutube>…</hsyoutube> (legacy tag)
	 *   - <hsgytb>…</hsgytb>       (canonical tag)
	 *   - {{#hsgytb: …}}          (parser function, via MakeYouTubeParserFunction)
	 */
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
		$caption     = $attributes['caption'] ?? '';
		$captionEsc  = $caption !== ''
			? htmlspecialchars( $caption, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8' )
			: '';
		$dataCaption = $captionEsc !== ''
			? ' data-hsg-caption="' . $captionEsc . '"'
			: '';

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

		$query = [];
		if ( $autoplayOn ) {
			$query[] = 'autoplay=1';
		}
		$query[] = 'mute=1';
		$query[] = 'autohide=1';
		$query[] = 'playlist=' . rawurlencode( $code );
		$query[] = 'loop=1';

		$href = 'https://www.youtube.com/embed/' . rawurlencode( $code ) . '?' . implode( '&', $query );

		// Optional gallery id so YT tiles can join image galleries (slideshowGroup).
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
			$s  = '<a class="highslide link-youtube"';
			$s .= ' title="' . $titleEsc . '"';
			$s .= ' href="' . htmlspecialchars( $href, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8' ) . '"';
			$s .= ' onclick="return (window.hsgOpenYouTube || hs.htmlExpand)(this, window.videoOptions || {});"';
			$s .= $dataCaption . $dataGroup . '>';
			$s .= $titleEsc . '</a>';
		} else {
			// THUMBNAIL LINK
			$thumbUrl = 'https://img.youtube.com/vi/' . rawurlencode( $code ) . '/hqdefault.jpg';
			$style    = '';

			if ( $width > 0 ) {
				$style = ' style="max-width: ' . $width . 'px; height: auto; width: auto;"';
			}

			$s  = '<a class="highslide link-youtube hsg-ytb-thumb"';
			$s .= ' title="' . $titleEsc . '"';
			$s .= ' href="' . htmlspecialchars( $href, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8' ) . '"';
			$s .= ' onclick="return (window.hsgOpenYouTube || hs.htmlExpand)(this, window.videoOptions || {});"';
			$s .= $dataCaption . $dataGroup . '>';
			$s .= '<img class="hsimg hsg-ytb-thumb-img" src="' .
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
	 *   {{#hsgytb: https://www.youtube.com/watch?v=CODE | title=... | caption=... | autoplay }}
	 *   {{#hsgytb: CODE | title=... | caption=... | autoplay }}
	 *
	 * First non-empty param = URL/content (even if it contains '=').
	 * Remaining params:
	 *   - "key=value" → $attributes[key] = value
	 *   - "autoplay"  → $attributes['autoplay'] = true
	 */
	public static function MakeYouTubeParserFunction( Parser $parser, ...$params ) {
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

	/**
	 * External image helper behind:
	 *   - {{#hsimg: …}}   (legacy)
	 *   - {{#hsgimg: …}}  (canonical)
	 *
	 * @param Parser $parser
	 * @param string $hsgIdParam  Group identifier (Highslide gallery id)
	 * @param int    $width       Max width (px)
	 * @param string $title       Caption/title
	 * @param string $content     Image URL
	 */
	public static function MakeExternalImageLink( $parser, $hsgIdParam, $width, $title, $content ) {
		// Accept either ordered params or named params; keep old behavior.
		if ( $content === '' && $title === '' ) {
			return false;
		}
		if ( $content === '' ) {
			$content = $title;
		}

		$caption = $title ?: $content;

		$hrefEsc    = htmlspecialchars( $content, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8' );
		$captionEsc = htmlspecialchars( $caption, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8' );

		$hs    = '<a href="' . $hrefEsc . '" class="image highslide-link" title="' . $captionEsc . '">';
		$hsimg = '<img class="hsimg" src="' . $hrefEsc . '" alt="' . $captionEsc . '"';

		$w = (int)$width;
		if ( $w > 0 ) {
			// Make caller width authoritative over any theme CSS.
			$hsimg .= ' style="max-width: ' . $w . 'px !important; height: auto; width: auto;"';
		}

		if ( $hsgIdParam !== '' ) {
			// Explicit group id → multi-image gallery if reused.
			self::$hsgId    = $hsgIdParam;
			self::$hsgLabel = $hsgIdParam;
		} else {
			// No id → force a fresh per-image group in AddHighslide().
			self::$hsgId    = null;
			self::$hsgLabel = null;
		}

		$s = $hs . $hsimg . ' /></a>';
		self::AddHighslide( $s, null, $caption, null );
		return [ $s, 'isHTML' => true ];
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

		$prefix = 'id="' . htmlspecialchars( (string)self::$hsgId, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8' ) .
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
