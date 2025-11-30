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

/*
 * HighslideGallery configuration for Highslide 5.x
 * Assumes highslide-full.js has already run and created global `hs`.
 *
 * Vendor:
 *   - `hs` and its properties
 *   - `.highslide-*`, `.you-tube`, `.link-youtube`
 *   - `hsgVideoOptions` as the base options object
 *
 * HSG:
 *   - All `hsg-*` classes (e.g. `hsg-frame`)
 *   - Any additional helpers or overlays we register here
 */

// Bridge ResourceLoader's local `hs` into the global scope so inline handlers work.
if ( typeof window !== 'undefined' && typeof hs !== 'undefined' ) {
	window.hs = window.hs || hs;
}

// -------------------------------------------------------------------------
// Core Highslide behaviour
// -------------------------------------------------------------------------

hs.graphicsDir = mw.config.get( 'wgExtensionAssetsPath' ) +
	'/HighslideGallery/modules/graphics/';

hs.align				= 'center';
hs.dimmingOpacity		= 0.75;
hs.outlineType			= null; // CSS-driven frame
// HSG-specific wrapper class; `floating-caption` is vendor CSS.
hs.wrapperClassName		= 'hsg-frame floating-caption';
hs.marginTop			= 60;
hs.marginBottom			= 60;
hs.marginLeft			= 60;
hs.marginRight			= 60;

/* No fades / transitions - snap in/out */
hs.fadeInOut			= false;
hs.expandDuration		= 0;
hs.restoreDuration		= 0;
hs.transitions			= [];

/* Alternative: fades / transitions (kept as a commented preset) */
//hs.fadeInOut			= true;
//hs.expandDuration		= 0;
//hs.restoreDuration		= 0;
//hs.transitions			= [ 'expand', 'crossfade' ];

hs.allowSizeReduction	= true;
hs.showCredits			= false;
hs.outlineWhileAnimating= true;

// 2025-11-27 HSG: enable vendor zoom toggle (fit <-> 1:1) support
hs.fullExpandToggle = true;

// Allow custom thumbnailId (used for inline text links with hidden thumb proxies)
if ( Array.isArray( hs.overrides ) && hs.overrides.indexOf( 'thumbnailId' ) === -1 ) {
	hs.overrides.push( 'thumbnailId' );
}

// Do not close when clicking the image itself; use controls/dimmer instead.
hs.closeOnClick			= false;

// Show image index in the caption area (vendor feature, configured here).
hs.numberPosition		= 'caption';
hs.lang.number			= 'Member %1 of %2'

// Cancel Highslide's default "click image to close".
if ( hs.Expander && hs.Expander.prototype ) {
	hs.Expander.prototype.onImageClick = function () {
		// Returning false stops exp.close() in Highslide's mouse handler.
		return false;
	};

	// 2025-11-27 HSG: disable zoom control for non-image content (e.g., videos)
	if ( !hs._hsgPatchedFullForNonImages ) {
		hs._hsgPatchedFullForNonImages = true;
		var _hsgOrigAfterExpand = hs.Expander.prototype.afterExpand;
		hs.Expander.prototype.afterExpand = function () {
			if ( _hsgOrigAfterExpand ) {
				_hsgOrigAfterExpand.apply( this, arguments );
			}

			if ( !this.slideshow || !this.slideshow.enable || !this.slideshow.disable ) {
				return;
			}

			if ( this.isImage ) {
				this.slideshow.enable( 'full-expand' );
				if ( typeof this.slideshow.updateZoomLabel === 'function' ) {
					this.slideshow.updateZoomLabel( !!this._hsgZoomed );
				}
			} else {
				this.slideshow.disable( 'full-expand' );
				if ( typeof this.slideshow.updateZoomLabel === 'function' ) {
					this.slideshow.updateZoomLabel( false );
				}
			}
		};
	}
}

// -------------------------------------------------------------------------
// Language tweaks
// -------------------------------------------------------------------------

