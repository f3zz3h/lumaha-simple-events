<?php
/**
 * A small QR code maker, so the plugin needs no extra libraries.
 *
 * Byte mode only, any version from 1 to 40, with the usual mask choice. Draws the code as SVG
 * (for web pages) or PNG (for emails). Follows Project Nayuki's QR Code generator
 * (https://www.nayuki.io/page/qr-code-generator-library), whose licence is below.
 *
 * Copyright (c) Project Nayuki. (MIT License)
 *
 * Permission is hereby granted, free of charge, to any person obtaining a copy of this software and
 * associated documentation files (the "Software"), to deal in the Software without restriction,
 * including without limitation the rights to use, copy, modify, merge, publish, distribute,
 * sublicense, and/or sell copies of the Software, and to permit persons to whom the Software is
 * furnished to do so, subject to the following conditions:
 * - The above copyright notice and this permission notice shall be included in all copies or
 *   substantial portions of the Software.
 * - The Software is provided "as is", without warranty of any kind, express or implied, including
 *   but not limited to the warranties of merchantability, fitness for a particular purpose and
 *   noninfringement. In no event shall the authors or copyright holders be liable for any claim,
 *   damages or other liability, whether in an action of contract, tort or otherwise, arising from,
 *   out of or in connection with the Software or the use or other dealings in the Software.
 *
 * @package Lumaha_Simple_Event_Tickets
 */

defined( 'ABSPATH' ) || exit;

class LSET_QR {

	// Error correction levels: index into the tables, and the format bits.
	const L = 0;
	const M = 1;
	const Q = 2;
	const H = 3;

	const FORMAT_BITS = array( 1, 0, 3, 2 );

	const ECC_PER_BLOCK = array(
		array( -1, 7, 10, 15, 20, 26, 18, 20, 24, 30, 18, 20, 24, 26, 30, 22, 24, 28, 30, 28, 28, 28, 28, 30, 30, 26, 28, 30, 30, 30, 30, 30, 30, 30, 30, 30, 30, 30, 30, 30, 30 ),
		array( -1, 10, 16, 26, 18, 24, 16, 18, 22, 22, 26, 30, 22, 22, 24, 24, 28, 28, 26, 26, 26, 26, 28, 28, 28, 28, 28, 28, 28, 28, 28, 28, 28, 28, 28, 28, 28, 28, 28, 28, 28 ),
		array( -1, 13, 22, 18, 26, 18, 24, 18, 22, 20, 24, 28, 26, 24, 20, 30, 24, 28, 28, 26, 30, 28, 30, 30, 30, 30, 28, 30, 30, 30, 30, 30, 30, 30, 30, 30, 30, 30, 30, 30, 30 ),
		array( -1, 17, 28, 22, 16, 22, 28, 26, 26, 24, 28, 24, 28, 22, 24, 24, 30, 28, 28, 26, 28, 30, 24, 30, 30, 30, 30, 30, 30, 30, 30, 30, 30, 30, 30, 30, 30, 30, 30, 30, 30 ),
	);

	const ECC_BLOCKS = array(
		array( -1, 1, 1, 1, 1, 1, 2, 2, 2, 2, 4, 4, 4, 4, 4, 6, 6, 6, 6, 7, 8, 8, 9, 9, 10, 12, 12, 12, 13, 14, 15, 16, 17, 18, 19, 19, 20, 21, 22, 24, 25 ),
		array( -1, 1, 1, 1, 2, 2, 4, 4, 4, 5, 5, 5, 8, 9, 9, 10, 10, 11, 13, 14, 16, 17, 17, 18, 20, 21, 23, 25, 26, 28, 29, 31, 33, 35, 37, 38, 40, 43, 45, 47, 49 ),
		array( -1, 1, 1, 2, 2, 4, 4, 6, 6, 8, 8, 8, 10, 12, 16, 12, 17, 16, 18, 21, 20, 23, 23, 25, 27, 29, 34, 34, 35, 38, 40, 43, 45, 48, 51, 53, 56, 59, 62, 65, 68 ),
		array( -1, 1, 1, 2, 4, 4, 4, 5, 6, 8, 8, 11, 11, 16, 16, 18, 16, 19, 21, 25, 25, 25, 34, 30, 32, 35, 37, 40, 42, 45, 48, 51, 54, 57, 60, 63, 66, 70, 74, 77, 81 ),
	);

