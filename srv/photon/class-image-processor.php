<?php

if ( ! class_exists( 'Image_Processor' ) ) {

require_once ( dirname( __FILE__ ) . '/class-gif-image.php' );
require_once ( dirname( __FILE__ ) . '/class-jpeg-image.php' );
require_once ( dirname( __FILE__ ) . '/class-image-effect.php' );

class Image_Processor {

	private $_JPG_MAX_QUALITY;
	private $_PNG_MAX_QUALITY;
	private $_WEBP_MAX_QUALITY;
	private $_UPSCALE_MAX_PIXELS;
	private $_UPSCALE_MAX_PIXELS_GIF;
	private $_IMAGE_MAX_WIDTH;
	private $_IMAGE_MAX_HEIGHT;

	private $_PNGCRUSH;
	private $_PNGQUANT;
	private $_OPTIPNG;
	private $_JPEGTRAN;
	private $_JPEGOPTIM;
	private $_CWEBP;

	private $_CWEBP_LOSSLESS;
	private $_CWEBP_DEFAULT_META_STRIP;
	private $_DISABLE_IMAGE_OPTIMIZATIONS;
	private $_ALLOW_DIMS_CHAINING;

	private $image = null;
	private $image_data = null;
	private $image_width = 0;
	private $image_height = 0;
	private $image_type = 0;
	private $image_format = '';
	private $mime_type = '';

	public static $allowed_functions = array( 'w', 'h', 'crop',
			'crop_offset', 'resize', 'fit', 'lb', 'ulb', 'filter',
			'brightness', 'contrast', 'colorize', 'smooth',
		);

	private $jpeg_details = null;
	private $upscale = null;
	private $quality = null;
	private $norm_color_profile = true;
	private $send_nosniff_header = true;
	public $use_client_hints = false;
	private $dpr_header_value = false;
	private $send_etag_header = false;
	private $send_bytes_saved = false;
	private $canonical_url = null;
	private $image_max_age = null;
	private $bytes_saved = 0;
	private $icc_profile_removed_size = 0;
	private $processed = array();

	function __construct() {
		// These constants should be defined externally to override the defaults
		$this->_JPG_MAX_QUALITY        = defined( 'JPG_MAX_QUALITY' ) ? JPG_MAX_QUALITY : 100;
		$this->_PNG_MAX_QUALITY        = defined( 'PNG_MAX_QUALITY' ) ? PNG_MAX_QUALITY : 100;
		$this->_WEBP_MAX_QUALITY       = defined( 'WEBP_MAX_QUALITY' ) ? WEBP_MAX_QUALITY : 100;
		$this->_UPSCALE_MAX_PIXELS     = defined( 'UPSCALE_MAX_PIXELS' ) ? UPSCALE_MAX_PIXELS : 1024;
		$this->_UPSCALE_MAX_PIXELS_GIF = defined( 'UPSCALE_MAX_PIXELS_GIF' ) ? UPSCALE_MAX_PIXELS_GIF : 1024;
		$this->_IMAGE_MAX_WIDTH        = defined( 'IMAGE_MAX_WIDTH' ) ? IMAGE_MAX_WIDTH : 20000;
		$this->_IMAGE_MAX_HEIGHT       = defined( 'IMAGE_MAX_HEIGHT' ) ? IMAGE_MAX_HEIGHT : 20000;

		// Allow global image optimization disabling for server load management
		$this->_DISABLE_IMAGE_OPTIMIZATIONS = defined( 'DISABLE_IMAGE_OPTIMIZATIONS' ) ? DISABLE_IMAGE_OPTIMIZATIONS  : false;

		if ( ! $this->_DISABLE_IMAGE_OPTIMIZATIONS ) {
			// External defines should be set to the local file path of the utilities
			$this->_PNGCRUSH  = defined( 'PNGCRUSH' )  ? PNGCRUSH  : false;
			$this->_PNGQUANT  = defined( 'PNGQUANT' )  ? PNGQUANT  : false;
			$this->_OPTIPNG   = defined( 'OPTIPNG' )   ? OPTIPNG   : false;
			$this->_JPEGTRAN  = defined( 'JPEGTRAN' )  ? JPEGTRAN  : false;
			$this->_JPEGOPTIM = defined( 'JPEGOPTIM' ) ? JPEGOPTIM : false;
			$this->_CWEBP     = defined( 'CWEBP' )     ? CWEBP     : false;
		}

		// Whether the image processor produces lossless WebP images or not
		$this->_CWEBP_LOSSLESS = defined( 'CWEBP_LOSSLESS' ) ? CWEBP_LOSSLESS : false;

		// The meta data to strip from WebP images by default. Passing the request parameter
		// 'strip=all|info|color|none' will override this setting.
		$this->_CWEBP_DEFAULT_META_STRIP = defined( 'CWEBP_DEFAULT_META_STRIP' ) ? CWEBP_DEFAULT_META_STRIP : false;

		// Whether the class will process all the 'w' and 'h' query arguments or just the first one.
		// For example, if the query args are 'w=100&h=150' then, without chaining, only the first 'w' parameter
		// change is applied, however with chaining enabled, first the width would be applied and then the height.
		$this->_ALLOW_DIMS_CHAINING = defined( 'ALLOW_DIMS_CHAINING' ) ? ALLOW_DIMS_CHAINING : false;
	}

	function __destruct() {
		;
	}

	public function __set( $name, $value ) {
		$this->$name = $value;
	}

	public function __get( $name ) {
		return $this->$name;
	}

	private function send_headers( $file_size = null ) {
		if ( $file_size )
			header( 'Content-Length: ' . $file_size );

		header( 'Content-Type: ' . $this->mime_type );
		header( 'Last-Modified: ' . gmdate( 'D, d M Y H:i:s', time() ) . ' GMT' );

		if ( $this->image_max_age ) {
			header( 'Expires: ' . gmdate( 'D, d M Y H:i:s', time() + $this->image_max_age ) . ' GMT' );
			header( 'Cache-Control: public, max-age=' . $this->image_max_age );
		}
		if ( $this->canonical_url ) {
			header( 'Link: <' . $this->canonical_url . '>; rel="canonical"' );
		}
		if ( $this->send_nosniff_header ) {
			header( 'X-Content-Type-Options: nosniff' );
		}
		if ( $this->send_etag_header && $file_size ) {
			header( 'ETag: "' . substr( md5( $file_size . '.' . time() ), 0, 16 ) . '"' );
		}
		if ( 0 < $this->bytes_saved ) {
			header( 'X-Bytes-Saved: ' . $this->bytes_saved );
		}
		if ( $this->_DISABLE_IMAGE_OPTIMIZATIONS ) {
			header( "X-Optim-Disabled: true" );
		}
		if ( $this->_CWEBP && 'image/gif' != $this->mime_type ) {
			// The Vary header must be sent for all non-GIF images if WEBP is enabled
			header( 'Vary: Accept' );
		}
		if ( false !== $this->dpr_header_value ) {
			header( 'Content-DPR: ' . $this->dpr_header_value );
		}
	}

	private function pngcrush( $file ) {
		$transformed = tempnam( '/dev/shm/', 'crush-post-' );
		$cmd = $this->_PNGCRUSH . " $file $transformed 2>&1";
		exec( $cmd, $o, $e );

		if ( $e == 0 && file_exists( $transformed ) ) {
			rename( $transformed, $file );
		} else {
			@unlink( $transformed );
		}
	}

	private function optipng( $file, $flags = '' ) {
		$transformed = tempnam( '/dev/shm/', 'tran-opti-' );
		if ( ! copy( $file, $transformed ) ) {
			@unlink( $transformed );
			return;
		}

		$cmd = $this->_OPTIPNG . " $flags $transformed >/dev/null 2>&1";
		exec( $cmd, $o, $e );

		if ( $e == 0 && file_exists( $transformed ) ) {
			rename( $transformed, $file );
		} else {
			@unlink( $transformed );
		}
	}

	private function jpegtran( $file ) {
		$transformed = tempnam( '/dev/shm/', 'tran-post-' );
		$cmd = $this->_JPEGTRAN . " -copy all -optimize -progressive -outfile $transformed $file 2>&1";
		exec( $cmd, $o, $e );

		if ( $e == 0 && file_exists( $transformed ) ) {
			rename( $transformed, $file );
		} else {
			@unlink( $transformed );
		}
	}

	private function webp_supported() {
		return ( $this->_CWEBP && false !== $this->_CWEBP &&
			( ( isset( $_SERVER['HTTP_ACCEPT'] ) && false !== strpos( $_SERVER['HTTP_ACCEPT'], 'image/webp' ) ) ||
			isset( $_GET['webp'] ) && 1 == intval( $_GET['webp'] ) ) );
	}

	private function cwebp( $file ) {
		$transformed = tempnam( '/dev/shm/', 'tran-webp-' );
		$cmd = "{$this->_CWEBP} -quiet";
		if ( $this->_CWEBP_LOSSLESS )
			$cmd .= ' -lossless';
		$strip = isset( $_GET['strip'] ) ? $_GET['strip'] : $this->_CWEBP_DEFAULT_META_STRIP;
		switch ( $strip ) {
			case 'all':
				$cmd .= ' -metadata none';
				break;
			case 'info':
				$cmd .= ' -metadata icc';
				break;
			case 'color':
				$cmd .= ' -metadata exif,xmp';
				break;
			default:
				$cmd .= ' -metadata all';
		}
		if ( 'image/jpeg' == $this->mime_type ) {
			if ( $strip && in_array( $strip, array( 'all', 'info', 'color' ), true ) ) {
				$this->exif_rotate( $file, $strip );
			}
			if ( ! $this->_CWEBP_LOSSLESS && Gmagick::IMGTYPE_GRAYSCALE == $this->image->getimagetype() ) {
				// We have to increase the quality for grayscale images otherwise they are generally too degraded.
				// This can also be fixed with the '-lossless' parameter, but that increases the size significantly.
				$this->quality = $this->_WEBP_MAX_QUALITY;
			}
			$cmd .= ' -m 2';
		} else if ( 'image/png' == $this->mime_type ) {
			$cmd .= ' -alpha_q 100';
		} else {
			$cmd .= ' -m 2';
		}

		$this->quality = min( $this->_WEBP_MAX_QUALITY, $this->quality );

		$cmd .= " -q {$this->quality} -o $transformed $file";
		exec( $cmd, $o, $e );

		if ( $e == 0 && file_exists( $transformed ) ) {
			rename( $transformed, $file );
			return true;
		} else {
			@unlink( $transformed );
			return false;
		}
	}

	private function jpegoptim( $file ) {
		$transformed = tempnam( '/dev/shm/', 'tran-jopt-' );
		if ( ! copy( $file, $transformed ) ) {
			@unlink( $transformed );
			return false;
		}

		$strip = false;
		if ( isset( $_GET['strip'] ) ) {
			$strip = $_GET['strip'];
			$this->exif_rotate( $transformed, $strip );
		}

		$cmd = $this->_JPEGOPTIM . ' -T0.0 --all-progressive';
		switch ( $strip ) {
			case 'all':
				$cmd .= ' -f --strip-all';
				break;
			case 'info':
				$cmd .= ' -f --strip-com --strip-exif --strip-iptc';
				break;
			case 'color':
				$cmd .= ' -f --strip-icc';
				break;
		}
		$cmd .= " -p $transformed";
		exec( $cmd, $o, $e );

		if ( $e == 0 && file_exists( $transformed ) ) {
			rename( $transformed, $file );
			return true;
		} else {
			@unlink( $transformed );
			return false;
		}
	}

	private function exif_rotate( $file, $strip ) {
		if ( ! function_exists( 'exif_read_data' ) )
			return;
		if ( ! in_array( $strip, array( 'all', 'info' ) ) )
			return;

		// If there is invalid EXIF data this will emit a warning, even
		// when using the @ operator.  A correct approach would be to validate
		// the EXIF data before attempting to use it.  For now, just
		// avoiding spewing warnings.
		$old_level = error_reporting( E_ERROR );
		$exif = @exif_read_data( $file );
		error_reporting( $old_level );

		if ( false === $exif || ! isset( $exif[ 'Orientation' ] ) )
			return;

		$degrees = 0;
		switch( $exif[ 'Orientation' ] ) {
			case 3:
				$degrees = 180;
				break;
			case 6:
				$degrees = 90;
				break;
			case 8:
				$degrees = 270;
				break;
		}
		if ( $degrees ) {
			$this->image->readimage( $file );
			$this->image->rotateimage( 'black', $degrees );
			$this->image->writeimage( $file );
		}
	}

	private function compress_and_send_png() {
		$output = tempnam( '/dev/shm/', 'pre-' );
		register_shutdown_function( 'unlink', $output );

		// If the image color was originally PALETTE and it has been changed, then change it back
		// to PALETTE, but only if PNGQUANT is disabled (otherwise the final file size is larger)
		if ( ! $this->_PNGQUANT && Gmagick::IMGTYPE_PALETTE == $this->image_type &&
			Gmagick::IMGTYPE_PALETTE != $this->image->getimagetype() ) {
			$this->image->setimagetype( Gmagick::IMGTYPE_PALETTE );
		}

		if ( $this->quality ) {
			if ( 100 == $this->quality )
				$this->_CWEBP_LOSSLESS = true;
			$this->quality = min( max( intval( $this->quality ), 20 ), $this->_PNG_MAX_QUALITY );
		} else {
			$this->quality = $this->_PNG_MAX_QUALITY;
		}

		$this->image->setcompressionquality( $this->quality );
		$this->image->setimageformat( 'PNG' );
		$this->clear_and_normalize_profile();
		$this->image->writeimage( $output );

		if ( $this->send_bytes_saved )
			$o_size = filesize( $output );

		if ( $this->webp_supported() && $this->cwebp( $output ) ) {
			$this->mime_type = 'image/webp';
		} else if ( $this->_PNGQUANT ) {
			exec( $this->_PNGQUANT . " --speed 5 --quality={$this->quality}-100 -f -o $output $output" );
			if ( $this->_OPTIPNG )
				$this->optipng( $output, '-o1' );
		} else if ( $this->_PNGCRUSH ) {
			$this->pngcrush( $output );
		} else if ( $this->_OPTIPNG )  {
			$this->optipng( $output );
		}

		if ( $this->send_bytes_saved ) {
			clearstatcache();
			$n_size = filesize( $output );
			$this->bytes_saved = $o_size - $n_size;
		} else {
			$n_size = filesize( $output );
		}
		$this->send_headers( $n_size );
		readfile( $output );
	}

	private function compress_and_send_jpeg() {
		$output = tempnam( '/dev/shm/', 'pre-' );
		register_shutdown_function( 'unlink', $output );

		$original_quality = $this->_JPG_MAX_QUALITY;

		if ( isset( $this->jpeg_details['q'] ) && ! empty( $this->jpeg_details['q'] ) )
			$original_quality = $this->jpeg_details['q'];

		if ( $this->quality ) {
			if ( 100 == $this->quality )
				$this->_CWEBP_LOSSLESS = true;
			$this->quality = min( max( intval( $this->quality ), 20 ), $original_quality );
		} else {
			$this->quality = min( $this->_JPG_MAX_QUALITY, $original_quality );
		}

		// We need to set the mimetype here as we may have a webp image stored but the current client does
		// not support webp. In which case we will serve a jpeg image created from the webp image source.
		// The same applies to any other image types in the system, BMP, etc. which are also served as JPEGs.
		$this->mime_type = 'image/jpeg';

		$this->image->setimageformat( 'JPEG' );
		$this->image->setcompressionquality( $this->quality );
		$this->clear_and_normalize_profile();
		$this->image->writeimage( $output );

		if ( $this->send_bytes_saved )
			$o_size = filesize( $output );

		if ( $this->webp_supported() && $this->cwebp( $output ) ) {
			$this->mime_type = 'image/webp';
		} else if ( $this->_JPEGOPTIM ) {
			$this->jpegoptim( $output );
		} else if ( $this->_JPEGTRAN ) {
			$this->jpegtran( $output );
		}

		if ( $this->send_bytes_saved ) {
			clearstatcache();
			$n_size = filesize( $output );
			$this->bytes_saved = $o_size - $n_size;
		} else {
			$n_size = filesize( $output );
		}
		$this->send_headers( $n_size );
		readfile( $output );
	}

	private function process_and_send_gif() {
		unset( $this->image_data );

		if ( ! $this->image->process_image_functions( $this->_UPSCALE_MAX_PIXELS_GIF ) ) {
			if ( function_exists( 'imageresize_graceful_fail' ) ) {
				imageresize_graceful_fail();
			} else if ( function_exists( 'httpdie' ) ) {
				httpdie( '400 Bad Request', 'Sorry, the parameters you provided were not valid' );
			} else {
				header( 'HTTP/1.1 400 Bad Request' );
				die( 'Sorry, the parameters you provided were not valid' );
			}
		}

		// The GIF class does it's own accounting for what processing functions
		// were successful in it and the Image_Effect class. So we use it's array.
		$this->processed = $this->image->get_processed();

		$this->send_headers();
		echo $this->image->get_image_blob();
	}

	private function load_jpeg_info() {
		$jpeg = new Jpeg_Image();
		$this->jpeg_details = $jpeg->get_jpeg_details( $this->image_data );
	}

	private function valid_request( $new_w, $new_h ) {
		// Just serve the original image if:
		// the requested dims are the same as the source image and we are not changing quality or stripping jpeg info
		//   or
		// one or both of the requested image dims are too small
		//   or
		// we have upscaled without permission (upscale == false) or have overstepped the upscale limits
		if ( ( $new_w == $this->image_width && $new_h == $this->image_height &&
				! ( 'image/jpeg' == $this->mime_type && ( isset( $_GET['quality'] ) || isset( $_GET['strip'] ) ) ) ) ||
			( $new_w < 3 || $new_h < 3 ) ||
			( $new_w > $this->image_width && $new_h > $this->image_height &&
				( ! $this->upscale || $new_w > $this->_UPSCALE_MAX_PIXELS || $new_h > $this->_UPSCALE_MAX_PIXELS ) ) ) {
			return false;
		}
		return true;
	}

	private function clear_and_normalize_profile() {
		try {
			if ( ! $this->norm_color_profile ) {
				return;
			}
			$profile = '';
			try {
				// throws an error if there isn't an ICM profile
				$profile = $this->image->getimageprofile( 'ICM' );
			} catch ( GmagickException $e ) {
				return;
			}
			$this->icc_profile_removed_size = strlen( $profile );
			if ( 0 == $this->icc_profile_removed_size )
				return;

			$icc = file_get_contents( dirname( __FILE__ ) . '/icc-profiles/sRGB.icc' );

			// apply the sRGB profile
			$this->image->profileimage( 'ICM', $icc );

			if ( 'image/jpeg' == $this->mime_type ) {
				$res = $this->image->getimageresolution();
				// if GMagick returns an invalid resolution, we check whether our jpeg header reading has
				// a valid resolution higher than the default of 72x72. If it does we use that setting.
				if ( ( $res['x'] < 72 || $res['y'] < 72 ) && ( $this->jpeg_details['x'] > 72 && $this->jpeg_details['y'] > 72 ) )
						$this->image->setimageresolution( $this->jpeg_details['x'], $this->jpeg_details['y'] );

				if ( Gmagick::COLORSPACE_RGB != $this->image->getimagecolorspace() )
					$this->image->setimagecolorspace( Gmagick::COLORSPACE_RGB );
			}

			// we now remove the profile from the image as the browser defaults work without it
			$this->image->setimageprofile( 'ICM', NULL );
		}
		catch ( GmagickException $e ) {
			return;
		}
	}

	private function get_memory_limit() {
		$mem_limit = ini_get( 'memory_limit' );
		if ( preg_match( '/^[0-9]+G$/', $mem_limit ) )
			return intval( str_replace( 'G', '', $mem_limit ) ) * 1024 * 1024 * 1024;
		else if ( preg_match( '/^[0-9]+M$/', $mem_limit ) )
			return intval( str_replace( 'M', '', $mem_limit ) ) * 1024 * 1024;
		else if ( preg_match( '/^[0-9]+K$/', $mem_limit ) )
			return intval( str_replace( 'K', '', $mem_limit ) ) * 1024;
		else if ( 0 < intval( $mem_limit ) )
			return intval( $mem_limit );
		else
			return 128 * 1024 * 1024; // default setting for the PHP memory limit
	}

	private function acceptable_dimensions() {
		if ( 24 > strlen( $this->image_data ) )
			return false;

		$w = $h = 0;
		if ( 'GIF8' === substr( $this->image_data, 0, 4 ) ) {
			$w = ord( substr( $this->image_data, 7, 1 ) ) << 8;
			$w = $w | ord( substr( $this->image_data, 6, 1 ) );

			$h = ord( substr( $this->image_data, 9, 1 ) ) << 8;
			$h = $h | ord( substr( $this->image_data, 8, 1 ) );
		} else if ( "PNG\r\n\032\n" == substr( $this->image_data, 1, 7 ) &&
				( 'IHDR' == substr( $this->image_data, 12, 4 ) ) ) {
			$w = ord( substr( $this->image_data, 16, 1 ) ) << 8;
			$w = ( $w | ord( substr( $this->image_data, 17, 1 ) ) ) << 8;
			$w = ( $w | ord( substr( $this->image_data, 18, 1 ) ) ) << 8;
			$w = $w | ord( substr( $this->image_data, 19, 1 ) );

			$h = ord( substr( $this->image_data, 20, 1 ) ) << 8;
			$h = ( $h | ord( substr( $this->image_data, 21, 1 ) ) ) << 8;
			$h = ( $h | ord( substr( $this->image_data, 22, 1 ) ) ) << 8;
			$h = $h | ord( substr( $this->image_data, 23, 1 ) );
		} else {
			$this->load_jpeg_info();

			if ( isset( $this->jpeg_details['x'] ) )
				$w = $this->jpeg_details['x'];
			if ( isset( $this->jpeg_details['y'] ) )
				$h = $this->jpeg_details['y'];
		}

		return ( $this->_IMAGE_MAX_WIDTH > $w || $this->_IMAGE_MAX_HEIGHT > $h );
	}

	public function load_image() {
		if ( ! $this->image_data || ! $this->acceptable_dimensions() )
			return false;

		if ( 'GIF' === substr( $this->image_data, 0, 3 ) ) {
			$this->image = new Gif_Image( $this->image_data );
			$this->image->disable_zoom(); // zooming is handled in this class
			if ( ! $this->send_etag_header )
				$this->image->disable_etag_header();
			$this->image_format = 'gif';
			$this->mime_type = 'image/gif';
			$this->image_width = $this->image->get_image_width();
			$this->image_height = $this->image->get_image_height();
		} else {
			try {
				$this->image = new Gmagick();
				$this->image->readimageblob( $this->image_data );

				$this->image_format = strtolower( $this->image->getimageformat() );
				if ( in_array( $this->image_format, array( 'jpg', 'jpeg' ) ) ) {
					$this->mime_type = 'image/jpeg';
					if ( ! isset( $this->jpeg_details ) )
						$this->load_jpeg_info();
				} elseif ( 'png' == $this->image_format ) {
					$this->mime_type = 'image/png';
				} elseif ( 'bmp' == $this->image_format ) {
					$this->mime_type = 'image/bmp';
				} elseif ( 'webp' == $this->image_format ) {
					$this->mime_type = 'image/webp';
				} else {
					$this->mime_type = 'application/octet-stream';
				}
				unset( $this->image_data );

				$this->image_width = $this->image->getimagewidth();
				$this->image_height = $this->image->getimageheight();
				$this->image_type = $this->image->getimagetype();
			} catch ( GmagickException $e ) {
				return false;
			}
		}
		return true;
	}

	public function get_transformation_args() {
		$retA = array();
		foreach( $_GET as $arg => $val ) {
			if ( ! is_numeric( $arg ) && in_array( $arg, self::$allowed_functions, true ) ) {
				$retA[ $arg ] = $val;
			}
		}
		return $retA;
	}

	public function process_image() {
		$this->upscale = isset( $_GET['upscale'] ) && '1' == $_GET['upscale'];
		if ( isset( $_GET['quality'] ) && 0 != intval( $_GET['quality'] ) )
			$this->quality = intval( $_GET['quality'] );

		$args = $this->get_transformation_args();

		// This type of crop is always processed first and the rest of the
		// arguments are processed in the order that they were specified in
		if ( isset( $args['crop'] ) && '1' == $args['crop'] ) {
			$requested_w = ( ! empty( $args['w'] ) ) ? max( 0, intval( $args['w'] ) ) : null;
			$requested_h = ( ! empty( $args['h'] ) ) ? max( 0, intval( $args['h'] ) ) : null;
			if ( isset( $args['w'] ) ) unset( $args['w'] );
			if ( isset( $args['h'] ) ) unset( $args['h'] );
			if ( ( $requested_w || $requested_h ) && $this->crop( $requested_w, $requested_h ) )
				$this->processed[] = 'crop';
			unset( $args['crop'] );
		}

		reset( $args );

		while ( 0 < count( $args ) ) {
			// get the next argument to process
			$arg = key( $args );
			$val = current( $args );

			switch ( $arg ) {
			case 'crop':
				if ( ! is_array( $val ) && strpos( $val, ',' ) ) {    // 1st-style crop (crop=x,y,w,h)
					$params = explode( ',', $val );
					if ( 4 == count( $params ) && $this->crop_offset( $params[0], $params[1], $params[2], $params[3] ) )
						$this->processed[] = 'crop_offset';
				} else if ( '1' == $val ) {     // 2nd-style crop (w=X&h=Y&crop=1)
					$requested_w = ( ! empty( $args['w'] ) ) ? max( 0, intval( $args['w'] ) ) : null;
					$requested_h = ( ! empty( $args['h'] ) ) ? max( 0, intval( $args['h'] ) ) : null;
					if ( isset( $args['w'] ) ) unset( $args['w'] );
					if ( isset( $args['h'] ) ) unset( $args['h'] );
					if ( ( $requested_w || $requested_h ) && $this->crop( $requested_w, $requested_h ) )
						$this->processed[] = 'crop';
				}
				unset( $args['crop'] );
				break;

			case 'crop_offset':  // explicit 1st-style crop (crop=x,y,w,h)
				$params = explode( ',', $val );
				unset( $args['crop_offset'] );
				if ( 4 == count( $params ) && $this->crop_offset( $params[0], $params[1], $params[2], $params[3] ) )
					$this->processed[] = 'crop_offset';
				break;

			case 'resize':
				$s_params = $this->apply_zoom( 'resize', $val );
				unset( $args['resize'] );
				$params = explode( ',', $s_params );
				if ( 2 == count( $params ) && $this->resize( $params[0], $params[1] ) )
					$this->processed[] = 'resize_and_crop';
				break;

			case 'fit':
				$s_params = $this->apply_zoom( 'fit', $val );
				unset( $args['fit'] );
				$params = explode( ',', $s_params );
				if ( 2 == count( $params ) && $this->fit( $params[0], $params[1] ) )
					$this->processed[] = 'fit_in_box';
				break;

			case 'w':
				$requested_w = ( ! empty( $val ) ) ? max( 0, intval( $val ) ) : null;
				unset( $args['w'] );
				if ( $requested_w ) {
					$w = $this->apply_zoom( 'set_width', $requested_w );
					if ( $this->set_width( $w ) ) {
						$this->processed[] = 'set_width';
						if ( ! $this->_ALLOW_DIMS_CHAINING && isset( $args['h'] ) )
							unset( $args['h'] );
					}
				}
				break;

			case 'h':
				$requested_h = ( ! empty( $val ) ) ? max( 0, intval( $val ) ) : null;
				unset( $args['h'] );
				if ( $requested_h ) {
					$h = $this->apply_zoom( 'set_height', $requested_h );
					if ( $this->set_height( $h ) ) {
						$this->processed[] = 'set_height';
						if ( ! $this->_ALLOW_DIMS_CHAINING && isset( $args['w'] ) )
							unset( $args['w'] );
					}
				}
				break;

			case 'brightness':
			case 'colorize':
			case 'contrast':
			case 'filter':
			case 'smooth':
				if ( 'image/gif' == $this->mime_type ) {
					$this->image->add_function( $arg, $val );
				} else {
					// Due to the fact that these functions require memory intensive conversions back and forth
					// between Gmagick and GD, we only allow them to be executed if the (roughly estimated)
					// memory requirement for executing them will be less than the current script memory limit
					// smooth requires at worst around 2.5 times more memory, in addition to the 1.7 for the working set.
					$est_gd_size = $this->image_width * $this->image_height * 4 * 1.7 * 2.5;
					if ( memory_get_usage( true ) + $est_gd_size < $this->get_memory_limit() ) {
						$effect = new Image_Effect( 'Gmagick', $this->mime_type );
						if ( method_exists( $effect, $arg ) ) {
							$effect->$arg( $this->image, $val );
							if ( is_array( $effect->processed ) )
								$this->processed = array_merge( $this->processed, $effect->processed );
						}
					}
				}
				unset( $args[$arg] );
				break;

			case 'lb':
				// letterboxing is not available for GIF images
				if ( 'image/gif' != $this->mime_type && $this->letterbox( $val ) )
					$this->processed[] = 'letterbox';

				unset( $args['lb'] );
				break;

			case 'ulb':
				// unletterboxing is not available for GIF images
				if ( 'image/gif' != $this->mime_type && 'true' == $val ) {
					// Due to the fact that these functions require memory intensive conversions back and forth
					// between Gmagick and GD, we only allow unletterboxing if the (roughly estimated) memory
					// requirement will be less than the script memory limit
					$est_gd_size = $this->image_width * $this->image_height * 4 * 1.7;
					if ( memory_get_usage( true ) + $est_gd_size < $this->get_memory_limit() && $this->unletterbox() ) {
						$this->processed[] = 'unletterbox';
					}
				}

				unset( $args['ulb'] );
				break;

			default:
				error_log( "Error: an unknown arg made it through: $arg" );
				unset( $args[ $arg ] );
				break;
			}
		}

		switch ( $this->mime_type ) {
			case 'image/gif':
				if ( 0 == count( $this->processed ) ) {
					// Unset the Gif_Image object to free some memory for the `echo` below
					unset( $this->image );
					$this->send_headers( strlen( $this->image_data ) );
					echo $this->image_data;
				} else {
					$this->process_and_send_gif();
				}
				break;
			case 'image/png':
				$this->compress_and_send_png();
				break;
			default:
				$this->compress_and_send_jpeg();
				break;
		}
	}

	public function crop( $width, $height ) {
		// crop the largest possible portion of the original image
		$aspect_ratio = $this->image_width / $this->image_height;
		$new_w = min( $width, $this->image_width );
		$new_h = min( $height, $this->image_height );

		if ( ! $new_w )
			$new_w = intval( $new_h * $aspect_ratio );
		if ( ! $new_h )
			$new_h = intval( $new_w / $aspect_ratio );

		$size_ratio = max( $new_w / $this->image_width, $new_h / $this->image_height );

		$crop_w = min( ceil( $new_w / $size_ratio ), $this->image_width );
		$crop_h = min( ceil( $new_h / $size_ratio ), $this->image_height );

		$s_x = round( ( $this->image_width - $crop_w ) / 2 );
		$s_y = round( ( $this->image_height - $crop_h ) / 2 );

		// checks params and skips this transformation if it is found to be out of bounds
		if ( ! $this->valid_request( $new_w, $new_h ) )
			return false;

		if ( 'image/gif' == $this->mime_type ) {
			$this->image->add_function( 'resize_and_crop', ( $width . ',' . $height ) );
			return true;
		}

		$this->image->cropimage( $crop_w, $crop_h, $s_x, $s_y );

		if ( 'image/png' == $this->mime_type && 1 < $this->image->getimagechanneldepth( Gmagick::CHANNEL_OPACITY ) )
			$this->image->resizeimage( $new_w, $new_h, Gmagick::FILTER_LANCZOS, 1.0 );
		else
			$this->image->scaleimage( $new_w, $new_h );

		$this->image_width = $new_w;
		$this->image_height = $new_h;
		return true;
	}

	public function crop_offset( $x, $y, $width, $height ) {
		// crop using the offsets
		if ( substr( $width, -2 ) == 'px' )
			$new_w = max( 0, min( $this->image_width, intval( $width ) ) );
		else
			$new_w = round( $this->image_width * abs( intval( $width ) ) / 100 );

		if ( substr( $height, -2 ) == 'px' )
			$new_h = max( 0, min( $this->image_height, intval( $height ) ) );
		else
			$new_h = round( $this->image_height * abs( intval( $height ) ) / 100 );

		if ( substr( $x, -2 ) == 'px' )
			$s_x = intval( $x );
		else
			$s_x = round( $this->image_width * abs( intval( $x ) ) / 100 );

		if ( substr( $y, -2 ) == 'px' )
			$s_y = intval( $y );
		else
			$s_y = round( $this->image_height * abs( intval( $y ) ) / 100 );

		// check we haven't overstepped any boundaries
		if ( $s_x >= $this->image_width ) $s_x = 0;
		if ( $s_y >= $this->image_height ) $s_y = 0;
		if ( $new_w > $this->image_width - $s_x ) $new_w = $this->image_width - $s_x;
		if ( $new_h > $this->image_height - $s_y ) $new_h = $this->image_height - $s_y;

		// checks params and skips this transformation if it is found to be out of bounds
		if ( ! $this->valid_request( $new_w, $new_h ) )
			return false;

		if ( 'image/gif' == $this->mime_type ) {
			$this->image->add_function( 'crop_offset', ( $s_x . ',' . $s_y . ',' . $new_w . ',' . $new_h ) );
			return true;
		}

		$this->image->cropimage( $new_w, $new_h, $s_x, $s_y );
		$this->image_width = $new_w;
		$this->image_height = $new_h;
		return true;
	}

	public function resize( $width, $height ) {
		$new_w = $requested_w = intval( $width );
		$new_h = $requested_h = intval( $height );

		if ( 0 >= $new_w || 0 >= $new_h ||
			( ( $new_w > $this->image_width ) && ( $new_w > $this->_UPSCALE_MAX_PIXELS ) ) ||
			( ( $new_h > $this->image_height ) && ( $new_h > $this->_UPSCALE_MAX_PIXELS ) ) ) {
			return false;
		}

		if ( 'image/gif' == $this->mime_type ) { // the GIF class processes internally
			$this->image->add_function( 'resize_and_crop', ( $requested_w . ',' . $requested_h ) );
			return true;
		}

		$ratio_orig = $this->image_width / $this->image_height;
		$ratio_end = $new_w / $new_h;
		// If the original and new images are proportional (no cropping needed)
		if ( $ratio_orig == $ratio_end ) {
			$ratio = $this->image_width / $new_w;
			if ( 0 == $ratio ) {
				return false;
			}
			$this->upscale = true;
			$crop_w = $new_w;
			$crop_h = $new_h = round( $this->image_height / $ratio );
			$s_x = $s_y = 0;
		}
		// If we need to crop off the sides
		elseif ( $ratio_orig > $ratio_end ) {
			$ratio = $this->image_height / $new_h;
			if ( 0 == $ratio ) {
				return false;
			}
			$this->upscale = true;
			$new_w = round( $this->image_width / $ratio );
			$s_x = floor( ( $new_w - $requested_w ) / 2 );
			$s_y = 0;
			$crop_w = max( 0, $requested_w );
			$crop_h = max( 0, $requested_h );
		}
		// If we need to crop off the top/bottom
		elseif ( $ratio_orig < $ratio_end ) {
			$ratio = $this->image_width / $new_w;
			if ( 0 == $ratio ) {
				return false;
			}
			$this->upscale = true;
			$new_h = round( $this->image_height / $ratio );
			$s_x = 0;
			$s_y = floor( ( $new_h - $requested_h ) / 2 );
			$crop_w = max( 0, $requested_w );
			$crop_h = max( 0, $requested_h );
		}

		// check dims and skip this transformation if they are found to be out of bounds
		if ( ! $this->valid_request( $crop_w, $crop_h ) )
			return false;

		if ( 'image/png' == $this->mime_type && 1 < $this->image->getimagechanneldepth( Gmagick::CHANNEL_OPACITY ) )
			$this->image->resizeimage( $new_w, $new_h, Gmagick::FILTER_LANCZOS, 1.0 );
		else
			$this->image->scaleimage( $new_w, $new_h );

		$this->image->cropimage( $crop_w, $crop_h, $s_x, $s_y );

		$this->image_width = $crop_w;
		$this->image_height = $crop_h;
		return true;
	}

	public function fit( $width, $height ) {
		$new_w = $requested_w = abs( intval( $width ) );
		$new_h = $requested_h = abs( intval( $height ) );

		// we do not allow both new width and height to be larger at the same time
		if ( ! $requested_w || ! $requested_h ||
			( $this->image_width < $requested_w && $this->image_height < $requested_h ) ) {
			return false;
		}

		if ( 'image/gif' == $this->mime_type ) { // the GIF class processes internally
			$this->image->add_function( 'fit_in_box', ( $requested_w . ',' . $requested_h ) );
			return true;
		}

		$original_aspect = $this->image_width / $this->image_height;
		$new_aspect = $requested_w / $requested_h;
		if ( $original_aspect >= $new_aspect ) {
			$new_h = $requested_h;
			$new_w = round( $this->image_width / ( $this->image_height / $requested_h ) );
			// check we haven't overstepped the width
			if ( $new_w > $requested_w ) {
				$new_w = $requested_w;
				$new_h = round( $this->image_height / ( $this->image_width / $requested_w ) );
			}
		} else {
			$new_w = $requested_w;
			$new_h = round( $this->image_height / ( $this->image_width / $requested_w ) );
			// check we haven't overstepped the height
			if ( $new_h > $requested_h ) {
				$new_h = $requested_h;
				$new_w = round( $this->image_width / ( $this->image_height / $requested_h ) );
			}
		}

		// checks params and skips this transformation if it is found to be out of bounds
		if ( ! $this->valid_request( $new_w, $new_h ) )
			return false;

		if ( 'image/png' == $this->mime_type && 1 < $this->image->getimagechanneldepth( Gmagick::CHANNEL_OPACITY ) )
			$this->image->resizeimage( $new_w, $new_h, Gmagick::FILTER_LANCZOS, 1.0, true );
		else
			$this->image->scaleimage( $new_w, $new_h, true );

		$this->image_width = $new_w;
		$this->image_height = $new_h;
		return true;
	}

	public function set_width( $width ) {
		if ( '%' == substr( $width, -1 ) )
			$width = round( $this->image_width * abs( intval( $width ) ) / 100 );
		else
			$width = intval( $width );

		$ratio = $this->image_width / $width;
		if ( 0 == $ratio )
			return false;

		$new_w = intval( $this->image_width / $ratio );
		$new_h = intval( $this->image_height / $ratio );

		// checks params and skips this transformation if it is found to be out of bounds
		if ( ! $this->valid_request( $new_w, $new_h ) )
			return false;

		if ( 'image/gif' == $this->mime_type ) {
			$this->image->add_function( 'set_width', $new_w );
			return true;
		}

		if ( 'image/png' == $this->mime_type && 1 < $this->image->getimagechanneldepth( Gmagick::CHANNEL_OPACITY ) )
			$this->image->resizeimage( $new_w, $new_h, Gmagick::FILTER_LANCZOS, 1.0 );
		else
			$this->image->scaleimage( $new_w, $new_h );

		$this->image_width = $new_w;
		$this->image_height = $new_h;
		return true;
	}

	public function set_height( $height ) {
		if ( '%' === substr( $height, -1 ) )
			$height = round( $this->image_height * abs( intval( $height ) ) / 100 );
		else
			$height = intval( $height );

		$ratio = $this->image_height / $height;
		if ( 0 == $ratio )
			return false;

		$new_w = intval( $this->image_width / $ratio );
		$new_h = intval( $this->image_height / $ratio );

		// checks params and skips this transformation if it is found to be out of bounds
		if ( ! $this->valid_request( $new_w, $new_h ) )
			return false;

		if ( 'image/gif' == $this->mime_type ) {
			$this->image->add_function( 'set_height', $new_h );
			return true;
		}

		if ( 'image/png' == $this->mime_type && 1 < $this->image->getimagechanneldepth( Gmagick::CHANNEL_OPACITY ) )
			$this->image->resizeimage( $new_w, $new_h, Gmagick::FILTER_LANCZOS, 1.0 );
		else
			$this->image->scaleimage( $new_w, $new_h );

		$this->image_width = $new_w;
		$this->image_height = $new_h;
		return true;
	}

	function client_hint_dpr() {
		if ( true === $this->use_client_hints ) {
			if ( true === array_key_exists( 'HTTP_DPR', $_SERVER ) ) {
				return floatval( $_SERVER['HTTP_DPR'] );
			}
		}
		return false;
	}

	function determine_zoom() {
		$hint = $this->client_hint_dpr();
		if ( false !== $hint ) {
			return $hint;
		}
		if ( isset( $_GET['zoom'] ) ) {
			return floatval( $_GET['zoom'] );
		}
		return 1;
	}

	function apply_zoom( $function_name, $arguments ) {
		$zoom = $this->determine_zoom();

		// Treat zoom < 1 and === 1 the same: return early 
		// (effectively 1x zoom)
		if ( $zoom <= 1 )
			return $arguments;
		
		// Make sure that zoom is effectively never more than 10.
		$zoom = min( 10, $zoom );
		if ( $zoom > 3 ) {
			// Round UP to the nearest 0.5
			$zoom = ceil( $zoom * 2 ) / 2;
		}

		if ( false !== $this->client_hint_dpr() ) {
			// If we are responding to a request which provided a DPR
			// hint then the client is expecting a Content-DPR header
			// in the response
			$this->dpr_header_value = $zoom;
		}

		switch ( $function_name ) {
			case 'set_height' :
			case 'set_width' :
				$new_arguments = $arguments * $zoom;
				if ( substr( $arguments, -1 ) == '%' )
					$new_arguments .= '%';
				break;
			case 'fit' :
			case 'resize' :
				list( $width, $height ) = explode( ',', $arguments );
				$new_width = $width * $zoom;
				$new_height = $height * $zoom;
				// Avoid dimensions larger than original.
				while ( ( $new_width > $this->image_width || $new_height > $this->image_height ) && $zoom > 1 ) {
					// Step down in increments until we have valid dims
					if ( $zoom > 3 ) {
						$zoom -= 0.5;
					} else {
						$zoom -= 0.1;
					}
					$new_width = $width * $zoom;
					$new_height = $height * $zoom;
				}
				$new_arguments = "$new_width,$new_height";
				break;
			default :
				$new_arguments = $arguments;
		}

		return $new_arguments;
	}

	private function unletterbox() {
		$img_effect = new Image_Effect( 'Gmagick', $this->mime_type );
		$img_effect->gmagick_to_gd( $this->image );

		// confirm the image is letterboxed and then sample the color
		// and use that color to search for the dims we need to crop
		$lb_red = -1;
		$lb_green = -1;
		$lb_blue = -1;

		for ( $w = 0; $w < $this->image_width; $w++ ) {
			$rgb = imagecolorat( $this->image, $w, 0 );
			$r = ( $rgb >> 16 ) & 0xFF;
			$g = ( $rgb >> 8 ) & 0xFF;
			$b = $rgb & 0xFF;

			if ( -1 == $lb_red ) {
				$lb_red = $r;
			} else if ( $lb_red > $r + 1 || $lb_red < $r - 1 ) {
				$lb_red = -1;
				break;
			}
			if ( -1 == $lb_green ) {
				$lb_green = $g;
			} else if ( $lb_green > $g + 1 || $lb_green < $g - 1 ) {
				$lb_green = -1;
				break;
			}
			if ( -1 == $lb_blue ) {
				$lb_blue = $b;
			} else if ( $lb_blue > $b + 1 || $lb_blue < $b - 1 ) {
				$lb_blue = -1;
				break;
			}
		}

		if ( 0 > $lb_red || 0 > $lb_green || 0 > $lb_blue ) {
			$img_effect->gd_to_gmagick( $this->image );
			return false;
		}

		$first_unmatched_line = -1;
		for ( $h = 1; $h < $this->image_height; $h++ ) {
			$tr = $tg = $tb = 0;
			for ( $w = 0; $w < $this->image_width; $w++ ) {
				$rgb = imagecolorat( $this->image, $w, $h );
				$r = ( $rgb >> 16 ) & 0xFF;
				$g = ( $rgb >> 8 ) & 0xFF;
				$b = $rgb & 0xFF;
				$tr += $r;
				$tg += $g;
				$tb += $b;
			}
			$ar = $tr / $w;
			$ag = $tg / $w;
			$ab = $tb / $w;
			if ( $lb_red > $ar + 10 || $lb_red < $ar - 10 ) {
				$first_unmatched_line = $h;
				break;
			}
			if ( $lb_green > $ag + 10 || $lb_green < $ag - 10 ) {
				$first_unmatched_line = $h;
				break;
			}
			if ( $lb_blue > $ab + 10 || $lb_blue < $ab - 10 ) {
				$first_unmatched_line = $h;
				break;
			}
		}

		if ( 0 > $first_unmatched_line ) {
			$img_effect->gd_to_gmagick( $this->image );
			return false;
		}

		$last_unmatched_line = -1;
		for ( $h = $this->image_height - 1; $h >= 0; $h-- ) {
			$tr = $tg = $tb = 0;
			for( $w = 0; $w < $this->image_width; $w++ ) {
				$rgb = imagecolorat( $this->image, $w, $h );
				$r = ( $rgb >> 16 ) & 0xFF;
				$g = ( $rgb >> 8 ) & 0xFF;
				$b = $rgb & 0xFF;
				$tr += $r;
				$tg += $g;
				$tb += $b;
			}
			$ar = $tr / $w;
			$ag = $tg / $w;
			$ab = $tb / $w;
			if ( $lb_red > $ar + 10 || $lb_red < $ar - 10 ) {
				$last_unmatched_line = $h + 1;
				break;
			}
			if ( $lb_green > $ag + 10 || $lb_green < $ag - 10 ) {
				$last_unmatched_line = $h + 1;
				break;
			}
			if ( $lb_blue > $ab + 10 || $lb_blue < $ab - 10 ) {
				$last_unmatched_line = $h + 1;
				break;
			}
		}

		$img_effect->gd_to_gmagick( $this->image );

		if ( 0 > $last_unmatched_line || $last_unmatched_line <= $first_unmatched_line ) {
			return false;
		}

		return $this->crop_offset(
				'0px',
				$first_unmatched_line . 'px',
				$this->image_width . 'px',
				( $last_unmatched_line - $first_unmatched_line ) . 'px'
			);
	}

	function letterbox( $args ) {
		$arg_arr = explode( ',', $args );
		if ( 2 > count( $arg_arr ) )
			return false;

		$end_w = abs( intval( $arg_arr[0] ) );
		$end_h = abs( intval( $arg_arr[1] ) );

		if ( 3 == count( $arg_arr ) ) {
			$color = $arg_arr[2];
			if ( false === strpos( $color, '#' ) )
				$color = '#' . $color;
			if ( ! preg_match( '/^#[0-9a-fA-F]{6}$/', $color ) )
				$color = '#000';
		} else {
			$color = '#000';
		}

		if ( ( $this->image_width == $end_w && $this->image_height == $end_h ) ||
			! $end_w || ! $end_h ||
			( $this->image_width < $end_w && $this->image_height < $end_h ) ) {
			return false;
		}

		if ( 'image/png' == $this->mime_type && 1 < $this->image->getimagechanneldepth( Gmagick::CHANNEL_OPACITY ) )
			$this->image->resizeimage( $end_w, $end_h, Gmagick::FILTER_LANCZOS, 1.0, true );
		else
			$this->image->scaleimage( $end_w, $end_h, true );

		$new_w = $this->image->getimagewidth();
		$new_h = $this->image->getimageheight();

		$border_h = round( ( $end_h - $new_h ) / 2 );
		$border_w = round( ( $end_w - $new_w ) / 2 );

		if ( $border_h > $this->_UPSCALE_MAX_PIXELS || $border_w > $this->_UPSCALE_MAX_PIXELS ) {
			return false;
		}

		$this->image->borderimage( $color, $border_w, $border_h );
		$this->image_width  = $end_w;
		$this->image_height = $end_h;

		// Since we create the borders with rounded values
		// we have to chop any excessive pixels off.
		$crop_x = $border_w * 2 + $new_w - $end_w;
		$crop_y = $border_h * 2 + $new_h - $end_h;
		if ( $crop_x || $crop_y )
			$this->image->cropimage( $end_w, $end_h, $crop_x, $crop_y );

		return true;
	}

} // class Image_Processor

} // class_exists

