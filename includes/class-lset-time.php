<?php
/**
 * Dates and times.
 *
 * Event times are stored as the wall-clock text the admin typed ("2027-07-03 12:00:00") plus
 * the timezone it was typed in. They are only turned into a timestamp to sort, compare or
 * print with wp_date(), so a July event entered at 12:00 always shows 12:00.
 *
 * Everything we store about "now" (holds, booking times) is UTC text, from gmdate().
 *
 * @package Lumaha_Simple_Event_Tickets
 */

defined( 'ABSPATH' ) || exit;

class LSET_Time {

	const FORMAT = 'Y-m-d H:i:s';

	/** The site timezone. */
	public static function tz() {
		return wp_timezone();
	}

	/**
	 * A local wall-clock string as a DateTimeImmutable in its timezone.
	 *
	 * @param string                   $local "Y-m-d H:i:s" or "Y-m-d H:i" or "Y-m-d".
	 * @param DateTimeZone|string|null $tz    Defaults to the site timezone.
	 * @return DateTimeImmutable|null
	 */
	public static function local( $local, $tz = null ) {
		$local = trim( (string) $local );
		if ( '' === $local ) {
			return null;
		}
		if ( is_string( $tz ) && '' !== $tz ) {
			try {
				$tz = new DateTimeZone( $tz );
			} catch ( Exception $e ) {
				$tz = null;
			}
		}
		if ( ! $tz instanceof DateTimeZone ) {
			$tz = self::tz();
		}
		foreach ( array( 'Y-m-d H:i:s', 'Y-m-d H:i', 'Y-m-d\TH:i', 'Y-m-d\TH:i:s', 'Y-m-d' ) as $format ) {
			$dt = DateTimeImmutable::createFromFormat( '!' . $format, $local, $tz );
			if ( $dt && $dt->format( $format ) === $local ) {
				return $dt;
			}
		}
		return null;
	}

	/** Unix timestamp for a local wall-clock string, or 0. */
	public static function ts( $local, $tz = null ) {
		$dt = self::local( $local, $tz );
		return $dt ? $dt->getTimestamp() : 0;
	}

	/** Normalise a date (Y-m-d) and a time (H:i) from a form into "Y-m-d H:i:s", or ''. */
	public static function from_parts( $date, $time = '' ) {
		$date = trim( (string) $date );
		$time = trim( (string) $time );
		if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date ) ) {
			return '';
		}
		if ( preg_match( '/^(\d{1,2}):(\d{2})$/', $time, $m ) ) {
			$time = sprintf( '%02d:%02d', $m[1], $m[2] );
		} else {
			$time = '00:00';
		}
		$dt = self::local( $date . ' ' . $time );
		return $dt ? $dt->format( self::FORMAT ) : '';
	}

	/** Print a stored local time with wp_date(). */
	public static function format( $local, $format, $tz = null ) {
		$dt = self::local( $local, $tz );
		if ( ! $dt ) {
			return '';
		}
		return wp_date( $format, $dt->getTimestamp(), $dt->getTimezone() );
	}

	/** ISO 8601 with offset, e.g. 2027-07-03T12:00:00+01:00. */
	public static function iso( $local, $tz = null ) {
		$dt = self::local( $local, $tz );
		return $dt ? $dt->format( DATE_ATOM ) : '';
	}

	/** Move a stored local time by a modifier ("+1 year", "+364 days"), keeping the wall-clock time. */
	public static function shift( $local, $modifier, $tz = null ) {
		$dt = self::local( $local, $tz );
		if ( ! $dt || '' === $modifier ) {
			return $local;
		}
		$moved = $dt->modify( $modifier );
		return $moved ? $moved->format( self::FORMAT ) : $local;
	}

	/** UTC now, as stored in our tables. */
	public static function now_utc( $plus_seconds = 0 ) {
		return gmdate( self::FORMAT, time() + (int) $plus_seconds );
	}
}
