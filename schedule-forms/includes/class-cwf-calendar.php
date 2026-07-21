<?php
/**
 * CWF_Calendar
 *
 * Stores and retrieves per-date availability status for a given form.
 *
 * Storage: a single row in wp_options per form, keyed by 'cwf_calendar_{slug}'.
 * Shape:  [ 'YYYY-MM-DD' => 'limited' | 'full', ... ]
 * Any date NOT present in this array is implicitly "available" (default/black).
 * We only store exceptions, so the option stays small even years out.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class CWF_Calendar {

	const STATUS_AVAILABLE = 'available'; // Default black, always implicit.
	const STATUS_LIMITED   = 'limited';   // Blue dot.
	const STATUS_FULL      = 'full';      // Red dot, unselectable.

	/** @var string */
	protected $form_slug;

	public function __construct( $form_slug ) {
		$this->form_slug = $form_slug;
	}

	protected function option_key() {
		return 'cwf_calendar_' . $this->form_slug;
	}

	/**
	 * Get the raw map of exception dates => status.
	 *
	 * @return array<string,string>
	 */
	public function get_all() {
		$data = get_option( $this->option_key(), array() );
		return is_array( $data ) ? $data : array();
	}

	/**
	 * Set ONE date's status atomically (read-modify-write on the option).
	 * Setting STATUS_AVAILABLE removes the date from storage entirely
	 * (since "available" is represented by absence, not an explicit value).
	 */
	public function set_status( $date, $status ) {
		if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date ) ) {
			return false;
		}

		$all = $this->get_all();

		if ( self::STATUS_AVAILABLE === $status ) {
			unset( $all[ $date ] );
		} elseif ( in_array( $status, array( self::STATUS_LIMITED, self::STATUS_FULL ), true ) ) {
			$all[ $date ] = $status;
		} else {
			return false;
		}

		return update_option( $this->option_key(), $all, false );
	}

	/**
	 * Replace the entire map (used when saving the admin settings screen).
	 *
	 * @param array<string,string> $map
	 */
	public function save_all( $map ) {
		$clean = array();

		foreach ( $map as $date => $status ) {
			if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date ) ) {
				continue;
			}
			if ( ! in_array( $status, array( self::STATUS_LIMITED, self::STATUS_FULL ), true ) ) {
				continue; // Only store exceptions; "available" = absence from map.
			}
			$clean[ $date ] = $status;
		}

		update_option( $this->option_key(), $clean, false );
	}

	/**
	 * Status for one date (YYYY-MM-DD).
	 */
	public function get_status( $date ) {
		$all = $this->get_all();
		return isset( $all[ $date ] ) ? $all[ $date ] : self::STATUS_AVAILABLE;
	}

	/**
	 * Is this date bookable at all? (Full dates and past dates are not.)
	 */
	public function is_selectable( $date ) {
		if ( self::STATUS_FULL === $this->get_status( $date ) ) {
			return false;
		}
		$today = current_time( 'Y-m-d' );
		return $date >= $today;
	}
}