hs.lang = hs.lang || {};

// Default "restore" tooltip was misleading ("Click to close image...").
// Our UX: image click does nothing; use controls, keys, or dimmer to close.
hs.lang.restoreTitle =
	'Click/drag to move. Use arrow keys or on-frame controls to navigate. ' +
	'Press Esc, or click the close button or margins to exit.';

// -------------------------------------------------------------------------
// Overlays & controls
// -------------------------------------------------------------------------

/*
 * Control layout presets so swapping positions is a simple config change
 * instead of manual CSS tweaks.
 *
 * Presets:
 *   - classic: controls + thumbstrip anchored to the bottom of the viewport
 *   - stacked-top: thumbstrip followed by controls above the expander
 */
var hsgControlLayoutPresets = {
	classic: {
		fixedControls: false,
		overlayOptions: {
			className: 'text-controls',
			position: 'bottom center',
			relativeTo: 'viewport',
			offsetY: -3
		},
		thumbstrip: {
			position: 'bottom center',
			mode: 'horizontal',
			relativeTo: 'viewport',
			offsetY: 0
		}
	},
	'stacked-top': {
		fixedControls: false,
		overlayOptions: {
			className: 'text-controls',
			position: 'top center',
			relativeTo: 'expander',
			offsetY: -27
		},
		thumbstrip: {
			position: 'top center',
			mode: 'horizontal',
			relativeTo: 'expander',
			offsetY: -102
		}
	}
};

function hsgSelectControlLayoutPreset() {
	var presetName = 'classic';

	if ( typeof mw !== 'undefined' && mw.config && typeof mw.config.get === 'function' ) {
		var cfgPreset = mw.config.get( 'wgHSGControlsPreset' );
		if ( typeof cfgPreset === 'string' && cfgPreset.trim() !== '' ) {
			presetName = cfgPreset.trim().toLowerCase().replace( /[\s_]+/g, '-' );
		}
	}

	if ( !Object.prototype.hasOwnProperty.call( hsgControlLayoutPresets, presetName ) ) {
		presetName = 'classic';
	}

	var preset = hsgControlLayoutPresets[ presetName ];
	var overlayOptions = Object.assign( {}, preset.overlayOptions || {} );
	var thumbstripOptions = preset.thumbstrip
		? Object.assign( {}, preset.thumbstrip )
		: null;

	overlayOptions.className = overlayOptions.className || 'text-controls';
	if ( thumbstripOptions ) {
		thumbstripOptions.mode = thumbstripOptions.mode || 'horizontal';
	}

	return {
		name: presetName,
		overlayOptions: overlayOptions,
		thumbstrip: thumbstripOptions,
		fixedControls: preset.fixedControls
	};
}

/*
 * Global close overlay (images + HTML/iframe).
 * HSG-specific CSS hook: `.controls.close` is styled in highslide.override.css.
 */
hs.registerOverlay( {
	html: '<div class="controls close"><a href="javascript:;" onclick="return hs.close(this)"></a></div>',
	position: 'top right',
	relativeTo: 'viewport',
	fade: false,
	useOnHtml: true
} );

/*
 * Slideshow for images (controls + thumbstrip).
 * Uses vendor `.highslide-controls` and thumbstrip styles.
 */
function hsgConfigureImageSlideshow() {
	if ( hs._hsgConfiguredSlideshow ) {
		return;
	}
	hs._hsgConfiguredSlideshow = true;

	var hsgControlsPreset = hsgSelectControlLayoutPreset();

	hs.addSlideshow( {
		repeat: false,	// prev/next looping
		useControls: true,
		fixedControls: (typeof hsgControlsPreset.fixedControls !== 'undefined')
			? hsgControlsPreset.fixedControls
			: false,
		overlayOptions: hsgControlsPreset.overlayOptions,
		thumbstrip: hsgControlsPreset.thumbstrip
	} );
}

