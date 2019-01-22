<?php
/*
 * Classes to manipulate animated GIF images.
 * Maintained at: https://code.trac.wordpress.org/browser/photon/class-gif-image.php
*/

if ( ! class_exists( 'Gif_Frame' ) ) {
	class Gif_Frame {
		private $_pos_x;
		private $_pos_y;
		private $_width;
		private $_height;
		private $_off_xy;
		private $_lc_mod;
		private $_palette;
		private $_transp;
		private $_gr_mod;
		private $_head;
		private $_image;

		function __construct( $lc_mod, $palette, $image, $head, $box_dims, $gr_mod ) {
			$this->pos_x   = $box_dims[0];
			$this->pos_y   = $box_dims[1];
			$this->width   = $box_dims[2];
			$this->height  = $box_dims[3];

			$this->lc_mod  = $lc_mod;
			$this->gr_mod  = $gr_mod;
			$this->palette = $palette;

			if ( strlen( $gr_mod ) == 8 )
				$this->transp = ord( $gr_mod[3] ) & 1 ? 1 : 0;
			else
				$this->transp = 0;

			$this->head    = $head;
			$this->image   = $image;
		}

		public function __set( $name, $value ) {
			$var = '_' . $name;
			$this->$var = $value;
		}

		public function __get( $name ) {
			$var = '_' . $name;
			if ( isset( $this->$var ) )
				return $this->$var;
			else
				return '';
		}
	}
}

