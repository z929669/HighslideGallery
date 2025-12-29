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

window.__HSG_BUILD__ = '20251223.1'; // Build identifier for debugging

/* Bridge ResourceLoader's local `hs` into the global scope so inline handlers work */
if ( typeof window !== 'undefined' && typeof hs !== 'undefined' ) {
	window.hs = window.hs || hs;
}

/* -------------------------------------------------------------------------
 * Core Highslide behaviour
 * ------------------------------------------------------------------------- */

hs.graphicsDir = `${mw.config.get( 'wgExtensionAssetsPath' ) 
}/HighslideGallery/modules/graphics/`;

hs.showCredits			= false;

hs.align				= 'center';
hs.dimmingOpacity		= 0.75;
hs.outlineType			= null; // CSS-driven frame
hs.wrapperClassName		= 'hsg-frame floating-caption'; // `floating-caption` is vendor CSS

hs.marginTop			= 40;
hs.marginBottom			= 40;
hs.marginLeft			= 30;
hs.marginRight			= 30;

//hs.transitions		= [ 'expand', 'crossfade' ]; // overlay zoom in/out on open/close only; 'crossfade' image transitions; thumbstrip/controls static
hs.transitions			= ['fade', 'crossfade']; // overlay fade in/out on open/close only; 'crossfade' image transitions; thumbstrip/controls static
hs.transitionDuration	= 0; // Default: 250; duration of ANY enabled transitions/animations of images, captions, or overlay
//hs.expandDuration		= 0; // Default: 250; duration of image transitions zoom in/out
//hs.restoreDuration	= 0; // Default: 250; duration of zoom out on closing the overlay

hs.fullExpandToggle		= true; // Vendor zoom toggle (fit <-> 1:1) support
hs.closeOnClick			= false; // Do not close when clicking the image itself; use controls/dimmer instead

/* Show image index in the caption area (vendor feature, configured here) */
hs.numberPosition		= 'caption';
hs.lang.number			= 'Member %1 of %2';

/* Allow custom thumbnailId (used for inline text links with hidden thumb proxies) */
if ( Array.isArray( hs.overrides ) && hs.overrides.indexOf( 'thumbnailId' ) === -1 ) {
	hs.overrides.push( 'thumbnailId' );
}

/* Add controls preset as a sanitized class so CSS can target layout presets.
 * e.g. wgHSGControlsPreset === 'stacked-top' -> hsg-preset-stacked-top
 */
var HSG_CONTROLS_PRESET = ( typeof mw !== 'undefined' && typeof mw.config !== 'undefined' && typeof mw.config.get === 'function' )
	? ( mw.config.get( 'wgHSGControlsPreset' ) || 'classic' )
	: 'classic';
HSG_CONTROLS_PRESET = String( HSG_CONTROLS_PRESET ).toLowerCase().replace(/[^a-z0-9_-]/g, '-');
var HSG_PRESET_CLASS = `hsg-preset-${  HSG_CONTROLS_PRESET}`;
if ( typeof hs.wrapperClassName === 'string' ) {
	hs.wrapperClassName = `${hs.wrapperClassName  } ${  HSG_PRESET_CLASS}`;
} else {
	hs.wrapperClassName = `hsg-frame floating-caption ${  HSG_PRESET_CLASS}`;
}
/* Ensure any Highslide-created viewport containers also get the preset class */
if ( typeof MutationObserver !== 'undefined' ) {
	(function () {
		const mo = new MutationObserver( ( mutations ) => {
			mutations.forEach( ( m ) => {
				m.addedNodes && m.addedNodes.forEach && m.addedNodes.forEach( ( node ) => {
					if ( node && node.nodeType === 1 ) {
						if ( node.classList && node.classList.contains( 'highslide-viewport' ) ) {
							node.classList.add( HSG_PRESET_CLASS );
						}
						/* Also catch wrapper elements created under the viewport */
						const viewports = node.querySelectorAll && node.querySelectorAll( '.highslide-viewport' );
						if ( viewports && viewports.length ) {
							Array.prototype.forEach.call( viewports, ( vp ) => { vp.classList.add( HSG_PRESET_CLASS ); } );
						}
					}
				} );
			} );
		} );
		mo.observe( document.documentElement || document.body, { childList: true, subtree: true } );
	}() );
}

/* Basic coarse-pointer detection for touch support */
var HSG_IS_COARSE_POINTER = ( function () {
	if ( typeof window === 'undefined' ) {
		return false;
	}
	if ( window.matchMedia ) {
		try {
			if ( window.matchMedia( '(pointer: coarse)' ).matches ) {
				return true;
			}
		} catch ( e ) {}
	}
	return 'ontouchstart' in window;
}() );

/* Map single-finger touch drag to Highslide's mouse drag (move or pan when zoomed)
 */
