jQuery(document).ready(function ($) {
  let preLocation = hbs_obj.pre_location,
    preService = hbs_obj.pre_service,
    preCity = hbs_obj.pre_city,
    isLocked = hbs_obj.is_locked || false;

  let calData = {},
    calDate = new Date(),
    selectedDate = null,
    isLoading = false;

  // Cache the form context so we never accidentally read from another form on the page
  const $form = $("#hbs-booking-form");

  // Hours Dropdown Init
  let hH = "";
  for (let i = 1; i <= hbs_obj.max_hours; i++)
    hH += `<option value="${i}">${i} Hour${i > 1 ? "s" : ""}</option>`;
  $("#hbs-hours", $form).html(hH);

  // Rooms Dropdown starts empty, waiting for date selection
  $("#hbs-rooms", $form).html('<option value="">Select Date First</option>');
  $("#hbs-rooms-field-wrap").hide();

  const safeParse = (r) => {
    try {
      return JSON.parse(r);
    } catch (e) {
      return null;
    }
  };

  function checkServiceType(serviceId) {
    if (parseInt(serviceId) === parseInt(hbs_obj.private_service_id)) {
      $("#hbs-rooms-field-wrap").slideUp(200);
    } else {
      $("#hbs-rooms-field-wrap").slideDown(200);
    }
  }

  function updatePrice() {
    let s = $("#hbs-service", $form).val(),
      l = $("#hbs-location", $form).val();
    if (s && l) {
      $.post(
        hbs_obj.ajax_url,
        { action: "hbs_get_price", service_id: s, location_id: l },
        (r) => {
          let res = safeParse(r);
          if (res && res.success)
            $("#hbs-price-display").text(parseFloat(res.price).toFixed(2));
        },
      );
    }
  }

  $("#hbs-service", $form).on("change", function () {
    updatePrice();
    checkServiceType($(this).val());
  });

  $("#hbs-city", $form).change(function () {
    if (!$(this).val()) return;
    $.post(
      hbs_obj.ajax_url,
      { action: "hbs_get_locations", city_id: $(this).val() },
      (r) => {
        $("#hbs-location", $form).html(r);
        if (preLocation) {
          $("#hbs-location", $form).val(preLocation);
          preLocation = "";
          updatePrice();
        }
      },
    );
  });

  // --- INITIALIZATION LOGIC ---
  if (isLocked) {
    checkServiceType(preService);
    updatePrice();
  } else {
    if (preCity) $("#hbs-city", $form).val(preCity).trigger("change");
    if (preService) $("#hbs-service", $form).val(preService).trigger("change");
    else updatePrice();
  }

  $("#hbs-location", $form).on("change", updatePrice);

  // Calendar UI
  $("#hbs-date, .hbs-date-icon", $form).on("click", function (e) {
    e.stopPropagation();
    if (!$("#hbs-location", $form).val())
      return alert("Please select a location first.");
    loadCalendar();
    $("#hbs-calendar-popover").addClass("is-open");
  });
  $(document).on("click", function (e) {
    if (!$(e.target).closest(".hbs-date-field").length)
      $("#hbs-calendar-popover").removeClass("is-open");
  });
  $(document).on("click", ".hbs-cal-nav-btn", function () {
    if (isLoading) return;
    if ($(this).data("dir") === "prev")
      calDate.setMonth(calDate.getMonth() - 1);
    else calDate.setMonth(calDate.getMonth() + 1);
    loadCalendar();
  });

  function loadCalendar() {
    if (isLoading) return;
    isLoading = true;
    $("#hbs-cal-days").html('<div class="hbs-cal-loading">Loading...</div>');
    $.post(
      hbs_obj.ajax_url,
      {
        action: "hbs_get_calendar",
        service_id: $("#hbs-service", $form).val(),
        location_id: $("#hbs-location", $form).val(),
        month: calDate.getMonth() + 1,
        year: calDate.getFullYear(),
      },
      (r) => {
        isLoading = false;
        let res = safeParse(r);
        if (res && res.success) {
          calData = res.calendar;
          renderCalendar();
        }
      },
    ).fail(() => {
      isLoading = false;
    });
  }

  function renderCalendar() {
    let mN = [
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
    let m = calDate.getMonth(),
      y = calDate.getFullYear();
    $(".hbs-cal-title").text(mN[m] + " " + y);
    let fD = new Date(y, m, 1).getDay(),
      dM = new Date(y, m + 1, 0).getDate(),
      h = "";
    for (let i = 0; i < fD; i++)
      h += '<button type="button" class="hbs-cal-day is-empty"></button>';
    for (let d = 1; d <= dM; d++) {
      let ds = `${y}-${String(m + 1).padStart(2, "0")}-${String(d).padStart(2, "0")}`,
        dd = calData[ds],
        c = "hbs-cal-day",
        dis = false,
        dot = "";
      if (!dd || dd.status === "past" || dd.status === "full") {
        c += " is-disabled";
        dis = true;
        if (dd && dd.status === "full")
          dot = '<span class="hbs-dot hbs-dot-full"></span>';
      } else if (dd.status === "limited") {
        c += " is-limited";
        dot = '<span class="hbs-dot hbs-dot-limited"></span>';
      }
      if (selectedDate === ds) c += " is-selected";
      h += `<button type="button" class="${c}" data-date="${ds}" data-rooms="${dd ? dd.rooms : 0}" ${dis ? "disabled" : ""}>${d}${dot}</button>`;
    }
    $("#hbs-cal-days").html(h);
  }

  // DATE CLICK
  $("#hbs-cal-days").on(
    "click",
    ".hbs-cal-day:not(.is-empty):not(.is-disabled)",
    function () {
      let date = $(this).data("date");
      let availableRooms = parseInt($(this).data("rooms"));

      selectedDate = date;
      $("#hbs-date", $form).val(selectedDate);

      let roomHtml = "";
      for (let i = 1; i <= availableRooms; i++) {
        roomHtml += `<option value="${i}">${i} Room${i > 1 ? "s" : ""}</option>`;
      }
      $("#hbs-rooms", $form).html(roomHtml);

      renderCalendar();
    },
  );

  // Submit Validation — ALL selectors scoped to $form
  $form.on("submit", function (e) {
    e.preventDefault();
    $("#hbs-form-message").removeClass("is-error").html("");

    if (!selectedDate) {
      $("#hbs-form-message").html("Please select a date.").addClass("is-error");
      return;
    }

    // ★ THE FIX: scope to $form so we read THIS form's fields, not the tour form's
    let email = $('input[name="email"]', $form).val().trim();
    let phone = $('input[name="phone"]', $form).val().trim();

    if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email)) {
      $("#hbs-form-message")
        .html("Please enter a valid email address.")
        .addClass("is-error");
      return;
    }
    if (!/^[6-9]\d{9}$/.test(phone)) {
      $("#hbs-form-message")
        .html("Please enter a valid 10-digit Indian phone number.")
        .addClass("is-error");
      return;
    }

    $(".hbs-submit-btn").prop("disabled", true);
    $("#hbs-form-message")
      .html('<span style="color:#555;">Processing...</span>')
      .removeClass("is-error is-success");

    $.post(
      hbs_obj.ajax_url,
      $(this).serialize() +
        "&seats=1&action=hbs_create_booking&nonce=" +
        hbs_obj.nonce,
      function (r) {
        let res = safeParse(r);
        if (res && res.success) {
          let rzp = new Razorpay({
            key: hbs_obj.razorpay_key,
            amount: res.amount,
            currency: "INR",
            name: "ThinkHaus",
            description: res.description,
            order_id: res.order_id,
            handler: function (pr) {
              $.post(
                hbs_obj.ajax_url,
                $.extend(
                  {
                    action: "hbs_verify_payment",
                    nonce: hbs_obj.nonce,
                    payment_id: pr.razorpay_payment_id,
                    order_id: pr.razorpay_order_id,
                    signature: pr.razorpay_signature,
                  },
                  res.booking_data,
                ),
                function (vr) {
                  if (vr === "verified") {
                    $("#hbs-form-message")
                      .html("Booking Successful!")
                      .addClass("is-success");
                    setTimeout(() => location.reload(), 2000);
                  } else {
                    $("#hbs-form-message")
                      .html("Verification Failed.")
                      .addClass("is-error");
                    $(".hbs-submit-btn").prop("disabled", false);
                  }
                },
              );
            },
            prefill: {
              name: $('input[name="full_name"]').val(),
              email: $('input[name="email"]').val(),
              contact: $('input[name="phone"]').val(),
            },
            modal: {
              ondismiss: function () {
                // FIX: clear the "Processing..." message when the user
                // closes the Razorpay popup instead of completing payment,
                // so the form doesn't stay stuck saying "Processing...".
                $("#hbs-form-message")
                  .removeClass("is-error is-success")
                  .html("");
                $(".hbs-submit-btn").prop("disabled", false);
              },
            },
          });
          rzp.on("payment.failed", function (resp) {
            // FIX: show a clear failure message instead of leaving the
            // box blank (which could still read as "stuck" to the user).
            $("#hbs-form-message")
              .html("Payment failed. Please try again.")
              .addClass("is-error");
            $(".hbs-submit-btn").prop("disabled", false);
          });
          rzp.open();
        } else {
          $("#hbs-form-message")
            .html(res ? res.message : "An error occurred.")
            .addClass("is-error");
          $(".hbs-submit-btn").prop("disabled", false);
        }
      },
    ).fail(() => {
      $("#hbs-form-message").html("Network error.").addClass("is-error");
      $(".hbs-submit-btn").prop("disabled", false);
    });
  });
});
