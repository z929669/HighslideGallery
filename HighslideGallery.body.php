<?php
/*
 * HighslideGallery class
 *
 * @file
 * @ingroup Extensions
 * @author Brian McCloskey, Step Modifications
 * @copyright 2012 Brian McCloskey, 2020 Step Modifications
 * @license CC BY-NC 3.0: http://creativecommons.org/licenses/by-nc/3.0/
 */

if ( !defined( 'MEDIAWIKI' ) )
	die( 'This is a MediaWiki extension, and must be run from within MediaWiki.' );

class HighslideGallery {
	private static $hgID;
	private static $isGallery = false;

/* NOTE: We inject highslide.js here (not via RL) because the library expects a page-global `hs`.
   Loading via ResourceLoader can sandbox/late-load scripts and break plugins relying on the early global.
   RL still delivers config/styles via ext.highslideGallery.
*/
public static function AddResources( OutputPage &$out, Skin &$skin ) {
	// Inject highslide.js as a traditional global script early in the page
	$out->addHeadItem( 'highslide-js',
		'<script src="' . $out->getConfig()->get( 'ExtensionAssetsPath' ) . '/HighslideGallery/modules/highslide.js"></script>'
	);

	// Load the rest via ResourceLoader
	$out->addModules( 'ext.highslideGallery' );
}

	// NOTE: Parser hooks are non-abortable; return true. Use [self::class, 'method'] to avoid string callables.
	public static function AddHooks( &$parser ) {
		$parser->setHook( 'hsyoutube', [ self::class, 'MakeYouTubeLink' ] );
		$parser->setFunctionHook( 'hsimg', [ self::class, 'MakeExtLink' ] );
		return true;
	}

	public static function MakeImageLink( &$dummy, &$title, &$file, &$frameParams, &$handlerParams, &$time, &$res ) {
		$galleryID = [];

		if ( preg_match( '/^highslide[^:]*:/', $frameParams['caption'] ) ) {
			preg_match( '/^highslide=(?!:)([a-zA-Z0-9]+)/i', $frameParams['caption'], $galleryID );
			self::$hgID = $galleryID[1] ?? null;

			$frameParams['caption'] = preg_replace( '/^highslide([^:]*):/', '', $frameParams['caption'] );
			if ( isset( $frameParams['alt'] ) ) {
				$frameParams['alt'] = preg_replace( '/^highslide([^:]*):/', '', $frameParams['alt'] );
			}
			if ( isset( $frameParams['title'] ) ) {
				$frameParams['title'] = preg_replace( '/^highslide([^:])*:/', '', $frameParams['title'] );
			}

			$res = $dummy->MakeThumbLink2( $title, $file, $frameParams, $handlerParams );
			self::AddHighslide( $res, $file, $frameParams['caption'], $title );
			return false;
		}

		return true;
	}

	public static function MakeYouTubeLink( $content, array $attributes, $parser ) {
		// NOTE: Chrome blocks autoplay with sound; add mute=1 to allow autoplay reliably.
		// Loop behavior requires playlist={code} with loop=1 (YouTube quirk).
		if ( preg_match( '/[?&]v=([^?&]+)/', $content, $embedCode ) ) {
			$code = $embedCode[1];
		} elseif ( preg_match( '/embed\/([^??]+)/', $content, $embedCode ) ) {
			$code = $embedCode[1];
		} else {
			return false;
		}

		$title = $attributes['title'] ?? 'YouTube Video';
		$autoplay = array_key_exists( 'autoplay', $attributes ) ? '?autoplay=1&amp;' : '?';

		$s = "<a class=\"highslide link-youtube\" onclick=\"return hs.htmlExpand(this, videoOptions)\" title=\"${title}\"";
		$s .= " href=\"https://www.youtube.com/embed/${code}${autoplay}mute=1&amp;autohide=1&amp;playlist=${code}&amp;loop=1\">";
		$s .= $title . "</a>";

		if ( isset( $attributes['caption'] ) ) {
			$s .= "<div class='highslide-caption'>" . $attributes['caption'] . "</div>";
		}

		return $s;
	}

	public static function MakeExtLink( $parser, $hsid, $width, $title, $content ) {
		if ( $content === '' ) {
			return false;
		}

		$caption = $title ?: $content;
		$hs = "<a href=\"$content\" class=\"image\" title=\"$caption\">";
		$hsimg = "<img class=\"hsimg\" src=\"$content\" alt=\"$caption\"";

		if ( $width ) {
			$hsimg .= " style=\"max-width: {$width}px\"";
		}
		if ( $hsid ) {
			self::$hgID = $hsid;
		}

		$s = $hs . $hsimg . " /></a>";
		self::AddHighslide( $s, null, $caption, null );
		return [ $s, 'isHTML' => true ];
	}

	private static function AddHighslide( &$s, $file, $caption, $title ) {
		global $wgStylePath;

		if ( $caption === '' && $file ) {
			$caption = $file->getName();
		}

		if ( $title instanceof Title ) {
			$url = $title->getLocalURL();
			$caption = "<a href=\'$url\' class=\'internal\'><img src=\'../$wgStylePath/common/images/magnify-clip.png\' width=\'15\' height=\'11\' alt=\'\'></img></a> $caption";
		}

		if ( !isset( self::$hgID ) ) {
			self::$hgID = uniqid();
		} else {
			self::$isGallery = true;
		}

		$hs = "id=\"" . self::$hgID . "\" onClick=\"return hs.expand(this, {slideshowGroup:'" . self::$hgID . "',captionText:'" . $caption . "'";
		if ( self::$isGallery ) {
			$hs .= "})\" href";
			$s = preg_replace( '/<img /', "<img id=\"wikigallery\" ", $s, 1 );
		} else {
			$hs .= ",numberPosition:'none'})\" href";
		}

		$s = preg_replace( '/href/', $hs, $s, 1 );

		if ( $file ) {
			$url = $file->getUrl();
			$s = preg_replace( '/href="[^"]*"/i', "href=\"$url\"", $s, 1 );
		}

		self::$isGallery = false;
	}
}
