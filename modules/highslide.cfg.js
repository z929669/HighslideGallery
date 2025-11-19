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
 */

// Bridge ResourceLoader's local `hs` into the global scope so inline handlers work.
if ( typeof window !== 'undefined' && typeof hs !== 'undefined' ) {
	window.hs = window.hs || hs;
}

hs.graphicsDir = mw.config.get( 'wgExtensionAssetsPath' ) +
	'/HighslideGallery/modules/graphics/';

/* Core behaviour */
hs.align							= 'center';
hs.dimmingOpacity					= 0.75;
hs.outlineType						= null; // CSS-driven frame
hs.wrapperClassName					= 'hsg-frame floating-caption';
hs.marginTop						= 40;
hs.marginBottom						= 40;

/* No fades / transitions – snap in/out */
hs.fadeInOut             = false;
hs.expandDuration        = 0;
hs.restoreDuration       = 0;
hs.transitions           = [];

/* No fades / transitions – fade in/out */
//hs.fadeInOut             = true;
//hs.expandDuration        = 0;
//hs.restoreDuration       = 0;
//hs.transitions           = [ 'expand', 'crossfade' ];

hs.allowSizeReduction				= true;
hs.showCredits						= false;
hs.outlineWhileAnimating			= true;

hs.closeOnClick						= false;

// Show image index in the caption area
hs.numberPosition = 'caption';

// Cancel Highslide's default "click image to close".
if (hs.Expander && hs.Expander.prototype) {
	hs.Expander.prototype.onImageClick = function () {
		// Returning false stops exp.close() in Highslide's mouse handler.
		return false;
	};
}

// ---------------------------------------------------------------------
// Language tweaks
// ---------------------------------------------------------------------
hs.lang = hs.lang || {};

// Default "restore" tooltip was misleading ("Click to close image...").
// Our UX: image click does nothing; use controls, keys, or dimmer to close.
hs.lang.restoreTitle =
	'Click/drag to move. Use arrow keys or on-frame controls to navigate. ' +
	'Press Esc, or click the close button or margins to exit.';

// ---------------------------------------------------------------------
// Overlays & controls
// ---------------------------------------------------------------------

/* Global close overlay (images + HTML/iframe) */
hs.registerOverlay( {
	html: '<div class="controls close"><a href="javascript:;" onclick="return hs.close(this)"></a></div>',
	position: 'top right',
	relativeTo: 'viewport',
	fade: false,
	useOnHtml: true
} );

/* Slideshow for images (controls + thumbstrip) */
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

// Prevent slideshow "Play" and manual Next from closing the expander
// when there is no next/previous slide. Instead, stop autoplay and stay
// on the current image.
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

/* Slideshow for YouTube (group "videos", no extra controls) */
hs.addSlideshow( {
	slideshowGroup: 'videos',
	repeat: false,
	useControls: false,
	fixedControls: false
} );

// ---------------------------------------------------------------------
// Video options (used by hs.htmlExpand via MakeYouTubeLink)
// ---------------------------------------------------------------------
var videoOptions = {
	slideshowGroup: 'videos',
	objectType: 'iframe',
	width: 720,
	height: 480,
	wrapperClassName: 'you-tube hsg-frame',
	allowSizeReduction: false,
	preserveContent: false,
	outlineType: 'drop-shadow',
	numberPosition: null
};

window.videoOptions = videoOptions;