if ( ! class_exists( 'Gif_Image' ) ) {

require_once ( dirname( __FILE__ ) . '/class-image-effect.php' );

	class Gif_Image {
		private $gif;
		private $gif_header;
		private $g_palette;
		private $g_mod;
		private $g_mode;
		private $img_effect;

		private $ptr = 0;
		private $max_len = 0;
		private $int_w = 0;
		private $int_h = 0;
		private $new_width = 0;
		private $new_height = 0;
		private $s_x = 0;
		private $s_y = 0;
		private $crop_width = 0;
		private $crop_height = 0;
		private $crop = false;
		private $fit = false;
		private $resize_ratio = array( 0, 0 );
		private $frame_count = 0;
		private $au = 0;
		private $nt = 0;

		private $frame_array = array();
		private $image_data = null;
		private $gn_fld  = array();
		private $dl_frmf = array();
		private $dl_frms = array();
		private $pre_process_actions = array();
		private $post_process_actions = array();
		private $processed = array();
		private $upscale_max_pixels = 1000;
		private $zoom_enabled = true;
		private $send_etag_header = true;

		private static $pre_actions = array( 'set_height', 'set_width', 'crop', 'crop_offset', 'resize_and_crop', 'fit_in_box' );

		const optimize = true;

		const GIF_BLOCK_IMAGE_DESCRIPTOR = 0x2C;
		const GIF_BLOCK_EXTENSION        = 0x21;
		const GIF_BLOCK_END              = 0x3B;

		const GIF_EXT_PLAINTEXT          = 0x01;
		const GIF_EXT_GRAPHIC_CONTROL    = 0xF9;
		const GIF_EXT_COMMENT            = 0xFE;
		const GIF_EXT_APPLICATION        = 0xFF;

		function __construct( $gif_data ) {
			$this->img_effect = new Image_Effect( 'GD', 'image/gif' );
			$this->gif = $gif_data;
			$this->max_len = strlen( $gif_data );
			if ( $this->max_len < 14 ) {
				$this->error_and_die( '400 Bad Request', 'unable to process the image data' );
			}
			$this->gif_header = $this->get_bytes(13);
			$this->parse_header();
			$this->parse_frames();

			$buffer_add = '';
			while ( self::GIF_BLOCK_END != ord( $this->gif[ $this->ptr ] ) ) {
				if ( $this->ptr >= $this->max_len ) {
					$this->error_and_die( '400 Bad Request', 'unable to process the image data' );
				}
				switch ( ord( $this->gif[ $this->ptr + 1 ] ) ) {
					case self::GIF_EXT_COMMENT:
						$sum = 2;
						while ( 0x00 != ( $lc_i = ord( $this->gif[ $this->ptr + $sum ] ) ) ) {
							$sum += $lc_i + 1;
						}
						self::optimize ? $this->get_bytes( $sum + 1 ) : $buffer_add .= $this->get_bytes($sum + 1);
						if ( 17 == $sum )
							$this->au = 1;
						break;
					case self::GIF_EXT_APPLICATION:
						$sum = 2;
						while ( 0x00 != ( $lc_i = ord( $this->gif[ $this->ptr + $sum ] ) ) ) {
							$sum += $lc_i + 1;
						}
						$buffer_add .= $this->get_bytes( $sum + 1 );
						break;
					case self::GIF_EXT_PLAINTEXT:
						$sum = 2;
						while ( 0x00 != ( $lc_i = ord( $this->gif[ $this->ptr + $sum ] ) ) ) {
							$sum += $lc_i + 1;
						}
						self::optimize ? $this->get_bytes( $sum + 1 ) : $buffer_add .= $this->get_bytes($sum + 1);
						break;
					default:
						// invalid start header found, increment by 1 byte to 'sync' to the next header
						$this->get_bytes( 1 );
				}
			}
			$this->g_mode = $buffer_add;
			$this->gif = '';
		}

		private function parse_header() {
			$this->int_w = $this->bytes_to_num( $this->gif_header[6] . $this->gif_header[7] );
			$this->int_h = $this->bytes_to_num( $this->gif_header[8] . $this->gif_header[9] );

			if ( ( $vt = ord( $this->gif_header[10] ) ) & 128 ? 1 : 0 )
				$this->g_palette = $this->get_bytes( pow( 2, ( $vt & 7 ) + 1 ) * 3 );

			$buff = '';
			if ( self::GIF_BLOCK_EXTENSION == ord( $this->gif[ $this->ptr ] ) ) {
				while ( self::GIF_EXT_GRAPHIC_CONTROL != ord( $this->gif[ $this->ptr + 1 ] ) &&
						self::GIF_BLOCK_IMAGE_DESCRIPTOR != ord( $this->gif[ $this->ptr ] ) ) {
					switch ( ord( $this->gif[ $this->ptr + 1 ] ) ) {
						case self::GIF_EXT_COMMENT:
							$sum = 2;
							while ( 0x00 != ( $lc_i = ord( $this->gif[ $this->ptr + $sum] ) ) ) {
								$sum += $lc_i + 1;
							}
							self::optimize ? $this->get_bytes( $sum + 1 ) : $buff .= $this->get_bytes( $sum + 1 );
							break;
						case self::GIF_EXT_APPLICATION:
							$sum = 2;
							while ( 0x00 != ( $lc_i = ord( $this->gif[ $this->ptr + $sum ] ) ) ) {
								$sum += $lc_i + 1;
							}
							$buff .= $this->get_bytes( $sum + 1 );
							break;
						case self::GIF_EXT_PLAINTEXT:
							$sum = 2;
							while ( 0x00 != ( $lc_i = ord( $this->gif[ $this->ptr + $sum ] ) ) ) {
								$sum += $lc_i + 1;
							}
							self::optimize ? $this->get_bytes( $sum + 1 ) : $buff .= $this->get_bytes( $sum + 1 );
							break;
						default:
							// invalid start header found, increment by 1 byte to 'sync' to the next header
							$this->get_bytes( 1 );
					}
					if ( $this->ptr >= $this->max_len ) {
						$this->error_and_die( '400 Bad Request', 'unable to process the image data' );
					}
				}
				$this->g_mod = $buff;
			}
		}

		private function parse_frames() {
			$buffer = '';
			while ( self::GIF_BLOCK_END != ord( $this->gif[ $this->ptr ] ) ) {
				$gr_mod = '';
				$this->frame_count++;

				while ( self::GIF_BLOCK_IMAGE_DESCRIPTOR != ord( $this->gif[ $this->ptr ] ) ) {
					if ( self::GIF_BLOCK_END == ord( $this->gif[ $this->ptr ] ) ) {
						$this->frame_count--;
						continue 2;
					}
					if ( $this->ptr >= $this->max_len ) {
						$this->error_and_die( '400 Bad Request', 'unable to process the image data' );
					}
					switch ( ord( $this->gif[ $this->ptr + 1 ] ) ) {
						case self::GIF_EXT_GRAPHIC_CONTROL:
							$this->gn_fld[]  = $this->gif[ $this->ptr + 3 ];
							$this->dl_frmf[] = $this->gif[ $this->ptr + 4 ];
							$this->dl_frms[] = $this->gif[ $this->ptr + 5 ];
							$gr_mod = $buffer = $this->get_bytes(8);
							break;
						case self::GIF_EXT_COMMENT:
							$sum = 2;
							while ( 0x00 != ( $lc_i = ord( $this->gif[ $this->ptr + $sum ] ) ) ) {
								$sum += $lc_i + 1;
							}
							self::optimize ? $this->get_bytes( $sum + 1 ) : $buffer .= $this->get_bytes( $sum + 1 );
							break;
						case self::GIF_EXT_APPLICATION:
							$sum = 2;
							while ( 0x00 != ( $lc_i = ord( $this->gif[ $this->ptr + $sum ] ) ) ) {
								$sum += $lc_i + 1;
							}
							if ( 'NETSCAPE' == substr( $tmp_buf = $this->get_bytes( $sum + 1 ), 3, 8) ) {
								if ( ! $this->nt ) {
									$this->nt = 1;
									$this->g_mod .= $tmp_buf;
								}
							} else {
								$buffer .= $tmp_buf;
							}
							break;
						case self::GIF_EXT_PLAINTEXT:
							$sum = 2;
							while ( 0x00 != ( $lc_i = ord( $this->gif[ $this->ptr + $sum ] ) ) ) {
								$sum += $lc_i + 1;
							}
							self::optimize ? $this->get_bytes( $sum + 1 ) : $buffer .= $this->get_bytes( $sum + 1 );
							break;
						default:
							// invalid start header found, increment by 1 byte to 'sync' to the next header
							$this->get_bytes( 1 );
					}
				}

				$lc_mod = $buffer;
				$dimms = array();
				$dimms[] = $this->get_num(1, 2);
				$dimms[] = $this->get_num(3, 2);
				$dimms[] = $this->get_num(5, 2);
				$dimms[] = $this->get_num(7, 2);

				$head = $this->get_bytes( 10 );

				if ( ( ( $dimms[0] + $dimms[2] ) - $this->int_w ) > 0 ) {
					$head[1]  = "\x00";
					$head[2]  = "\x00";
					$head[5]  = chr( $this->int_w & 255 );
					$head[6]  = chr( ( $this->int_w & 0xFF00 ) >> 8 );
					$dimms[0] = 0;
					$dimms[2] = $this->int_w;
				}
				if ( ( ( $dimms[1] + $dimms[3] ) - $this->int_h ) > 0 ) {
					$head[3]  = "\x00";
					$head[4]  = "\x00";
					$head[7]  = chr( $this->int_h & 255 );
					$head[8]  = chr( ( $this->int_h & 0xFF00 ) >> 8 );
					$dimms[1] = 0;
					$dimms[3] = $this->int_h;
				}
				$palette = '';
				if ( ( ord( $this->gif[ $this->ptr - 1 ] ) & 128 ? 1 : 0 ) ) {
					$lc_i = pow( 2, ( ord( $this->gif[ $this->ptr - 1 ] ) & 7 ) + 1 ) * 3;
					$palette = $this->get_bytes( $lc_i );
				}
				$sum = 0;
				$this->ptr++;
				while ( 0x00 != ( $lc_i = ord( $this->gif[ $this->ptr + $sum ] ) ) ) {
					$sum += $lc_i + 1;
				}
				$this->ptr--;
				$this->frame_array[] = new Gif_Frame( $lc_mod, $palette, $this->get_bytes( $sum + 2 ), $head, $dimms, $gr_mod );
			}
		}

		private function get_frame_image( $index ) {
			return $this->gif_header . $this->g_palette . $this->g_mod . $this->frame_array[$index]->lc_mod .
					$this->frame_array[$index]->head . $this->frame_array[$index]->palette . $this->frame_array[$index]->image . "\x3B";
		}

		private function get_bytes( $num_bytes ) {
			$bytes = substr( $this->gif, $this->ptr, $num_bytes );
			$this->ptr += $num_bytes;
			return $bytes;
		}

		private function bytes_to_num( $bytes ) {
			$retval = ord( $bytes[1] ) << 8;
			$retval = $retval | ord( $bytes[0] );
			return $retval;
		}

		private function get_num( $offset, $len ) {
			return $this->bytes_to_num( substr( $this->gif, $this->ptr + $offset, $len ) );
		}

		private function int_raw( $int ) {
			return chr( $int & 255 ) . chr( ( $int & 0xFF00 ) >> 8 );
		}

		private function error_and_die( $result = '400 Bad Request', $message = '' ) {
			if ( function_exists( 'imageresize_graceful_fail' ) ) {
				imageresize_graceful_fail();
			} else if ( function_exists( 'httpdie' ) ) {
				httpdie( $result, $message );
			} else {
				header( "HTTP/1.1 $result" );
				die( $message );
			}
		}

		private function pre_process_frame( $img_data, $index ) {
			$str_img = @imagecreatefromstring( $img_data );

			if ( 1 == $this->frame_count )
				$img_s = @imagecreatetruecolor( $this->int_w, $this->int_h );
			else
				$img_s = @imagecreate( $this->int_w, $this->int_h );

			if ( $this->frame_array[ $index ]->transp ) {
				$in_trans = @imagecolortransparent( $str_img);

				if ( ( $in_trans >= 0 ) && ( $in_trans < @imagecolorstotal( $str_img ) ) )
					$tr_clr = @imagecolorsforindex( $str_img, $in_trans );

				if ( 1 == $this->frame_count || ! isset( $tr_clr )  ) {
					$n_trans = @imagecolorallocatealpha( $img_s, 255, 255, 255, 127 );
				} else {
					if ( array_key_exists( 'alpha' , $tr_clr ) )
						$n_trans = @imagecolorallocatealpha( $img_s, $tr_clr['red'], $tr_clr['green'], $tr_clr['blue'], $tr_clr['alpha'] );
					else
						$n_trans = @imagecolorallocate( $img_s, $tr_clr['red'], $tr_clr['green'], $tr_clr['blue'] );
				}
				@imagecolortransparent( $img_s, $n_trans );
				@imagefill( $img_s, 0, 0, $n_trans );
			}

			@imagecopyresampled( $img_s, $str_img,
								$this->frame_array[$index]->pos_x, $this->frame_array[$index]->pos_y,
								0, 0,
								$this->frame_array[$index]->width, $this->frame_array[$index]->height,
								$this->frame_array[$index]->width, $this->frame_array[$index]->height );
			ob_start();
			@imagegif( $img_s );
			$t_img = ob_get_clean();
			@imagedestroy( $str_img );
			@imagedestroy( $img_s );

			// Set the new object top-left co-ord to be the new main image's top-left
			$this->frame_array[$index]->pos_x = 0;
			$this->frame_array[$index]->pos_y = 0;
			$this->frame_array[$index]->off_xy = $this->int_raw( 0 ) . $this->int_raw( 0 );
			// Set the new width and height to full image size
			$this->frame_array[$index]->width = $this->int_w;
			$this->frame_array[$index]->height = $this->int_h;

			return $t_img;
		}

		private function process_frame( $img_data, $index ) {
			// if a frame is transparent and does not fill the entire image size, we need to extend the frame
			// to be full size to overcome resizing ratio differences between different size frames
			if ( $this->frame_array[ $index ]->transp &&
				( ( $this->frame_array[$index]->width != $this->int_w ) || ( $this->frame_array[$index]->height != $this->int_h ) ) ) {
				$img_data = $this->pre_process_frame( $img_data, $index );
			}
			if ( $this->crop ) {
				// find the frames image crop offsets
				$offset_x = $this->s_x - $this->frame_array[$index]->pos_x;
				if ( $offset_x < 0 )
					$offset_x = 0;
				$offset_y = $this->s_y - $this->frame_array[$index]->pos_y;
				if ( $offset_y < 0 )
					$offset_y = 0;

				// crop bottom-right co-ord
				$crop_b_x = $this->s_x + $this->crop_width;
				$crop_b_y = $this->s_y + $this->crop_height;

				// object bottom-right co-ord
				$obj_b_x = $this->frame_array[$index]->pos_x + $this->frame_array[$index]->width;
				$obj_b_y = $this->frame_array[$index]->pos_y + $this->frame_array[$index]->height;

				// calculate the new top-left co-ord
				$new_x = max( $this->frame_array[$index]->pos_x, $this->s_x ) - $this->s_x;
				$new_y = max( $this->frame_array[$index]->pos_y, $this->s_y ) - $this->s_y;

				// calculate the new image rectangle co-ord
				$s_width = min( $crop_b_x, $obj_b_x ) - max( $this->frame_array[$index]->pos_x, $this->s_x );
				$s_height = min( $crop_b_y, $obj_b_y ) - max( $this->frame_array[$index]->pos_y, $this->s_y );

				// Set the new object top-left co-ord
				$this->frame_array[$index]->pos_x = $new_x;
				$this->frame_array[$index]->pos_y = $new_y;

				$n_width  = round( $s_width * $this->resize_ratios[0] ) ? : 1;
				$n_height = round( $s_height * $this->resize_ratios[1] ) ? : 1;
			} else {
				$offset_x = 0;
				$offset_y = 0;
				$n_width  = round( $this->frame_array[$index]->width * $this->resize_ratios[0] ) ? : 1;
				$n_height = round( $this->frame_array[$index]->height * $this->resize_ratios[1] ) ? : 1;
				$s_width  = $this->frame_array[$index]->width;
				$s_height = $this->frame_array[$index]->height;
			}

			if ( 0 >= $n_width )  $n_width   = 1;
			if ( 0 >= $n_height ) $n_height  = 1;
			if ( 0 >= $s_width )  $s_width   = 1;
			if ( 0 >= $s_height ) $s_height  = 1;

			$n_pos_x  = round( $this->frame_array[$index]->pos_x * $this->resize_ratios[0] );
			$n_pos_y  = round( $this->frame_array[$index]->pos_y * $this->resize_ratios[1] );
			$this->frame_array[$index]->off_xy = $this->int_raw( $n_pos_x ) . $this->int_raw( $n_pos_y );

			$str_img = @imagecreatefromstring( $img_data );

			if ( 1 == $this->frame_count )
				$img_s = @imagecreatetruecolor( $n_width, $n_height );
			else
				$img_s = @imagecreate( $n_width, $n_height );

			if ( $this->frame_array[ $index ]->transp ) {
				$in_trans = @imagecolortransparent( $str_img );

				if ( ( $in_trans >= 0 ) && ( $in_trans < @imagecolorstotal( $str_img ) ) )
					$tr_clr = @imagecolorsforindex( $str_img, $in_trans );

				if ( 1 == $this->frame_count || ! isset( $tr_clr ) ) {
					$n_trans = @imagecolorallocatealpha( $img_s, 255, 255, 255, 127 );
				} else {
					if ( array_key_exists( 'alpha' , $tr_clr ) )
						$n_trans = @imagecolorallocatealpha( $img_s, $tr_clr['red'], $tr_clr['green'], $tr_clr['blue'], $tr_clr['alpha'] );
					else
						$n_trans = @imagecolorallocate( $img_s, $tr_clr['red'], $tr_clr['green'], $tr_clr['blue'] );
				}
				@imagecolortransparent( $img_s, $n_trans );
				@imagefill( $img_s, 0, 0, $n_trans );
			}

			@imagecopyresampled( $img_s, $str_img, 0, 0, $offset_x, $offset_y, $n_width, $n_height, $s_width, $s_height );

			// perform the post-processing functions
			foreach ( $this->post_process_actions as $action ) {
				if ( method_exists( $this->img_effect, $action[ 'func_name' ] ) )
					$this->img_effect->{$action[ 'func_name' ]}( $img_s, $action[ 'params' ] );
			}

			ob_start();
			@imagegif( $img_s );
			$t_img = ob_get_clean();
			@imagedestroy( $str_img );
			@imagedestroy( $img_s );

			return $t_img;
		}

		private function repack_frame( $str_img, $index ) {
			$hd = $offset = 13 + pow( 2, ( ord( $str_img[10] ) & 7 ) + 1 ) * 3;
			$palet = '';
			$i_hd = 0;

			for ( $i = 13; $i < $offset; $i++ )
				$palet .= $str_img[$i];

			$str_max_len = strlen( $str_img );
			while ( self::GIF_BLOCK_IMAGE_DESCRIPTOR != ord( $str_img[ $offset ] ) ) {
				if ( self::GIF_EXT_GRAPHIC_CONTROL == ord( $str_img[ $offset + 1 ] ) &&
					$this->frame_array[$index]->transp ) {
						$str_img[ $offset + 3 ] = $this->gn_fld[ $index ];
						$str_img[ $offset + 4 ] = $this->dl_frmf[ $index ];
						$str_img[ $offset + 5 ] = $this->dl_frms[ $index ];
				}
				$sum = 2;
				while ( 0x00 != ( $lc_i = ord( $str_img[ $offset + $sum ] ) ) )
					$sum += $lc_i + 1;
				$offset += ( $sum + 1 );
				$i_hd += ( $sum + 1 );
				if ( ( $offset + 10 ) > $str_max_len ) {
					$this->error_and_die( '400 Bad Request', 'unable to reprocess the image frame' );
				}
			}

			$str_img[ $offset + 1 ] = $this->frame_array[ $index ]->off_xy[0];
			$str_img[ $offset + 2 ] = $this->frame_array[ $index ]->off_xy[1];
			$str_img[ $offset + 3 ] = $this->frame_array[ $index ]->off_xy[2];
			$str_img[ $offset + 4 ] = $this->frame_array[ $index ]->off_xy[3];
			$str_img[ $offset + 9 ] = chr( ord( $str_img[ $offset + 9 ] ) | 0x80 | ( ord( $str_img[10] ) & 0x7 ) );

			$ms1 = substr( $str_img, $hd, $i_hd + 10 );
			$ms1 = $this->frame_array[$index]->gr_mod . $ms1;

			return $ms1 . $palet . substr( substr( $str_img, $offset + 10 ), 0, -1 );
		}

		private function set_height( $args, $upscale = false ) {
			if ( substr( $args, -1 ) == '%' )
				$this->new_height = round( $this->int_h * abs( intval( $args ) ) / 100 );
			else
				$this->new_height = intval( $args );

			// new height is invalid or is greater than original image, but we don't have permission to upscale
			if ( ( ! $this->new_height ) || ( $this->new_height > $this->int_h && ! $upscale ) ) {
				// if the sizes are too big, then we serve the original size
				$this->new_width  = $this->int_w;
				$this->new_height = $this->int_h;
				return;
			}
			// sane limit when upscaling, defaults to 1000
			if ( $this->new_height > $this->int_h && $upscale && $this->new_height > $this->upscale_max_pixels ) {
				// if the sizes are too big, then we serve the original size
				$this->new_width  = $this->int_w;
				$this->new_height = $this->int_h;
				return;
			}

			$ratio = $this->new_height / $this->int_h;

			$this->new_width = round( $this->int_w * $ratio );
			$this->s_x = $this->s_y = 0;
			$this->crop_width = $this->new_width;
			$this->crop_height = $this->new_height;
			$this->processed[] = 'set_height';
		}

		private function set_width( $args, $upscale = false ) {
			if ( '%' == substr( $args, -1 ) )
				$this->new_width = round( $this->int_w * abs( intval( $args ) ) / 100 );
			else
				$this->new_width = intval( $args );

			// New width is invalid or is greater than original image, but we don't have permission to upscale
			if ( ( ! $this->new_width ) || ( $this->new_width > $this->int_w && ! $upscale ) ) {
				// if the sizes are too big, then we serve the original size
				$this->new_width  = $this->int_w;
				$this->new_height = $this->int_h;
				return;
			}

			// Sane limit when upscaling, defaults to 1000
			if ( $this->new_width > $this->int_w && $upscale && $this->new_width > $this->upscale_max_pixels ) {
				// if the sizes are too big, then we serve the original size
				$this->new_width  = $this->int_w;
				$this->new_height = $this->int_h;
				return;
			}

			$ratio = $this->new_width / $this->int_w;

			$this->new_height = round( $this->int_h * $ratio );
			$this->s_x = $this->s_y = 0;
			$this->crop_width = $this->new_width;
			$this->crop_height = $this->new_height;
			$this->processed[] = 'set_width';
		}

		private function zoom( $zoom_val ) {
			if ( strlen( $zoom_val ) ) {
				$zoom = floatval( $zoom_val );
				// clamp to 1-10
				$zoom = max( 1, $zoom );
				$zoom = min( 10, $zoom );
				if ( $zoom < 2 ) {
					// round UP to the nearest half
					$zoom = ceil( $zoom * 2 ) / 2;
				} else {
					// round UP to the nearest integer
					$zoom = ceil( $zoom );
				}
			} else {
				$zoom = 0;
			}

			if ( $zoom > 1 ) {
				$this->new_width = round( $this->new_width * $zoom );
				$this->new_height = round( $this->new_height * $zoom );

				// check that if we have made it bigger than the images original size, that we remain with bounds
				if ( $this->new_width >= $this->int_w && $this->new_height >= $this->int_h ) {
					if ( ( $this->new_width > $this->upscale_max_pixels ) ||
						( $this->new_height > $this->upscale_max_pixels ) ) {
						$this->new_width  = $this->int_w;
						$this->new_height = $this->int_h;
					}
				}
			}
		}

		private function fit_in_box( $args ) {
			// if the args are malformed, default to the original size
			if ( false === strpos( $args, ',' ) ) {
				$this->new_width  = $this->int_w;
				$this->new_height = $this->int_h;
				return;
			}
			list( $end_w, $end_h ) = explode( ',', $args );

			$end_w = abs( intval( $end_w ) );
			$end_h = abs( intval( $end_h ) );

			// we do not allow both new width and height to be larger at the same time
			if ( ! $end_w || ! $end_h || ( $this->int_w <= $end_w && $this->int_h <= $end_h ) ) {
				$this->new_width  = $this->int_w;
				$this->new_height = $this->int_h;
			} else {
				$original_aspect = $this->int_w / $this->int_h;
				$new_aspect = $end_w / $end_h;

				if ( $original_aspect >= $new_aspect ) {
					$this->new_height = $end_h;
					$this->new_width = round( $this->int_w / ( $this->int_h / $end_h ) );
					// check we haven't overstepped the width
					if ( $this->new_width > $end_w ) {
						$this->new_width = $end_w;
						$this->new_height = round( $this->int_h / ( $this->int_w / $end_w ) );
					}
				} else {
					$this->new_width = $end_w;
					$this->new_height = round( $this->int_h / ( $this->int_w / $end_w ) );
					// check we haven't overstepped the height
					if ( $this->new_height > $end_h ) {
						$this->new_height = $end_h;
						$this->new_width = round( $this->int_w / ( $this->int_h / $end_h ) );
					}
				}
				$this->processed[] = 'fit_in_box';
			}
		}

		private function crop( $args ) {
			// if the args are malformed, default to the original size
			if ( false === strpos( $args, ',' ) ) {
				$this->new_width  = $this->int_w;
				$this->new_height = $this->int_h;
				return;
			}
			$args = explode( ',', $args );

			// if we don't have the correct number of args, default
			if ( count( $args ) != 4 ) {
				$this->new_width  = $this->int_w;
				$this->new_height = $this->int_h;
				return;
			}

			if ( 'px' == substr( $args[2], -2 ) )
				$this->crop_width = max( 0, min( $this->int_w, intval( $args[2] ) ) );
			else
				$this->crop_width = round( $this->int_w * abs( intval( $args[2] ) ) / 100 );

			if ( 'px' == substr( $args[3], -2 ) )
				$this->crop_height = max( 0, min( $this->int_h, intval( $args[3] ) ) );
			else
				$this->crop_height = round( $this->int_h * abs( intval( $args[3] ) ) / 100 );

			if ( 'px' == substr( $args[0], -2 ) )
				$this->s_x = intval( $args[0] );
			else
				$this->s_x = round( $this->int_w * abs( intval( $args[0] ) ) / 100 );

			if ( 'px' == substr( $args[1], -2 ) )
				$this->s_y = intval( $args[1] );
			else
				$this->s_y = round( $this->int_h * abs( intval( $args[1] ) ) / 100 );

			$this->new_width = $this->crop_width;
			$this->new_height = $this->crop_height;
			$this->crop = true;
			$this->processed[] = 'crop';
		}

		private function crop_offset( $args ) {
			// if the args are malformed, default to the original size
			if ( false === strpos( $args, ',' ) ) {
				$this->new_width  = $this->int_w;
				$this->new_height = $this->int_h;
				return;
			}
			$args = explode( ',', $args );

			// if we don't have the correct number of args, default
			if ( count( $args ) != 4 ) {
				$this->new_width  = $this->int_w;
				$this->new_height = $this->int_h;
				return;
			}

			$this->crop_width = max( 0, min( $this->int_w, intval( $args[2] ) ) );
			$this->crop_height = max( 0, min( $this->int_h, intval( $args[3] ) ) );
			$this->s_x = intval( $args[0] );
			$this->s_y = intval( $args[1] );

			$this->new_width = $this->crop_width;
			$this->new_height = $this->crop_height;
			$this->crop = true;
			$this->processed[] = 'crop_offset';
		}

		private function resize_and_crop( $args ) {
			// if the args are malformed, default to the original size
			if ( false === strpos( $args, ',' ) ) {
				$this->new_width  = $this->int_w;
				$this->new_height = $this->int_h;
				return;
			}

			list( $end_w, $end_h ) = explode( ',', $args );
			$end_w = (int) $end_w;
			$end_h = (int) $end_h;

			// if the sizes are invalid, default to the original size
			if ( 0 == $end_w || 0 == $end_h ) {
				$this->new_width  = $this->int_w;
				$this->new_height = $this->int_h;
				return;
			}

			$ratio_orig = $this->int_w / $this->int_h;
			$ratio_end = $end_w / $end_h;

			// If the original and new images are proportional (no cropping needed), just do a standard resize
			if ( $ratio_orig == $ratio_end ) {
				$this->set_width( $end_w, true );
			} else {
				if ( $end_w >= $this->int_w && $end_h >= $this->int_h ) {
					$this->new_width = max( $end_w, $this->int_w );
					$this->new_height = max( $end_h, $this->int_h );
				} else {
					$this->new_width = $end_w;
					$this->new_height = $end_h;
				}

				if ( ! $this->new_width )
					$this->new_width = intval( $this->new_height * $ratio_orig );
				if ( ! $this->new_height )
					$this->new_height = intval( $this->new_width / $ratio_orig );

				// Check if the width or height are too large, if they are then default to original size
				if ( ( ( $this->new_width > $this->int_w ) && ( $this->new_width > $this->upscale_max_pixels ) ) ||
					( $this->new_height > $this->int_h && ( $this->new_height > $this->upscale_max_pixels ) ) ) {
					$this->new_width = $this->int_w;
					$this->new_height = $this->int_h;
					return;
				}

				$size_ratio = max( $this->new_width / $this->int_w, $this->new_height / $this->int_h );
				$this->crop_width = min( ceil( $this->new_width / $size_ratio ), $this->int_w );
				$this->crop_height = min( ceil( $this->new_height / $size_ratio ), $this->int_h );

				$this->s_x = round( ( $this->int_w - $this->crop_width ) / 2 );
				$this->s_y = round( ( $this->int_h - $this->crop_height ) / 2 );
				$this->crop = true;
				$this->processed[] = 'resize_and_crop';
			}
		}

		public function process_image( $new_w, $new_h, $crop, $s_x, $s_y, $crop_w, $crop_h ) {
			// if the gif image has an invalid size for either value, do not process it
			if ( 1 > $this->int_w || 1 > $this->int_h )
				return false;

			$this->new_width   = $new_w;
			$this->new_height  = $new_h;
			$this->crop        = $crop;
			$this->s_x         = $s_x;
			$this->s_y         = $s_y;
			$this->crop_width  = $crop_w;
			$this->crop_height = $crop_h;

			// we fail if the image size is too small
			if ( 1 > $this->new_width || 1 > $this->new_height )
				return false;

			if ( $this->crop ) {
				$this->resize_ratios[0] = $this->new_width / $this->crop_width;
				$this->resize_ratios[1] = $this->new_height / $this->crop_height;
			} else {
				$this->resize_ratios[0] = $this->new_width / $this->int_w;
				$this->resize_ratios[1] = $this->new_height / $this->int_h;
			}

			$this->image_data = '';
			for ( $i = 0; $i < $this->frame_count; $i++ ) {
				$this->image_data .= $this->repack_frame( $this->process_frame( $this->get_frame_image( $i ), $i ), $i );
				$this->frame_array[ $i ] = null;
			}

			return true;
		}

		public function process_image_functions( $upscale_max_pixels ) {
			if ( isset( $upscale_max_pixels ) )
				$this->upscale_max_pixels = $upscale_max_pixels;

			// if the gif image has an invalid size for either value, do not process it
			if ( 1 > $this->int_w || 1 > $this->int_h )
				return false;

			// we need at least one action to perform otherwise we should just send the original
			if ( 0 == count( $this->pre_process_actions ) ) {
				$this->pre_process_actions[] = array(
						'func_name' => 'set_width',
						'params'    => $this->int_w,
					);
			}

			$cropped = false;
			// do the pre-processing functions
			foreach ( $this->pre_process_actions as $action ) {
				$this->{$action[ 'func_name' ]}( $action[ 'params' ] );
				if ( 'crop' == $action[ 'func_name' ] || 'crop_offset' == $action[ 'func_name' ] )
					$cropped = true;
			}

			// zoom functionality is not supported with the 'crop-style' functions
			if ( ! $cropped && isset( $_GET['zoom'] ) && $this->zoom_enabled ) {
				$this->zoom( $_GET['zoom'] );
			}

			// we fail if the image size is too small
			if ( 1 > $this->new_width || 1 > $this->new_height )
				return false;

			if ( $this->crop ) {
				$this->resize_ratios[0] = $this->new_width / $this->crop_width;
				$this->resize_ratios[1] = $this->new_height / $this->crop_height;
			} else {
				$this->resize_ratios[0] = $this->new_width / $this->int_w;
				$this->resize_ratios[1] = $this->new_height / $this->int_h;
			}

			$this->image_data = '';
			for ( $i = 0; $i < $this->frame_count; $i++ ) {
				$this->image_data .= $this->repack_frame( $this->process_frame( $this->get_frame_image( $i ), $i ), $i );
				$this->frame_array[ $i ] = null;
			}

			return true;
		}

		public function get_image_blob() {
			$gm = $this->gif_header;
			$gm[10] = ord( $gm[10] ) & 0x7F;
			$i_bytes = $this->int_raw( round( ( $this->crop ? $this->crop_width : $this->int_w ) * $this->resize_ratios[0] ) ? : 1 );
			$gm[6] = $i_bytes[0];
			$gm[7] = $i_bytes[1];
			$i_bytes = $this->int_raw( round( ( $this->crop ? $this->crop_height : $this->int_h ) * $this->resize_ratios[1] ) ? : 1 );
			$gm[8] = $i_bytes[0];
			$gm[9] = $i_bytes[1];

			$this->image_data = $gm . $this->g_mod . $this->image_data;

			$con = '';
			if ( strlen( $this->g_mode ) )
				$con = $this->g_mode . "\x3B";
			else
				$con = "\x3B";

			if ( ! $this->au )
				$con = "\x21\xFE\x0Eautomattic_inc\x00" . $con;

			$this->image_data .= ( strlen( $con ) >= 19 ? $con : "\x21" );

			if ( ! headers_sent() ) {
				header( 'Content-Length: ' . strlen( $this->image_data ) );
				if ( $this->send_etag_header )
					header( 'ETag: "' . substr( md5( strlen( $this->image_data ) . '.' . time() ), 0, 16 ) . '"' );
			}

			return $this->image_data;
		}

		public function add_function( $function_name, $arguments ) {
			if ( in_array( $function_name, self::$pre_actions ) ) {
				$this->pre_process_actions[] = array(
						'func_name' => $function_name,
						'params'    => $arguments,
					);
			} else {
				$this->post_process_actions[] = array(
						'func_name' => $function_name,
						'params'    => $arguments,
					);
			}
		}

		public function get_frame_count() {
			return $this->frame_count;
		}

		public function get_image_width() {
			return intval( $this->int_w );
		}

		public function get_image_height() {
			return intval( $this->int_h );
		}

		public function get_processed() {
			if ( is_array( $this->img_effect->processed ) ) {
				$this->processed = array_merge( $this->processed, $this->img_effect->processed );
			}
			return $this->processed;
		}

		public function disable_etag_header() {
			$this->send_etag_header = false;
		}

		public function disable_zoom() {
			$this->zoom_enabled = false;
		}
	}
}
