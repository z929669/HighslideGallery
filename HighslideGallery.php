<?php
/**
 * HighslideGallery extension entry point
 *
 * @file
 * @ingroup Extensions
 * @author Brian McCloskey, Step Modifications
 * @copyright 2012 Brian McCloskey, 2020 Step Modifications
 * @license CC BY-NC 3.0: http://creativecommons.org/licenses/by-nc/3.0/
 */

if ( !defined( 'MEDIAWIKI' ) )
    die( 'This is a MediaWiki extension, and must be run from within MediaWiki.' );

$wgExtensionMessagesFiles['HighslideGallery'] = dirname( __FILE__ ) . '/HighslideGallery.i18n.php';
$wgExtensionCredits['parserhook'][] = array(
    'path'              => __FILE__,
    'name'              => 'HighslideGallery',
    'url'               => 'https://www.mediawiki.org/wiki/Extension:HighslideGallery',
    'author'            => array('Brian McCloskey','Step Modifications'),
    'descriptionmsg'    => 'hg-desc',
    'version'           => '1.1.0'
);

$wgAutoloadClasses['HighslideGallery'] = dirname( __FILE__ ) . '/HighslideGallery.body.php';

$wgResourceModules['ext.highslideGallery'] = array(
		'scripts'	=> array('highslide.js','highslide.cfg.js'),
		'styles'	=> array('highslide.css','highslide.override.css'),
		'position'	=> 'top',
		'localBasePath'	=> dirname(__FILE__) . '/modules',
		'remoteExtPath'	=> 'HighslideGallery/modules',
);

//$hg = new HighslideGallery;

$wgHooks['ImageBeforeProduceHTML'][]	= 'HighslideGallery::MakeImageLink';
$wgHooks['BeforePageDisplay'][]		= 'HighslideGallery::AddResources';
$wgHooks['ParserFirstCallInit'][]		= 'HighslideGallery::AddHooks';
