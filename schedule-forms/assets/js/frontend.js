/**
 * Co-Working Forms — front-end behaviour.
 * Vanilla JS (no framework dependency beyond jQuery being present in WP).
 *
 * IMPORTANT — Elementor Popup compatibility:
 * Elementor (and most other popup/modal builders) does NOT insert the
 * popup's markup into the page at initial load. It injects it later, on
 * its own schedule, when the popup is opened. That means by the time our
 * old `DOMContentLoaded` handler ran `querySelectorAll(...)` and attached
 * listeners directly to the matched elements, the popup's form/date-field/
 * calendar elements often didn't exist in the DOM yet — so nothing was
 * ever wired up for them.
 *
 * Fix: every listener below is attached ONCE to `document` (or `window`)
 * using event delegation (`e.target.closest(...)`). Delegated listeners
 * keep working for elements added to the page at any point afterwards —
 * it doesn't matter whether the markup was there on page load, injected
 * by Elementor when a popup opens, or added by any other dynamic process.
 * No re-initialization step is required.
 *
 * Per-element state (which month/year a date-field's calendar is showing,
 * which date is selected) is kept in a WeakMap keyed by the field's wrapper
 * element instead of in a closure, since delegation means we no longer have
 * a closure created at "bind time" for each element.
 */
(function () {
  "use strict";

  var MONTH_NAMES = [
    "January",
    "February",
    "March",
    "April",
    "May",
    "June",
    "July",
    "August",
    "September",
    "October",
    "November",
    "December",
  ];
  var DOW = ["Sun", "Mon", "Tue", "Wed", "Thu", "Fri", "Sat"];

  // Per date-field state, keyed by the [data-cwf-date-field] wrapper element.
  var dateFieldState = new WeakMap();

  function getState(wrap) {
    if (!dateFieldState.has(wrap)) {
      dateFieldState.set(wrap, {
        year: new Date().getFullYear(),
        month: new Date().getMonth() + 1, // 1-12
        selected: null,
      });
    }
    return dateFieldState.get(wrap);
  }

  /* ---------------- Modal open/close ---------------- */

  function openModalFor(slug) {
    var modal = document.getElementById("cwf-modal-" + slug);
    if (modal) {
      modal.classList.add("is-open");
      modal.setAttribute("aria-hidden", "false");
    }
  }

  function closeModal(overlay) {
    if (!overlay) {
      return;
    }
    overlay.classList.remove("is-open");
    overlay.setAttribute("aria-hidden", "true");
  }

  /* ---------------- Calendar popover ---------------- */

  function closeAllPopovers() {
    document
      .querySelectorAll(".cwf-calendar-popover.is-open")
      .forEach(function (p) {
        p.classList.remove("is-open");
      });
  }

  function toggleDateField(wrap) {
    var input = wrap.querySelector("input");
    var popover = wrap.querySelector("[data-cwf-calendar]");
    var form = wrap.closest(".cwf-form");
    if (!input || !popover || !form) {
      return;
    }
    var slug = form.getAttribute("data-cwf-slug");
    var state = getState(wrap);

    var isOpen = popover.classList.contains("is-open");
    closeAllPopovers();
    if (!isOpen) {
      popover.classList.add("is-open");
      loadMonth(slug, state, popover, input);
    }
  }

  function loadMonth(slug, state, popover, input) {
    if (!window.cwfFrontend || !window.cwfFrontend.ajaxUrl) {
      popover.innerHTML =
        '<div style="padding:20px;color:#c0392b;">Calendar assets did not load on this page.</div>';
      return;
    }

    popover.innerHTML =
      '<div style="padding:20px;text-align:center;color:#999;">Loading...</div>';

    var body = new URLSearchParams();
    body.append("action", "cwf_calendar_" + slug.replace(/-/g, "_"));
    body.append("nonce", window.cwfFrontend.nonce);
    body.append("year", state.year);
    body.append("month", state.month);

    fetch(window.cwfFrontend.ajaxUrl, {
      method: "POST",
      headers: { "Content-Type": "application/x-www-form-urlencoded" },
      body: body.toString(),
    })
      .then(function (res) {
        return res.json();
      })
      .then(function (json) {
        if (json.success) {
          renderCalendar(json.data, state, popover);
        } else {
          popover.innerHTML =
            '<div style="padding:20px;color:#c0392b;">Could not load calendar.</div>';
        }
      })
      .catch(function () {
        popover.innerHTML =
          '<div style="padding:20px;color:#c0392b;">Network error.</div>';
      });
  }

  // Note: no per-render addEventListener calls here anymore — clicks on the
  // nav buttons / day buttons are handled by the single delegated document
  // click listener below, so re-rendering this innerHTML on every month
  // change or selection no longer "loses" any handlers.
  function renderCalendar(data, state, popover) {
    var year = data.year;
    var month = data.month; // 1-12
    var days = data.days;

    var firstDow = new Date(year, month - 1, 1).getDay(); // 0=Sun
    var daysInMonth = Object.keys(days).length;

    var html = "";
    html += '<div class="cwf-cal-header">';
    html +=
      '<button type="button" class="cwf-cal-nav-btn" data-cwf-prev>&#8249;</button>';
    html +=
      '<span class="cwf-cal-title">' +
      MONTH_NAMES[month - 1] +
      " " +
      year +
      "</span>";
    html +=
      '<button type="button" class="cwf-cal-nav-btn" data-cwf-next>&#8250;</button>';
    html += "</div>";

    html += '<div class="cwf-cal-grid">';
    DOW.forEach(function (d, i) {
      var weekendClass = i === 0 || i === 6 ? " is-weekend" : "";
      html += '<div class="cwf-cal-dow' + weekendClass + '">' + d + "</div>";
    });

    for (var i = 0; i < firstDow; i++) {
      html += '<div class="cwf-cal-day is-empty"></div>';
    }

    for (var d = 1; d <= daysInMonth; d++) {
      var dateStr = year + "-" + pad(month) + "-" + pad(d);
      var info = days[dateStr] || { status: "available", selectable: true };
      var dow = new Date(year, month - 1, d).getDay();
      var classes = "cwf-cal-day";
      if (dow === 0 || dow === 6) {
        classes += " is-weekend";
      }
      if (!info.selectable) {
        classes += " is-disabled";
      }
      if (state.selected === dateStr) {
        classes += " is-selected";
      }

      var dot = "";
      if (info.status === "limited") {
        dot = '<span class="cwf-dot cwf-dot-limited"></span>';
      } else if (info.status === "full") {
        dot = '<span class="cwf-dot cwf-dot-full"></span>';
      }

      html +=
        '<button type="button" class="' +
        classes +
        '" data-date="' +
        dateStr +
        '"' +
        (info.selectable ? "" : " disabled") +
        ">" +
        d +
        dot +
        "</button>";
    }

    html += "</div>";
    popover.innerHTML = html;
  }

  function pad(n) {
    return n < 10 ? "0" + n : "" + n;
  }

  /* ---------------- Field-level validation UI ---------------- */

  var FULL_NAME_REGEX = /^[A-Za-z]+(?:\s+[A-Za-z]+)+$/;
  var PHONE_REGEX = /^[0-9]{10}$/;

  function getFieldWrap(control) {
    return control && control.closest(".cwf-field");
  }

  function getFieldErrorEl(wrap) {
    return wrap && wrap.querySelector(".cwf-field-error-msg");
  }

  function showFieldError(control, message) {
    var wrap = getFieldWrap(control);
    var errEl = getFieldErrorEl(wrap);
    control.classList.add("cwf-input-error");
    control.style.borderColor = "#d63638";
    if (errEl) {
      errEl.textContent = message;
      errEl.style.display = "block";
    }
  }

  function clearFieldError(control) {
    var wrap = getFieldWrap(control);
    var errEl = getFieldErrorEl(wrap);
    control.classList.remove("cwf-input-error");
    control.style.borderColor = "";
    if (errEl) {
      errEl.textContent = "";
      errEl.style.display = "none";
    }
  }

  function clearAllFieldErrors(form) {
    form
      .querySelectorAll("input[name], select[name], textarea[name]")
      .forEach(clearFieldError);
  }

  function validateCwfForm(form) {
    clearAllFieldErrors(form);

    var isValid = true;
    var firstInvalidControl = null;

    form
      .querySelectorAll("input[name], select[name], textarea[name]")
      .forEach(function (control) {
        var key = control.getAttribute("name");
        var value = (control.value || "").trim();

        if (control.hasAttribute("required") && !value) {
          showFieldError(control, "This field is required.");
          isValid = false;
          firstInvalidControl = firstInvalidControl || control;
          return;
        }

        if (key === "full_name" && value && !FULL_NAME_REGEX.test(value)) {
          showFieldError(
            control,
            "Please enter your full name (first and last name)."
          );
          isValid = false;
          firstInvalidControl = firstInvalidControl || control;
        }

        if (key === "phone_number" && value && !PHONE_REGEX.test(value)) {
          showFieldError(
            control,
            "Please enter a valid 10-digit phone number."
          );
          isValid = false;
          firstInvalidControl = firstInvalidControl || control;
        }
      });

    if (firstInvalidControl) {
      firstInvalidControl.focus();
    }

    return isValid;
  }

  /* ---------------- Form submit ---------------- */

  function submitCwfForm(form) {
    var slug = form.getAttribute("data-cwf-slug");
    var msgEl = form.querySelector(".cwf-form-message");
    var submitBtn = form.querySelector(".cwf-submit-btn");

    if (msgEl) {
      msgEl.textContent = "";
      msgEl.className = "cwf-form-message";
    }

    if (!validateCwfForm(form)) {
      return;
    }

    if (!window.cwfFrontend || !window.cwfFrontend.ajaxUrl) {
      if (msgEl) {
        msgEl.textContent =
          "This form's scripts did not load on this page. Please reload and try again.";
        msgEl.className = "cwf-form-message is-error";
      }
      return;
    }

    var formData = new FormData(form);
    formData.append("action", "cwf_submit_" + slug.replace(/-/g, "_"));

    if (submitBtn) {
      submitBtn.disabled = true;
    }

    fetch(window.cwfFrontend.ajaxUrl, {
      method: "POST",
      body: formData,
    })
      .then(function (res) {
        return res.json();
      })
      .then(function (json) {
        if (submitBtn) {
          submitBtn.disabled = false;
        }
        if (!msgEl) {
          return;
        }
        if (json.success) {
          msgEl.textContent = json.data.message;
          msgEl.className = "cwf-form-message is-success";
          form.reset();
        } else {
          msgEl.textContent =
            json.data && json.data.message
              ? json.data.message
              : "Something went wrong.";
          msgEl.className = "cwf-form-message is-error";
        }
      })
      .catch(function () {
        if (submitBtn) {
          submitBtn.disabled = false;
        }
        if (msgEl) {
          msgEl.textContent = "Network error. Please try again.";
          msgEl.className = "cwf-form-message is-error";
        }
      });
  }

  /* ---------------- Delegated listeners (work for any markup,            */
  /* present at load or injected later by Elementor/any builder) --------- */

  document.addEventListener("click", function (e) {
    var openBtn = e.target.closest("[data-cwf-open]");
    if (openBtn) {
      openModalFor(openBtn.getAttribute("data-cwf-open"));
      return;
    }

    var closeBtn = e.target.closest("[data-cwf-close]");
    if (closeBtn) {
      closeModal(closeBtn.closest(".cwf-modal-overlay"));
      return;
    }

    var overlay =
      e.target.classList && e.target.classList.contains("cwf-modal-overlay")
        ? e.target
        : null;
    if (overlay) {
      closeModal(overlay);
      return;
    }

    var dateInput = e.target.closest("[data-cwf-date-field] input");
    if (dateInput) {
      e.stopPropagation();
      toggleDateField(dateInput.closest("[data-cwf-date-field]"));
      return;
    }

    var prevBtn = e.target.closest("[data-cwf-prev]");
    if (prevBtn) {
      e.stopPropagation();
      var prevPopover = prevBtn.closest(".cwf-calendar-popover");
      var prevWrap =
        prevPopover && prevPopover.closest("[data-cwf-date-field]");
      if (prevWrap) {
        var prevInput = prevWrap.querySelector("input");
        var prevForm = prevWrap.closest(".cwf-form");
        var prevSlug = prevForm && prevForm.getAttribute("data-cwf-slug");
        var prevState = getState(prevWrap);
        prevState.month--;
        if (prevState.month < 1) {
          prevState.month = 12;
          prevState.year--;
        }
        loadMonth(prevSlug, prevState, prevPopover, prevInput);
      }
      return;
    }

    var nextBtn = e.target.closest("[data-cwf-next]");
    if (nextBtn) {
      e.stopPropagation();
      var nextPopover = nextBtn.closest(".cwf-calendar-popover");
      var nextWrap =
        nextPopover && nextPopover.closest("[data-cwf-date-field]");
      if (nextWrap) {
        var nextInput = nextWrap.querySelector("input");
        var nextForm = nextWrap.closest(".cwf-form");
        var nextSlug = nextForm && nextForm.getAttribute("data-cwf-slug");
        var nextState = getState(nextWrap);
        nextState.month++;
        if (nextState.month > 12) {
          nextState.month = 1;
          nextState.year++;
        }
        loadMonth(nextSlug, nextState, nextPopover, nextInput);
      }
      return;
    }

    var dayBtn = e.target.closest(".cwf-cal-day[data-date]");
    if (
      dayBtn &&
      !dayBtn.disabled &&
      !dayBtn.classList.contains("is-disabled")
    ) {
      e.stopPropagation();
      var dayPopover = dayBtn.closest(".cwf-calendar-popover");
      var dayWrap = dayPopover && dayPopover.closest("[data-cwf-date-field]");
      if (dayWrap) {
        var dayInput = dayWrap.querySelector("input");
        var dayState = getState(dayWrap);
        var date = dayBtn.getAttribute("data-date");
        dayState.selected = date;
        if (dayInput) {
          dayInput.value = date;
          dayInput.dispatchEvent(new Event("change", { bubbles: true }));
        }
        dayPopover.classList.remove("is-open");
      }
      return;
    }

    // Click was outside any date field — close any open calendar popovers.
    if (!e.target.closest("[data-cwf-date-field]")) {
      closeAllPopovers();
    }
  });

  document.addEventListener("submit", function (e) {
    var form = e.target;
    if (!form.classList || !form.classList.contains("cwf-form")) {
      return;
    }
    e.preventDefault();
    submitCwfForm(form);
  });

  // As the person edits a field that's currently flagged invalid, clear
  // that field's error immediately rather than making them re-submit to
  // find out it's fixed. `input` covers typing; `change` covers the
  // calendar date-field, which sets its value programmatically and
  // dispatches a `change` event (see the day-button handler above).
  function maybeClearOnEdit(e) {
    var control = e.target.closest(
      ".cwf-field input[name], .cwf-field select[name], .cwf-field textarea[name]"
    );
    if (control && control.classList.contains("cwf-input-error")) {
      clearFieldError(control);
    }
  }
  document.addEventListener("input", maybeClearOnEdit);
  document.addEventListener("change", maybeClearOnEdit);
})();