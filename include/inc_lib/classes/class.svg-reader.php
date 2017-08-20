<?php
/**
 * Extraction of SVG image metadata.
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 2 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License along
 * with this program; if not, write to the Free Software Foundation, Inc.,
 * 51 Franklin Street, Fifth Floor, Boston, MA 02110-1301, USA.
 * http://www.gnu.org/copyleft/gpl.html
 *
 * @description Classes are taken from MediaWiki and changed for phpwcms.
 * @file Defines classes to read SVG metadata
 * @author "Derk-Jan Hartman <hartman _at_ videolan d0t org>"
 * @author Brion Vibber
 * @copyright Copyright © 2010-2010 Brion Vibber, Derk-Jan Hartman
 * @license http://www.gnu.org/copyleft/gpl.html GNU General Public License
 */

/**
 * SVGMetadataExtractor class.
 */
class SVGMetadataExtractor {
	static function getMetadata( $filename ) {
		$svg = new SVGReader( $filename );

		return $svg->getMetadata();
	}
}

/**
 * SVGReader class.
 */
class SVGReader {
	const DEFAULT_WIDTH = PHPWCMS_IMAGE_WIDTH;
	const DEFAULT_HEIGHT = PHPWCMS_IMAGE_HEIGHT;
	const NS_SVG = 'http://www.w3.org/2000/svg';

	/** @var null|XMLReader */
	private $reader = null;

	/** @var array */
	private $metadata = array();
    private $error = array();

	/**
	 * Constructor
	 *
	 * Creates an SVGReader drawing from the source provided
	 * @param string $source URI from which to read
	 * @throws MWException|Exception
	 */
	function __construct( $source ) {
		$this->reader = new XMLReader();

        $filesize = filesize( $source );

		// Don't use $file->getSize() since file object passed to SVGHandler::getMetadata is bogus.
		if ( $filesize !== false ) {
			$this->reader->open( $source, null, LIBXML_NOERROR | LIBXML_NOWARNING );

            // Expand entities, since Adobe Illustrator uses them for xmlns
    		// attributes (bug 31719). Note that libxml2 has some protection
    		// against large recursive entity expansions so this is not as
    		// insecure as it might appear to be. However, it is still extremely
    		// insecure. It's necessary to wrap any read() calls with
    		// libxml_disable_entity_loader() to avoid arbitrary local file
    		// inclusion, or even arbitrary code execution if the expect
    		// extension is installed (bug 46859).
    		$oldDisable = libxml_disable_entity_loader( true );
    		$this->reader->setParserProperty( XMLReader::SUBST_ENTITIES, true );

    		$this->metadata['width'] = self::DEFAULT_WIDTH;
    		$this->metadata['height'] = self::DEFAULT_HEIGHT;

    		// The size in the units specified by the SVG file
    		// (for the metadata box)
    		// Per the SVG spec, if unspecified, default to '100%'
    		$this->metadata['originalWidth'] = '100%';
    		$this->metadata['originalHeight'] = '100%';

    		// Because we cut off the end of the svg making an invalid one. Complicated
    		// try catch thing to make sure warnings get restored. Seems like there should
    		// be a better way.
    		$this->read();
            libxml_disable_entity_loader( $oldDisable );
		}
	}

	/**
	 * @return array Array with the known metadata
	 */
	public function getMetadata() {
		return $this->metadata;
	}

	/**
	 * Read the SVG
	 * @throws MWException
	 * @return bool
	 */
	protected function read() {
		$keepReading = $this->reader->read();

		/* Skip until first element */
		while ( $keepReading && $this->reader->nodeType != XMLReader::ELEMENT ) {
			$keepReading = $this->reader->read();
		}

		if ( $this->reader->localName != 'svg' || $this->reader->namespaceURI != self::NS_SVG ) {
			$this->error[] = 'Expected <svg> tag, got ' . $this->reader->localName . ' in NS ' . $this->reader->namespaceURI;
		}

		$this->handleSVGAttribs();

		$exitDepth = $this->reader->depth;
		$keepReading = $this->reader->read();
		while ( $keepReading ) {
			$tag = $this->reader->localName;
			$type = $this->reader->nodeType;
			$isSVG = ( $this->reader->namespaceURI == self::NS_SVG );

			if ( $isSVG && $tag == 'svg' && $type == XMLReader::END_ELEMENT && $this->reader->depth <= $exitDepth) {
				break;
			} elseif ( $isSVG && $tag == 'title' ) {
				$this->readField( $tag, 'title' );
			} elseif ( $isSVG && $tag == 'desc' ) {
				$this->readField( $tag, 'description' );
			} elseif ( $isSVG && $tag == 'metadata' && $type == XMLReader::ELEMENT ) {
				$this->readXml( $tag, 'metadata' );
			} elseif ( $isSVG && $tag == 'script' ) {
				// We normally do not allow scripted svgs.
				// However its possible to configure MW to let them
				// in, and such files should be considered animated.
				$this->metadata['animated'] = true;
			}

			// Goto next element, which is sibling of current (Skip children).
			$keepReading = $this->reader->next();
		}

		$this->reader->close();

		return true;
	}

