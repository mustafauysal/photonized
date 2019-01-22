<?php

define( 'PHOTON__ALLOW_QUERY_STRINGS', 1 );

require dirname( __FILE__ ) . '/plugin.php';
if ( file_exists( dirname( __FILE__ ) . '/../config.php' ) )
	require dirname( __FILE__ ) . '/../config.php';
else if ( file_exists( dirname( __FILE__ ) . '/config.php' ) )
	require dirname( __FILE__ ) . '/config.php';

// Explicit Configuration
$allowed_functions = apply_filters( 'allowed_functions', array(
//	'q'           => RESERVED
//	'zoom'        => global resolution multiplier (argument filter)
//	'quality'     => sets the quality of JPEG images during processing
//	'strip        => strips JPEG images of exif, icc or all "extra" data (params: info,color,all)
'h'           => 'set_height',      // done
'w'           => 'set_width',       // done
'crop'        => 'crop',            // done
'resize'      => 'resize_and_crop', // done
'fit'         => 'fit_in_box',      // done
'lb'          => 'letterbox',       // done
'ulb'         => 'unletterbox',     // compat
'filter'      => 'filter',          // compat
'brightness'  => 'brightness',      // compat
'contrast'    => 'contrast',        // compat
'colorize'    => 'colorize',        // compat
'smooth'      => 'smooth',          // compat
) );

unset( $allowed_functions['q'] );

$allowed_types = apply_filters( 'allowed_types', array(
	'gif',
	'jpg',
	'jpeg',
	'png',
) );

$disallowed_file_headers = apply_filters( 'disallowed_file_headers', array(
	'8BPS',
) );

$remote_image_max_size = apply_filters( 'remote_image_max_size', 55 * 1024 * 1024 );

/* Array of domains exceptions
 * Keys are domain name
 * Values are bitmasks with the following options:
 * PHOTON__ALLOW_QUERY_STRINGS: Append the string found in the 'q' query string parameter as the query string of the remote URL
 */
$origin_domain_exceptions = apply_filters( 'origin_domain_exceptions', array() );

// If unprocessed origin images should cached by a Photon-enabled CDN, then the CDN's base URL should be returned by the filter
$origin_image_cdn_url = apply_filters( 'origin_image_cdn_url', false );

define( 'JPG_MAX_QUALITY', 89 );
define( 'PNG_MAX_QUALITY', 80 );
define( 'WEBP_MAX_QUALITY', 80 );

// The 'w' and 'h' parameter are processed distinctly
define( 'ALLOW_DIMS_CHAINING', true );

// Strip all meta data from WebP images by default
define( 'CWEBP_DEFAULT_META_STRIP', 'all' );

// You can override this by defining it in config.php
if ( ! defined( 'UPSCALE_MAX_PIXELS' ) )
	define( 'UPSCALE_MAX_PIXELS', 2000 );

// Allow smaller upscales for GIFs, compared to the other image types
if ( ! defined( 'UPSCALE_MAX_PIXELS_GIF' ) )
	define( 'UPSCALE_MAX_PIXELS_GIF', 1000 );

// Implicit configuration
if ( file_exists( '/usr/local/bin/optipng' ) && ! defined( 'DISABLE_IMAGE_OPTIMIZATIONS' ) )
	define( 'OPTIPNG', '/usr/local/bin/optipng' );
else
	define( 'OPTIPNG', false );

if ( file_exists( '/usr/local/bin/pngquant' ) && ! defined( 'DISABLE_IMAGE_OPTIMIZATIONS' ) )
	define( 'PNGQUANT', '/usr/local/bin/pngquant' );
else
	define( 'PNGQUANT', false );

if ( file_exists( '/usr/local/bin/cwebp' ) && ! defined( 'DISABLE_IMAGE_OPTIMIZATIONS' ) )
	define( 'CWEBP', '/usr/local/bin/cwebp' );
else
	define( 'CWEBP', false );

if ( file_exists( '/usr/local/bin/jpegoptim' ) && ! defined( 'DISABLE_IMAGE_OPTIMIZATIONS' ) )
	define( 'JPEGOPTIM', '/usr/local/bin/jpegoptim' );
else
	define( 'JPEGOPTIM', false );

require dirname( __FILE__ ) . '/class-image-processor.php';

function httpdie( $code = '404 Not Found', $message = 'Error: 404 Not Found' ) {
	$numerical_error_code = preg_replace( '/[^\\d]/', '', $code );
	do_action( 'bump_stats', "http_error-$numerical_error_code" );
	header( 'HTTP/1.1 ' . $code );
	die( $message );
}

