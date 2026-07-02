<?php
/**
 * CWF_Form_Tour_Booking
 *
 * "Lets schedule a tour for you!" — the first concrete form.
 *
 * This is intentionally just configuration. All behaviour (rendering,
 * AJAX, admin screens) lives in the shared CWF_Form_Module parent class.
 *
 * TO CREATE YOUR NEXT FORM (e.g. "Inquiry Form"):
 *   1. Copy this file to class-cwf-form-inquiry.php
 *   2. Rename the class to CWF_Form_Inquiry
 *   3. Change $slug (must be unique, lowercase, hyphenated)
 *   4. Change $label, $heading, $button_text
 *   5. Change $fields to whatever that form needs
 *   6. If it does NOT need the calendar/time picker, just leave
 *      $has_calendar = false and use normal field types instead.
 *   7. Register it in coworking-forms.php inside cwf_registered_forms()
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class CWF_Form_Tour_Booking extends CWF_Form_Module {

	public function __construct() {
		$this->slug        = 'tour-booking';
		$this->label       = 'Schedule Form';
		$this->heading     = 'Lets schedule a tour for you!';
		$this->button_text = 'Schedule a Tour';
		$this->has_calendar = true;

		$this->fields = array(
			array(
				'key'      => 'full_name',
				'label'    => 'Full Name',
				'type'     => 'text',
				'required' => true,
			),
			array(
				'key'      => 'company_name',
				'label'    => 'Company Name (Optional)',
				'type'     => 'text',
				'required' => false,
			),
			array(
				'key'      => 'phone_number',
				'label'    => 'Phone Number',
				'type'     => 'tel',
				'required' => true,
			),
			array(
				'key'      => 'tour_date',
				'label'    => 'Date',
				'type'     => 'cwf_date_picker',
				'required' => true,
			),
			array(
				'key'      => 'tour_time',
				'label'    => 'Time',
				'type'     => 'cwf_time_picker',
				'required' => true,
			),
		);

		// Must run after fields/slug are set (parent constructor builds the CWF_Calendar instance).
		parent::__construct();
	}
}