// Configure immediately now that wgHSGControlsPreset is provided before module load.
hsgConfigureImageSlideshow();

/*
 * Prevent slideshow "Play" and manual Next from closing the expander
 * when there is no next/previous slide. Instead, stop autoplay and stay
 * on the current image.
 */
if (typeof hs.transit === 'function' && !hs._hsgPatchedTransit) {
	hs._hsgPatchedTransit = true;
	hs._hsgOrigTransit = hs.transit;

	hs.transit = function (adj, exp) {
		// No adjacent anchor → end of gallery in this direction.
		if (!adj) {
			// If autoplay is running, pause it so controls reflect reality.
			if (exp && exp.slideshow && typeof exp.slideshow.pause === 'function') {
				exp.slideshow.pause();
			}
			// Keep the current expander open.
			return false;
		}

		// Normal case: delegate to original behaviour.
		return hs._hsgOrigTransit(adj, exp);
	};
}

/*
 * Slideshow for YouTube (group "videos", no extra controls).
 * Group name is a configuration constant shared with `hsgVideoOptions.slideshowGroup`.
 */
hs.addSlideshow( {
	slideshowGroup: 'videos',
	repeat: false,
	useControls: false,
	fixedControls: false
} );

// Prefer image content for thumbstrip items (handles inline text links with hidden img proxies).
hs.stripItemFormatter = function ( anchor ) {
	if ( anchor && anchor.querySelector ) {
		var img = anchor.querySelector( 'img' );
		if ( img && img.src ) {
			return '<img src="' + img.src + '" alt="" />';
		}
	}
	return anchor ? anchor.innerHTML : '';
};

// -------------------------------------------------------------------------
// Video options (used by hs.htmlExpand via MakeYouTubeLink / hsgytb)
// -------------------------------------------------------------------------

/*
 * `hsgVideoOptions` is kept as the vendor-style base object. HSG code
 * may later wrap or clone this (e.g. via `hsgGetVideoOptions`) but
 * the canonical global name remains `hsgVideoOptions`.
 */
var hsgVideoOptions = {
	slideshowGroup: 'videos',
	objectType: 'iframe',
	width: 720,  // used as a baseline; auto-fit will override per click
	height: 480,
	// `you-tube` is a vendor skin hook; `hsg-frame` is HSG-specific.
	wrapperClassName: 'you-tube hsg-frame',
	allowSizeReduction: false,
	preserveContent: false,
	outlineType: null, // avoid drop-shadow table on iframe popups
	numberPosition: null
};

window.hsgVideoOptions = hsgVideoOptions;

/*
 * Per-click helper for YouTube expanders so they can join image galleries.
 * Reads data-hsgid (slideshowGroup) and data-hsg-caption (captionText) off
 * the anchor and merges them into a clone of hsgVideoOptions. Also sizes the
 * iframe to a best-fit 16:9 box within the current viewport and HS margins.
 */
window.hsgOpenYouTube = function ( anchor, baseOptions ) {
	if ( typeof hs === 'undefined' || !anchor ) {
		return true;
	}

	var opts = {};
	var source = (baseOptions && typeof baseOptions === 'object')
		? baseOptions
		: window.hsgVideoOptions;

	if ( source ) {
		for ( var k in source ) {
			if ( Object.prototype.hasOwnProperty.call( source, k ) ) {
				opts[k] = source[k];
			}
		}
	}

	var caption = anchor.getAttribute( 'data-hsg-caption' ) || anchor.getAttribute( 'title' );
	if ( caption ) {
		opts.captionText = caption;
	}

	var hsgId = anchor.getAttribute( 'data-hsgid' ) || anchor.getAttribute( 'data-hsg-group' );
	if ( hsgId ) {
		opts.slideshowGroup = hsgId;
	}

	// Align numbering position with images when grouping is enabled.
	if ( !opts.numberPosition ) {
		opts.numberPosition = hs.numberPosition || 'caption';
	}

	// Viewport-aware sizing (16:9 best fit within HS margins).
	var page = hs.getPageSize ? hs.getPageSize() : { width: 0, height: 0 };
	var marginW = (hs.marginLeft || 0) + (hs.marginRight || 0);
	var marginH = (hs.marginTop || 0) + (hs.marginBottom || 0);
	var maxW = Math.max(320, page.width - marginW - 20);
	var maxH = Math.max(180, page.height - marginH - 20);

	var aspect = (opts.width && opts.height) ? (opts.width / opts.height) : (16 / 9);
	var fitW = Math.min(maxW, Math.floor(maxH * aspect));
	var fitH = Math.min(maxH, Math.floor(fitW / aspect));

	opts.width = fitW;
	opts.height = fitH;

	return hs.htmlExpand( anchor, opts );
};