	/** @var int */
	private $version;
	/** @var int */
	private $size;
	/** @var int */
	private $ecl;
	/** @var bool[][] Dark modules, [y][x]. */
	private $modules = array();
	/** @var bool[][] */
	private $is_function = array();

	/**
	 * Make the QR code for some text.
	 *
	 * @param string $text Text (a URL, usually), as bytes.
	 * @param int    $ecl  Error correction level.
	 * @return self
	 */
	public static function encode( $text, $ecl = self::M ) {
		$bytes = array_values( unpack( 'C*', (string) $text ) ?: array() ); // phpcs:ignore Universal.Operators.DisallowShortTernary
		$len   = count( $bytes );

		for ( $ver = 1; ; $ver++ ) {
			$cap  = self::data_codewords( $ver, $ecl ) * 8;
			$used = 4 + ( $ver < 10 ? 8 : 16 ) + $len * 8;
			if ( $used <= $cap ) {
				break;
			}
			if ( $ver >= 40 ) {
				throw new InvalidArgumentException( 'Text too long for a QR code.' );
			}
		}
		// Use a higher error correction level if it still fits the same size.
		foreach ( array( self::M, self::Q, self::H ) as $better ) {
			if ( $better > $ecl && $used <= self::data_codewords( $ver, $better ) * 8 ) {
				$ecl = $better;
			}
		}

		$bits = array();
		self::append_bits( $bits, 4, 4 ); // Byte mode.
		self::append_bits( $bits, $len, $ver < 10 ? 8 : 16 );
		foreach ( $bytes as $b ) {
			self::append_bits( $bits, $b, 8 );
		}
		$cap = self::data_codewords( $ver, $ecl ) * 8;
		self::append_bits( $bits, 0, min( 4, $cap - count( $bits ) ) );
		self::append_bits( $bits, 0, ( 8 - count( $bits ) % 8 ) % 8 );
		for ( $pad = 0xEC; count( $bits ) < $cap; $pad ^= 0xEC ^ 0x11 ) {
			self::append_bits( $bits, $pad, 8 );
		}

		$codewords = array_fill( 0, count( $bits ) >> 3, 0 );
		foreach ( $bits as $i => $bit ) {
			$codewords[ $i >> 3 ] |= $bit << ( 7 - ( $i & 7 ) );
		}
		return new self( $ver, $ecl, $codewords );
	}

	private function __construct( $ver, $ecl, array $data ) {
		$this->version = $ver;
		$this->ecl     = $ecl;
		$this->size    = $ver * 4 + 17;
		$row           = array_fill( 0, $this->size, false );
		$this->modules = array_fill( 0, $this->size, $row );
		$this->is_function = array_fill( 0, $this->size, $row );

		$this->draw_function_patterns();
		$this->draw_codewords( $this->add_ecc_and_interleave( $data ) );

		// Pick the mask with the lowest penalty.
		$best      = 0;
		$min_score = PHP_INT_MAX;
		for ( $mask = 0; $mask < 8; $mask++ ) {
			$this->apply_mask( $mask );
			$this->draw_format_bits( $mask );
			$score = $this->penalty();
			if ( $score < $min_score ) {
				$best      = $mask;
				$min_score = $score;
			}
			$this->apply_mask( $mask ); // Undo (XOR).
		}
		$this->apply_mask( $best );
		$this->draw_format_bits( $best );
		$this->is_function = array();
	}

	public function size() {
		return $this->size;
	}

	public function version() {
		return $this->version;
	}

