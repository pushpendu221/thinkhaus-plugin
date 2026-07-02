jQuery(document).ready(function ($) {
  let calDate = new Date();
  let clickCount = 0;
  let clickTimer = null;

  function loadLocations(citySelect, locSelect) {
    let cityId = $(citySelect).val();
    if (!cityId) {
      /* CHANGED: Reset location dropdown when city is cleared */
      $(locSelect).html('<option value="">— Select Location —</option>');
      return;
    }
    $.post(
      dpbs_admin_obj.ajax_url,
      { action: "dpbs_get_locations", city_id: cityId },
      function (response) {
        $(locSelect).html(response);
      },
    );
  }

  $("#admin_city").change(function () {
    loadLocations("#admin_city", "#admin_location");
  });
  $("#loc_price_city").change(function () {
    loadLocations("#loc_price_city", "#loc_price_location");
  });

  /* CHANGED: Now checks both service AND location before loading calendar */
  $("#admin_service, #admin_location").change(function () {
    if ($("#admin_service").val() && $("#admin_location").val()) loadCalendar();
  });

  function loadCalendar() {
    $("#cwf-admin-calendar").html(
      '<div class="cwf-cal-loading">Loading calendar...</div>',
    );
    let month = calDate.getMonth() + 1;
    let year = calDate.getFullYear();

    $.post(
      dpbs_admin_obj.ajax_url,
      {
        action: "dpbs_get_calendar",
        service_id: $("#admin_service").val(),
        location_id: $("#admin_location").val(),
        month: month,
        year: year,
      },
      function (response) {
        let res = JSON.parse(response);
        if (res.success) renderAdminCalendar(res.calendar);
      },
    );
  }

  function renderAdminCalendar(calendarData) {
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
    let month = calDate.getMonth();
    let year = calDate.getFullYear();

    let firstDay = new Date(year, month, 1).getDay();
    let daysInMonth = new Date(year, month + 1, 0).getDate();

    let html = '<div class="cwf-admin-cal-header">';
    html +=
      '<button type="button" class="cwf-admin-cal-nav" id="cal-prev">&laquo;</button>';
    html +=
      '<span class="cwf-admin-cal-month-label">' +
      monthNames[month] +
      " " +
      year +
      "</span>";
    html +=
      '<button type="button" class="cwf-admin-cal-nav" id="cal-next">&raquo;</button></div>';

    html += '<div class="cwf-admin-cal-grid">';
    ["Su", "Mo", "Tu", "We", "Th", "Fr", "Sa"].forEach(
      (d) => (html += '<div class="cwf-admin-cal-dow">' + d + "</div>"),
    );

    for (let i = 0; i < firstDay; i++)
      html += '<div class="cwf-admin-cal-day is-empty"></div>';

    for (let d = 1; d <= daysInMonth; d++) {
      let dateStr =
        year +
        "-" +
        String(month + 1).padStart(2, "0") +
        "-" +
        String(d).padStart(2, "0");
      let dayData = calendarData[dateStr] || { status: "available" };
      let classes = "cwf-admin-cal-day";
      let dotHtml = "";

      if (dayData.status === "past") classes += " is-past";
      if (dayData.status === "limited") {
        classes += " status-limited";
        dotHtml = '<span class="dot dot-limited"></span>';
      }
      if (dayData.status === "full") {
        classes += " status-full";
        dotHtml = '<span class="dot dot-full"></span>';
      }

      html +=
        '<div class="' +
        classes +
        '" data-date="' +
        dateStr +
        '" data-status="' +
        dayData.status +
        '">' +
        d +
        dotHtml +
        "</div>";
    }
    html += "</div>";
    $("#cwf-admin-calendar").html(html);

    $("#cal-prev").click(function () {
      calDate.setMonth(calDate.getMonth() - 1);
      loadCalendar();
    });
    $("#cal-next").click(function () {
      calDate.setMonth(calDate.getMonth() + 1);
      loadCalendar();
    });

    $(".cwf-admin-cal-day:not(.is-empty):not(.is-past)").on(
      "click",
      function () {
        let el = $(this);
        let date = el.data("date");

        clickCount++;

        if (clickCount === 1) {
          clickTimer = setTimeout(function () {
            updateAdminStatus(el, date, "limited");
            clickCount = 0;
          }, 300);
        } else if (clickCount === 2) {
          clearTimeout(clickTimer);
          clickTimer = setTimeout(function () {
            updateAdminStatus(el, date, "full");
            clickCount = 0;
          }, 300);
        } else if (clickCount >= 3) {
          clearTimeout(clickTimer);
          updateAdminStatus(el, date, "available");
          clickCount = 0;
        }
      },
    );
  }

  function updateAdminStatus(el, date, newStatus) {
    let indicator = $("#cwf-save-indicator");
    indicator
      .text("Saving...")
      .removeClass("is-success is-error")
      .addClass("is-saving");

    $.post(
      dpbs_admin_obj.ajax_url,
      {
        action: "dpbs_toggle_date_status",
        nonce: dpbs_admin_obj.nonce,
        date: date,
        service_id: $("#admin_service").val(),
        location_id: $("#admin_location").val(),
        target_status: newStatus,
      },
      function (response) {
        let res = JSON.parse(response);
        if (res.success) {
          el.removeClass("status-limited status-full").attr(
            "data-status",
            newStatus,
          );
          el.find(".dot").remove();
          if (newStatus === "limited") {
            el.addClass("status-limited");
            el.append('<span class="dot dot-limited"></span>');
          }
          if (newStatus === "full") {
            el.addClass("status-full");
            el.append('<span class="dot dot-full"></span>');
          }
          indicator.text("Saved").addClass("is-success");
          setTimeout(function () {
            indicator.text("");
          }, 1500);
        } else {
          indicator.text("Error").addClass("is-error");
        }
      },
    );
  }

  $("#save_svc_price").click(function () {
    let sid = $("#svc_price_service").val();
    let price = $("#svc_price_amount").val();
    if (!sid || !price) {
      alert("Please select service and enter price.");
      return;
    }

    $.post(
      dpbs_admin_obj.ajax_url,
      {
        action: "dpbs_save_price_rule",
        nonce: dpbs_admin_obj.nonce,
        type: "svc",
        service_id: sid,
        price: price,
      },
      function (html) {
        $("#svc_price_list").html(html);
        $("#svc_price_amount").val("");
      },
    );
  });

  $("#save_loc_price").click(function () {
    let sid = $("#loc_price_service").val();
    let lid = $("#loc_price_location").val();
    let price = $("#loc_price_amount").val();
    if (!sid || !lid || !price) {
      alert("Please select service, location and enter price.");
      return;
    }

    $.post(
      dpbs_admin_obj.ajax_url,
      {
        action: "dpbs_save_price_rule",
        nonce: dpbs_admin_obj.nonce,
        type: "loc",
        service_id: sid,
        location_id: lid,
        price: price,
      },
      function (html) {
        $("#loc_price_list").html(html);
        $("#loc_price_amount").val("");
      },
    );
  });

  $(document).on("click", ".cwf-price-delete", function (e) {
    e.preventDefault();
    let btn = $(this);
    let type = btn.data("type");
    let id = btn.data("id");

    $.post(
      dpbs_admin_obj.ajax_url,
      {
        action: "dpbs_delete_price_rule",
        nonce: dpbs_admin_obj.nonce,
        type: type,
        id: id,
      },
      function (html) {
        if (type === "svc") $("#svc_price_list").html(html);
        else $("#loc_price_list").html(html);
      },
    );
  });
});
