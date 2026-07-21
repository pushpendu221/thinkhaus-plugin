/**
 * ThinkHaus Frontend — DPBS-Style Calendar
 * Rewritten: Body-appended popover, mobile modal, scroll tracking,
 *            touch dedup, viewport-aware positioning, weekend highlighting.
 */
jQuery(document).ready(function ($) {
  /* ------------------------------------------------------------------ *
   *  STATE
   * ------------------------------------------------------------------ */
  var preLocation = hbs_obj.pre_location,
    preService = hbs_obj.pre_service,
    preCity = hbs_obj.pre_city,
    isLocked = hbs_obj.is_locked || false;

  var calData = {},
    calDate = new Date(),
    selectedDate = null,
    isLoading = false,
    calendarOpen = false,
    _scrollParents = null,
    _touchHandled = false,
    _useMobileModal = false,
    _mobileModal = null,
    _scrollParentModal = null,
    _savedScrollTopModal = 0;

  // Last price/tax info fetched from the server (see updatePrice()).
  var lastPriceInfo = {
    unitPrice: 0,
    taxEnabled: false,
    taxPercentage: 0,
    taxLabel: "GST",
  };

  // Cache the single form context
  var $form = $("#hbs-booking-form");

  // Grab the popover BEFORE we move it and keep a reference
  var $calPopover = $("#hbs-calendar-popover");

  // ★ MOVE POPOVER TO <body> — prevents clipping inside overflow:hidden containers
  if ($calPopover.length) {
    $calPopover.appendTo(document.body);
  }

  /* ------------------------------------------------------------------ *
   *  HOURS DROPDOWN
   * ------------------------------------------------------------------ */
  var hH = "";
  for (var i = 1; i <= hbs_obj.max_hours; i++) {
    hH +=
      '<option value="' +
      i +
      '">' +
      i +
      " Hour" +
      (i > 1 ? "s" : "") +
      "</option>";
  }
  $("#hbs-hours", $form).html(hH);

  /* ------------------------------------------------------------------ *
   *  ROOMS DROPDOWN (starts empty)
   * ------------------------------------------------------------------ */
  $("#hbs-rooms", $form).html('<option value="">Select Room</option>');
  $("#hbs-rooms-field-wrap").hide();

  /* ------------------------------------------------------------------ *
   *  ROOMS DROPDOWN GUARD — can't be opened/changed until a date is
   *  picked; attempting to do so shows an inline error instead.
   * ------------------------------------------------------------------ */
  function blockRoomsIfNoDate(e) {
    if (!selectedDate) {
      e.preventDefault();
      $(this).blur();
      $("#hbs-form-message")
        .html("Please select a date first.")
        .removeClass("is-success")
        .addClass("is-error");
      return false;
    }
  }

  $("#hbs-rooms", $form).on("mousedown keydown", function (e) {
    // Allow tabbing through the field (Tab/Shift) without triggering the
    // error; only block interactions that would open/change the dropdown.
    if (
      e.type === "keydown" &&
      e.key !== "Enter" &&
      e.key !== " " &&
      e.key !== "ArrowUp" &&
      e.key !== "ArrowDown"
    ) {
      return;
    }
    blockRoomsIfNoDate.call(this, e);
  });

  // Fallback: if a change slips through anyway (e.g. some assistive-tech
  // input path), snap it back to the placeholder and show the same error.
  $("#hbs-rooms", $form).on("change", function () {
    if (!selectedDate && $(this).val() !== "") {
      $(this).val("");
      $("#hbs-form-message")
        .html("Please select a date first.")
        .removeClass("is-success")
        .addClass("is-error");
    }
  });

  /* ------------------------------------------------------------------ *
   *  HELPERS
   * ------------------------------------------------------------------ */
  function safeParse(r) {
    try {
      return JSON.parse(r);
    } catch (e) {
      return null;
    }
  }

  function clearFormMessage() {
    $("#hbs-form-message").removeClass("is-error is-success").html("");
  }

  /** Is the form inside a sticky/fixed header? */
  function isInHeader() {
    var selectors = [
      "header",
      ".elementor-location-header",
      ".site-header",
      "[data-elementor-location='header']",
      ".mobile-header",
      ".elementor-mobile",
    ];
    for (var i = 0; i < selectors.length; i++) {
      if ($form.closest(selectors[i]).length) return true;
    }
    return false;
  }

  /** Walk up the DOM and collect every scrollable ancestor + window */
  function getScrollParents(el) {
    var parents = [],
      node = el ? el.parentElement : null;
    while (
      node &&
      node !== document.body &&
      node !== document.documentElement
    ) {
      var s = window.getComputedStyle(node);
      if (/(auto|scroll)/.test(s.overflowY + " " + s.overflow))
        parents.push(node);
      node = node.parentElement;
    }
    parents.push(window);
    return parents;
  }

  /* ------------------------------------------------------------------ *
   *  POSITIONING (desktop popover)
   * ------------------------------------------------------------------ */
  function positionCalendar() {
    var $trigger = $(".hbs-date-field", $form);
    if (!$trigger.length || !$calPopover.length) return;

    var rect = $trigger[0].getBoundingClientRect(),
      popW = $calPopover.outerWidth() || 300,
      popH = $calPopover.outerHeight() || 320,
      vpW = window.innerWidth || document.documentElement.clientWidth,
      vpH = window.innerHeight || document.documentElement.clientHeight,
      gap = 10,
      left = rect.left,
      top = rect.bottom + gap;

    // Horizontal clamp
    if (left + popW > vpW - 12) left = vpW - popW - 12;
    if (left < 12) left = 12;

    // Flip above if no room below
    if (top + popH > vpH - 12) {
      top = rect.top - popH - gap;
      if (top < 12) top = rect.bottom + gap; // give up, put below
    }

    $calPopover.css({
      position: "fixed",
      left: left + "px",
      top: top + "px",
      zIndex: 99999999,
      transform: "none",
      WebkitTransform: "none",
    });
  }

  /* ------------------------------------------------------------------ *
   *  OPEN / CLOSE CALENDAR
   * ------------------------------------------------------------------ */
  function openCalendar() {
    var vpW = window.innerWidth || document.documentElement.clientWidth;
    calendarOpen = true;

    // Use mobile modal on small screens OR if embedded inside a header
    if (vpW <= 768 || isInHeader()) {
      _useMobileModal = true;
      _openMobileModal();
    } else {
      _useMobileModal = false;
      _openPopover();
    }
  }

  function closeCalendar() {
    calendarOpen = false;

    // Tear down mobile modal
    if (_mobileModal) {
      _mobileModal.remove();
      _mobileModal = null;
    }

    // Restore scroll parent overflow
    if (_scrollParentModal) {
      _scrollParentModal.style.overflow =
        _scrollParentModal._hbs_old_overflow || "";
      _scrollParentModal.scrollTop = _savedScrollTopModal || 0;
      _scrollParentModal = null;
    }

    // Hide desktop popover
    $calPopover.removeClass("is-open");
    if ($calPopover[0]) $calPopover[0].style.removeProperty("display");

    // Unbind global listeners
    $(document).off("click.hbs-close-cal touchend.hbs-close-cal");
    if (_scrollParents) {
      $(_scrollParents).off("scroll.hbs-cal");
      _scrollParents = null;
    }
  }

  /** Desktop: show popover, attach scroll / click-outside listeners */
  function _openPopover() {
    $calPopover.css({
      zIndex: 99999999,
      transform: "none",
      WebkitTransform: "none",
    });
    $calPopover.addClass("is-open");
    $calPopover[0].style.setProperty("display", "block", "important");
    positionCalendar();

    if ($("#hbs-cal-days").children().length === 0) loadCalendar();

    // Click-outside to close
    $(document).off("click.hbs-close-cal touchend.hbs-close-cal");
    $(document).on("click.hbs-close-cal touchend.hbs-close-cal", function (e) {
      if (e.type === "click" && _touchHandled) return;
      var $t = $(e.target);
      if (
        !$t.closest(".hbs-date-field", $form).length &&
        !$t.closest($calPopover).length
      ) {
        closeCalendar();
      }
    });

    // Reposition on any ancestor scroll
    _scrollParents = getScrollParents($(".hbs-date-field", $form)[0]);
    $(_scrollParents).off("scroll.hbs-cal");
    $(_scrollParents).on("scroll.hbs-cal", function () {
      if (calendarOpen) positionCalendar();
    });
  }

  /** Mobile / header: full-screen modal with its own calendar grid */
  function _openMobileModal() {
    // Find the nearest scrollable ancestor so we can lock it
    var scrollParent = null,
      $p = $form.parent();
    while ($p.length && $p[0] !== document.body) {
      if ($p[0].scrollHeight > $p[0].clientHeight) {
        scrollParent = $p[0];
        break;
      }
      $p = $p.parent();
    }
    if (scrollParent) {
      _savedScrollTopModal = scrollParent.scrollTop;
      scrollParent._hbs_old_overflow = scrollParent.style.overflow;
      scrollParent.style.overflow = "hidden";
      scrollParent.scrollTop = 0;
      _scrollParentModal = scrollParent;
    }

    var uid = "hbs-mm-" + Date.now();
    var html =
      '<div id="' +
      uid +
      '" style="position:absolute;top:0;left:0;right:0;bottom:0;height:100vh;height:100dvh;z-index:2147483647;background:rgba(0,0,0,.6);display:flex;flex-direction:column;align-items:center;justify-content:center;padding:60px 16px 16px;box-sizing:border-box;">' +
      '<div style="background:#fff;border-radius:16px;width:100%;max-width:340px;overflow:hidden;font-family:-apple-system,BlinkMacSystemFont,Segoe UI,Roboto,sans-serif;">' +
      '<div style="display:flex;align-items:center;justify-content:space-between;padding:16px;border-bottom:1px solid #f0ede6;">' +
      '<button type="button" class="hbs-mm-prev" style="background:#f5f3ed;border:none;cursor:pointer;width:44px;height:44px;border-radius:8px;display:flex;align-items:center;justify-content:center;font-size:16px;color:#444;">&laquo;</button>' +
      '<span class="hbs-mm-title" style="font-weight:700;font-size:15px;color:#1f1f1f;"></span>' +
      '<button type="button" class="hbs-mm-next" style="background:#f5f3ed;border:none;cursor:pointer;width:44px;height:44px;border-radius:8px;display:flex;align-items:center;justify-content:center;font-size:16px;color:#444;">&raquo;</button>' +
      "</div>" +
      '<div style="padding:12px 16px 8px;">' +
      '<div style="display:grid;grid-template-columns:repeat(7,1fr);gap:2px;text-align:center;margin-bottom:8px;">' +
      '<div style="font-size:10px;font-weight:800;color:#e8521e;padding:4px 0;">Su</div>' +
      '<div style="font-size:10px;font-weight:800;color:#aaa;padding:4px 0;">Mo</div>' +
      '<div style="font-size:10px;font-weight:800;color:#aaa;padding:4px 0;">Tu</div>' +
      '<div style="font-size:10px;font-weight:800;color:#aaa;padding:4px 0;">We</div>' +
      '<div style="font-size:10px;font-weight:800;color:#aaa;padding:4px 0;">Th</div>' +
      '<div style="font-size:10px;font-weight:800;color:#aaa;padding:4px 0;">Fr</div>' +
      '<div style="font-size:10px;font-weight:800;color:#e8521e;padding:4px 0;">Sa</div>' +
      "</div>" +
      '<div class="hbs-mm-days" style="display:grid;grid-template-columns:repeat(7,1fr);gap:2px;text-align:center;"></div>' +
      "</div>" +
      '<div style="padding:8px 16px 16px;text-align:center;">' +
      '<button type="button" class="hbs-mm-close" style="background:#e8521e;color:#fff;border:none;padding:12px 32px;border-radius:8px;font-size:15px;font-weight:600;cursor:pointer;">Close</button>' +
      "</div>" +
      "</div>" +
      "</div>";

    _mobileModal = $(html).appendTo(scrollParent || $form);

    // --- Modal event delegation ---
    _mobileModal.on("click", ".hbs-mm-prev", function (e) {
      e.preventDefault();
      e.stopPropagation();
      calDate.setMonth(calDate.getMonth() - 1);
      _loadCalendarIntoModal();
    });
    _mobileModal.on("click", ".hbs-mm-next", function (e) {
      e.preventDefault();
      e.stopPropagation();
      calDate.setMonth(calDate.getMonth() + 1);
      _loadCalendarIntoModal();
    });
    _mobileModal.on("click", ".hbs-mm-day:not(.hbs-mm-disabled)", function (e) {
      e.preventDefault();
      e.stopPropagation();
      _selectDateFromModal($(this));
    });
    _mobileModal.on("click", ".hbs-mm-close", function (e) {
      e.preventDefault();
      e.stopPropagation();
      closeCalendar();
    });
    _mobileModal.on("click", function (e) {
      if ($(e.target).is("#" + uid)) closeCalendar();
    });

    _loadCalendarIntoModal();
  }

  /** Fetch data & render days inside the mobile modal */
  function _loadCalendarIntoModal() {
    var sid = $("#hbs-service", $form).val(),
      lid = $("#hbs-location", $form).val();
    if (!sid || !lid || !_mobileModal) return;

    var $days = _mobileModal.find(".hbs-mm-days"),
      $title = _mobileModal.find(".hbs-mm-title"),
      mN = [
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

    $title.text(mN[calDate.getMonth()] + " " + calDate.getFullYear());
    $days.html(
      '<div style="grid-column:1/-1;padding:20px;color:#999;font-size:13px;">Loading...</div>',
    );

    $.post(
      hbs_obj.ajax_url,
      {
        action: "hbs_get_calendar",
        service_id: sid,
        location_id: lid,
        month: calDate.getMonth() + 1,
        year: calDate.getFullYear(),
      },
      function (r) {
        var res = safeParse(r);
        if (res && res.success) {
          calData = res.calendar;
          _renderMobileDays(calData, $days);
          renderCalendar(); // keep desktop grid in sync
        }
      },
    ).fail(function () {
      $days.html(
        '<div style="grid-column:1/-1;padding:20px;color:#c0392b;font-size:13px;">Failed to load.</div>',
      );
    });
  }

  /** Paint day cells inside the mobile modal */
  function _renderMobileDays(data, $c) {
    var m = calDate.getMonth(),
      y = calDate.getFullYear(),
      fD = new Date(y, m, 1).getDay(),
      dM = new Date(y, m + 1, 0).getDate(),
      h = "";

    for (var i = 0; i < fD; i++) h += '<div style="min-height:44px;"></div>';

    for (var d = 1; d <= dM; d++) {
      var ds =
          y +
          "-" +
          String(m + 1).padStart(2, "0") +
          "-" +
          String(d).padStart(2, "0"),
        dd = data[ds] || { status: "available", rooms: 0 },
        dow = new Date(y, m, d).getDay(),
        dis = dd.status === "past" || dd.status === "full",
        sel = ds === selectedDate,
        base =
          "min-height:44px;display:flex;align-items:center;justify-content:center;font-size:13px;border-radius:8px;cursor:pointer;position:relative;",
        st = "",
        cls = "hbs-mm-day";

      if (dis) {
        st =
          base +
          "color:#d4d0c8;cursor:not-allowed;text-decoration:line-through;";
        cls += " hbs-mm-disabled";
      } else if (sel) {
        st =
          base +
          "background:#e8521e;color:#fff;font-weight:700;box-shadow:0 3px 10px rgba(232,82,30,.3);";
      } else if (dow === 0 || dow === 6) {
        st = base + "color:#e8521e;";
      } else {
        st = base + "color:#2b2b2b;";
      }

      var dot = "";
      if (dd.status === "limited" && !dis)
        dot =
          '<span style="position:absolute;bottom:2px;left:50%;transform:translateX(-50%);width:5px;height:5px;border-radius:50%;background:#3b82f6;"></span>';
      else if (dd.status === "full")
        dot =
          '<span style="position:absolute;bottom:2px;left:50%;transform:translateX(-50%);width:5px;height:5px;border-radius:50%;background:#ef4444;"></span>';

      h +=
        '<div class="' +
        cls +
        '" data-date="' +
        ds +
        '" data-rooms="' +
        (dd.rooms || 0) +
        '" style="' +
        st +
        '">' +
        d +
        dot +
        "</div>";
    }
    $c.html(h);
  }

  /** Shared logic: a date was tapped in the mobile modal */
  function _selectDateFromModal($day) {
    var date = $day.data("date"),
      rooms = parseInt($day.data("rooms")) || 0;

    selectedDate = date;
    $("#hbs-date", $form).val(selectedDate);

    // Populate rooms dropdown
    var rh = "";
    for (var i = 1; i <= rooms; i++)
      rh +=
        '<option value="' +
        i +
        '">' +
        i +
        " Room" +
        (i > 1 ? "s" : "") +
        "</option>";
    $("#hbs-rooms", $form).html(rh);
    updatePriceBreakdown();

    // Sync desktop selection state
    $("#hbs-cal-days").find(".hbs-cal-day").removeClass("is-selected");
    $("#hbs-cal-days")
      .find('.hbs-cal-day[data-date="' + selectedDate + '"]')
      .addClass("is-selected");

    closeCalendar();
    clearFormMessage();
  }

  /* ------------------------------------------------------------------ *
   *  SERVICE TYPE & PRICE
   * ------------------------------------------------------------------ */
  function checkServiceType(serviceId) {
    if (parseInt(serviceId) === parseInt(hbs_obj.private_service_id)) {
      $("#hbs-rooms-field-wrap").slideUp(200);
    } else {
      $("#hbs-rooms-field-wrap").slideDown(200);
    }
    updatePriceBreakdown();
  }

  function updatePrice() {
    var s = $("#hbs-service", $form).val(),
      l = $("#hbs-location", $form).val();
    if (s && l) {
      $.post(
        hbs_obj.ajax_url,
        { action: "hbs_get_price", service_id: s, location_id: l },
        function (r) {
          var res = safeParse(r);
          if (res && res.success) {
            $("#hbs-price-display").text(parseFloat(res.price).toFixed(2));
            lastPriceInfo.unitPrice = parseFloat(res.price) || 0;
            lastPriceInfo.taxEnabled = !!res.tax_enabled;
            lastPriceInfo.taxPercentage = parseFloat(res.tax_percentage) || 0;
            lastPriceInfo.taxLabel = res.tax_label || "GST";
            updatePriceBreakdown();
          }
        },
      );
    }
  }

  // Renders "Subtotal / Tax / Total" under the price tag based on the
  // currently selected hours + rooms, using the last fetched unit price.
  function updatePriceBreakdown() {
    var $breakdown = $("#hbs-price-breakdown");
    if (!$breakdown.length) return;

    var hours = parseInt($("#hbs-hours", $form).val(), 10) || 0,
      rooms = parseInt($("#hbs-rooms", $form).val(), 10) || 1;

    if (!lastPriceInfo.unitPrice || !hours) {
      $breakdown.hide().empty();
      return;
    }

    var subtotal = lastPriceInfo.unitPrice * hours * rooms,
      taxAmount = lastPriceInfo.taxEnabled
        ? (subtotal * lastPriceInfo.taxPercentage) / 100
        : 0,
      total = subtotal + taxAmount;

    var html = "";
    if (lastPriceInfo.taxEnabled && taxAmount > 0) {
      // html +=
      //   '<div class="hbs-price-row hbs-price-row-total" style="font-weight:600;">' +
      //   "+ " +
      //   lastPriceInfo.taxLabel +
      //   " (" +
      //   lastPriceInfo.taxPercentage +
      //   "%): ₹" +
      //   taxAmount.toFixed(2) +
      //   "  |  You pay: ₹" +
      //   total.toFixed(2) +
      //   "</div>";
      html +=
        '<div class="hbs-price-row hbs-price-row-total" style="font-weight:600; margin-left: 2px;">' +
        " + " +
        lastPriceInfo.taxLabel +
        " (" +
        lastPriceInfo.taxPercentage +
        "% )" +
        "</div>";
    }

    $breakdown.html(html).show();
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
      function (r) {
        $("#hbs-location", $form).html(r);
        if (preLocation) {
          $("#hbs-location", $form).val(preLocation);
          preLocation = "";
          updatePrice();
        }
      },
    );
  });

  /* ------------------------------------------------------------------ *
   *  INITIALIZATION
   * ------------------------------------------------------------------ */
  if (isLocked) {
    checkServiceType(preService);
    updatePrice();
  } else {
    if (preCity) $("#hbs-city", $form).val(preCity).trigger("change");
    if (preService) $("#hbs-service", $form).val(preService).trigger("change");
    else updatePrice();
  }

  $("#hbs-location", $form).on("change", updatePrice);

  $("#hbs-hours, #hbs-rooms", $form).on("change", updatePriceBreakdown);

  /* ------------------------------------------------------------------ *
   *  DATE-FIELD CLICK → TOGGLE CALENDAR
   * ------------------------------------------------------------------ */
  $(".hbs-date-field", $form).on(
    "click.hbs-date touchend.hbs-date",
    function (e) {
      // Ignore clicks that originated inside the popover itself
      if ($(e.target).closest($calPopover).length) return;
      // Touch dedup
      if (e.type === "click" && _touchHandled) return;
      if (e.type === "touchend") {
        e.preventDefault();
        _touchHandled = true;
        setTimeout(function () {
          _touchHandled = false;
        }, 400);
      }
      e.preventDefault();
      e.stopPropagation();

      if (!$("#hbs-location", $form).val()) {
        var msg = "Please select a location first.";
        clearFormMessage();
        $("#hbs-form-message").html(msg).addClass("is-error");
        if (isInHeader()) alert(msg);
        return;
      }

      if (calendarOpen) closeCalendar();
      else openCalendar();
    },
  );

  /* ------------------------------------------------------------------ *
   *  DESKTOP POPOVER: NAVIGATION + DAY SELECTION
   * ------------------------------------------------------------------ */
  $calPopover.on("click", ".hbs-cal-nav-btn", function (e) {
    e.preventDefault();
    e.stopPropagation();
    if (isLoading) return;
    calDate.setMonth(
      calDate.getMonth() + ($(this).data("dir") === "prev" ? -1 : 1),
    );
    loadCalendar();
  });

  $calPopover.on(
    "click.hbs-day touchend.hbs-day",
    ".hbs-cal-day:not(.is-empty):not(.is-disabled)",
    function (e) {
      if (e.type === "click" && _touchHandled) return;
      if (e.type === "touchend") {
        e.preventDefault();
        _touchHandled = true;
        setTimeout(function () {
          _touchHandled = false;
        }, 400);
      }
      e.stopPropagation();

      var date = $(this).data("date"),
        rooms = parseInt($(this).data("rooms")) || 0;

      selectedDate = date;
      $("#hbs-date", $form).val(selectedDate);

      // Rooms dropdown
      var rh = "";
      for (var i = 1; i <= rooms; i++)
        rh +=
          '<option value="' +
          i +
          '">' +
          i +
          " Room" +
          (i > 1 ? "s" : "") +
          "</option>";
      $("#hbs-rooms", $form).html(rh);
      updatePriceBreakdown();

      // Visual selection
      $("#hbs-cal-days").find(".hbs-cal-day").removeClass("is-selected");
      $(this).addClass("is-selected");

      closeCalendar();
      clearFormMessage();
    },
  );

  /* ------------------------------------------------------------------ *
   *  LOAD & RENDER CALENDAR (desktop grid)
   * ------------------------------------------------------------------ */
  function loadCalendar() {
    if (isLoading) return;
    isLoading = true;
    $("#hbs-cal-days").html(
      '<div style="text-align:center;padding:20px;color:#999;">Loading...</div>',
    );

    $.post(
      hbs_obj.ajax_url,
      {
        action: "hbs_get_calendar",
        service_id: $("#hbs-service", $form).val(),
        location_id: $("#hbs-location", $form).val(),
        month: calDate.getMonth() + 1,
        year: calDate.getFullYear(),
      },
      function (r) {
        isLoading = false;
        var res = safeParse(r);
        if (res && res.success) {
          calData = res.calendar;
          renderCalendar();
          // Recalculate position after content changes height
          if (calendarOpen && !_useMobileModal) {
            setTimeout(positionCalendar, 10);
          }
        }
      },
    ).fail(function () {
      isLoading = false;
      $("#hbs-cal-days").html(
        '<div style="text-align:center;padding:20px;color:#c0392b;">Failed to load calendar.</div>',
      );
    });
  }

  function renderCalendar() {
    var mN = [
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
      ],
      m = calDate.getMonth(),
      y = calDate.getFullYear();

    $(".hbs-cal-title").text(mN[m] + " " + y);

    var fD = new Date(y, m, 1).getDay(),
      dM = new Date(y, m + 1, 0).getDate(),
      h = "";

    // Leading empty cells
    for (var i = 0; i < fD; i++)
      h += '<div class="hbs-cal-day is-empty"></div>';

    for (var d = 1; d <= dM; d++) {
      var ds =
          y +
          "-" +
          String(m + 1).padStart(2, "0") +
          "-" +
          String(d).padStart(2, "0"),
        dd = calData[ds],
        dow = new Date(y, m, d).getDay(),
        c = "hbs-cal-day",
        dot = "";

      // Weekend
      if (dow === 0 || dow === 6) c += " is-weekend";

      if (!dd || dd.status === "past" || dd.status === "full") {
        c += " is-disabled";
        if (dd && dd.status === "full")
          dot = '<span class="hbs-dot hbs-dot-full"></span>';
      } else if (dd.status === "limited") {
        c += " is-limited";
        dot = '<span class="hbs-dot hbs-dot-limited"></span>';
      }

      if (selectedDate === ds) c += " is-selected";

      h +=
        '<div class="' +
        c +
        '" data-date="' +
        ds +
        '" data-rooms="' +
        (dd ? dd.rooms : 0) +
        '" data-status="' +
        (dd ? dd.status : "available") +
        '">' +
        d +
        dot +
        "</div>";
    }

    $("#hbs-cal-days").html(h);
  }

  /* ------------------------------------------------------------------ *
   *  FORM SUBMISSION
   * ------------------------------------------------------------------ */
  $form.on("submit", function (e) {
    e.preventDefault();
    clearFormMessage();

    if (!selectedDate) {
      $("#hbs-form-message").html("Please select a date.").addClass("is-error");
      return;
    }

    var email = $('input[name="email"]', $form).val().trim(),
      phone = $('input[name="phone"]', $form).val().trim();

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
        var res = safeParse(r);
        if (res && res.success) {
          var rzp = new Razorpay({
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
                  if (typeof vr === "string" && vr.trim() === "verified") {
                    $("#hbs-form-message")
                      .html("Booking Successful!")
                      .addClass("is-success");
                    setTimeout(function () {
                      location.reload();
                    }, 2000);
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
              name: $('input[name="full_name"]', $form).val(),
              email: $('input[name="email"]', $form).val(),
              contact: $('input[name="phone"]', $form).val(),
            },
            modal: {
              ondismiss: function () {
                clearFormMessage();
                $(".hbs-submit-btn").prop("disabled", false);
              },
            },
          });

          rzp.on("payment.failed", function () {
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
    ).fail(function () {
      $("#hbs-form-message").html("Network error.").addClass("is-error");
      $(".hbs-submit-btn").prop("disabled", false);
    });
  });
});
