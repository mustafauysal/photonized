<?php

if ( ! class_exists( 'Jpeg_Image' ) ) {

class Jpeg_Image {

	private $_JPG_MAX_QUALITY;

	// The names of the JPEG segment markers, indexed by their marker number
	private $JPEG_Segment_Names = array(
		0xC0 =>  "SOF0",  0xC1 =>  "SOF1",  0xC2 =>  "SOF2",  0xC3 =>  "SOF3",
		0xC5 =>  "SOF5",  0xC6 =>  "SOF6",  0xC7 =>  "SOF7",  0xC8 =>  "JPG",
		0xC9 =>  "SOF9",  0xCA =>  "SOF10", 0xCB =>  "SOF11", 0xCD =>  "SOF13",
		0xCE =>  "SOF14", 0xCF =>  "SOF15",
		0xC4 =>  "DHT",   0xCC =>  "DAC",
		0xD0 =>  "RST0",  0xD1 =>  "RST1",  0xD2 =>  "RST2",  0xD3 =>  "RST3",
		0xD4 =>  "RST4",  0xD5 =>  "RST5",  0xD6 =>  "RST6",  0xD7 =>  "RST7",
		0xD8 =>  "SOI",   0xD9 =>  "EOI",   0xDA =>  "SOS",   0xDB =>  "DQT",
		0xDC =>  "DNL",   0xDD =>  "DRI",   0xDE =>  "DHP",   0xDF =>  "EXP",
		0xE0 =>  "APP0",  0xE1 =>  "APP1",  0xE2 =>  "APP2",  0xE3 =>  "APP3",
		0xE4 =>  "APP4",  0xE5 =>  "APP5",  0xE6 =>  "APP6",  0xE7 =>  "APP7",
		0xE8 =>  "APP8",  0xE9 =>  "APP9",  0xEA =>  "APP10", 0xEB =>  "APP11",
		0xEC =>  "APP12", 0xED =>  "APP13", 0xEE =>  "APP14", 0xEF =>  "APP15",
		0xF0 =>  "JPG0",  0xF1 =>  "JPG1",  0xF2 =>  "JPG2",  0xF3 =>  "JPG3",
		0xF4 =>  "JPG4",  0xF5 =>  "JPG5",  0xF6 =>  "JPG6",  0xF7 =>  "JPG7",
		0xF8 =>  "JPG8",  0xF9 =>  "JPG9",  0xFA =>  "JPG10", 0xFB =>  "JPG11",
		0xFC =>  "JPG12", 0xFD =>  "JPG13",
		0xFE =>  "COM",   0x01 =>  "TEM",   0x02 =>  "RES",
	);

	function __construct() {
		// This constant should be defined externally to override the default of 100
		$this->_JPG_MAX_QUALITY = defined( 'JPG_MAX_QUALITY' ) ? JPG_MAX_QUALITY : 100;
	}

	private function get_jpeg_header_data( &$buff, $want=null ) {
		$data = $this->buffer_read( $buff, 2, true ); // Read the first two characters
		// Check that the first two characters are 0xFF 0xDA  (SOI - Start of image)
		if ( $data != "\xFF\xD8" ) {
			// No SOI (FF D8) at start of file - This probably isn't a JPEG file - close file and return;
			return false;
		}
		$data = $this->buffer_read( $buff, 2 ); // Read the third character
		// Check that the third character is 0xFF (Start of first segment header)
		if ( $data{0} != "\xFF" ) {
			// NO FF found - close file and return - JPEG is probably corrupted
			return false;
		}
		// Cycle through the file until, one of:
		//   1) an EOI (End of image) marker is hit,
		//   2) we have hit the compressed image data (no more headers are allowed after data)
		//   3) or end of file is hit
		$headerdata = array();
		while ( ( $data{1} != "\xD9" ) && ( $data != '' ) ) {
			// Found a segment to look at.
			// Check that the segment marker is not a Restart marker - restart markers don't have size or data after them
			if (  ( ord($data{1}) < 0xD0 ) || ( ord($data{1}) > 0xD7 ) ) {
				// Segment isn't a Restart marker
				$sizestr = $this->buffer_read( $buff, 2 ); // Read the next two bytes (size)
				if ( null === $sizestr )
					break;
				$decodedsize = unpack ("nsize", $sizestr); // convert the size bytes to an integer
				// Read the segment data with length indicated by the previously read size
				$segdata = $this->buffer_read( $buff, $decodedsize['size'] - 2 );
				// Store the segment information in the output array
				if ( !$want || $want == ord($data{1}) ) {
					$headerdata[] = (object)array(
						"SegType" => ord($data{1}),
						"SegName" => $this->JPEG_Segment_Names[ ord($data{1}) ],
						"SegData" => $segdata
					);
				}
			}
			// If this is a SOS (Start Of Scan) segment, then there is no more header data - the compressed image data follows
			if ( $data{1} == "\xDA" ) {
				break;
			} else {
				// Not an SOS - Read the next two bytes - should be the segment marker for the next segment
				$data = $this->buffer_read( $buff, 2 );
				// Check that the first byte of the two is 0xFF as it should be for a marker
				if ( $data{0} != "\xFF" ) {
					// NO FF found - close file and return - JPEG is probably corrupted
					return false;
				}
			}
		}
		return $headerdata;
	}

	private function buffer_read( &$buff, $len, $new = false ) {
		static $pointer = 0;
		static $total_len = 0;
		if ( $new ) {
			$pointer = 0;
			$total_len = strlen( $buff );
		}
		if ( $pointer + $len > $total_len ) {
			$len = $total_len - $pointer;
			if ( $len < 1 )
				return null;
		}
		$data = substr( $buff, $pointer, $len );
		$pointer += $len;
		return $data;
	}

	private function get_value_by_endianness( $data, $little_endian = true ) {
		$ret_val = 0;
		if ( $little_endian ) {
			for ( $i = ( strlen( $data ) - 1 ); $i >= 0; $i-- ) {
				$ret_val = $ret_val | ord( $data[$i] );
				if ( $i > 0 )
					$ret_val = $ret_val << 8;
			}
		} else {
			for ( $i = 0; $i < strlen( $data ); $i++ ) {
				$ret_val = $ret_val | ord( $data[$i] );
				if ( $i < ( strlen( $data ) - 1 ) )
					$ret_val = $ret_val << 8;
			}
		}
		return $ret_val;
	}

	public function get_jpeg_details( &$image_data, $return_value = 'all' ) {
		$tables = array(
			'multi' => array(
				'hash' => array(
					 1020, 1015,  932,  848,  780,  735,  702,  679,  660,  645,
					  632,  623,  613,  607,  600,  594,  589,  585,  581,  571,
					  555,  542,  529,  514,  494,  474,  457,  439,  424,  410,
					  397,  386,  373,  364,  351,  341,  334,  324,  317,  309,
					  299,  294,  287,  279,  274,  267,  262,  257,  251,  247,
					  243,  237,  232,  227,  222,  217,  213,  207,  202,  198,
					  192,  188,  183,  177,  173,  168,  163,  157,  153,  148,
					  143,  139,  132,  128,  125,  119,  115,  108,  104,   99,
					   94,   90,   84,   79,   74,   70,   64,   59,   55,   49,
					   45,   40,   34,   30,   25,   20,   15,   11,    6,    4,
						0
				), // hash
				'sums' => array (
					 32640, 32635, 32266, 31495, 30665, 29804, 29146, 28599, 28104,
					 27670, 27225, 26725, 26210, 25716, 25240, 24789, 24373, 23946,
					 23572, 22846, 21801, 20842, 19949, 19121, 18386, 17651, 16998,
					 16349, 15800, 15247, 14783, 14321, 13859, 13535, 13081, 12702,
					 12423, 12056, 11779, 11513, 11135, 10955, 10676, 10392, 10208,
					  9928,  9747,  9564,  9369,  9193,  9017,  8822,  8639,  8458,
					  8270,  8084,  7896,  7710,  7527,  7347,  7156,  6977,  6788,
					  6607,  6422,  6236,  6054,  5867,  5684,  5495,  5305,  5128,
					  4945,  4751,  4638,  4442,  4248,  4065,  3888,  3698,  3509,
					  3326,  3139,  2957,  2775,  2586,  2405,  2216,  2037,  1846,
					  1666,  1483,  1297,  1109,   927,   735,   554,   375,   201,
					   128,     0
				 ), // sums
			), // multi
			'single' => array(
				'hash' => array(
				   510,  505,  422,  380,  355,  338,  326,  318,  311,  305,
				   300,  297,  293,  291,  288,  286,  284,  283,  281,  280,
				   279,  278,  277,  273,  262,  251,  243,  233,  225,  218,
				   211,  205,  198,  193,  186,  181,  177,  172,  168,  164,
				   158,  156,  152,  148,  145,  142,  139,  136,  133,  131,
				   129,  126,  123,  120,  118,  115,  113,  110,  107,  105,
				   102,  100,   97,   94,   92,   89,   87,   83,   81,   79,
					76,   74,   70,   68,   66,   63,   61,   57,   55,   52,
					50,   48,   44,   42,   39,   37,   34,   31,   29,   26,
					24,   21,   18,   16,   13,   11,    8,    6,    3,    2,
					 0
				), // hash
				'sums' => array(
				   16320, 16315, 15946, 15277, 14655, 14073, 13623, 13230, 12859,
				   12560, 12240, 11861, 11456, 11081, 10714, 10360, 10027,  9679,
					9368,  9056,  8680,  8331,  7995,  7668,  7376,  7084,  6823,
					6562,  6345,  6125,  5939,  5756,  5571,  5421,  5240,  5086,
					4976,  4829,  4719,  4616,  4463,  4393,  4280,  4166,  4092,
					3980,  3909,  3835,  3755,  3688,  3621,  3541,  3467,  3396,
					3323,  3247,  3170,  3096,  3021,  2952,  2874,  2804,  2727,
					2657,  2583,  2509,  2437,  2362,  2290,  2211,  2136,  2068,
					1996,  1915,  1858,  1773,  1692,  1620,  1552,  1477,  1398,
					1326,  1251,  1179,  1109,  1031,   961,   884,   814,   736,
					 667,   592,   518,   441,   369,   292,   221,   151,    86,
					  64,     0
				), // sums
			), // single
		); // tables
		
		$headers = $this->get_jpeg_header_data( $image_data );
		if ( ! $headers ) {
			if ( 'quality' == $return_value )
				return $this->_JPG_MAX_QUALITY;
			else
				return array(
						'x'=> 0,
						'y'=> 0,
						'q'=> $this->_JPG_MAX_QUALITY,
					);
		}

		$width = 0;
		$height = 0;
		$quality = -1;

		foreach ( (array)$headers as $header ) {
			if ( ( 'all' == $return_value ) && ( strlen( $header->SegData ) >=4 ) &&
				( 192 <= $header->SegType && 207 >= $header->SegType ) &&
				( 196 != $header->SegType && 200 != $header->SegType && 204 != $header->SegType ) ) {
				$height = ( ord( $header->SegData[1] ) << 8 ) | ord( $header->SegData[2] );
				$width = ( ord( $header->SegData[3] ) << 8 ) | ord( $header->SegData[4] );
			}

			if ( ( -1 == $quality ) && ( 'DQT' == $header->SegName ) ) {
				if ( strlen( $header->SegData ) > 128 ) {
					$entry = array( 0 => array(), 1 => array() );
					foreach ( str_split( substr( $header->SegData, 1, 64) ) as $chr )
						$entry[0][] = ord($chr);
					foreach ( str_split( substr( $header->SegData, -64) ) as $chr )
						$entry[1][] = ord($chr);
					$sum = array_sum( $entry[0] ) + array_sum( $entry[1] );
					$qvalue = $entry[0][2] + $entry[0][53] + $entry[1][0] + $entry[1][63];
					$table = "multi";
				} else if ( strlen( $header->SegData ) > 64 ) {
					$entry = array( 0 => array() );
					foreach ( str_split( substr( $header->SegData, 1, 64) ) as $chr )
						$entry[0][] = ord($chr);
					$sum = array_sum( $entry[0] );
					$qvalue = $entry[0][2] + $entry[0][53];
					$table = "single";
				} else {
					continue; // go with the safe value
				}
				for( $i = 0; $i <= 100; $i++ ) {
					if ( ( $qvalue < $tables[$table]['hash'][$i] ) && ( $sum < $tables[$table]['sums'][$i] ) )
						continue;
					$quality = min( $i+1, 100 );
					break;
				}
			} else if ( ( ( -1 != $quality ) && 'quality' == $return_value ) ||
						( ( -1 != $quality ) && ( 0 != $width ) && ( 0 != $height ) ) ) {
				// we have what we came for, bail
				break;
			}
		}
		if ( 'quality' == $return_value )
			return ( -1 == $quality ) ? $this->_JPG_MAX_QUALITY : $quality;
		else
			return array(
					'x'=> $width,
					'y'=> $height,
					'q'=> ( -1 == $quality ) ? $this->_JPG_MAX_QUALITY : $quality,
				);
	}

} // class Jpeg_Image

} // class_exists