function fetch_raw_data( $url, $timeout = 10, $connect_timeout = 3, $max_redirs = 3, $fetch_from_origin_cdn = false ) {
	// reset image data since we redirect recursively
	$GLOBALS['raw_data'] = '';
	$GLOBALS['raw_data_size'] = 0;

	if ( $fetch_from_origin_cdn ) {
		// Construct a Photon request for the unprocessed origin image
		$timeout = $timeout + 2;
		$is_ssl  = preg_match( '|^https://|', $url );
		$path    = preg_replace( '|^http[s]?://|', '', $url );
		$url     = $GLOBALS['origin_image_cdn_url'] . $path;
		if ( $is_ssl ) {
			$url .= '?ssl=1';
		}
	}

	$parsed = parse_url( apply_filters( 'url', $url ) );
	$required = array( 'scheme', 'host', 'path' );

	if ( ! $parsed || count( array_intersect_key( array_flip( $required ), $parsed ) ) !== count( $required ) ) {
		do_action( 'bump_stats', 'invalid_url' );
		return false;
	}

	$ip   = gethostbyname( $parsed['host'] );
	$port = getservbyname( $parsed['scheme'], 'tcp' );
	$url  = $parsed['scheme'] . '://' . $parsed['host'] . $parsed['path'];

	if ( PHOTON__ALLOW_QUERY_STRINGS && isset( $parsed['query'] ) ) {
		$host = strtolower( $parsed['host'] );
		if ( array_key_exists( $host, $GLOBALS['origin_domain_exceptions'] ) ) {
			if ( $GLOBALS['origin_domain_exceptions'][$host] ) {
				$url .= '?' . $parsed['query'];
			}
		}
	}

	// Ensure we maintain our SSL flag for 'fetch_from_origin_cdn' requests,
	// regardless of whether PHOTON__ALLOW_QUERY_STRINGS is enabled or not.
	if ( $fetch_from_origin_cdn && 'ssl=1' == $parsed['query'] ) {
		$url .= '?ssl=1';
	}

	// https://bugs.php.net/bug.php?id=64948
	if ( ! filter_var( str_replace( '_', '-', $url ), FILTER_VALIDATE_URL, FILTER_FLAG_SCHEME_REQUIRED | FILTER_FLAG_PATH_REQUIRED ) ) {
		do_action( 'bump_stats', 'invalid_url' );
		return false;
	}

	$allowed_ip_types = array( 'flags' => FILTER_FLAG_IPV4, );
	if ( apply_filters( 'allow_ipv6', false ) ) {
		$allowed_ip_types['flags'] |= FILTER_FLAG_IPV6;
	}

	if ( ! filter_var( $ip, FILTER_VALIDATE_IP, $allowed_ip_types ) ) {
		do_action( 'bump_stats', 'invalid_ip' );
		return false;
	}

	if ( ! filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE ) && ! apply_filters( 'allow_private_ips', false ) ) {
		do_action( 'bump_stats', 'private_ip' );
		return false;
	}

	if ( isset( $parsed['port'] ) && $parsed['port'] !== $port ) {
		do_action( 'bump_stats', 'invalid_port' );
		return false;
	}

	$ch = curl_init( $url );

	curl_setopt_array( $ch, array(
		CURLOPT_USERAGENT            => apply_filters( 'photon_user_agent', 'Photon/1.0', $fetch_from_origin_cdn ),
		CURLOPT_TIMEOUT              => $timeout,
		CURLOPT_CONNECTTIMEOUT       => $connect_timeout,
		CURLOPT_PROTOCOLS            => CURLPROTO_HTTP | CURLPROTO_HTTPS,
		CURLOPT_SSL_VERIFYPEER       => apply_filters( 'ssl_verify_peer', false ),
		CURLOPT_SSL_VERIFYHOST       => apply_filters( 'ssl_verify_host', false ),
		CURLOPT_FOLLOWLOCATION       => false,
		CURLOPT_DNS_USE_GLOBAL_CACHE => false,
		CURLOPT_RESOLVE              => array( $parsed['host'] . ':' . $port . ':' . $ip ),
		CURLOPT_HEADERFUNCTION       => function( $ch, $header ) {
			if ( preg_match( '/^Content-Length:\s*(\d+)$/i', rtrim( $header ), $matches ) ) {
				if ( $matches[1] > $GLOBALS['remote_image_max_size'] ) {
					httpdie( '400 Bad Request', 'You can only process images up to ' . $GLOBALS['remote_image_max_size'] . ' bytes.' );
				}
			}

			return strlen( $header );
		},
		CURLOPT_WRITEFUNCTION        => function( $ch, $data ) {
			$bytes = strlen( $data );
			$GLOBALS['raw_data'] .= $data;
			$GLOBALS['raw_data_size'] += $bytes;

			if ( $GLOBALS['raw_data_size'] > $GLOBALS['remote_image_max_size'] ) {
				httpdie( '400 Bad Request', 'You can only process images up to ' . $GLOBALS['remote_image_max_size'] . ' bytes.' );
			}

			return $bytes;
		},
	) );

	if ( ! curl_exec( $ch ) ) {
		do_action( 'bump_stats', 'invalid_request' );
		return false;
	}

	$status = curl_getinfo( $ch, CURLINFO_HTTP_CODE );
	if ( 200 == $status ) {
		return true;
	}

	// handle redirects
	if ( $status >= 300 && $status <= 399 ) {
		if ( $max_redirs > 0 ) {
			return fetch_raw_data( curl_getinfo( $ch, CURLINFO_REDIRECT_URL ), $timeout, $connect_timeout, $max_redirs - 1 );
		}
		do_action( 'bump_stats', 'max_redirects_exceeded' );
		httpdie( '400 Bad Request', 'Too many redirects' );
	}

	// handle all other errors
	switch( $status ) {
		case 401:
		case 403:
			httpdie( '403 Forbidden', 'We cannot complete this request, remote data could not be fetched' );
			break;
		case 404:
		case 410:
			httpdie( '404 File Not Found', 'We cannot complete this request, remote data could not be fetched' );
			break;
		case 429:
			httpdie( '429 Too Many Requests', 'We cannot complete this request, remote data could not be fetched' );
			break;
		case 451:
			httpdie( '451 Unavailable For Legal Reasons', 'We cannot complete this request, remote data could not be fetched' );
			break;
		default:
			do_action( 'bump_stats', 'http_error-400-' . $status );
			httpdie( '400 Bad Request', 'We cannot complete this request, remote server returned an unexpected status code (' . $status . ')' );
	}
}

