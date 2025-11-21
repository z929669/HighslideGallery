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
 *   - `videoOptions` as the base options object
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
hs.marginTop			= 40;
hs.marginBottom			= 40;

/* No fades / transitions – snap in/out */
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

// Do not close when clicking the image itself; use controls/dimmer instead.
hs.closeOnClick			= false;

// Show image index in the caption area (vendor feature, configured here).
hs.numberPosition		= 'caption';

// Cancel Highslide's default "click image to close".
if ( hs.Expander && hs.Expander.prototype ) {
	hs.Expander.prototype.onImageClick = function () {
		// Returning false stops exp.close() in Highslide's mouse handler.
		return false;
	};
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
hs.addSlideshow( {
	repeat: false,	// prev/next looping
	useControls: true,
	fixedControls: false,
	overlayOptions: {
		className: 'text-controls',
		position: 'bottom center',
		relativeTo: 'viewport'
	},
	thumbstrip: {
		position: 'bottom center',
		mode: 'horizontal',
		relativeTo: 'viewport'
	}
} );

/*
 * Slideshow for YouTube (group "videos", no extra controls).
 * Group name is a configuration constant shared with `videoOptions.slideshowGroup`.
 */
hs.addSlideshow( {
	slideshowGroup: 'videos',
	repeat: false,
	useControls: false,
	fixedControls: false
} );

// -------------------------------------------------------------------------
// Video options (used by hs.htmlExpand via MakeYouTubeLink / hsgytb)
// -------------------------------------------------------------------------

/*
 * `videoOptions` is kept as the vendor-style base object. HSG code
 * may later wrap or clone this (e.g. via `hsgGetVideoOptions`) but
 * the canonical global name remains `videoOptions`.
 */
var videoOptions = {
	slideshowGroup: 'videos',
	objectType: 'iframe',
	width: 720,
	height: 480,
	// `you-tube` is a vendor skin hook; `hsg-frame` is HSG-specific.
	wrapperClassName: 'you-tube hsg-frame',
	allowSizeReduction: false,
	preserveContent: false,
	outlineType: 'drop-shadow',
	numberPosition: null
};

window.videoOptions = videoOptions;
