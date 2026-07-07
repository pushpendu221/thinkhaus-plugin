jQuery(document).ready(function ($) {
  let calDate = new Date(),
    clickCount = 0,
    clickTimer = null;
  const safeParse = (r) => {
    try {
      return JSON.parse(r);
    } catch (e) {
      return null;
    }
  };

  function loadLocations(c, l) {
    if (!$(c).val()) return;
    $.post(
      hbs_admin_obj.ajax_url,
      { action: "hbs_get_locations", city_id: $(c).val() },
      (r) => $(l).html(r),
    );
  }

  // All location dropdowns cascade
  $("#hbs_admin_city, #hbs_loc_price_city, #hbs_room_city").change(function () {
    let target = $(this).attr("id").replace("city", "location");
    loadLocations("#" + $(this).attr("id"), "#" + target);
  });

  $("#hbs_admin_service, #hbs_admin_location").change(() => {
    if ($("#hbs_admin_location").val()) loadCalendar();
  });

  function loadCalendar() {
    $("#hbs-admin-calendar").html(
      '<div class="hbs-cal-loading">Loading calendar...</div>',
    );
    $.post(
      hbs_admin_obj.ajax_url,
      {
        action: "hbs_get_calendar",
        service_id: $("#hbs_admin_service").val(),
        location_id: $("#hbs_admin_location").val(),
        month: calDate.getMonth() + 1,
        year: calDate.getFullYear(),
      },
      (r) => {
        let res = safeParse(r);
        if (res && res.success) renderAdminCalendar(res.calendar);
      },
    );
  }

  function renderAdminCalendar(d) {
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
      y = calDate.getFullYear(),
      fD = new Date(y, m, 1).getDay(),
      dM = new Date(y, m + 1, 0).getDate();
    let h = `<div class="hbs-admin-cal-header"><button type="button" class="hbs-admin-cal-nav" id="hbs-cal-prev">&laquo;</button><span class="hbs-admin-cal-month-label">${mN[m]} ${y}</span><button type="button" class="hbs-admin-cal-nav" id="hbs-cal-next">&raquo;</button></div><div class="hbs-admin-cal-grid">`;
    ["Su", "Mo", "Tu", "We", "Th", "Fr", "Sa"].forEach(
      (x) => (h += `<div class="hbs-admin-cal-dow">${x}</div>`),
    );
    for (let i = 0; i < fD; i++)
      h += '<div class="hbs-admin-cal-day is-empty"></div>';
    for (let day = 1; day <= dM; day++) {
      let ds = `${y}-${String(m + 1).padStart(2, "0")}-${String(day).padStart(2, "0")}`,
        dd = d[ds] || { status: "available" },
        c = "hbs-admin-cal-day",
        dot = "";
      if (dd.status === "past") c += " is-past";
      if (dd.status === "limited") {
        c += " status-limited";
        dot = '<span class="dot dot-limited"></span>';
      }
      if (dd.status === "full") {
        c += " status-full";
        dot = '<span class="dot dot-full"></span>';
      }
      h += `<div class="${c}" data-date="${ds}" data-status="${dd.status}">${day}${dot}</div>`;
    }
    h += "</div>";
    $("#hbs-admin-calendar").html(h);
    $("#hbs-cal-prev").click(() => {
      calDate.setMonth(calDate.getMonth() - 1);
      loadCalendar();
    });
    $("#hbs-cal-next").click(() => {
      calDate.setMonth(calDate.getMonth() + 1);
      loadCalendar();
    });

    $("#hbs-admin-calendar")
      .off("click.hbs")
      .on(
        "click.hbs",
        ".hbs-admin-cal-day:not(.is-empty):not(.is-past)",
        function () {
          let el = $(this),
            date = el.data("date");
          clickCount++;
          if (clickCount === 1) {
            clickTimer = setTimeout(() => {
              updateStatus(el, date, "limited");
              clickCount = 0;
            }, 300);
          } else if (clickCount === 2) {
            clearTimeout(clickTimer);
            clickTimer = setTimeout(() => {
              updateStatus(el, date, "full");
              clickCount = 0;
            }, 300);
          } else if (clickCount >= 3) {
            clearTimeout(clickTimer);
            updateStatus(el, date, "available");
            clickCount = 0;
          }
        },
      );
  }

  function updateStatus(el, date, status) {
    let ind = $("#hbs-save-indicator")
      .text("Saving...")
      .removeClass("is-success is-error")
      .addClass("is-saving");
    $.post(
      hbs_admin_obj.ajax_url,
      {
        action: "hbs_toggle_date_status",
        nonce: hbs_admin_obj.nonce,
        date,
        service_id: $("#hbs_admin_service").val(),
        location_id: $("#hbs_admin_location").val(),
        target_status: status,
      },
      (r) => {
        let res = safeParse(r);
        if (res && res.success) {
          el.removeClass("status-limited status-full")
            .attr("data-status", status)
            .find(".dot")
            .remove();
          if (status === "limited")
            el.addClass("status-limited").append(
              '<span class="dot dot-limited"></span>',
            );
          if (status === "full")
            el.addClass("status-full").append(
              '<span class="dot dot-full"></span>',
            );
          ind.text("Saved").addClass("is-success");
          setTimeout(() => ind.text(""), 1500);
        } else ind.text("Error").addClass("is-error");
      },
    );
  }

  // Generic Save Rule (Handles Service Price, Location Price, and Rooms)
  // Maps each rule "type" to its actual list container + amount field IDs in the DOM,
  // since they don't follow a consistent "hbs_{type}_..." naming pattern.
  const ruleListId = {
    svc: "hbs_svc_price_list",
    loc: "hbs_loc_price_list",
    room: "hbs_room_list",
  };
  const ruleAmountId = {
    svc: "hbs_svc_price_amount",
    loc: "hbs_loc_price_amount",
    room: "hbs_room_amount",
  };

  function saveRule(btnId, type, extraFields) {
    $(btnId).click(function () {
      let data = {
        action: "hbs_save_price_rule",
        nonce: hbs_admin_obj.nonce,
        type: type,
      };
      $(extraFields).each(function () {
        data[$(this).data("key")] = $(this).val();
      });
      if (
        !data.service_id ||
        (type !== "svc" && !data.location_id) ||
        !data.price
      )
        return alert("Please fill all fields.");
      $.post(hbs_admin_obj.ajax_url, data, (h) => {
        $("#" + ruleListId[type]).html(h);
        $("#" + ruleAmountId[type]).val("");
      });
    });
  }

  saveRule(
    "#hbs_save_svc_price",
    "svc",
    $("#hbs_svc_price_service, #hbs_svc_price_amount"),
  );
  saveRule(
    "#hbs_save_loc_price",
    "loc",
    $(
      "#hbs_loc_price_service, #hbs_loc_price_city, #hbs_loc_price_location, #hbs_loc_price_amount",
    ),
  );
  saveRule(
    "#hbs_save_room_rule",
    "room",
    $(
      "#hbs_room_service, #hbs_room_city, #hbs_room_location, #hbs_room_amount",
    ),
  );

  // Generic Delete Rule
  $(document).on("click", ".hbs-price-delete", function (e) {
    e.preventDefault();
    let t = $(this).data("type"),
      i = $(this).data("id");
    $.post(
      hbs_admin_obj.ajax_url,
      {
        action: "hbs_delete_price_rule",
        nonce: hbs_admin_obj.nonce,
        type: t,
        id: i,
      },
      (h) => {
        $("#" + ruleListId[t]).html(h);
      },
    );
  });
});