// -------------------------------------------------------------------------
// DOM cleanup: prevent MediaWiki thumb float on HSG thumbs (e.g. when templates
// are used inside lists and MW wraps the first item in a floated thumb).
// -------------------------------------------------------------------------

function hsgFixThumbFloat( root ) {
	var scope = root || document;
	var anchors = scope.querySelectorAll( '.thumb.tright .hsg-thumb, .thumb.tleft .hsg-thumb' );

	anchors.forEach( function ( a ) {
		var container = a.closest( '.thumb' );
		if ( !container || container.classList.contains( 'hsg-thumb-normalized' ) ) {
			return;
		}

		container.classList.add( 'hsg-thumb-normalized' );
		container.classList.remove( 'tright', 'tleft' );
	} );
}

	// Normalize list-based galleries: when an empty gallery block sits inside a list
	// item and real gallery blocks follow, move those gallery blocks into the list
	// item so bullets align and items render side by side.
	function hsgFixListGalleries( root ) {
		var scope = root || document;
		// 2025-11-30 HSG: include all list types (ul/ol/dl) and mixed list nesting, including misplaced direct list children
		var placeholders = scope.querySelectorAll( 'li .gallery.text-break, dd .gallery.text-break, ul > .gallery.text-break, ol > .gallery.text-break, dl > .gallery.text-break' );

		var isList = function ( node ) {
			return !!node && (node.tagName === 'UL' || node.tagName === 'OL' || node.tagName === 'DL');
		};

		var isListItem = function ( node ) {
			return !!node && (node.tagName === 'LI' || node.tagName === 'DD');
		};

		var isGalleryBlock = function ( node ) {
			return !!node && node.classList && node.classList.contains( 'gallery' ) && node.classList.contains( 'text-break' );
		};

		var isThumbBlock = function ( node ) {
			return !!node && node.classList && node.classList.contains( 'thumb' );
		};

		// 2025-11-30 HSG: climb ancestor lists to also collect siblings after outer UL/OL/DL
		var getAncestorLists = function ( listNode ) {
			var lists = [];
			var cur = listNode;
			while ( isList( cur ) ) {
				lists.push( cur );
				var liParent = cur.parentElement;
				if ( !isListItem( liParent ) ) {
					break;
				}
				cur = liParent.parentElement;
			}
			return lists;
		};

		var isEffectivelyEmpty = function ( node ) {
			if ( !node ) return true;
			for ( var i = 0; i < node.childNodes.length; i++ ) {
				var c = node.childNodes[ i ];
				if ( c.nodeType === 1 ) return false;
				if ( c.nodeType === 3 && c.textContent && c.textContent.trim() ) return false;
			}
			return true;
		};

		// Generic collector: walk sibling chain starting at `start`, adding any gallery/thumb.
		var collectBlocks = function ( start ) {
			var collected = [];
			var removeEmptyItems = [];
			var next = start;
			while ( next ) {
				if ( isGalleryBlock( next ) || isThumbBlock( next ) ) {
					collected.push( { node: next, removeParent: false } );
					next = next.nextElementSibling;
					continue;
				}
				if ( isListItem( next ) ) {
					var inner = next.querySelectorAll( ':scope > .gallery.text-break, :scope > .thumb' );
					if ( inner.length ) {
						inner.forEach( function ( node ) {
							collected.push( { node: node, removeParent: false } );
						} );
						// If the list item only contains these nodes (and whitespace), mark for removal after moves.
						removeEmptyItems.push( next );
						next = next.nextElementSibling;
						continue;
					}
				}
				break; // stop on first non-gallery/non-thumb case
			}
			return { collected: collected, removeEmptyItems: removeEmptyItems };
		};

		placeholders.forEach( function ( placeholder ) {
			// Only care about empty placeholders (no thumb inside).
			if ( placeholder.querySelector( '.thumb' ) ) {
				return;
			}

			var item = placeholder.parentElement;
			if ( !isListItem( item ) ) {
				// 2025-11-30 HSG: if placeholder is a direct child of a list, bind to the next list item
				if ( isList( item ) ) {
					var probe = placeholder.nextElementSibling;
					while ( probe && !isListItem( probe ) ) {
						probe = probe.nextElementSibling;
					}
					item = probe;
				} else {
					item = null;
				}
			}

			if ( !isListItem( item ) ) {
				return;
			}

			var list = item.parentElement;
			if ( !isList( list ) ) {
				return;
			}

			// 2025-11-30 HSG: collect from multiple paths to cover mixed lists and misplaced placeholders.
			var collected = [];
			var seen = new WeakSet();
			var toPrune = [];
			var addAll = function ( result ) {
				if ( !result || !result.collected ) return;
				result.collected.forEach( function ( entry ) {
					var n = entry.node;
					if ( n && !seen.has( n ) ) {
						seen.add( n );
						collected.push( n );
					}
				} );
				if ( result.removeEmptyItems && result.removeEmptyItems.length ) {
					result.removeEmptyItems.forEach( function ( itm ) { toPrune.push( itm ); } );
				}
			};

			// 1) siblings after the placeholder itself
			addAll( collectBlocks( placeholder.nextElementSibling ) );
			// 2) siblings after the list item containing the placeholder
			addAll( collectBlocks( item.nextElementSibling ) );
			// 3) siblings immediately after the list container
			addAll( collectBlocks( list.nextElementSibling ) );
			// 4) siblings immediately after ancestor lists (covers galleries placed after outer OL/UL/DL)
			var ancestorLists = getAncestorLists( list );
			ancestorLists.forEach( function ( lst ) {
				addAll( collectBlocks( lst.nextElementSibling ) );
			} );

			if ( collected.length === 0 ) {
				// Still remove empty placeholder to avoid stray styling.
				placeholder.remove();
				return;
			}

			// Remove the empty placeholder gallery.
			placeholder.remove();
			// Remove any now-empty list items we marked (e.g., when galleries were the only children).
			toPrune.forEach( function ( itm ) {
				if ( itm && isEffectivelyEmpty( itm ) && itm.parentNode ) {
					itm.parentNode.removeChild( itm );
				}
			} );

			// Wrapper to keep list marker intact while laying out children horizontally.
			var wrap = document.createElement( 'div' );
			wrap.className = 'hsg-list-gallery-wrap';

			collected.forEach( function ( node ) {
				wrap.appendChild( node );
			} );

			item.appendChild( wrap );
		} );
	}

if ( typeof mw !== 'undefined' && mw.hook && mw.hook( 'wikipage.content' ) ) {
	mw.hook( 'wikipage.content' ).add( function ( $content ) {
		hsgFixThumbFloat( $content && $content[0] ? $content[0] : document );
		hsgFixListGalleries( $content && $content[0] ? $content[0] : document );
	} );
} else {
	document.addEventListener( 'DOMContentLoaded', function () {
		hsgFixThumbFloat( document );
		hsgFixListGalleries( document );
	} );
}
