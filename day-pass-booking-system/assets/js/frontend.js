/**
 * DPBS Frontend - Multi-Instance Support
 * FIXED: Calendar close issue + Mobile calendar visibility in Elementor headers
 */
(function ($) {
  "use strict";

  var instances = {};

  function DPBSInstance(container, uniqueKey) {
    this.container = container;
    this.id = uniqueKey || container.data("instance-id");
    this.calDate = new Date();
    this.selectedDate = "";
    this.bookingData = null;
    this.initialized = false;
    this.calendarOpen = false;
    this._scrollParents = null;
    this._touchHandled = false;
    this._useMobileModal = false;
    this._mobileModal = null;
    this._scrollParentModal = null;
    this._savedScrollTopModal = 0;

    this.els = {
      form: container.find(".dpbs-booking-form"),
      service: container.find(".dpbs-service"),
      city: container.find(".dpbs-city"),
      location: container.find(".dpbs-location"),
      company: container.find(".dpbs-company"),
      fullName: container.find(".dpbs-fullname"),
      phone: container.find(".dpbs-phone"),
      email: container.find(".dpbs-email"),
      seats: container.find(".dpbs-seats"),
      seatsInfo: container.find(".dpbs-seats-info"),
      date: container.find(".dpbs-date"),
      dateField: container.find(".dpbs-date-field"),
      calPopover: container.find(".dpbs-calendar-popover"),
      calTitle: container.find(".dpbs-cal-title"),
      calDays: container.find(".dpbs-cal-days"),
      calNav: container.find(".dpbs-cal-nav-btn"),
      priceDisplay: container.find('[id$="-price-display"]'),
      formMessage: container.find(".dpbs-form-message"),
      submitBtn: container.find(".dpbs-submit-btn"),
      // NEW: Private Suites (service 357) only fields. Empty jQuery sets on
      // any form that doesn't render them - harmless everywhere else.
      suiteFields: container.find(".dpbs-suite-field"),
      suiteStartDate: container.find(".dpbs-suite-start-date"),
      suiteEndDate: container.find(".dpbs-suite-end-date"),
      managerSeats: container.find(".dpbs-manager-seats"),
    };

    this.init();
  }

  DPBSInstance.prototype = {
    init: function () {
      if (this.initialized) return;
      this.initialized = true;

      if (this.els.calPopover.length) {
        this.els.calPopover.appendTo(document.body);
      }

      this.bindEvents();
      this.loadPreselected();
      this.toggleSuiteMode();
    },

    // =============================================
    // PRIVATE SUITES MODE (service ID 357)
    // =============================================
    // Shows Start/End date + Manager Seats and hides the regular single
    // "Date" field whenever the Private Suites service is selected; restores
    // the regular field for every other service. Does not touch any other
    // logic - the normal day-pass calendar/Razorpay flow is untouched.
    isSuiteMode: function () {
      var suiteId =
        typeof dpbs_obj !== "undefined" ? dpbs_obj.suite_service_id : null;
      if (!suiteId) return false;
      return String(this.els.service.val()) === String(suiteId);
    },

    toggleSuiteMode: function () {
      if (!this.els.suiteFields.length) return; // form has no suite markup

      if (this.isSuiteMode()) {
        this.closeCalendar();
        this.els.dateField.hide();
        this.els.date.prop("required", false);
        this.els.suiteFields.show();
        this.els.suiteStartDate.prop("required", true);
        this.els.suiteEndDate.prop("required", true);
      } else {
        this.els.suiteFields.hide();
        this.els.suiteStartDate.prop("required", false);
        this.els.suiteEndDate.prop("required", false);
        this.els.dateField.show();
        this.els.date.prop("required", true);
      }
    },

    bindEvents: function () {
      var self = this;

      this.els.city.on("change", function () {
        self.selectedDate = "";
        self.els.date.val("");
        self.loadLocations(function () {
          self.updatePrice();
          self.loadCalendar();
        });
      });

      this.els.service.on("change", function () {
        self.selectedDate = "";
        self.els.date.val("");
        self.toggleSuiteMode();
        self.loadCitiesForService(function () {
          self.loadLocations(function () {
            self.updatePrice();
            self.loadCalendar();
          });
        });
      });

      this.els.location.on("change", function () {
        self.selectedDate = "";
        self.els.date.val("");
        self.updatePrice();
        self.loadCalendar();
      });

      this.els.seats.on("change", function () {
        self.updateSeatsInfo();
      });

      this.els.dateField.on("click.dpbs-date touchend.dpbs-date", function (e) {
        if ($(e.target).closest(".dpbs-calendar-popover").length) return;
        if (e.type === "click" && self._touchHandled) return;
        if (e.type === "touchend") {
          e.preventDefault();
          self._touchHandled = true;
          setTimeout(function () {
            self._touchHandled = false;
          }, 400);
        }
        e.preventDefault();
        e.stopPropagation();
        self.toggleCalendar();
      });

      this.els.calPopover.on("click", ".dpbs-cal-nav-btn", function (e) {
        e.preventDefault();
        e.stopPropagation();
        var dir = $(this).data("dir");
        if (dir === "prev") {
          self.calDate.setMonth(self.calDate.getMonth() - 1);
        } else {
          self.calDate.setMonth(self.calDate.getMonth() + 1);
        }
        self.loadCalendar();
      });

      this.els.calPopover.on(
        "click.dpbs-day touchend.dpbs-day",
        ".dpbs-cal-day:not(.is-empty):not(.is-disabled)",
        function (e) {
          if (e.type === "click" && self._touchHandled) return;
          if (e.type === "touchend") {
            e.preventDefault();
            self._touchHandled = true;
            setTimeout(function () {
              self._touchHandled = false;
            }, 400);
          }
          e.stopPropagation();

          self.els.calDays.find(".dpbs-cal-day").removeClass("is-selected");
          $(this).addClass("is-selected");
          self.selectedDate = $(this).data("date");
          self.els.date.val(self.selectedDate);

          self.closeCalendar();
          self.updateSeatsInfo();
          self.clearFormMessage();
        },
      );

      this.els.form.on("submit", function (e) {
        e.preventDefault();
        self.handleFormSubmit();
      });
    },

    loadPreselected: function () {
      var self = this;

      var preService = this.container.data("pre-service") || "";
      var preCity = this.container.data("pre-city") || "";
      var preLocation = this.container.data("pre-location") || "";

      if (preService) {
        this.els.service.val(preService);
      }

      if (preCity) {
        this.els.city.val(preCity);
        this.loadLocations(function () {
          if (preLocation) {
            self.els.location.val(preLocation);
          }
          self.updatePrice();
          self.loadCalendar();
        });
      } else if (preService && this.els.location.val()) {
        self.updatePrice();
        self.loadCalendar();
      }
    },

    isValidEmail: function (email) {
      return /^[a-zA-Z0-9._%+\-]+@[a-zA-Z0-9.\-]+\.[a-zA-Z]{2,}$/.test(email);
    },

    isValidIndianPhone: function (phone) {
      return /^[6-9]\d{9}$/.test(phone.replace(/[\s\-\+\(\)]/g, ""));
    },

    loadCitiesForService: function (callback) {
      var self = this;
      var serviceId = this.els.service.val();
      var currentCity = this.els.city.val();

      if (!serviceId) {
        this.els.location.html('<option value="">Select Location</option>');
        if (typeof callback === "function") callback();
        return;
      }

      $.post(
        dpbs_obj.ajax_url,
        { action: "dpbs_get_cities_for_service", service_id: serviceId },
        function (response) {
          self.els.city.html(response);
          if (
            currentCity &&
            self.els.city.find('option[value="' + currentCity + '"]').length
          ) {
            self.els.city.val(currentCity);
          } else {
            self.els.city.val("");
            self.els.location.html('<option value="">Select Location</option>');
          }
          if (typeof callback === "function") callback();
        },
      );
    },

    loadLocations: function (callback) {
      var self = this;
      var cityId = this.els.city.val();
      var serviceId = this.els.service.val();

      if (!cityId) {
        this.els.location.html('<option value="">Select Location</option>');
        if (typeof callback === "function") callback();
        return;
      }

      $.post(
        dpbs_obj.ajax_url,
        {
          action: "dpbs_get_locations",
          city_id: cityId,
          service_id: serviceId,
        },
        function (response) {
          self.els.location.html(response);
          if (typeof callback === "function") callback();
        },
      );
    },

    updatePrice: function () {
      var self = this;
      var sid = this.els.service.val();
      var lid = this.els.location.val();

      if (!sid || !lid) {
        this.els.priceDisplay.text("0.00");
        return;
      }

      $.post(
        dpbs_obj.ajax_url,
        { action: "dpbs_get_price", service_id: sid, location_id: lid },
        function (response) {
          var res = JSON.parse(response);
          if (res.success) {
            self.els.priceDisplay.text(parseFloat(res.price).toFixed(2));
            self.updateSeatsInfo();
          }
        },
      );
    },

    updateSeatsInfo: function () {
      if (!this.selectedDate) {
        this.els.seatsInfo.text("");
        this.els.seats.find("option").prop("disabled", false);
        return;
      }

      var dayEl = this.els.calDays.find(
        '.dpbs-cal-day[data-date="' + this.selectedDate + '"]',
      );
      if (dayEl.length && dayEl.data("seats") !== undefined) {
        var avail = parseInt(dayEl.data("seats"));
        this.els.seatsInfo.text(
          avail + " seat" + (avail !== 1 ? "s" : "") + " available",
        );

        if (avail > 0) {
          var currentVal = parseInt(this.els.seats.val());
          this.els.seats.find("option").each(function () {
            $(this).prop("disabled", parseInt($(this).val()) > avail);
          });
          if (currentVal > avail) {
            this.els.seats.val(avail);
          }
        } else {
          this.els.seats.find("option").prop("disabled", true);
        }
      } else {
        this.els.seatsInfo.text("");
        this.els.seats.find("option").prop("disabled", false);
      }
    },

    toggleCalendar: function () {
      var sid = this.els.service.val();
      var lid = this.els.location.val();

      if (!sid || !lid) {
        var errorMsg = "Please select a Service and Location first.";
        this.showFormMessage(errorMsg, "error");
        if (this.isInHeader()) {
          alert(errorMsg);
        }
        return;
      }

      if (this.calendarOpen) {
        this.closeCalendar();
      } else {
        this.openCalendar();
      }
    },

    isInHeader: function () {
      var $el = this.container;
      var selectors = [
        "header",
        ".elementor-location-header",
        ".site-header",
        "[data-elementor-location='header']",
        ".mobile-header",
        ".elementor-mobile",
      ];
      for (var i = 0; i < selectors.length; i++) {
        if ($el.closest(selectors[i]).length) {
          return true;
        }
      }
      return false;
    },

    getScrollParents: function (el) {
      var parents = [];
      var node = el ? el.parentElement : null;
      while (
        node &&
        node !== document.body &&
        node !== document.documentElement
      ) {
        var style = window.getComputedStyle(node);
        if (/(auto|scroll)/.test(style.overflowY + " " + style.overflow)) {
          parents.push(node);
        }
        node = node.parentElement;
      }
      parents.push(window);
      return parents;
    },

    openCalendar: function () {
      var viewportWidth =
        window.innerWidth || document.documentElement.clientWidth;

      if (viewportWidth <= 768) {
        this.calendarOpen = true;
        this._useMobileModal = true;
        this._openMobileModal();
      } else {
        this.calendarOpen = true;
        this._useMobileModal = false;
        this._openPopover();
      }
    },

    _openPopover: function () {
      var self = this;

      this.els.calPopover.css({
        "z-index": "99999999",
        transform: "none",
        "-webkit-transform": "none",
      });

      this.els.calPopover.addClass("is-open");
      this.els.calPopover[0].style.setProperty("display", "block", "important");

      this.positionCalendar();

      if (this.els.calDays.children().length === 0) {
        this.loadCalendar();
      }

      $(document).off(
        "click.dpbs-close-" + this.id + " touchend.dpbs-close-" + this.id,
      );
      $(document).on(
        "click.dpbs-close-" + this.id + " touchend.dpbs-close-" + this.id,
        function (e) {
          var $target = $(e.target);
          if (e.type === "click" && self._touchHandled) return;

          var clickedOutside =
            !$target.closest(self.els.dateField).length &&
            !$target.closest(self.els.calPopover).length;

          if (clickedOutside) {
            self.closeCalendar();
          }
        },
      );

      this._scrollParents = this.getScrollParents(this.els.dateField[0]);
      $(this._scrollParents).off("scroll.dpbs-" + this.id);
      $(this._scrollParents).on("scroll.dpbs-" + this.id, function () {
        if (self.calendarOpen) {
          self.positionCalendar();
        }
      });
    },

    _openMobileModal: function () {
      var self = this;

      // 1. Find the scrollable parent (the Elementor off-canvas menu)
      var scrollParent = null;
      var $p = this.container.parent();
      while ($p.length && $p[0] !== document.body) {
        if ($p[0].scrollHeight > $p[0].clientHeight) {
          scrollParent = $p[0];
          break;
        }
        $p = $p.parent();
      }

      // 2. Lock scroll and snap to top so top:0 aligns perfectly with screen
      if (scrollParent) {
        this._savedScrollTopModal = scrollParent.scrollTop;
        scrollParent._dpbs_old_overflow = scrollParent.style.overflow;
        scrollParent.style.overflow = "hidden";
        scrollParent.scrollTop = 0;
        this._scrollParentModal = scrollParent;
      }

      // 3. Simple top:0 left:0 now works perfectly because we snapped scroll to 0
      var m =
        '<div id="dpbs-mm-' +
        this.id +
        '" style="position:absolute;top:0;left:0;right:0;bottom:0;height:100vh;height:100dvh;z-index:2147483647;background:rgba(0,0,0,0.6);display:flex;flex-direction:column;align-items:center;justify-content:center;padding: 60px 16px 16px;box-sizing:border-box;"><div style="background:#fff;border-radius:16px;width:100%;max-width:340px;box-shadow:0 25px 60px rgba(0,0,0,0.4);overflow:hidden;font-family:-apple-system,BlinkMacSystemFont,Segoe UI,Roboto,sans-serif;"><div style="display:flex;align-items:center;justify-content:space-between;padding:16px;border-bottom:1px solid #f0ede6;"><button type="button" class="dpbs-mm-prev" style="background:#f5f3ed;border:none;cursor:pointer;width:44px;height:44px;border-radius:8px;display:flex;align-items:center;justify-content:center;font-size:16px;color:#444;">&laquo;</button><span class="dpbs-mm-title" style="font-weight:700;font-size:15px;color:#1f1f1f;"></span><button type="button" class="dpbs-mm-next" style="background:#f5f3ed;border:none;cursor:pointer;width:44px;height:44px;border-radius:8px;display:flex;align-items:center;justify-content:center;font-size:16px;color:#444;">&raquo;</button></div><div style="padding:12px 16px 8px;"><div style="display:grid;grid-template-columns:repeat(7,1fr);gap:2px;text-align:center;margin-bottom:8px;"><div style="font-size:10px;font-weight:800;color:#e8521e;padding:4px 0;">Su</div><div style="font-size:10px;font-weight:800;color:#aaa;padding:4px 0;">Mo</div><div style="font-size:10px;font-weight:800;color:#aaa;padding:4px 0;">Tu</div><div style="font-size:10px;font-weight:800;color:#aaa;padding:4px 0;">We</div><div style="font-size:10px;font-weight:800;color:#aaa;padding:4px 0;">Th</div><div style="font-size:10px;font-weight:800;color:#aaa;padding:4px 0;">Fr</div><div style="font-size:10px;font-weight:800;color:#e8521e;padding:4px 0;">Sa</div></div><div class="dpbs-mm-days" style="display:grid;grid-template-columns:repeat(7,1fr);gap:2px;text-align:center;"></div></div><div style="padding:8px 16px 16px;text-align:center;"><button type="button" class="dpbs-mm-close" style="background:#e8521e;color:#fff;border:none;padding:12px 32px;border-radius:8px;font-size:15px;font-weight:600;cursor:pointer;">Close</button></div></div></div>';

      // Append to scroll parent so it's guaranteed not to be clipped
      var appendTarget = scrollParent || this.container;
      this._mobileModal = $(m).appendTo(appendTarget);

      var $modal = this._mobileModal;

      $modal.on("click", ".dpbs-mm-prev", function (e) {
        e.preventDefault();
        e.stopPropagation();
        self.calDate.setMonth(self.calDate.getMonth() - 1);
        self._loadCalendarIntoModal();
      });

      $modal.on("click", ".dpbs-mm-next", function (e) {
        e.preventDefault();
        e.stopPropagation();
        self.calDate.setMonth(self.calDate.getMonth() + 1);
        self._loadCalendarIntoModal();
      });

      $modal.on("click", ".dpbs-mm-day:not(.dpbs-mm-disabled)", function (e) {
        e.preventDefault();
        e.stopPropagation();
        self.selectedDate = $(this).data("date");
        self.els.date.val(self.selectedDate);
        self.els.calDays.find(".dpbs-cal-day").removeClass("is-selected");
        self.els.calDays
          .find('.dpbs-cal-day[data-date="' + self.selectedDate + '"]')
          .addClass("is-selected");
        self.closeCalendar();
        self.updateSeatsInfo();
        self.clearFormMessage();
      });

      $modal.on("click", ".dpbs-mm-close", function (e) {
        e.preventDefault();
        e.stopPropagation();
        self.closeCalendar();
      });

      $modal.on("click", function (e) {
        if ($(e.target).is("#dpbs-mm-" + self.id)) {
          self.closeCalendar();
        }
      });

      this._loadCalendarIntoModal();
    },

    _loadCalendarIntoModal: function () {
      var self = this;
      var sid = this.els.service.val();
      var lid = this.els.location.val();
      if (!sid || !lid || !this._mobileModal) return;

      var $days = this._mobileModal.find(".dpbs-mm-days");
      var $title = this._mobileModal.find(".dpbs-mm-title");
      var monthNames = [
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

      $title.text(
        monthNames[this.calDate.getMonth()] + " " + this.calDate.getFullYear(),
      );
      $days.html(
        '<div style="grid-column:1/-1;padding:20px;color:#999;font-size:13px;">Loading...</div>',
      );

      $.post(
        dpbs_obj.ajax_url,
        {
          action: "dpbs_get_calendar",
          service_id: sid,
          location_id: lid,
          month: this.calDate.getMonth() + 1,
          year: this.calDate.getFullYear(),
        },
        function (response) {
          var res = JSON.parse(response);
          if (res.success) {
            self._renderMobileModalDays(res.calendar, $days);
            // Also update standard popover so seats info works correctly later
            self.renderCalendar(res.calendar);
          }
        },
      ).fail(function () {
        $days.html(
          '<div style="grid-column:1/-1;padding:20px;color:#c0392b;font-size:13px;">Failed to load calendar.</div>',
        );
      });
    },

    _renderMobileModalDays: function (calendarData, $container) {
      var month = this.calDate.getMonth();
      var year = this.calDate.getFullYear();
      var firstDay = new Date(year, month, 1).getDay();
      var daysInMonth = new Date(year, month + 1, 0).getDate();
      var html = "";

      // Empty days padding
      for (var i = 0; i < firstDay; i++) {
        html += '<div style="min-height:44px;"></div>';
      }

      // Actual days
      for (var d = 1; d <= daysInMonth; d++) {
        var dateStr =
          year +
          "-" +
          String(month + 1).padStart(2, "0") +
          "-" +
          String(d).padStart(2, "0");
        var dayData = calendarData[dateStr] || {
          status: "available",
          seats: 0,
        };
        var dayOfWeek = new Date(year, month, d).getDay();
        var isDisabled = dayData.status === "past" || dayData.status === "full";
        var isSelected = dateStr === this.selectedDate;

        var baseStyle =
          "min-height:44px;display:flex;align-items:center;justify-content:center;font-size:13px;border-radius:8px;cursor:pointer;position:relative;";
        var finalStyle = "";
        var cls = "dpbs-mm-day";

        if (isDisabled) {
          finalStyle =
            baseStyle +
            "color:#d4d0c8;cursor:not-allowed;text-decoration:line-through;";
          cls += " dpbs-mm-disabled";
        } else if (isSelected) {
          finalStyle =
            baseStyle +
            "background:#e8521e;color:#fff;font-weight:700;box-shadow:0 3px 10px rgba(232, 82, 30, 0.3);";
        } else if (dayOfWeek === 0 || dayOfWeek === 6) {
          finalStyle = baseStyle + "color:#e8521e;";
        } else {
          finalStyle = baseStyle + "color:#2b2b2b;";
        }

        var dot = "";
        if (dayData.status === "limited" && !isDisabled) {
          dot =
            '<span style="position:absolute;bottom:2px;left:50%;transform:translateX(-50%);width:5px;height:5px;border-radius:50%;background:#3b82f6;"></span>';
        } else if (dayData.status === "full") {
          dot =
            '<span style="position:absolute;bottom:2px;left:50%;transform:translateX(-50%);width:5px;height:5px;border-radius:50%;background:#ef4444;"></span>';
        }

        html +=
          '<div class="' +
          cls +
          '" data-date="' +
          dateStr +
          '" data-seats="' +
          dayData.seats +
          '" style="' +
          finalStyle +
          '">' +
          d +
          dot +
          "</div>";
      }
      $container.html(html);
    },

    closeCalendar: function () {
      this.calendarOpen = false;

      if (this._mobileModal) {
        this._mobileModal.remove();
        this._mobileModal = null;
      }

      // Unlock scroll and instantly restore the user's original scroll position
      if (this._scrollParentModal) {
        this._scrollParentModal.style.overflow =
          this._scrollParentModal._dpbs_old_overflow || "";
        this._scrollParentModal.scrollTop = this._savedScrollTopModal || 0;
        this._scrollParentModal = null;
      }

      this.els.calPopover.removeClass("is-open");
      this.els.calPopover[0].style.removeProperty("display");

      $(document).off("click.dpbs-close-" + this.id);
      $(document).off("touchend.dpbs-close-" + this.id);

      if (this._scrollParents) {
        $(this._scrollParents).off("scroll.dpbs-" + this.id);
        this._scrollParents = null;
      }
    },

    positionCalendar: function () {
      var dateInput = this.els.date;
      var popover = this.els.calPopover;

      if (!dateInput.length || !dateInput[0]) return;

      var rect = dateInput[0].getBoundingClientRect();
      var popoverWidth = popover.outerWidth() || 300;
      var popoverHeight = popover.outerHeight() || 360;
      var viewportWidth =
        window.innerWidth || document.documentElement.clientWidth;
      var viewportHeight =
        window.innerHeight || document.documentElement.clientHeight;

      // Adjust width for mobile
      if (viewportWidth <= 768) {
        popoverWidth = Math.min(viewportWidth - 40, 320);
        popover.css("width", popoverWidth + "px");
      }

      // Calculate horizontal position
      var left = rect.left;
      if (left + popoverWidth > viewportWidth - 10) {
        left = viewportWidth - popoverWidth - 10;
      }
      left = Math.max(10, left);

      // Calculate vertical position with intelligent flip
      var spaceBelow = viewportHeight - rect.bottom - 10;
      var spaceAbove = rect.top - 10;
      var top;

      if (popoverHeight <= spaceBelow) {
        top = rect.bottom + 5;
      } else if (popoverHeight <= spaceAbove) {
        top = rect.top - popoverHeight - 5;
      } else {
        top =
          spaceBelow >= spaceAbove
            ? rect.bottom + 5
            : rect.top - popoverHeight - 5;
      }

      // Final clamp
      top = Math.max(10, Math.min(top, viewportHeight - 50));

      popover.css({
        top: top + "px",
        left: left + "px",
        position: "fixed",
        "z-index": "99999999",
        transform: "none",
        "-webkit-transform": "none",
      });
    },

    // =============================================
    // CALENDAR - Load
    // =============================================

    loadCalendar: function () {
      var self = this;
      var sid = this.els.service.val();
      var lid = this.els.location.val();

      if (!sid || !lid || this.isSuiteMode()) {
        this.els.calDays.html("");
        return;
      }

      var month = this.calDate.getMonth() + 1;
      var year = this.calDate.getFullYear();

      this.els.calDays.html(
        '<div style="text-align:center;padding:20px;color:#999;">Loading...</div>',
      );

      $.post(
        dpbs_obj.ajax_url,
        {
          action: "dpbs_get_calendar",
          service_id: sid,
          location_id: lid,
          month: month,
          year: year,
        },
        function (response) {
          var res = JSON.parse(response);
          if (res.success) {
            self.renderCalendar(res.calendar);
            if (self.calendarOpen && !self._useMobileModal) {
              setTimeout(function () {
                self.positionCalendar();
              }, 10);
            }
          }
        },
      ).fail(function () {
        self.els.calDays.html(
          '<div style="text-align:center;padding:20px;color:#c0392b;">Failed to load calendar.</div>',
        );
      });
    },

    // =============================================
    // CALENDAR - Render
    // =============================================

    renderCalendar: function (calendarData) {
      var monthNames = [
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
      var month = this.calDate.getMonth();
      var year = this.calDate.getFullYear();

      this.els.calTitle.text(monthNames[month] + " " + year);

      var firstDay = new Date(year, month, 1).getDay();
      var daysInMonth = new Date(year, month + 1, 0).getDate();

      var html = "";
      for (var i = 0; i < firstDay; i++) {
        html += '<div class="dpbs-cal-day is-empty"></div>';
      }

      for (var d = 1; d <= daysInMonth; d++) {
        var dateStr =
          year +
          "-" +
          String(month + 1).padStart(2, "0") +
          "-" +
          String(d).padStart(2, "0");
        var dayData = calendarData[dateStr] || {
          status: "available",
          seats: 0,
        };
        var classes = "dpbs-cal-day";
        var dotHtml = "";
        var dayOfWeek = new Date(year, month, d).getDay();

        if (dayOfWeek === 0 || dayOfWeek === 6) classes += " is-weekend";
        if (dayData.status === "past") classes += " is-disabled";
        if (dayData.status === "full") {
          classes += " is-disabled";
          dotHtml = '<span class="dpbs-dot dpbs-dot-full"></span>';
        }
        if (dayData.status === "limited") {
          dotHtml = '<span class="dpbs-dot dpbs-dot-limited"></span>';
        }
        if (dateStr === this.selectedDate) classes += " is-selected";

        html +=
          '<div class="' +
          classes +
          '" data-date="' +
          dateStr +
          '" data-status="' +
          dayData.status +
          '" data-seats="' +
          dayData.seats +
          '">' +
          d +
          dotHtml +
          "</div>";
      }

      this.els.calDays.html(html);
    },

    // =============================================
    // FORM MESSAGES
    // =============================================

    showFormMessage: function (text, type) {
      this.els.formMessage.text(text).removeClass("is-error is-success");
      if (type === "error") this.els.formMessage.addClass("is-error");
      if (type === "success") this.els.formMessage.addClass("is-success");
    },

    clearFormMessage: function () {
      this.els.formMessage.text("").removeClass("is-error is-success");
    },

    // =============================================
    // FORM SUBMISSION
    // =============================================

    handleFormSubmit: function () {
      var self = this;
      this.clearFormMessage();

      if (this.isSuiteMode()) {
        this.handleSuiteFormSubmit();
        return;
      }

      var service = this.els.service.val();
      var city = this.els.city.val();
      var location = this.els.location.val();
      var fullName = this.els.fullName.val().trim();
      var phone = this.els.phone.val().trim();
      var email = this.els.email.val().trim();
      var seats = parseInt(this.els.seats.val()) || 0;
      var date = this.selectedDate;
      var company = this.els.company.val().trim();

      if (!service) {
        this.showFormMessage("Please select a Service.", "error");
        this.els.service.focus();
        return;
      }
      if (!city) {
        this.showFormMessage("Please select a City.", "error");
        this.els.city.focus();
        return;
      }
      if (!location) {
        this.showFormMessage("Please select a Location.", "error");
        this.els.location.focus();
        return;
      }
      if (!fullName || fullName.length < 2) {
        this.showFormMessage("Please enter your Full Name.", "error");
        this.els.fullName.focus();
        return;
      }
      if (!phone) {
        this.showFormMessage("Please enter your Phone Number.", "error");
        this.els.phone.focus();
        return;
      }
      if (!this.isValidIndianPhone(phone)) {
        this.showFormMessage("Enter a valid 10-digit phone number.", "error");
        this.els.phone.focus();
        return;
      }
      if (!email) {
        this.showFormMessage("Please enter your Email.", "error");
        this.els.email.focus();
        return;
      }
      if (!this.isValidEmail(email)) {
        this.showFormMessage("Enter a valid Email.", "error");
        this.els.email.focus();
        return;
      }
      if (!date) {
        this.showFormMessage("Please select a Date.", "error");
        return;
      }
      if (seats < 1) {
        this.showFormMessage("Please select at least 1 seat.", "error");
        this.els.seats.focus();
        return;
      }

      var dayEl = this.els.calDays.find(
        '.dpbs-cal-day[data-date="' + date + '"]',
      );
      if (dayEl.length) {
        var availSeats = parseInt(dayEl.data("seats"));
        if (isNaN(availSeats) || availSeats < 1) {
          this.showFormMessage("This date is fully booked.", "error");
          return;
        }
        if (seats > availSeats) {
          this.showFormMessage(
            "Only " + availSeats + " seat(s) available.",
            "error",
          );
          return;
        }
      }

      this.showFormMessage("Processing...");
      this.els.submitBtn.prop("disabled", true);

      $.post(
        dpbs_obj.ajax_url,
        {
          action: "dpbs_create_booking",
          nonce: dpbs_obj.nonce,
          service: service,
          city: city,
          location: location,
          date: date,
          seats: seats,
          full_name: fullName,
          email: email,
          phone: phone,
          company: company,
        },
        function (response) {
          var res = JSON.parse(response);
          if (res.success) {
            self.bookingData = res.booking_data;
            self.openRazorpay(res.order_id, res.amount, res.description);
          } else {
            self.showFormMessage(res.message, "error");
            self.els.submitBtn.prop("disabled", false);
          }
        },
      ).fail(function () {
        self.showFormMessage(
          "Something went wrong. Please try again.",
          "error",
        );
        self.els.submitBtn.prop("disabled", false);
      });
    },

    // =============================================
    // PRIVATE SUITES SUBMISSION (service 357, no payment)
    // =============================================
    // Separate from handleFormSubmit's regular flow above: validates the
    // Start/End date + Manager Seats fields, then posts straight to
    // dpbs_submit_suite_booking and shows the result - no Razorpay step.
    handleSuiteFormSubmit: function () {
      var self = this;

      var service = this.els.service.val();
      var city = this.els.city.val();
      var location = this.els.location.val();
      var fullName = this.els.fullName.val().trim();
      var phone = this.els.phone.val().trim();
      var email = this.els.email.val().trim();
      var seats = parseInt(this.els.seats.val()) || 0;
      var company = this.els.company.val().trim();
      var startDate = this.els.suiteStartDate.val();
      var endDate = this.els.suiteEndDate.val();
      var managerSeats = this.els.managerSeats.length
        ? this.els.managerSeats.val()
        : "No";

      if (!city) {
        this.showFormMessage("Please select a City.", "error");
        this.els.city.focus();
        return;
      }
      if (!location) {
        this.showFormMessage("Please select a Location.", "error");
        this.els.location.focus();
        return;
      }
      if (!fullName || fullName.length < 2) {
        this.showFormMessage("Please enter your Full Name.", "error");
        this.els.fullName.focus();
        return;
      }
      if (!phone) {
        this.showFormMessage("Please enter your Phone Number.", "error");
        this.els.phone.focus();
        return;
      }
      if (!this.isValidIndianPhone(phone)) {
        this.showFormMessage("Enter a valid 10-digit phone number.", "error");
        this.els.phone.focus();
        return;
      }
      if (!email) {
        this.showFormMessage("Please enter your Email.", "error");
        this.els.email.focus();
        return;
      }
      if (!this.isValidEmail(email)) {
        this.showFormMessage("Enter a valid Email.", "error");
        this.els.email.focus();
        return;
      }
      if (!startDate) {
        this.showFormMessage("Please select a Start Date.", "error");
        this.els.suiteStartDate.focus();
        return;
      }
      if (!endDate) {
        this.showFormMessage("Please select an End Date.", "error");
        this.els.suiteEndDate.focus();
        return;
      }
      if (endDate < startDate) {
        this.showFormMessage("End date cannot be before start date.", "error");
        return;
      }
      if (seats < 1) {
        this.showFormMessage("Please select at least 1 seat.", "error");
        this.els.seats.focus();
        return;
      }

      this.showFormMessage("Processing...");
      this.els.submitBtn.prop("disabled", true);

      $.post(
        dpbs_obj.ajax_url,
        {
          action: "dpbs_submit_suite_booking",
          nonce: dpbs_obj.nonce,
          service: service,
          city: city,
          location: location,
          start_date: startDate,
          end_date: endDate,
          seats: seats,
          full_name: fullName,
          email: email,
          phone: phone,
          company: company,
          manager_seats: managerSeats,
        },
        function (response) {
          var res = JSON.parse(response);
          self.els.submitBtn.prop("disabled", false);
          if (res.success) {
            self.showFormMessage(res.message, "success");
            self.els.form[0].reset();
            self.toggleSuiteMode();
          } else {
            self.showFormMessage(res.message, "error");
          }
        },
      ).fail(function () {
        self.showFormMessage(
          "Something went wrong. Please try again.",
          "error",
        );
        self.els.submitBtn.prop("disabled", false);
      });
    },

    // =============================================
    // RAZORPAY
    // =============================================

    openRazorpay: function (orderId, amount, description) {
      var self = this;
      var options = {
        key: dpbs_obj.razorpay_key,
        amount: amount,
        currency: "INR",
        name: "Day Pass Booking",
        description: description,
        order_id: orderId,
        handler: function (response) {
          self.verifyPayment(
            response.razorpay_payment_id,
            response.razorpay_order_id,
            response.razorpay_signature,
          );
        },
        modal: {
          ondismiss: function () {
            self.showFormMessage("Payment was cancelled.", "error");
            self.els.submitBtn.prop("disabled", false);
          },
        },
        prefill: {
          name: self.bookingData.full_name,
          email: self.bookingData.email,
          contact: self.bookingData.phone,
        },
        theme: { color: "#e8521e" },
      };
      var rzp = new Razorpay(options);
      rzp.open();
    },

    verifyPayment: function (paymentId, orderId, signature) {
      var self = this;
      this.showFormMessage("Verifying payment...");

      $.post(
        dpbs_obj.ajax_url,
        {
          action: "dpbs_verify_payment",
          nonce: dpbs_obj.nonce,
          payment_id: paymentId,
          order_id: orderId,
          signature: signature,
          service: this.bookingData.service,
          city: this.bookingData.city,
          location: this.bookingData.location,
          date: this.bookingData.date,
          seats: this.bookingData.seats,
          full_name: this.bookingData.full_name,
          email: this.bookingData.email,
          phone: this.bookingData.phone,
          company: this.bookingData.company,
        },
        function (response) {
          if (response.trim() === "verified") {
            self.showFormMessage("Booking confirmed!", "success");
            self.els.form[0].reset();
            self.selectedDate = "";
            self.els.date.val("");
            self.els.priceDisplay.text("0.00");
            self.els.seatsInfo.text("");
            self.els.calDays.html("");
            self.els.seats.find("option").prop("disabled", false);
            setTimeout(function () {
              self.loadCalendar();
            }, 1000);
          } else {
            self.showFormMessage(
              "Payment verification failed. Contact support with ID: " +
                paymentId,
              "error",
            );
          }
          self.els.submitBtn.prop("disabled", false);
        },
      ).fail(function () {
        self.showFormMessage("Verification failed. Contact support.", "error");
        self.els.submitBtn.prop("disabled", false);
      });
    },

    destroy: function () {
      this.closeCalendar();
      delete instances[this.id];
    },
  };

  // =============================================
  // INITIALIZATION
  // =============================================

  window.initDPBSForm = function (container) {
    if (!container) return;
    var $container = $(container);

    if ($container.data("dpbsInitialized")) return;
    $container.data("dpbsInitialized", true);

    var instanceId =
      $container.data("instance-id") ||
      "dpbs-auto-" + Date.now() + "-" + Math.floor(Math.random() * 1e6);
    var key = instanceId;

    if (instances[key] && instances[key].container[0] !== $container[0]) {
      key =
        instanceId + "-" + Date.now() + "-" + Math.floor(Math.random() * 1e6);
    }

    instances[key] = new DPBSInstance($container, key);
  };

  function initAllForms() {
    $(".dpbs-booking-instance").each(function () {
      window.initDPBSForm(this);
    });
  }

  $(document).ready(function () {
    initAllForms();
  });

  $(window).on("load", function () {
    // Multiple attempts for Elementor mobile menus
    setTimeout(initAllForms, 100);
    setTimeout(initAllForms, 500);
    setTimeout(initAllForms, 1000);
  });

  // MutationObserver for dynamic content
  if (typeof MutationObserver !== "undefined") {
    var observer = new MutationObserver(function (mutations) {
      var shouldInit = false;
      mutations.forEach(function (mutation) {
        if (mutation.addedNodes) {
          $(mutation.addedNodes)
            .find(".dpbs-booking-instance")
            .each(function () {
              shouldInit = true;
            });
          $(mutation.addedNodes)
            .filter(".dpbs-booking-instance")
            .each(function () {
              shouldInit = true;
            });
        }
      });
      if (shouldInit) initAllForms();
    });

    $(document).ready(function () {
      observer.observe(document.body, { childList: true, subtree: true });
    });
  }

  // Reposition on resize/orientation
  $(window).on("resize orientationchange", function () {
    $.each(instances, function (id, instance) {
      if (instance.calendarOpen && !instance._useMobileModal) {
        setTimeout(function () {
          instance.positionCalendar();
        }, 100);
      }
    });
  });
})(jQuery);