	/**
	 * Read a textelement from an element
	 *
	 * @param string $name Name of the element that we are reading from
	 * @param string $metafield Field that we will fill with the result
	 */
	private function readField( $name, $metafield = null ) {
		if ( !$metafield || $this->reader->nodeType != XMLReader::ELEMENT ) {
			return;
		}
		$keepReading = $this->reader->read();
		while ( $keepReading ) {
			if ( $this->reader->localName == $name && $this->reader->namespaceURI == self::NS_SVG && $this->reader->nodeType == XMLReader::END_ELEMENT) {
				break;
			} elseif ( $this->reader->nodeType == XMLReader::TEXT ) {
				$this->metadata[$metafield] = trim( $this->reader->value );
			}
			$keepReading = $this->reader->read();
		}
	}

	/**
	 * Read an XML snippet from an element
	 *
	 * @param string $metafield Field that we will fill with the result
	 * @throws MWException
	 */
	private function readXml( $metafield = null ) {
		if ( !$metafield || $this->reader->nodeType != XMLReader::ELEMENT ) {
			return;
		}
		// @todo Find and store type of xml snippet. metadata['metadataType'] = "rdf"
		if ( method_exists( $this->reader, 'readInnerXML' ) ) {
			$this->metadata[$metafield] = trim( $this->reader->readInnerXml() );
		} else {
			$this->error[] = 'The PHP XMLReader extension does not come with readInnerXML() method. Your libxml is probably out of date (need 2.6.20 or later).';
		}
		$this->reader->next();
	}

	/**
	 * Parse the attributes of an SVG element
	 *
	 * The parser has to be in the start element of "<svg>"
	 */
	private function handleSVGAttribs() {
		$defaultWidth = self::DEFAULT_WIDTH;
		$defaultHeight = self::DEFAULT_HEIGHT;
		$aspect = 1.0;
		$width = null;
		$height = null;

		if ( $this->reader->getAttribute( 'viewBox' ) ) {
			// min-x min-y width height
			$viewBox = preg_split( '/\s+/', trim( $this->reader->getAttribute( 'viewBox' ) ) );
			if ( count( $viewBox ) == 4 ) {
				$viewWidth = $this->scaleSVGUnit( $viewBox[2] );
				$viewHeight = $this->scaleSVGUnit( $viewBox[3] );
				if ( $viewWidth > 0 && $viewHeight > 0 ) {
					$aspect = $viewWidth / $viewHeight;
					$defaultHeight = $defaultWidth / $aspect;
				}
			}
		}
		if ( $this->reader->getAttribute( 'width' ) ) {
			$width = $this->scaleSVGUnit( $this->reader->getAttribute( 'width' ), $defaultWidth );
			$this->metadata['originalWidth'] = $this->reader->getAttribute( 'width' );
		}
		if ( $this->reader->getAttribute( 'height' ) ) {
			$height = $this->scaleSVGUnit( $this->reader->getAttribute( 'height' ), $defaultHeight );
			$this->metadata['originalHeight'] = $this->reader->getAttribute( 'height' );
		}

		if ( !isset( $width ) && !isset( $height ) ) {
			$width = $defaultWidth;
			$height = $width / $aspect;
		} elseif ( isset( $width ) && !isset( $height ) ) {
			$height = $width / $aspect;
		} elseif ( isset( $height ) && !isset( $width ) ) {
			$width = $height * $aspect;
		}

		if ( $width > 0 && $height > 0 ) {
			$this->metadata['width'] = intval( round( $width ) );
			$this->metadata['height'] = intval( round( $height ) );
		}
	}

	/**
	 * Return a rounded pixel equivalent for a labeled CSS/SVG length.
	 * http://www.w3.org/TR/SVG11/coords.html#UnitIdentifiers
	 *
	 * @param string $length CSS/SVG length.
	 * @param float|int $viewportSize Optional scale for percentage units...
	 * @return float Length in pixels
	 */
	static function scaleSVGUnit( $length, $viewportSize = 512 ) {
		static $unitLength = [
			'px' => 1.0,
			'pt' => 1.25,
			'pc' => 15.0,
			'mm' => 3.543307,
			'cm' => 35.43307,
			'in' => 90.0,
			'em' => 16.0, // fake it?
			'ex' => 12.0, // fake it?
			'' => 1.0, // "User units" pixels by default
		];
		$matches = [];
		if ( preg_match( '/^\s*(\d+(?:\.\d+)?)(em|ex|px|pt|pc|cm|mm|in|%|)\s*$/', $length, $matches ) ) {
			$length = floatval( $matches[1] );
			$unit = $matches[2];
			if ( $unit == '%' ) {
				return $length * 0.01 * $viewportSize;
			} else {
				return $length * $unitLength[$unit];
			}
		} else {
			// Assume pixels
			return floatval( $length );
		}
	}
}