	public function dark( $x, $y ) {
		return $x >= 0 && $y >= 0 && $x < $this->size && $y < $this->size && $this->modules[ $y ][ $x ];
	}

	/**
	 * An SVG image: one path, black on white, with the standard 4-module quiet zone.
	 *
	 * @param int    $px    Width and height in CSS pixels (0 to leave it to CSS).
	 * @param string $label Accessible label.
	 */
	public function svg( $px = 0, $label = '' ) {
		$n    = $this->size + 8;
		$path = '';
		for ( $y = 0; $y < $this->size; $y++ ) {
			for ( $x = 0; $x < $this->size; $x++ ) {
				if ( $this->modules[ $y ][ $x ] ) {
					$path .= 'M' . ( $x + 4 ) . ',' . ( $y + 4 ) . 'h1v1h-1z';
				}
			}
		}
		$dims = $px ? ' width="' . (int) $px . '" height="' . (int) $px . '"' : '';
		$aria = '' !== $label ? ' role="img" aria-label="' . esc_attr( $label ) . '"' : ' aria-hidden="true"';
		return '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 ' . $n . ' ' . $n . '"' . $dims . $aria . ' shape-rendering="crispEdges"><rect width="100%" height="100%" fill="#fff"/><path d="' . $path . '" fill="#000"/></svg>';
	}

	/**
	 * A black and white PNG (1 bit), $scale pixels per module, with the 4-module quiet zone.
	 * Written by hand so it needs neither GD nor Imagick.
	 */
	public function png( $scale = 6 ) {
		$scale = max( 1, (int) $scale );
		$n     = ( $this->size + 8 ) * $scale;
		$raw   = '';
		for ( $py = 0; $py < $n; $py++ ) {
			$y    = intdiv( $py, $scale ) - 4;
			$line = "\0"; // Filter: none.
			$byte = 0;
			for ( $px = 0; $px < $n; $px++ ) {
				$white = ! $this->dark( intdiv( $px, $scale ) - 4, $y );
				$byte  = ( $byte << 1 ) | ( $white ? 1 : 0 );
				if ( 7 === $px % 8 ) {
					$line .= chr( $byte );
					$byte  = 0;
				}
			}
			if ( $n % 8 ) {
				$line .= chr( $byte << ( 8 - $n % 8 ) );
			}
			$raw .= $line;
		}
		return "\x89PNG\r\n\x1a\n"
			. self::png_chunk( 'IHDR', pack( 'NNCCCCC', $n, $n, 1, 0, 0, 0, 0 ) )
			. self::png_chunk( 'IDAT', self::zlib( $raw ) )
			. self::png_chunk( 'IEND', '' );
	}

	private static function png_chunk( $type, $data ) {
		return pack( 'N', strlen( $data ) ) . $type . $data . pack( 'N', crc32( $type . $data ) );
	}

	/** zlib data; uses stored (uncompressed) blocks if PHP has no zlib. */
	private static function zlib( $raw ) {
		if ( function_exists( 'gzcompress' ) ) {
			return gzcompress( $raw, 9 );
		}
		$out    = "\x78\x01";
		$chunks = str_split( $raw, 65535 );
		foreach ( $chunks as $i => $c ) {
			$len  = strlen( $c );
			$out .= chr( count( $chunks ) - 1 === $i ? 1 : 0 ) . pack( 'v', $len ) . pack( 'v', 0xFFFF ^ $len ) . $c;
		}
		$a = 1;
		$b = 0;
		$l = strlen( $raw );
		for ( $i = 0; $i < $l; $i++ ) {
			$a = ( $a + ord( $raw[ $i ] ) ) % 65521;
			$b = ( $b + $a ) % 65521;
		}
		return $out . pack( 'N', ( $b << 16 ) | $a );
	}

	/* ---------------------------------------------------------------------
	 * Building the symbol
	 * ------------------------------------------------------------------ */

