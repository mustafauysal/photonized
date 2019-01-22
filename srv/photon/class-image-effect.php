<?php

if ( ! class_exists( 'Image_Effect' ) ) {

class Image_Effect {

	private $image_object_type;
	private $mime_type;

	private $_MAX_FILTER_FUNCTIONS;

	public $processed = array();

	public function __construct( $image_object_type, $mime_type ) {
		$this->image_object_type = $image_object_type;
		$this->mime_type = $mime_type;

		// To prevent potentially service disrupting long 'filter' action chains we set a sane limit
		$this->_MAX_FILTER_FUNCTIONS = defined( 'MAX_FILTER_FUNCTIONS' ) ? MAX_FILTER_FUNCTIONS : 3;
	}

	public function gmagick_to_gd( &$image ) {
		if ( 'image/jpeg' == $this->mime_type )
			$image->setcompressionquality( 100 );
		$image = imagecreatefromstring( $image->getimageblob() );
	}

	public function gd_to_gmagick( &$image ) {
		$tmp_name = tempnam( '/dev/shm/', 'gd-gmagick-' );
		$file_ptr = fopen( $tmp_name, 'w' );
		if ( false == $file_ptr ) {
			@unlink( $tmp_name );
			$image = new Gmagick();
			return;
		}
		switch( $this->mime_type ) {
			case 'image/gif':
				imagegif( $image, $file_ptr );
				break;
			case 'image/png':
				imagepng( $image, $file_ptr, 0 );
				break;
			default:
				imagejpeg( $image, $file_ptr, 100 );
				break;
		}
		fflush( $file_ptr );
		fclose( $file_ptr );
		$image = new Gmagick();
		$image->readimage( $tmp_name );
		@unlink( $tmp_name );
	}

	/**
	 * Performs various filters on the image
	 *
	 * @param resource $image The source image resource
	 * @param string $filter The filter name
	 **/
	public function filter( &$image, $filter ) {
		if ( 'Gmagick' == $this->image_object_type )
			$this->gmagick_to_gd( $image );

		// Note: 'filters_applied' is used to limit the number of filters allowed sequentially,
		// whereas 'this->processed' is used for stats, which is why it only tallies each filter
		// once. Otherwise we end up with an entry for every frame in an animated GIF.
		$filters_applied = 0;
		$args = explode( ',', $filter );

		while ( 0 < count( $args ) && $this->_MAX_FILTER_FUNCTIONS > $filters_applied ) {
			$filter = array_shift( $args );
			switch ( $filter ) {
				case 'negate':
					imagefilter( $image, IMG_FILTER_NEGATE );
					if ( ! in_array( 'filter_negate', $this->processed ) )
						$this->processed[] = 'filter_negate';
					$filters_applied++;
					break;
				case 'grayscale':
				case 'greyscale':
					imagefilter( $image, IMG_FILTER_GRAYSCALE );
					if ( ! in_array( 'filter_grayscale', $this->processed ) )
						$this->processed[] = 'filter_grayscale';
					$filters_applied++;
					break;
				case 'sepia':
					imagefilter( $image, IMG_FILTER_GRAYSCALE );
					imagefilter( $image, IMG_FILTER_COLORIZE, 90, 60, 40 );
					if ( ! in_array( 'filter_sepia', $this->processed ) )
						$this->processed[] = 'filter_sepia';
					$filters_applied++;
					break;
				case 'edge':
				case 'edgedetect':
					imagefilter( $image, IMG_FILTER_EDGEDETECT );
					if ( ! in_array( 'filter_edgedetect', $this->processed ) )
						$this->processed[] = 'filter_edgedetect';
					$filters_applied++;
					break;
				case 'emboss':
					imagefilter( $image, IMG_FILTER_EMBOSS );
					if ( ! in_array( 'filter_emboss', $this->processed ) )
						$this->processed[] = 'filter_emboss';
					$filters_applied++;
					break;
				case 'blur':
				case 'blurgaussian':
					imagefilter( $image, IMG_FILTER_GAUSSIAN_BLUR );
					if ( ! in_array( 'filter_blurgaussian', $this->processed ) )
						$this->processed[] = 'filter_blurgaussian';
					$filters_applied++;
					break;
				case 'blurselective':
					imagefilter( $image, IMG_FILTER_SELECTIVE_BLUR );
					if ( ! in_array( 'filter_blurselective', $this->processed ) )
						$this->processed[] = 'filter_blurselective';
					$filters_applied++;
					break;
				case 'mean':
				case 'meanremoval':
					imagefilter( $image, IMG_FILTER_MEAN_REMOVAL );
					if ( ! in_array( 'filter_meanremoval', $this->processed ) )
						$this->processed[] = 'filter_meanremoval';
					$filters_applied++;
					break;
			}
		}
		if ( 'Gmagick' == $this->image_object_type )
			$this->gd_to_gmagick( $image );
	}

	/**
	 * Adjusts image brightness (-255 through 255)
	 *
	 * @param resource $original The source image resource
	 * @param resource $brightness The brightness adjustment value
	 **/
	public function brightness( &$image, $brightness ) {
		$brightness = intval( $brightness );

		if ( 'Gmagick' == $this->image_object_type )
			$this->gmagick_to_gd( $image );

		imagefilter( $image, IMG_FILTER_BRIGHTNESS, $brightness );

		if ( 'Gmagick' == $this->image_object_type )
			$this->gd_to_gmagick( $image );

		if ( ! in_array( 'brightness', $this->processed ) )
			$this->processed[] = 'brightness';
	}

	/**
	 * Adjusts image contrast (-100 through 100)
	 *
	 * @param resource $original The source image resource
	 * @param resource $contrast The contrast adjustment value
	 **/
	public function contrast( &$image, $contrast ) {
		$contrast = intval( $contrast );

		if ( 'Gmagick' == $this->image_object_type )
			$this->gmagick_to_gd( $image );

		// Make +value increase contrast by multiplying by -1
		imagefilter( $image, IMG_FILTER_CONTRAST, $contrast * -1 );

		if ( 'Gmagick' == $this->image_object_type )
			$this->gd_to_gmagick( $image );

		if ( ! in_array( 'contrast', $this->processed ) )
			$this->processed[] = 'contrast';
	}

	/**
	 * Hues the image to a certain color:  red,green,blue
	 *
	 * @param resource $original The source image resource
	 * @param resource $colors A comma seperated rgb value (255,255,255 = white)
	 **/
	public function colorize( &$image, $colors ) {
		$colors = explode( ',', $colors );
		$color = array_map( 'intval', $colors );

		$red   = ( ! empty( $color[0]) ) ? $color[0] : 0;
		$green = ( ! empty( $color[1]) ) ? $color[1] : 0;
		$blue  = ( ! empty( $color[2]) ) ? $color[2] : 0;

		if ( 'Gmagick' == $this->image_object_type )
			$this->gmagick_to_gd( $image );

		imagefilter( $image, IMG_FILTER_COLORIZE, $red, $green, $blue );

		if ( 'Gmagick' == $this->image_object_type )
			$this->gd_to_gmagick( $image );

		if ( ! in_array( 'colorize', $this->processed ) )
			$this->processed[] = 'colorize';
	}

	/**
	 * Adjusts image smoothness
	 *
	 * @param resource $original The source image resource
	 * @param resource $smoothness The smoothness adjustment value
	 **/
	public function smooth( &$image, $smoothness ) {
		if ( 'Gmagick' == $this->image_object_type )
			$this->gmagick_to_gd( $image );

		imagefilter( $image, IMG_FILTER_SMOOTH, floatval( $smoothness ) );

		if ( 'Gmagick' == $this->image_object_type )
			$this->gd_to_gmagick( $image );

		if ( ! in_array( 'smooth', $this->processed ) )
			$this->processed[] = 'smooth';
	}

} // class Image_Effect

} // class_exists
