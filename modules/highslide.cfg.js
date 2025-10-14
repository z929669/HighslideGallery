/*
 * HighslideGallery extension
 *
 * @file
 * @ingroup Extensions
 * @author Brian McCloskey, Step Modifications
 * @copyright 2012 Brian McCloskey, 2020 Step Modifications
 * @license CC BY-NC 3.0: http://creativecommons.org/licenses/by-nc/3.0/
 *
 * Some of the code used is from http://www.roadrash.no/
 * Creates image galleries as appropriate using the Highslide library.
 * Accepts syntax for images via caption prefix containing "highslide:"
 * without the quotes. Works for both single images as well as wiki galleries.
 */

// NOTE: Avoid forcibly binding hs to window; the core script is injected early by PHP and exposes hs globally.
// Double-binding can cause collisions in certain load orders.
//window.hs = hs

hs.graphicsDir = mw.config.get( 'wgExtensionAssetsPath' ) + '/HighslideGallery/modules/graphics/';
hs.align = 'center';
hs.transitions = ['expand', 'crossfade'];
hs.fadeInOut = true;
hs.dimmingOpacity = 1;
hs.outlineType = null;
hs.wrapperClassName = 'borderless floating-caption';
hs.showCredits = false;
hs.outlineWhileAnimating = true;

hs.marginTop = 60;
hs.marginBottom = 180;
hs.marginLeft = 80;
hs.marginRight = 80;
hs.onDimmerClick = function() {
	return false;
}

hs.numberPosition = 'caption';
hs.captionOverlay = {
	position: 'bottom left',
	fade: false,
	relativeTo: 'viewport',
	offsetY: -100,
	offsetX: 80,
};

// Register gallery controls
hs.registerOverlay({
	html: '<div class="controls prev"><a href="javascript:;" onclick="return hs.previous(this)" title="Previous (left arrow key)"></a></div>',
	position: 'middle left',
	relativeTo: 'viewport',
	fade: false,
	thumbnailId: 'wikigallery'
});
hs.registerOverlay({
	html: '<div class="controls next"><a href="javascript:;" onclick="return hs.next(this)" title="Next (right arrow key)"></a></div>',
	position: 'middle right',
	relativeTo: 'viewport',
	fade: false,
	thumbnailId: 'wikigallery'
});
// Need to register the close overlay twice (for images and youtube videos)
hs.registerOverlay({
	html: '<div class="controls close"><a href="javascript:;" onclick="return hs.close(this)"></a></div>',
	position: 'top right',
	relativeTo: 'viewport',
	fade: false
});
hs.registerOverlay({
	html: '<div class="controls close"><a href="javascript:;" onclick="return hs.close(this)"></a></div>',
	position: 'top right',
	relativeTo: 'viewport',
	fade: false,
	slideshowGroup: 'videos',
	useOnHtml: true
});

//// Keep positions when the window is resized
hs.addEventListener(window, 'resize', function() {
	var i, exp;
	hs.getPageSize();

	for (i = 0; i < hs.expanders.length; i++) {
		exp = hs.expanders[i];
		if (exp) {
			var x = exp.x,
				y = exp.y;

			// Get new thumb positions
			exp.tpos = hs.getPosition(exp.el);
			x.calcThumb();
			y.calcThumb();

			// Calculate new popup position
			x.pos = x.tpos - x.cb + x.tb;
			x.scroll = hs.page.scrollLeft;
			x.clientSize = hs.page.width;
			y.pos = y.tpos - y.cb + y.tb;
			y.scroll = hs.page.scrollTop;
			y.clientSize = hs.page.height;
			exp.justify(x, true);
			exp.justify(y, true);

			// Set new left and top to wrapper and outline
			exp.moveTo(x.pos, y.pos);
		}
	}
});

// Slideshow for images
hs.addSlideshow({
	repeat: true,
	useControls: false,
	fixedControls: false,
	thumbstrip: {
		position: 'bottom center',
		mode: 'horizontal',
		relativeTo: 'viewport'
	}
});

// Slideshow for YouTube
hs.addSlideshow({
	slideshowGroup: 'videos',
	repeat: false,
	useControls: false,
	fixedControls: false
});

// Video options
var videoOptions = {
	slideshowGroup: 'videos',
	objectType: 'iframe',
	width: 720,
	height: 480,
	wrapperClassName: 'dark you-tube',
	allowSizeReduction: false,
	preserveContent: false,
	outlineType: 'drop-shadow',
	numberPosition: null,
	maincontentText: 'You need to upgrade your Flash player'
};

window.videoOptions = videoOptions;