	private static function append_bits( array &$bits, $val, $len ) {
		for ( $i = $len - 1; $i >= 0; $i-- ) {
			$bits[] = ( $val >> $i ) & 1;
		}
	}

	private static function raw_modules( $ver ) {
		$result = ( 16 * $ver + 128 ) * $ver + 64;
		if ( $ver >= 2 ) {
			$align   = intdiv( $ver, 7 ) + 2;
			$result -= ( 25 * $align - 10 ) * $align - 55;
			if ( $ver >= 7 ) {
				$result -= 36;
			}
		}
		return $result;
	}

	private static function data_codewords( $ver, $ecl ) {
		return intdiv( self::raw_modules( $ver ), 8 ) - self::ECC_PER_BLOCK[ $ecl ][ $ver ] * self::ECC_BLOCKS[ $ecl ][ $ver ];
	}

	private function set_function( $x, $y, $dark ) {
		$this->modules[ $y ][ $x ]     = (bool) $dark;
		$this->is_function[ $y ][ $x ] = true;
	}

	private function draw_function_patterns() {
		for ( $i = 0; $i < $this->size; $i++ ) {
			$this->set_function( 6, $i, 0 === $i % 2 );
			$this->set_function( $i, 6, 0 === $i % 2 );
		}
		$this->draw_finder( 3, 3 );
		$this->draw_finder( $this->size - 4, 3 );
		$this->draw_finder( 3, $this->size - 4 );

		$pos = $this->alignment_positions();
		$n   = count( $pos );
		for ( $i = 0; $i < $n; $i++ ) {
			for ( $j = 0; $j < $n; $j++ ) {
				if ( ( 0 === $i && 0 === $j ) || ( 0 === $i && $n - 1 === $j ) || ( $n - 1 === $i && 0 === $j ) ) {
					continue;
				}
				for ( $dy = -2; $dy <= 2; $dy++ ) {
					for ( $dx = -2; $dx <= 2; $dx++ ) {
						$this->set_function( $pos[ $i ] + $dx, $pos[ $j ] + $dy, 1 !== max( abs( $dx ), abs( $dy ) ) );
					}
				}
			}
		}
		$this->draw_format_bits( 0 ); // Reserve the area; drawn for real after masking.
		$this->draw_version();
	}

	private function draw_finder( $x, $y ) {
		for ( $dy = -4; $dy <= 4; $dy++ ) {
			for ( $dx = -4; $dx <= 4; $dx++ ) {
				$xx = $x + $dx;
				$yy = $y + $dy;
				if ( $xx >= 0 && $xx < $this->size && $yy >= 0 && $yy < $this->size ) {
					$d = max( abs( $dx ), abs( $dy ) );
					$this->set_function( $xx, $yy, 2 !== $d && 4 !== $d );
				}
			}
		}
	}

	private function alignment_positions() {
		if ( 1 === $this->version ) {
			return array();
		}
		$n      = intdiv( $this->version, 7 ) + 2;
		$step   = intdiv( $this->version * 8 + $n * 3 + 5, $n * 4 - 4 ) * 2;
		$result = array();
		for ( $i = $n - 1, $pos = $this->size - 7; $i >= 1; $i--, $pos -= $step ) {
			$result[] = $pos;
		}
		$result[] = 6;
		return array_reverse( $result );
	}

	private function draw_format_bits( $mask ) {
		$data = ( self::FORMAT_BITS[ $this->ecl ] << 3 ) | $mask;
		$rem  = $data;
		for ( $i = 0; $i < 10; $i++ ) {
			$rem = ( $rem << 1 ) ^ ( ( $rem >> 9 ) * 0x537 );
		}
		$bits = ( ( $data << 10 ) | $rem ) ^ 0x5412;
		$bit  = function ( $i ) use ( $bits ) {
			return 1 === ( ( $bits >> $i ) & 1 );
		};

		for ( $i = 0; $i <= 5; $i++ ) {
			$this->set_function( 8, $i, $bit( $i ) );
		}
		$this->set_function( 8, 7, $bit( 6 ) );
		$this->set_function( 8, 8, $bit( 7 ) );
		$this->set_function( 7, 8, $bit( 8 ) );
		for ( $i = 9; $i < 15; $i++ ) {
			$this->set_function( 14 - $i, 8, $bit( $i ) );
		}

		for ( $i = 0; $i < 8; $i++ ) {
			$this->set_function( $this->size - 1 - $i, 8, $bit( $i ) );
		}
		for ( $i = 8; $i < 15; $i++ ) {
			$this->set_function( 8, $this->size - 15 + $i, $bit( $i ) );
		}
		$this->set_function( 8, $this->size - 8, true );
	}

