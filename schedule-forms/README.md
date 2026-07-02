# Co-Working Forms (Custom WordPress Plugin)

A multi-form system: shortcode-triggered popup forms, with a Google-Calendar-style
date picker whose availability (Available / Limited seats / Full) is configured
per-date in wp-admin, plus an admin-configurable 1-hour time-slot generator.

Built to support **multiple distinct forms** (Tour Booking today; Inquiry,
Contact, etc. later), where each form has its own:
- Shortcode
- Settings screen (notification email, time range, calendar availability)
- Submissions screen (list + detail view)
- Storage (its own Custom Post Type, used purely as a data store — not shown
  in the normal Posts UI)

---

## Installation

1. Zip the `coworking-forms` folder (or upload it as-is via SFTP) into
   `wp-content/plugins/`.
2. Activate **Co-Working Forms** under Plugins in wp-admin.
3. You'll see a new **Co-Working Forms** item in the left admin menu, with
   "Tour Booking Form: Settings" and "Tour Booking Form: Submissions" beneath it.

## Using the Tour Booking form

Place this shortcode anywhere — a page, a post, or an Elementor "Shortcode"
widget:

```
[cwf_form_tour_booking]
```

This renders an orange **"Schedule a Tour"** button. Clicking it opens the
popup with the form shown in your design (Full Name, Company Name, Phone
Number, Date, Time).

You can override the button text per-placement:

```
[cwf_form_tour_booking button_text="Book a Visit"]
```

### Elementor

Drop in Elementor's native **Shortcode** widget and paste the shortcode above.
No special integration is needed — it's a normal WP shortcode.

## Configuring the calendar (Available / Limited / Full)

Go to **Co-Working Forms → Tour Booking Form: Settings**. Under "Calendar
Availability" you'll see a small calendar. Click any date to cycle:

`Available (default, black) → Limited seats (blue dot) → Full (red dot, unselectable) → back to Available`

Click **Save Changes** to persist. The front-end calendar popover will reflect
this immediately — limited dates show a blue dot but remain clickable, full
dates show a red dot and cannot be selected, and any date with no dot is
open/available. Past dates are automatically disabled regardless of status.

## Configuring time slots

On the same Settings screen, set the **Time Slot Range** ("From" / "To").
The plugin generates consecutive 1-hour slots automatically, e.g. From=10AM,
To=1PM produces:

- 10AM - 11AM
- 11AM - 12PM
- 12PM - 1PM

Change the range any time; the dropdown on the live form updates instantly
(no code changes needed).

## Viewing submissions

**Co-Working Forms → Tour Booking Form: Submissions** lists every entry with
all field values in a sortable-by-date table. Click **View** on any row for
the full detail page. Each new submission also emails the address configured
in that form's Settings (defaults to the site's admin email).

---

## Adding a new form later (e.g. "Inquiry Form")

The whole point of this architecture is that you do **not** touch the shared
engine to add a new form — you only add configuration.

1. Copy `includes/forms/class-cwf-form-tour-booking.php` to
   `includes/forms/class-cwf-form-inquiry.php`.
2. Rename the class to `CWF_Form_Inquiry`.
3. Change the constructor's properties:
   ```php
   $this->slug         = 'inquiry';          // unique, lowercase, hyphenated
   $this->label        = 'Inquiry Form';
   $this->heading      = 'Get in touch';
   $this->button_text  = 'Send an Inquiry';
   $this->has_calendar = false;              // no date/time picker needed
   $this->fields       = array(
       array( 'key' => 'full_name', 'label' => 'Full Name', 'type' => 'text', 'required' => true ),
       array( 'key' => 'email',     'label' => 'Email',     'type' => 'email', 'required' => true ),
       array( 'key' => 'message',   'label' => 'Message',   'type' => 'textarea', 'required' => true ),
   );
   ```
4. In `coworking-forms.php`, inside `cwf_registered_forms()`, add:
   ```php
   require_once CWF_PLUGIN_DIR . 'includes/forms/class-cwf-form-inquiry.php';
   $inquiry = new CWF_Form_Inquiry();
   $forms[ $inquiry->slug ] = $inquiry;
   ```
   (also add the `require_once` near the top of the file alongside the
   existing one for tour-booking)
5. Done. It automatically gets:
   - shortcode `[cwf_form_inquiry]`
   - its own admin menu entries (Settings + Submissions)
   - its own CPT storage, separate from Tour Booking
   - email notifications to whatever address you set in its Settings

Available field `type` values out of the box: `text`, `tel`, `email`,
`textarea`, `select` (needs an `options` array of `value => label`),
plus the two special types used by the tour form: `cwf_date_picker` and
`cwf_time_picker` (only meaningful if `$has_calendar = true`, since the
time picker pulls its slot list from that form's own time-range setting).

---

## File structure

```
coworking-forms/
├── coworking-forms.php              Bootstrap: registers all forms, hooks, assets
├── includes/
│   ├── class-cwf-form-module.php    Shared engine (rendering, AJAX, admin screens)
│   ├── class-cwf-calendar.php       Per-form date-availability storage
│   ├── class-cwf-admin-menu.php     Builds the wp-admin menu structure
│   └── forms/
│       └── class-cwf-form-tour-booking.php   Tour Booking config (template for new forms)
└── assets/
    ├── css/frontend.css             Popup/button/calendar styling
    ├── css/admin.css                Admin settings screen styling
    ├── js/frontend.js               Modal, calendar popover, AJAX submit
    └── js/admin.js                  Admin's clickable availability calendar
```

## Notes on SCF (Secure Custom Fields)

This build does not require SCF — all fields are defined in PHP config and
stored as post meta directly, since you said each form's structure is fixed
(not user-editable in wp-admin via a builder). If you'd later like specific
forms' field *values themselves* to show up nicely in the standard WP edit
screen (e.g. for editing a submission after the fact), SCF field groups can
be layered on top of each form's CPT without changing how submissions are
captured — just register an SCF field group targeting `cwf_tour-booking` (or
whichever CPT) using the same meta keys already used here (`full_name`,
`phone_number`, etc.) and SCF will pick up the existing data automatically.