function hsgAttachTouchPan( exp ) {
	if ( !HSG_IS_COARSE_POINTER || !exp || exp._hsgTouchPanAttached ) {
		return;
	}
	const el = exp.content || exp.wrapper;
	if ( !el ) {
		return;
	}

	const sendMouse = function ( type, touch, target ) {
		const evt = new MouseEvent( type, {
			bubbles: true,
			cancelable: true,
			clientX: touch.clientX,
			clientY: touch.clientY,
			screenX: touch.screenX,
			screenY: touch.screenY,
			button: 0
		} );
		( target || el ).dispatchEvent( evt );
	};

	let touchId = null;
	let move, end;
	const start = function ( e ) {
		if ( !e.touches || e.touches.length !== 1 || !e.touches[0] ) {
			return;
		}
		e.preventDefault();
		touchId = e.touches[0].identifier;
		sendMouse( 'mousedown', e.touches[0], el );
		move = function ( ev ) {
			if ( !ev.touches || ev.touches.length === 0 ) {
				return;
			}
			for ( let i = 0; i < ev.touches.length; i++ ) {
				const t = ev.touches[i];
				if ( t && t.identifier === touchId ) {
					ev.preventDefault();
					sendMouse( 'mousemove', t, document );
					return;
				}
			}
		};
		end = function ( evEnd ) {
			let t2 = null;
			if ( evEnd.changedTouches && evEnd.changedTouches.length ) {
				for ( let j = 0; j < evEnd.changedTouches.length; j++ ) {
					if ( evEnd.changedTouches[j].identifier === touchId ) {
						t2 = evEnd.changedTouches[j];
						break;
					}
				}
			}
			if ( t2 ) {
				sendMouse( 'mouseup', t2, document );
			}
			document.removeEventListener( 'touchmove', move );
			document.removeEventListener( 'touchend', end );
			document.removeEventListener( 'touchcancel', end );
			touchId = null;
		};

		document.addEventListener( 'touchmove', move, { passive: false } );
		document.addEventListener( 'touchend', end );
		document.addEventListener( 'touchcancel', end );
	};

	el.addEventListener( 'touchstart', start, { passive: true } );
	exp._hsgTouchPanAttached = true;
}

/* Cancel Highslide's default "click image to close" */
if ( hs.Expander && hs.Expander.prototype ) {
	hs.Expander.prototype.onImageClick = function () {
		return false; // Returning false stops exp.close() in Highslide's mouse handler
	};

	/* Disable zoom control for non-image content (e.g., videos) */
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
				hsgAttachTouchPan( this );
			} else {
				this.slideshow.disable( 'full-expand' );
				if ( typeof this.slideshow.updateZoomLabel === 'function' ) {
					this.slideshow.updateZoomLabel( false );
				}
			}
		};
	}
}

/* -------------------------------------------------------------------------
 * Minimal viewport-relative overlay alignment (horizontal only)
 * ------------------------------------------------------------------------- */

if ( hs.Expander && hs.Expander.prototype && !hs.Expander.prototype._hsgPatchedOverlayCenter ) {
	hs.Expander.prototype._hsgPatchedOverlayCenter = true;
	var _hsgOrigPositionOverlay = hs.Expander.prototype.positionOverlay;
	hs.Expander.prototype.positionOverlay = function ( overlay ) {
		if ( _hsgOrigPositionOverlay ) {
			_hsgOrigPositionOverlay.call( this, overlay );
		}
		if ( !overlay || overlay.relativeTo !== 'viewport' ) {
			return;
		}
		const vv = window.visualViewport;
		if ( !vv ) {
			return;
		}
		const pos = overlay.position || '';
		const offX = overlay.offsetX || 0;

		if ( /center$/.test( pos ) ) {
			const leftPx = vv.pageLeft + ( vv.width - overlay.offsetWidth ) / 2 + offX;
			overlay.style.left = `${leftPx  }px`;
			overlay.style.right = 'auto';
			overlay.style.marginLeft = '0';
		} else if ( /right$/.test( pos ) ) {
			const leftRight = vv.pageLeft + vv.width - overlay.offsetWidth - offX;
			overlay.style.left = `${leftRight  }px`;
			overlay.style.right = 'auto';
			overlay.style.marginLeft = '0';
		} else if ( /left$/.test( pos ) ) {
			overlay.style.left = `${vv.pageLeft + offX   }px`;
			overlay.style.right = 'auto';
			overlay.style.marginLeft = '0';
		}
	};
}

/* -------------------------------------------------------------------------
 * Language tweaks
 * ------------------------------------------------------------------------- */

hs.lang = hs.lang || {};

function hsgMsg( key, fallback ) {
	if ( typeof mw !== 'undefined' && typeof mw.message === 'function' ) {
		const m = mw.message( key );
		if ( m && typeof m.exists === 'function' && m.exists() ) {
			const txt = m.text();
			if ( typeof txt === 'string' && txt.trim() !== '' ) {
				return txt;
			}
		}
	}
	return fallback;
}

/* Fine tuning for hovertext and control tooltips. */
var hsgRestoreTitleDefault =
	'Click/drag to move image. Use controls or keyboard arrow keys to navigate, f key ' +
	'to zoom in/out, spacebar to play/pause. Press Esc, or click close/margins to exit.';