	private function draw_version() {
		if ( $this->version < 7 ) {
			return;
		}
		$rem = $this->version;
		for ( $i = 0; $i < 12; $i++ ) {
			$rem = ( $rem << 1 ) ^ ( ( $rem >> 11 ) * 0x1F25 );
		}
		$bits = ( $this->version << 12 ) | $rem;
		for ( $i = 0; $i < 18; $i++ ) {
			$dark = 1 === ( ( $bits >> $i ) & 1 );
			$a    = $this->size - 11 + $i % 3;
			$b    = intdiv( $i, 3 );
			$this->set_function( $a, $b, $dark );
			$this->set_function( $b, $a, $dark );
		}
	}

	private function add_ecc_and_interleave( array $data ) {
		$blocks_n    = self::ECC_BLOCKS[ $this->ecl ][ $this->version ];
		$ecc_len     = self::ECC_PER_BLOCK[ $this->ecl ][ $this->version ];
		$raw         = intdiv( self::raw_modules( $this->version ), 8 );
		$short_n     = $blocks_n - $raw % $blocks_n;
		$short_len   = intdiv( $raw, $blocks_n );
		$divisor     = self::rs_divisor( $ecc_len );
		$blocks      = array();
		for ( $i = 0, $k = 0; $i < $blocks_n; $i++ ) {
			$len = $short_len - $ecc_len + ( $i < $short_n ? 0 : 1 );
			$dat = array_slice( $data, $k, $len );
			$k  += $len;
			$ecc = self::rs_remainder( $dat, $divisor );
			if ( $i < $short_n ) {
				$dat[] = 0;
			}
			$blocks[] = array_merge( $dat, $ecc );
		}
		$out = array();
		$n   = count( $blocks[0] );
		for ( $i = 0; $i < $n; $i++ ) {
			foreach ( $blocks as $j => $block ) {
				if ( $i !== $short_len - $ecc_len || $j >= $short_n ) {
					$out[] = $block[ $i ];
				}
			}
		}
		return $out;
	}

	private static function rs_divisor( $degree ) {
		$result              = array_fill( 0, $degree, 0 );
		$result[ $degree - 1 ] = 1;
		$root                = 1;
		for ( $i = 0; $i < $degree; $i++ ) {
			for ( $j = 0; $j < $degree; $j++ ) {
				$result[ $j ] = self::gf_mul( $result[ $j ], $root );
				if ( $j + 1 < $degree ) {
					$result[ $j ] ^= $result[ $j + 1 ];
				}
			}
			$root = self::gf_mul( $root, 0x02 );
		}
		return $result;
	}

	private static function rs_remainder( array $data, array $divisor ) {
		$result = array_fill( 0, count( $divisor ), 0 );
		foreach ( $data as $b ) {
			$factor   = $b ^ array_shift( $result );
			$result[] = 0;
			foreach ( $divisor as $i => $coef ) {
				$result[ $i ] ^= self::gf_mul( $coef, $factor );
			}
		}
		return $result;
	}

	private static function gf_mul( $x, $y ) {
		$z = 0;
		for ( $i = 7; $i >= 0; $i-- ) {
			$z  = ( $z << 1 ) ^ ( ( $z >> 7 ) * 0x11D );
			$z ^= ( ( $y >> $i ) & 1 ) * $x;
		}
		return $z;
	}