// In order to minimise requests to the site, if it's not a full-size image request,
// then we can grab the image internally from Photon's CDN without params, which then
// either returns the image from the cache or fetches from the site to prime the cache.
$request_arg_array = array_intersect_key( $_GET, $allowed_functions );
$request_from_origin_cdn = ( 0 < count( $request_arg_array ) && false !== $origin_image_cdn_url );

$raw_data = '';
$raw_data_size = 0;

$url = sprintf( '%s://%s%s',
	array_key_exists( 'ssl', $_GET ) ? 'https' : 'http',
	substr( parse_url( 'scheme://host' . $_SERVER['REQUEST_URI'], PHP_URL_PATH ), 1 ), // see https://bugs.php.net/bug.php?id=71112 (and #66813)
	isset( $_GET['q'] ) ? '?' . $_GET['q'] : ''
);

if ( ! fetch_raw_data( $url, 10, 3, 3, $request_from_origin_cdn ) ) {
	httpdie( '400 Bad Request', 'Sorry, the parameters you provided were not valid' );
}

foreach ( $disallowed_file_headers as $file_header ) {
	if ( substr( $raw_data, 0, strlen( $file_header ) ) == $file_header )
		httpdie( '400 Bad Request', 'Error 0002. The type of image you are trying to process is not allowed.' );
}

$img_proc = new Image_Processor();
if ( ! $img_proc )
	httpdie( '500 Internal Server Error', 'Error 0003. Unable to load the image.' );

$img_proc->use_client_hints    = false;
$img_proc->send_nosniff_header = true;
$img_proc->norm_color_profile  = false;
$img_proc->send_bytes_saved    = true;
$img_proc->send_etag_header    = true;
$img_proc->canonical_url       = $url;
$img_proc->image_max_age       = 63115200;
$img_proc->image_data          = $raw_data;

if ( ! $img_proc->load_image() )
	httpdie( '400 Bad Request', 'Error 0004. Unable to load the image.' );

if ( ! in_array( $img_proc->image_format, $allowed_types ) )
	httpdie( '400 Bad Request', 'Error 0005. The type of image you are trying to process is not allowed.' );

$original_mime_type = $img_proc->mime_type;
$img_proc->process_image();

// Update the stats of the processed functions
foreach ( $img_proc->processed as $function_name ) {
	do_action( 'bump_stats', $function_name );
}

switch ( $original_mime_type ) {
	case 'image/png':
		do_action( 'bump_stats', 'image_png' . ( 'image/webp' == $img_proc->mime_type ? '_as_webp' : '' ) );
		do_action( 'bump_stats', 'png_bytes_saved', $img_proc->bytes_saved );
		break;
	case 'image/gif':
		do_action( 'bump_stats', 'image_gif' );
		break;
	default:
		do_action( 'bump_stats', 'image_jpeg' . ( 'image/webp' == $img_proc->mime_type ? '_as_webp' : '' ) );
		do_action( 'bump_stats', 'jpg_bytes_saved', $img_proc->bytes_saved );
}