hs.lang.loadingText		= hsgMsg( 'hsg-loading-text', hs.lang.loadingText );
hs.lang.loadingTitle	= hsgMsg( 'hsg-loading-title', hs.lang.loadingTitle );
hs.lang.focusTitle		= hsgMsg( 'hsg-focus-title', hs.lang.focusTitle );
hs.lang.fullExpandTitle	= hsgMsg( 'hsg-full-expand-title', hs.lang.fullExpandTitle );
hs.lang.previousTitle	= hsgMsg( 'hsg-previous-title', hs.lang.previousTitle );
hs.lang.nextTitle		= hsgMsg( 'hsg-next-title', hs.lang.nextTitle );
hs.lang.moveTitle		= hsgMsg( 'hsg-move-title', hs.lang.moveTitle );
hs.lang.closeTitle		= hsgMsg( 'hsg-close-title', hs.lang.closeTitle );
hs.lang.playTitle		= hsgMsg( 'hsg-play-title', hs.lang.playTitle );
hs.lang.pauseTitle		= hsgMsg( 'hsg-pause-title', hs.lang.pauseTitle );
hs.lang.number			= hsgMsg( 'hsg-number', hs.lang.number || 'Member %1 of %2' );
hs.lang.restoreTitle	= hsgMsg( 'hsg-hover-instructions', hsgRestoreTitleDefault );

/* -------------------------------------------------------------------------
 * Overlays & controls
 * -------------------------------------------------------------------------
 *
 * Control layout presets so swapping positions is a simple config change
 * instead of manual CSS tweaks.
 *
 * Presets:
 * - classic: controls + thumbstrip anchored to the bottom of the viewport
 * - stacked-top: thumbstrip followed by controls above the expander
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
			offsetY: -36
		},
		thumbstrip: {
			position: 'top center',
			mode: 'horizontal',
			relativeTo: 'expander',
			offsetY: -111
		}
	}
};

function hsgSelectControlLayoutPreset() {
	let presetName = HSG_CONTROLS_PRESET || 'classic';

	if ( typeof mw !== 'undefined' && mw.config && typeof mw.config.get === 'function' ) {
		const cfgPreset = mw.config.get( 'wgHSGControlsPreset' );
		if ( typeof cfgPreset === 'string' && cfgPreset.trim() !== '' ) {
			presetName = cfgPreset.trim().toLowerCase().replace( /[\s_]+/g, '-' );
		}
	}

	if ( !Object.prototype.hasOwnProperty.call( hsgControlLayoutPresets, presetName ) ) {
		presetName = 'classic';
	}

	const preset = hsgControlLayoutPresets[ presetName ];
	const overlayOptions = Object.assign( {}, preset.overlayOptions || {} );
	const thumbstripOptions = preset.thumbstrip
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

/* Global close overlay (images + HTML/iframe).
 * HSG-specific CSS hook: `.controls.close` is styled in highslide.override.css.
 */
hs.registerOverlay( {
	html: '<div class="controls close"><a href="javascript:;" onclick="return hs.close(this)"></a></div>',
	position: 'top right',
	relativeTo: 'viewport',
	fade: false,
	useOnHtml: true
} );

/* Slideshow for images (controls + thumbstrip).
 * Uses vendor `.highslide-controls` and thumbstrip styles.
 */