	private function draw_codewords( array $data ) {
		$i     = 0;
		$total = count( $data ) * 8;
		for ( $right = $this->size - 1; $right >= 1; $right -= 2 ) {
			if ( 6 === $right ) {
				$right = 5;
			}
			for ( $vert = 0; $vert < $this->size; $vert++ ) {
				for ( $j = 0; $j < 2; $j++ ) {
					$x      = $right - $j;
					$upward = 0 === ( ( $right + 1 ) & 2 );
					$y      = $upward ? $this->size - 1 - $vert : $vert;
					if ( ! $this->is_function[ $y ][ $x ] && $i < $total ) {
						$this->modules[ $y ][ $x ] = 1 === ( ( $data[ $i >> 3 ] >> ( 7 - ( $i & 7 ) ) ) & 1 );
						$i++;
					}
				}
			}
		}
	}

	private function apply_mask( $mask ) {
		for ( $y = 0; $y < $this->size; $y++ ) {
			for ( $x = 0; $x < $this->size; $x++ ) {
				if ( $this->is_function[ $y ][ $x ] ) {
					continue;
				}
				switch ( $mask ) {
					case 0:
						$invert = 0 === ( $x + $y ) % 2;
						break;
					case 1:
						$invert = 0 === $y % 2;
						break;
					case 2:
						$invert = 0 === $x % 3;
						break;
					case 3:
						$invert = 0 === ( $x + $y ) % 3;
						break;
					case 4:
						$invert = 0 === ( intdiv( $x, 3 ) + intdiv( $y, 2 ) ) % 2;
						break;
					case 5:
						$invert = 0 === $x * $y % 2 + $x * $y % 3;
						break;
					case 6:
						$invert = 0 === ( $x * $y % 2 + $x * $y % 3 ) % 2;
						break;
					default:
						$invert = 0 === ( ( $x + $y ) % 2 + $x * $y % 3 ) % 2;
				}
				if ( $invert ) {
					$this->modules[ $y ][ $x ] = ! $this->modules[ $y ][ $x ];
				}
			}
		}
	}

	/** The standard penalty score: long runs, 2×2 blocks, finder-like patterns, dark/light balance. */
	private function penalty() {
		$score = 0;
		$n     = $this->size;
		$lines = array();
		for ( $y = 0; $y < $n; $y++ ) {
			$row = '';
			$col = '';
			for ( $x = 0; $x < $n; $x++ ) {
				$row .= $this->modules[ $y ][ $x ] ? '1' : '0';
				$col .= $this->modules[ $x ][ $y ] ? '1' : '0';
			}
			$lines[] = $row;
			$lines[] = $col;
		}
		$dark = 0;
		foreach ( $lines as $k => $line ) {
			if ( 0 === $k % 2 ) {
				$dark += substr_count( $line, '1' );
			}
			if ( preg_match_all( '/0{5,}|1{5,}/', $line, $m ) ) {
				foreach ( $m[0] as $run ) {
					$score += 3 + strlen( $run ) - 5;
				}
			}
			// Light quiet zone counts as part of the pattern.
			$padded = '0000' . $line . '0000';
			$score += 40 * preg_match_all( '/(?=00001011101|10111010000)/', $padded );
		}
		for ( $y = 0; $y < $n - 1; $y++ ) {
			for ( $x = 0; $x < $n - 1; $x++ ) {
				$c = $this->modules[ $y ][ $x ];
				if ( $c === $this->modules[ $y ][ $x + 1 ] && $c === $this->modules[ $y + 1 ][ $x ] && $c === $this->modules[ $y + 1 ][ $x + 1 ] ) {
					$score += 3;
				}
			}
		}
		$total  = $n * $n;
		$k      = (int) ceil( abs( $dark * 20 - $total * 10 ) / $total ) - 1;
		$score += max( 0, $k ) * 10;
		return $score;
	}
}
