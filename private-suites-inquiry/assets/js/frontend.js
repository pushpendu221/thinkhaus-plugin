jQuery(document).ready(function ($) {
  /* =============================================
       STATE
       ============================================= */
  let startDate = "";
  let endDate = "";
  let currentPrice = 0;
  let currentMaxSeats = 10;
  let calState = { start: new Date(), end: new Date() };

  /* =============================================
       VALIDATION
       ============================================= */
  function isValidEmail(email) {
    return /^[a-zA-Z0-9._%+\-]+@[a-zA-Z0-9.\-]+\.[a-zA-Z]{2,}$/.test(email);
  }
  function isValidIndianPhone(phone) {
    return /^[6-9]\d{9}$/.test(phone.replace(/[\s\-\+\(\)]/g, ""));
  }
  function formatDate(d) {
    return (
      d.getFullYear() +
      "-" +
      String(d.getMonth() + 1).padStart(2, "0") +
      "-" +
      String(d.getDate()).padStart(2, "0")
    );
  }
  function calcMonths() {
    if (!startDate || !endDate) return 0;
    let s = new Date(startDate),
      e = new Date(endDate);
    let days = Math.ceil((e - s) / 86400000) + 1;
    return Math.max(1, Math.ceil(days / 30));
  }

  /* =============================================
       MESSAGES
       ============================================= */
  function showMsg(text, type) {
    let el = $("#psi-form-message");
    el.text(text).removeClass("is-error is-success");
    if (type === "error") el.addClass("is-error");
    if (type === "success") el.addClass("is-success");
  }
  function clearMsg() {
    $("#psi-form-message").text("").removeClass("is-error is-success");
  }

  /* =============================================
       PRICE & SEAT CALCULATION
       ============================================= */
  function updatePriceDisplay() {
    let seats = parseInt($("#psi-seats").val()) || 1;
    let months = calcMonths();

    if (currentPrice > 0) {
      $("#psi-rate-display").text(currentPrice.toLocaleString("en-IN"));
    } else {
      $("#psi-rate-display").text("—");
    }

    if (currentPrice > 0 && months > 0 && seats > 0) {
      let total = currentPrice * seats * months;
      $("#psi-total-display").text("₹" + total.toLocaleString("en-IN"));
      $("#psi-total-section").show();
      /* CHANGED: Removed calculation text, keeping it hidden */
      $("#psi-breakdown").hide();
    } else {
      $("#psi-total-section").hide();
      $("#psi-breakdown").hide();
    }
  }

  function buildSeatsOptions(maxSeats) {
    let limit = maxSeats > 0 ? maxSeats : 1;
    let current = parseInt($("#psi-seats").val()) || 1;
    let html = "";
    for (let i = 1; i <= limit; i++) {
      html +=
        '<option value="' +
        i +
        '">' +
        i +
        (i > 1 ? " Seats" : " Seat") +
        "</option>";
    }
    $("#psi-seats").html(html);
    $("#psi-seats").val(current <= limit ? current : limit);
  }

  function updateSeatsInfo() {
    if (currentMaxSeats > 0) {
      $("#psi-seats-info").text(
        currentMaxSeats +
          " seat" +
          (currentMaxSeats !== 1 ? "s" : "") +
          " available",
      );
      buildSeatsOptions(currentMaxSeats);
    } else {
      $("#psi-seats-info").text("");
      buildSeatsOptions(1);
    }
    updatePriceDisplay();
  }

  /* =============================================
       LOCATION LOADER
       ============================================= */
  function loadLocations(citySelect, locSelect, callback) {
    let cityId = $(citySelect).val();
    if (!cityId) {
      $(locSelect).html('<option value="">Select Location</option>');
      currentPrice = 0;
      currentMaxSeats = 0;
      updatePriceDisplay();
      updateSeatsInfo();
      if (typeof callback === "function") callback();
      return;
    }
    $.post(
      psi_obj.ajax_url,
      { action: "psi_get_locations", city_id: cityId },
      function (response) {
        $(locSelect).html(response);
        if (typeof callback === "function") callback();
      },
    );
  }

  function fetchLocationDetails() {
    let cid = $("#psi-city").val();
    let lid = $("#psi-location").val();
    if (!cid || !lid) {
      currentPrice = 0;
      currentMaxSeats = 0;
      updatePriceDisplay();
      updateSeatsInfo();
      return;
    }
    $.post(
      psi_obj.ajax_url,
      {
        action: "psi_get_location_details",
        city_id: cid,
        location_id: lid,
      },
      function (response) {
        let res = JSON.parse(response);
        if (res.success) {
          currentPrice = res.price;
          currentMaxSeats = res.max_seats;
          updatePriceDisplay();
          updateSeatsInfo();
        }
      },
    );
  }

  $("#psi-city").change(function () {
    loadLocations("#psi-city", "#psi-location", function () {
      if (psi_obj.pre_location) $("#psi-location").val(psi_obj.pre_location);
      fetchLocationDetails();
    });
  });
  $("#psi-location").change(function () {
    fetchLocationDetails();
  });
  $("#psi-seats").on("input change", function () {
    updatePriceDisplay();
  });

  /* =============================================
       CALENDAR — RENDER
       ============================================= */
  function renderCalendar(which) {
    let date = calState[which];
    let monthNames = [
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
    let month = date.getMonth(),
      year = date.getFullYear();
    let today = new Date();
    today.setHours(0, 0, 0, 0);
    let minDate = new Date(today);
    let maxDate = null; // Only used for 'end' calendar to enforce 30-day cap

    if (which === "end" && startDate) {
      let p = startDate.split("-");
      minDate = new Date(parseInt(p[0]), parseInt(p[1]) - 1, parseInt(p[2]));

      /* CHANGED: Cap end date at exactly 30 days from start date */
      maxDate = new Date(minDate);
      maxDate.setDate(maxDate.getDate() + 29);
    }

    $("#psi-cal-" + which + " .psi-cal-title").text(
      monthNames[month] + " " + year,
    );

    let firstDay = new Date(year, month, 1).getDay();
    let daysInMonth = new Date(year, month + 1, 0).getDate();
    let html = "";
    for (let i = 0; i < firstDay; i++)
      html += '<div class="psi-cal-day is-empty"></div>';

    for (let d = 1; d <= daysInMonth; d++) {
      let thisDate = new Date(year, month, d);
      thisDate.setHours(0, 0, 0, 0);
      let dateStr = formatDate(thisDate);
      let classes = "psi-cal-day";
      if (thisDate.getDay() === 6) classes += " is-weekend";
      if (thisDate.getTime() === today.getTime()) classes += " is-today";

      /* Disable past dates */
      if (thisDate < minDate) classes += " is-disabled";

      /* CHANGED: Disable dates beyond the 30-day cap for end calendar */
      if (which === "end" && maxDate && thisDate > maxDate)
        classes += " is-disabled";

      if (which === "start" && dateStr === startDate) classes += " is-selected";
      if (which === "end" && dateStr === endDate) classes += " is-selected";
      html +=
        '<div class="' +
        classes +
        '" data-date="' +
        dateStr +
        '">' +
        d +
        "</div>";
    }
    $("#psi-cal-" + which + "-days").html(html);

    $(
      "#psi-cal-" +
        which +
        "-days .psi-cal-day:not(.is-empty):not(.is-disabled)",
    )
      .off("click")
      .on("click", function () {
        let picked = $(this).data("date");
        if (which === "start") {
          startDate = picked;
          $("#psi-start-date")
            .val(picked)
            .removeClass("psi-field-error")
            .addClass("psi-field-valid");
          $("#psi-cal-start").removeClass("is-open");

          /* CHANGED: If existing end date is beyond 30 days, clear it */
          if (endDate) {
            let start = new Date(startDate);
            let maxEnd = new Date(start);
            maxEnd.setDate(maxEnd.getDate() + 29);
            if (new Date(endDate) > maxEnd) {
              endDate = "";
              $("#psi-end-date").val("").removeClass("psi-field-valid");
            }
          }
          if (endDate) renderCalendar("end");
        } else {
          endDate = picked;
          $("#psi-end-date")
            .val(picked)
            .removeClass("psi-field-error")
            .addClass("psi-field-valid");
          $("#psi-cal-end").removeClass("is-open");
        }
        clearMsg();
        updatePriceDisplay();
      });
  }

  $(document).on("click", ".psi-cal-nav-btn", function (e) {
    e.preventDefault();
    let which = $(this).data("cal"),
      dir = $(this).data("dir");
    if (dir === "prev")
      calState[which].setMonth(calState[which].getMonth() - 1);
    else calState[which].setMonth(calState[which].getMonth() + 1);
    renderCalendar(which);
  });

  function openCal(which) {
    $(".psi-calendar-popover").removeClass("is-open");
    if (which === "end" && startDate) {
      let p = startDate.split("-");
      calState.end = new Date(parseInt(p[0]), parseInt(p[1]) - 1, 1);
    }
    renderCalendar(which);
    $("#psi-cal-" + which).addClass("is-open");
  }

  $("#psi-start-date").on("focus click", function (e) {
    e.preventDefault();
    openCal("start");
  });
  $("#psi-end-date").on("focus click", function (e) {
    e.preventDefault();
    if (!startDate) {
      showMsg("Please select a Start Date first.", "error");
      $("#psi-start-date").addClass("psi-field-error");
      return;
    }
    openCal("end");
  });
  $(document).on("click", function (e) {
    if (!$(e.target).closest(".psi-date-field").length)
      $(".psi-calendar-popover").removeClass("is-open");
  });

  /* =============================================
       REAL-TIME FIELD VALIDATION (visual)
       ============================================= */
  function showFieldError($errorEl, message) {
    $errorEl.text(message).show();
  }
  function clearFieldError($errorEl) {
    $errorEl.text("").hide();
  }

  function validateField(input) {
    let id = input.attr("id"),
      val = input.val().trim();
    input.removeClass("psi-field-error psi-field-valid");

    if (id === "psi-phone") {
      let $err = $("#psi-phone-error");
      if (!val) {
        clearFieldError($err);
      } else if (isValidIndianPhone(val)) {
        input.addClass("psi-field-valid");
        clearFieldError($err);
      } else {
        input.addClass("psi-field-error");
        showFieldError(
          $err,
          "Please enter a valid 10-digit Indian phone number.",
        );
      }
    }

    if (id === "psi-email") {
      let $err = $("#psi-email-error");
      if (!val) {
        clearFieldError($err);
      } else if (isValidEmail(val)) {
        input.addClass("psi-field-valid");
        clearFieldError($err);
      } else {
        input.addClass("psi-field-error");
        showFieldError($err, "Please enter a valid email address.");
      }
    }

    if (id === "psi-full-name" && val && val.length >= 2) {
      input.addClass("psi-field-valid");
    }
  }
  $("#psi-full-name, #psi-phone, #psi-email").on("blur", function () {
    validateField($(this));
  });
  $("#psi-full-name, #psi-phone, #psi-email").on("input", function () {
    $(this).removeClass("psi-field-error psi-field-valid");
    if ($(this).attr("id") === "psi-phone")
      clearFieldError($("#psi-phone-error"));
    if ($(this).attr("id") === "psi-email")
      clearFieldError($("#psi-email-error"));
  });

  /* =============================================
       FORM SUBMISSION
       ============================================= */
  $("#psi-inquiry-form").on("submit", function (e) {
    e.preventDefault();
    clearMsg();
    let hasError = false;

    let service = $("#psi-service").val();
    let city = $("#psi-city").val();
    let location = $("#psi-location").val();
    let name = $("#psi-full-name").val().trim();
    let company = $('input[name="company"]').val().trim();
    let phone = $("#psi-phone").val().trim();
    let email = $("#psi-email").val().trim();
    let sDate = startDate;
    let eDate = endDate;
    let seats = parseInt($("#psi-seats").val()) || 0;
    let manager = $("#psi-manager-seats").val();

    $(".psi-field-error").removeClass("psi-field-error");
    clearFieldError($("#psi-phone-error"));
    clearFieldError($("#psi-email-error"));

    if ($("#psi-city").is("select") && !city) {
      $("#psi-city").addClass("psi-field-error");
      hasError = true;
    }
    if ($("#psi-location").is("select") && !location) {
      $("#psi-location").addClass("psi-field-error");
      hasError = true;
    }

    if (!name) {
      $("#psi-full-name").addClass("psi-field-error");
      if (!hasError) $("#psi-full-name").focus();
      hasError = true;
    } else if (name.length < 2) {
      showMsg("Full Name must be at least 2 characters.", "error");
      $("#psi-full-name").addClass("psi-field-error").focus();
      return;
    }

    if (!phone) {
      $("#psi-phone").addClass("psi-field-error");
      showFieldError($("#psi-phone-error"), "Phone number is required.");
      if (!hasError) $("#psi-phone").focus();
      hasError = true;
    } else if (!isValidIndianPhone(phone)) {
      showMsg("Enter a valid 10-digit Indian phone number.", "error");
      $("#psi-phone").addClass("psi-field-error");
      showFieldError(
        $("#psi-phone-error"),
        "Please enter a valid 10-digit Indian phone number.",
      );
      $("#psi-phone").focus();
      return;
    }

    if (!email) {
      $("#psi-email").addClass("psi-field-error");
      showFieldError($("#psi-email-error"), "Email address is required.");
      if (!hasError) $("#psi-email").focus();
      hasError = true;
    } else if (!isValidEmail(email)) {
      showMsg("Please enter a valid Email Address.", "error");
      $("#psi-email").addClass("psi-field-error");
      showFieldError(
        $("#psi-email-error"),
        "Please enter a valid email address.",
      );
      $("#psi-email").focus();
      return;
    }

    if (!sDate) {
      $("#psi-start-date").addClass("psi-field-error");
      showMsg("Please select a Start Date.", "error");
      hasError = true;
    }
    if (!eDate) {
      $("#psi-end-date").addClass("psi-field-error");
      if (!hasError) showMsg("Please select an End Date.", "error");
      hasError = true;
    }

    if (seats < 1) {
      $("#psi-seats").addClass("psi-field-error");
      showMsg("Please enter at least 1 seat.", "error");
      return;
    }
    if (currentMaxSeats > 0 && seats > currentMaxSeats) {
      $("#psi-seats").addClass("psi-field-error");
      showMsg(
        "Only " +
          currentMaxSeats +
          " seat" +
          (currentMaxSeats > 1 ? "s" : "") +
          " available for this location.",
        "error",
      );
      return;
    }

    if (hasError) return;

    /* Duration check (enforces 30-day cap from backend too) */
    let durationDays = (new Date(eDate) - new Date(sDate)) / 86400000 + 1;
    if (durationDays < parseInt(psi_obj.min_days)) {
      showMsg(
        "Minimum stay is " +
          psi_obj.min_days +
          " day" +
          (psi_obj.min_days > 1 ? "s" : "") +
          ".",
        "error",
      );
      return;
    }
    if (durationDays > parseInt(psi_obj.max_days)) {
      showMsg("Maximum stay is " + psi_obj.max_days + " days.", "error");
      return;
    }

    /* Submit */
    let $btn = $("#psi-submit-btn"),
      $text = $btn.find(".psi-btn-text"),
      $load = $btn.find(".psi-btn-loader");
    $btn.prop("disabled", true);
    $text.hide();
    $load.show();
    showMsg("Sending your inquiry...");

    $.post(
      psi_obj.ajax_url,
      {
        action: "psi_submit_inquiry",
        nonce: psi_obj.nonce,
        service: service,
        city: city,
        location: location,
        full_name: name,
        company: company,
        phone: phone,
        email: email,
        start_date: sDate,
        end_date: eDate,
        seats: seats,
        manager_seats: manager,
      },
      function (response) {
        let res = JSON.parse(response);
        if (res.success) {
          showSuccessState(res.message);
        } else {
          showMsg(res.message, "error");
          $btn.prop("disabled", false);
          $text.show();
          $load.hide();
        }
      },
    ).fail(function () {
      showMsg("Something went wrong. Please try again.", "error");
      $btn.prop("disabled", false);
      $text.show();
      $load.hide();
    });
  });

  /* =============================================
       SUCCESS STATE
       ============================================= */
  function showSuccessState(msg) {
    let $modal = $(".psi-modal");
    $modal.addClass("psi-success-state");
    $modal
      .find(".psi-form-grid")
      .after(
        '<div class="psi-success-content">' +
          '<div class="psi-success-icon"><span class="dashicons dashicons-yes-alt"></span></div>' +
          '<p class="psi-success-text">' +
          msg +
          "</p>" +
          '<p class="psi-success-sub">We\'ll get back to you shortly.</p>' +
          "</div>",
      );
  }

  /* =============================================
       INITIAL LOAD
       ============================================= */
  if (psi_obj.is_locked) {
    /* Locked via URL: city/location are pre-set (and disabled), so just
       fetch price/seat info directly without triggering the cascade. */
    fetchLocationDetails();
  } else if (psi_obj.pre_city && $("#psi-city").is("select")) {
    $("#psi-city").val(psi_obj.pre_city);
    loadLocations("#psi-city", "#psi-location", function () {
      if (psi_obj.pre_location) $("#psi-location").val(psi_obj.pre_location);
      fetchLocationDetails();
    });
  }
});