function hsgConfigureImageSlideshow() {
	if ( hs._hsgConfiguredSlideshow ) {
		return;
	}
	hs._hsgConfiguredSlideshow = true;

	const hsgControlsPreset = hsgSelectControlLayoutPreset();

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

/* Configure immediately now that wgHSGControlsPreset is provided before module load. */
hsgConfigureImageSlideshow();

/* -------------------------------------------------------------------------
 * Behaviour patches (overlay navigation and controls)
 * -------------------------------------------------------------------------
 *
 * Prevent slideshow "Play" and manual Next from closing the expander when there is no next/previous
 * slide. Instead, stop autoplay and stay on the current image.
 */
if (typeof hs.transit === 'function' && !hs._hsgPatchedTransit) {
	hs._hsgPatchedTransit = true;
	hs._hsgOrigTransit = hs.transit;

	hs.transit = function (adj, exp) {
		/* No adjacent anchor means end of gallery in this direction */
		if (!adj) {
			/* If autoplay is running, pause it so controls reflect reality */
			if (exp && exp.slideshow && typeof exp.slideshow.pause === 'function') {
				exp.slideshow.pause();
			}
			/* Keep the current expander open */
			return false;
		}

		/* Normal case: delegate to original behaviour */
		return hs._hsgOrigTransit(adj, exp);
	};
}

/* Keep the play control visible during autoplay so the control bar layout stays aligned. */
if (hs.Slideshow && hs.Slideshow.prototype && !hs.Slideshow.prototype._hsgPatchedPlayVisibility) {
	hs.Slideshow.prototype._hsgPatchedPlayVisibility = true;
	hs.Slideshow.prototype._hsgOrigPlay = hs.Slideshow.prototype.play;
	hs.Slideshow.prototype._hsgOrigPause = hs.Slideshow.prototype.pause;

	hs.Slideshow.prototype.play = function ( wait ) {
		const res = typeof this._hsgOrigPlay === 'function'
			? this._hsgOrigPlay.call( this, wait )
			: undefined;

		if ( this.btn && this.btn.play ) {
			this.btn.play.style.display = '';
		}
		if ( this.btn && this.btn.pause ) {
			this.btn.pause.style.display = 'none';
		}

		return res;
	};

	hs.Slideshow.prototype.pause = function () {
		const res = typeof this._hsgOrigPause === 'function'
			? this._hsgOrigPause.call( this )
			: undefined;

		if ( this.btn && this.btn.play ) {
			this.btn.play.style.display = '';
		}
		if ( this.btn && this.btn.pause ) {
			this.btn.pause.style.display = 'none';
		}

		return res;
	};
}

/* Prevent keyboard navigation from disabling itself when hitting gallery boundaries. */
if (typeof hs.previousOrNext === 'function' && !hs._hsgPatchedPreviousOrNext) {
	hs._hsgPatchedPreviousOrNext = true;
	hs._hsgOrigPreviousOrNext = hs.previousOrNext;

	hs.previousOrNext = function ( el, op ) {
		const exp = hs.getExpander( el );
		if ( !exp ) {
			return false;
		}

		const adj = exp.getAdjacentAnchor( op );
		const result = hs.transit( adj, exp );

		if ( !adj ) {
			/* Re-attach the key listener so arrow/space handling stays active */
			hs.addEventListener( document, window.opera ? 'keypress' : 'keydown', hs.keyHandler );
		}

		return result;
	};
}

/* Slideshow for YouTube (group "videos", no extra controls)
 * Group name is a configuration constant shared with `hsgVideoOptions.slideshowGroup`
 */
hs.addSlideshow( {
	slideshowGroup: 'videos',
	repeat: false,
	useControls: false,
	fixedControls: false
} );

/* Prefer image content for thumbstrip items (handles inline text links with hidden img proxies). */
hs.stripItemFormatter = function ( anchor ) {
	if ( anchor && anchor.querySelector ) {
		const img = anchor.querySelector( 'img' );
		if ( img && img.src ) {
			return `<img src="${  img.src  }" alt="" />`;
		}
	}
	return anchor ? anchor.innerHTML : '';
};

/* -------------------------------------------------------------------------
 * Video options (used by hs.htmlExpand via MakeYouTubeLink / hsgytb)
 * ------------------------------------------------------------------------- */

/* `hsgVideoOptions` is kept as the vendor-style base object. HSG code may later wrap or clone this
 * (e.g. via `hsgGetVideoOptions`) but the canonical global name remains `hsgVideoOptions`.
 */
var hsgVideoOptions = {
	slideshowGroup: 'videos',
	objectType: 'iframe',
	width: 720, // used as a baseline; auto-fit will override per click
	height: 480,
	wrapperClassName: 'you-tube hsg-frame',
	allowSizeReduction: false,
	preserveContent: false,
	outlineType: null, // avoid drop-shadow table on iframe popups
	numberPosition: null
};

window.hsgVideoOptions = hsgVideoOptions;

/* Per-click helper for YouTube expanders so they can join image galleries. Reads data-hsgid (slideshowGroup)
 * and data-hsg-caption (captionText) off the anchor and merges them into a clone of hsgVideoOptions. Also sizes
 * the iframe to a best-fit 16:9 box within the current viewport and HS margins.
 */
window.hsgOpenYouTube = function ( anchor, baseOptions ) {
	if ( typeof hs === 'undefined' || !anchor ) {
		return true;
	}

	const opts = {};
	const source = (baseOptions && typeof baseOptions === 'object')
		? baseOptions
		: window.hsgVideoOptions;

	if ( source ) {
		for ( const k in source ) {
			if ( Object.prototype.hasOwnProperty.call( source, k ) ) {
				opts[k] = source[k];
			}
		}
	}

	const caption = anchor.getAttribute( 'data-hsg-html' ) || anchor.getAttribute( 'data-hsg-caption' ) || anchor.getAttribute( 'title' );
	if ( caption ) {
		opts.captionText = caption;
	}

	const hsgId = anchor.getAttribute( 'data-hsgid' ) || anchor.getAttribute( 'data-hsg-group' );
	if ( hsgId ) {
		opts.slideshowGroup = hsgId;
	}

	/* Align numbering position with images when grouping is enabled */
	if ( !opts.numberPosition ) {
		opts.numberPosition = hs.numberPosition || 'caption';
	}

	/* Viewport-aware sizing (16:9 best fit within HS margins) */
	const page = hs.getPageSize ? hs.getPageSize() : { width: 0, height: 0 };
	const marginW = (hs.marginLeft || 0) + (hs.marginRight || 0);
	const marginH = (hs.marginTop || 0) + (hs.marginBottom || 0);
	const maxW = Math.max(320, page.width - marginW - 20);
	const maxH = Math.max(180, page.height - marginH - 20);

	const aspect = (opts.width && opts.height) ? (opts.width / opts.height) : (16 / 9);
	const fitW = Math.min(maxW, Math.floor(maxH * aspect));
	const fitH = Math.min(maxH, Math.floor(fitW / aspect));

	opts.width = fitW;
	opts.height = fitH;

	return hs.htmlExpand( anchor, opts );
};

/* -------------------------------------------------------------------------
 * DOM cleanup: prevent MediaWiki thumb float on HSG thumbs (e.g. when templates
 * are used inside lists and MW wraps the first item in a floated thumb).
 * ------------------------------------------------------------------------- */

/* Shared selectors and helpers for thumb/list normalization. */
var HSG_LIST_ITEM_SELECTOR = 'ul > li, ol > li, dl > dd';
var HSG_THUMB_SELECTOR = '.thumb';
var HSG_GALLERY_TEXT_SELECTOR = '.gallery.text-break';

function hsgAddClass( node, className ) {
	if ( !node || !className ) {
		return;
	}
	if ( node.classList ) {
		node.classList.add( className );
		return;
	}
	if ( typeof node.className === 'string' && node.className.indexOf( className ) === -1 ) {
		node.className += (node.className ? ' ' : '') + className;
	}
}

function hsgIsWhitespaceNode( node ) {
	if ( !node ) {
		return false;
	}
	if ( node.nodeType === 3 ) {
		return !node.textContent || node.textContent.trim() === '';
	}
	if ( node.nodeType !== 1 ) {
		return false;
	}
	if ( node.tagName === 'BR' ) {
		return true;
	}
	if ( node.tagName === 'P' ) {
		return !node.textContent || node.textContent.trim() === '';
	}
	return false;
}

function hsgIsSeparatorNode( node ) {
	if ( !node ) {
		return false;
	}
	if ( node.nodeType === 3 ) {
		return !!( node.textContent && node.textContent.trim() );
	}
	if ( node.nodeType !== 1 ) {
		return false;
	}
	const tag = node.tagName;
	if ( tag === 'BR' || tag === 'HR' ) {return true;}
	if ( /^H[1-6]$/.test( tag ) ) {return true;}
	if ( tag === 'P' ) {return true;}
	if ( tag === 'DIV' && node.style && node.style.clear ) {return true;}
	if ( node.classList && node.classList.contains( 'clear' ) ) {return true;}
	return false;
}

function hsgRunHasTile( nodes ) {
	for ( let i = 0; i < nodes.length; i++ ) {
		const n = nodes[i];
		if ( !n ) {
			continue;
		}
		if ( n.nodeType === 1 && n.classList && n.classList.contains( 'thumb' ) &&
			n.getAttribute( 'data-hsg-tile' ) === '1' ) {
			return true;
		}
		if ( n.querySelector && n.querySelector( '.thumb[data-hsg-tile="1"]' ) ) {
			return true;
		}
	}
	return false;
}

function hsgApplyTileClassToWrappers( root ) {
	const scope = root || document;
	scope.querySelectorAll( '.hsg-gallery-wrap, .hsg-list-gallery-wrap' ).forEach( ( wrap ) => {
		if ( wrap.querySelector( '.thumb[data-hsg-tile="1"]' ) ) {
			hsgAddClass( wrap, 'hsg-tiles-horizontal' );
		}
	} );
}

function hsgFixThumbFloat( root ) {
	const scope = root || document;
	const anchors = scope.querySelectorAll( '.thumb.tright .hsg-thumb, .thumb.tleft .hsg-thumb' );

	anchors.forEach( ( a ) => {
		const container = a.closest( '.thumb' );
		if ( !container || container.classList.contains( 'hsg-thumb-normalized' ) ) {
			return;
		}

		container.classList.add( 'hsg-thumb-normalized' );
		container.classList.remove( 'tright', 'tleft' );
	} );
}

/* Normalize list-based galleries and wrap thumbs within each list item so they stay together
 */
function hsgNormalizeListGalleries( root ) {
	const scope = root || document;
	const listItems = scope.querySelectorAll( HSG_LIST_ITEM_SELECTOR );

	listItems.forEach( ( li ) => {
		const existingWrap = li.querySelector( ':scope > .hsg-list-gallery-wrap' );
		if ( existingWrap ) {
			if ( li.classList && !li.classList.contains( 'hsg-thumb-list-item' ) ) {
				li.classList.add( 'hsg-thumb-list-item' );
				li.classList.remove( 'mw-empty-elt' );
			}
			return;
		}

		let directBlocks = Array.prototype.slice.call(
			li.querySelectorAll( `:scope > ${  HSG_GALLERY_TEXT_SELECTOR  }, :scope > .thumb` )
		);

		/* Drop empty gallery placeholders to avoid wrapping dead nodes */
		directBlocks = directBlocks.filter( ( node ) => {
			const isGallery = node.classList && node.classList.contains( 'gallery' ) && node.classList.contains( 'text-break' );
			if ( isGallery && !node.querySelector( '.thumb' ) ) {
				node.remove();
				return false;
			}
			return true;
		} );

		if ( directBlocks.length === 0 ) {
			return;
		}

		const wrap = document.createElement( 'div' );
		wrap.className = `hsg-list-gallery-wrap${   hsgRunHasTile( directBlocks ) ? ' hsg-tiles-horizontal' : ''}`;
		li.insertBefore( wrap, directBlocks[0] );

		directBlocks.forEach( ( node ) => {
			wrap.appendChild( node );
		} );

		if ( li.classList ) {
			if ( li.classList.contains( 'mw-empty-elt' ) ) {
				li.classList.remove( 'mw-empty-elt' );
			}
			if ( !li.classList.contains( 'hsg-thumb-list-item' ) ) {
				li.classList.add( 'hsg-thumb-list-item' );
			}
		}
	} );
}

/* Normalize gallery/list thumb wrappers and apply HSG hooks to all thumbs
 */
function hsgNormalizeThumbBlocks( root ) {
	const scope = root || document;

	// Unwrap <p> around gallery text thumbs if present.
	const galleries = scope.querySelectorAll( HSG_GALLERY_TEXT_SELECTOR );
	galleries.forEach( ( gallery ) => {
		hsgAddClass( gallery, 'hsg-gallery-block' );
		const pChild = gallery.querySelector( ':scope > p' );
		const anchor = pChild ? pChild.querySelector( 'a' ) : null;
		if ( pChild && anchor && !gallery.querySelector( ':scope > .thumb' ) ) {
			const img = anchor.querySelector( 'img' );
			const inner = document.createElement( 'div' );
			inner.className = 'thumbinner hsg-thumb';
			if ( img && img.width ) {
				inner.style.width = `${img.width + 2   }px`;
			}
			inner.appendChild( anchor );
			const thumb = document.createElement( 'div' );
			thumb.className = 'thumb hsg-thumb hsg-thumb-normalized';
			thumb.appendChild( inner );
			gallery.insertBefore( thumb, pChild );
			pChild.remove();
		}
	} );

	/* Apply HSG hooks to thumbs, inners, anchors, captions */
	scope.querySelectorAll( HSG_THUMB_SELECTOR ).forEach( ( t ) => {
		hsgAddClass( t, 'hsg-thumb' );
	} );
	scope.querySelectorAll( '.thumbinner' ).forEach( ( ti ) => {
		hsgAddClass( ti, 'hsg-thumb' );
	} );
	scope.querySelectorAll( `.thumb a, ${  HSG_GALLERY_TEXT_SELECTOR  } a` ).forEach( ( a ) => {
		hsgAddClass( a, 'hsg-thumb' );
	} );
	scope.querySelectorAll( '.thumbcaption, .gallerytext' ).forEach( ( cap ) => {
		hsgAddClass( cap, 'hsg-caption' );
		cap.querySelectorAll( 'a' ).forEach( ( link ) => {
			hsgAddClass( link, 'hsg-thumb' );
		} );
	} );

	/* Wrap loose sibling thumbs (non-list) together until a separator */
	const parents = new Set();
	scope.querySelectorAll( HSG_THUMB_SELECTOR ).forEach( ( t ) => {
		if ( t.parentElement ) {
			parents.add( t.parentElement );
		}
	} );
	parents.forEach( ( parent ) => {
		if ( parent.tagName === 'LI' || parent.tagName === 'DD' ) {
			return;
		}
		const nodes = Array.prototype.slice.call( parent.childNodes || [] );
		let run = [];
		const wrapRun = function ( collection, beforeNode ) {
			if ( !collection || collection.length === 0 ) {
				return;
			}
			const hasTile = hsgRunHasTile( collection );
			if ( collection.length === 1 && !hasTile ) {
				return;
			}
			const wrap = document.createElement( 'div' );
			wrap.className = `hsg-gallery-wrap${   hasTile ? ' hsg-tiles-horizontal' : ''}`;
			parent.insertBefore( wrap, beforeNode || collection[0] );
			collection.forEach( ( n ) => { wrap.appendChild( n ); } );
		};

		nodes.forEach( ( node ) => {
			if ( hsgIsSeparatorNode( node ) ) {
				wrapRun( run, node );
				run = [];
				return;
			}
			if ( node.nodeType === 1 && node.classList && node.classList.contains( 'thumb' ) ) {
				run.push( node );
			} else if ( node.nodeType === 3 && !node.textContent.trim() ) {
				// ignore whitespace
			} else {
				wrapRun( run, node );
				run = [];
			}
		} );
		wrapRun( run, null );
	} );

	hsgApplyTileClassToWrappers( scope );
}

/* 2025-12-04 HSG: If a thumb run got wrapped outside a list (e.g. from block HTML in list items),
 * move the wrapper back into the nearest list item so bullets stay with their gallery.
 */
function hsgRelocateWrapsIntoLists( root ) {
	const scope = root || document;

	const isList = function ( node ) {
		return !!node && (node.tagName === 'UL' || node.tagName === 'OL' || node.tagName === 'DL');
	};

	const isListItem = function ( node ) {
		return !!node && (node.tagName === 'LI' || node.tagName === 'DD');
	};

	const deepestItemFromNode = function ( node ) {
		if ( !node ) {return null;}

		if ( isListItem( node ) ) {
			const nestedList = node.querySelector( ':scope > ul:last-of-type, :scope > ol:last-of-type, :scope > dl:last-of-type' );
			if ( nestedList ) {
				const deeper = deepestItemFromNode( nestedList );
				return deeper || node;
			}
			return node;
		}

		if ( isList( node ) ) {
			const tail = node.querySelector( ':scope > li:last-of-type, :scope > dd:last-of-type' );
			if ( !tail ) {
				return null;
			}
			return deepestItemFromNode( tail ) || tail;
		}

		return null;
	};

	const findListContext = function ( wrap ) {
		let cur = wrap.previousSibling;
		while ( cur ) {
			if ( hsgIsWhitespaceNode( cur ) ) {
				cur = cur.previousSibling;
				continue;
			}
			if ( cur.nodeType === 1 ) {
				if ( cur.classList && cur.classList.contains( 'hsg-gallery-wrap' ) ) {
					cur = cur.previousSibling;
					continue;
				}
				if ( isListItem( cur ) || isList( cur ) ) {
					return cur;
				}
			}
			break;
		}
		return null;
	};

	const findTargetItem = function ( wrap ) {
		/* 1) Nearest prior sibling that is a list or list item */
		const ctx = findListContext( wrap );
		const target = deepestItemFromNode( ctx );
		if ( target ) {
			return target;
		}

		/* 2) Walk up ancestors; at each level, inspect prior siblings for lists/items. */
		let ancestor = wrap.parentElement;
		while ( ancestor ) {
			let prev = ancestor.previousSibling;
			while ( prev ) {
				if ( hsgIsWhitespaceNode( prev ) ) {
					prev = prev.previousSibling;
					continue;
				}
				if ( prev.nodeType === 1 ) {
					if ( prev.classList && prev.classList.contains( 'hsg-gallery-wrap' ) ) {
						prev = prev.previousSibling;
						continue;
					}
					const candidate = deepestItemFromNode( prev );
					if ( candidate ) {
						return candidate;
					}
				}
				break;
			}
			if ( isListItem( ancestor ) ) {
				return null;
			}
			ancestor = ancestor.parentElement;
		}

		return null;
	};

	scope.querySelectorAll( '.hsg-gallery-wrap' ).forEach( ( wrap ) => {
		const parent = wrap.parentElement;
		if ( !parent ) {
			return;
		}

		/* If wrapper sits directly under a list, move into the last item and mark it */
		if ( parent.tagName === 'OL' || parent.tagName === 'UL' || parent.tagName === 'DL' ) {
			const lastItem = parent.querySelector( ':scope > li:last-of-type, :scope > dd:last-of-type' );
			if ( lastItem ) {
				lastItem.appendChild( wrap );
				if ( lastItem.classList ) {
					lastItem.classList.remove( 'mw-empty-elt' );
					lastItem.classList.add( 'hsg-thumb-list-item' );
				}
				return;
			}
		}

		if ( !wrap.parentElement ) {return;}
		/* Already inside a list item/description item */
		if ( wrap.parentElement.tagName === 'LI' || wrap.parentElement.tagName === 'DD' ) {
			if ( wrap.parentElement.classList ) {
				wrap.parentElement.classList.remove( 'mw-empty-elt' );
				wrap.parentElement.classList.add( 'hsg-thumb-list-item' );
			}
			return;
		}

		const target = findTargetItem( wrap );
		if ( !target ) {
			return;
		}

		if ( wrap.className.indexOf( 'hsg-list-gallery-wrap' ) === -1 ) {
			wrap.className += `${wrap.className ? ' ' : ''  }hsg-list-gallery-wrap`;
		}

		target.appendChild( wrap );
		if ( target.classList ) {
			target.classList.remove( 'mw-empty-elt' );
			target.classList.add( 'hsg-thumb-list-item' );
		}
	} );
}

/* Merge immediately adjacent lists of the same type so numbering/bullets continue.
 */
function hsgMergeAdjacentLists( root ) {
	const scope = root || document;
	const lists = scope.querySelectorAll( 'ol, ul' );
	lists.forEach( ( list ) => {
		const prev = list.previousElementSibling;
		if ( !prev ) {
			return;
		}
		if ( prev.tagName !== list.tagName ) {
			return;
		}
		while ( list.firstChild ) {
			prev.appendChild( list.firstChild );
		}
		list.remove();
	} );
}

/* Move orphan HSG thumbs (not inside list items) into the adjacent list item when clearly adjacent to a list.
 */
function hsgRelocateThumbsIntoLists( root ) {
	const scope = root || document;

	const nearestPrevElement = function ( node ) {
		let prev = node.previousSibling;
		while ( prev ) {
			if ( prev.nodeType === 1 ) {
				return prev;
			}
			if ( prev.nodeType === 3 && prev.textContent && prev.textContent.trim() ) {
				return null; // non-whitespace text -> stop
			}
			prev = prev.previousSibling;
		}
		return null;
	};

	const findDeepestListItem = function ( list, depth ) {
		if ( !list || depth < 0 ) {
			return null;
		}
		let item = null;
		if ( list.tagName === 'DL' ) {
			item = list.querySelector( ':scope > dd:last-of-type' );
		} else {
			item = list.querySelector( ':scope > li:last-of-type' );
		}
		if ( !item ) {
			return null;
		}
		if ( depth === 0 ) {
			return item;
		}
		const nested = item.querySelector( ':scope > ol:last-of-type, :scope > ul:last-of-type, :scope > dl:last-of-type' );
		return nested ? ( findDeepestListItem( nested, depth - 1 ) || item ) : item;
	};

	scope.querySelectorAll( '.thumb.hsg-thumb' ).forEach( ( thumb ) => {
		if ( thumb.closest( 'li, dd' ) ) {
			return;
		}
		const prevEl = nearestPrevElement( thumb );
		let target = null;
		if ( prevEl && ( prevEl.tagName === 'LI' || prevEl.tagName === 'DD' ) ) {
			/* Prefer nested DL within this item if present; depth limit 2 */
			const nestedList = prevEl.querySelector( ':scope > dl:last-of-type, :scope > ol:last-of-type, :scope > ul:last-of-type' );
			target = nestedList ? ( findDeepestListItem( nestedList, 2 ) || prevEl ) : prevEl;
		} else if ( prevEl && ( prevEl.tagName === 'OL' || prevEl.tagName === 'UL' || prevEl.tagName === 'DL' ) ) {
			target = findDeepestListItem( prevEl, 3 );
		}
		if ( !target ) {
			return;
		}
		target.appendChild( thumb );
		if ( target.classList ) {
			target.classList.remove( 'mw-empty-elt' );
			if ( !target.classList.contains( 'hsg-thumb-list-item' ) ) {
				target.classList.add( 'hsg-thumb-list-item' );
			}
		}
	} );
}

/* 2025-12-08 HSG: If an inline HSG ended up in a paragraph right after a list item,
 * move that paragraph's contents back into the last list item to keep bullets with the inline HSG.
 */
function hsgRelocateInlineIntoLists( root ) {
	const scope = root || document;

	const isInlineOnlyParagraph = function ( p ) {
		if ( !p || p.tagName !== 'P' ) {
			return false;
		}
		const bad = p.querySelector( 'div, ul, ol, dl, table, blockquote, p' );
		return !bad;
	};

	const moveIntoLastItem = function ( list, para ) {
		if ( !list || !para ) {
			return;
		}
		const lastChild = list.querySelector( ':scope > li:last-of-type, :scope > dd:last-of-type' );
		if ( !lastChild ) {
			return;
		}
		while ( para.firstChild ) {
			lastChild.appendChild( para.firstChild );
		}
		para.remove();
	};

	scope.querySelectorAll( 'p' ).forEach( ( p ) => {
		if ( !isInlineOnlyParagraph( p ) ) {
			return;
		}
		if ( !p.querySelector( '.hsg-inline, .link-youtube.hsg-thumb' ) ) {
			return;
		}
		const prev = p.previousElementSibling;
		if ( !prev || ( prev.tagName !== 'OL' && prev.tagName !== 'UL' && prev.tagName !== 'DL' ) ) {
			return;
		}
		moveIntoLastItem( prev, p );
	} );

	/* If an inline HSG ended up directly inside a list item after DOM operations,
	 * ensure it stays within the current LI instead of creating a new list */
	scope.querySelectorAll( '.hsg-inline' ).forEach( ( inline ) => {
		const li = inline.closest( 'li, dd' );
		if ( !li ) {
			return;
		}
		if ( li.classList && !li.classList.contains( 'hsg-inline-list-item' ) ) {
			li.classList.add( 'hsg-inline-list-item' );
		}
		const parentList = li.parentElement;
		if ( !parentList || ( parentList.tagName !== 'OL' && parentList.tagName !== 'UL' && parentList.tagName !== 'DL' ) ) {
			return;
		}
		/* If the inline anchor was split into its own list item, merge back into previous sibling if appropriate. */
		const prev = li.previousElementSibling;
		if ( prev && prev.tagName === li.tagName ) {
			while ( inline.previousSibling ) {
				prev.appendChild( inline.previousSibling );
			}
			prev.appendChild( inline );
			if ( li.childNodes.length === 0 ) {
				li.remove();
			}
		}
	} );
}

if ( typeof mw !== 'undefined' && mw.hook && mw.hook( 'wikipage.content' ) ) {
	mw.hook( 'wikipage.content' ).add( ( $content ) => {
		hsgFixThumbFloat( $content && $content[0] ? $content[0] : document );
		hsgNormalizeListGalleries( $content && $content[0] ? $content[0] : document );
		hsgNormalizeThumbBlocks( $content && $content[0] ? $content[0] : document );
		hsgRelocateWrapsIntoLists( $content && $content[0] ? $content[0] : document );
		hsgRelocateThumbsIntoLists( $content && $content[0] ? $content[0] : document );
		hsgMergeAdjacentLists( $content && $content[0] ? $content[0] : document );
		hsgRelocateInlineIntoLists( $content && $content[0] ? $content[0] : document );
		setTimeout( () => {
			hsgRelocateThumbsIntoLists( $content && $content[0] ? $content[0] : document );
			hsgMergeAdjacentLists( $content && $content[0] ? $content[0] : document );
			hsgRelocateInlineIntoLists( $content && $content[0] ? $content[0] : document );
		}, 0 );
	} );
} 
else {
	document.addEventListener( 'DOMContentLoaded', () => {
		hsgFixThumbFloat( document );
		hsgNormalizeListGalleries( document );
		hsgNormalizeThumbBlocks( document );
		hsgRelocateWrapsIntoLists( document );
		hsgRelocateThumbsIntoLists( document );
		hsgMergeAdjacentLists( document );
		hsgRelocateInlineIntoLists( document );
		setTimeout( () => {
			hsgRelocateThumbsIntoLists( document );
			hsgMergeAdjacentLists( document );
			hsgRelocateInlineIntoLists( document );
		}, 0 );
	} );
}
