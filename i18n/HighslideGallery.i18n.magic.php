<?php
/*
 * HighslideGallery (HSG) delivers Highslide JS-powered overlays/slideshows for images and YouTube videos
 * displayed as thumbnails or inline links on the page with titles and captions. Clicking a HSG thumbnail
 * or link opens the image or video in an interactive overlay auto-sized to the viewport with ability to
 * expand to full size with panning. See [this example](https://stepmodifications.org/wikidev/Template:Hsg).
 * 
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

$magicWords = [];

// English (content language). Add other languages as needed.
$magicWords['en'] = [
	// Parser function name (used as {{#hsg*:...}})
	'hsgimg'  => [ 0, 'hsimg', 'hsgimg' ],
	'hsgytb'  => [ 0, 'hsgytb' ]
];
