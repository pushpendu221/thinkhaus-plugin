/**
 * DPBS Frontend - Multi-Instance Support
 * FIXED: Calendar close issue + Mobile calendar visibility in Elementor headers
 * FIXED: Private Suites uses identical custom calendar UI as regular Day Pass
 * FIXED: Space issue when switching to Private Suites (hides parent grid cell)
 * FIXED: Price calculation = price × days for Private Suites
 * FIXED: Max 1 month (30 days) restriction for Private Suites end date
 * FIXED: Button text changes to "Inquire" for Private Suites
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
    this._basePrice = 0; // Used for suite price calculation

    // Suite calendar state
    this.suiteStartCalDate = new Date();
    this.suiteEndCalDate = new Date();
    this.suiteStartCalendarOpen = false;
    this.suiteEndCalendarOpen = false;
    this._suiteStartScrollParents = null;
    this._suiteEndScrollParents = null;
    this._suiteStartMobileModal = null;
    this._suiteEndMobileModal = null;

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
      dateField: container
        .find(".dpbs-date-field")
        .not(".dpbs-date-field--suite"),
      regularDateWrap: container.find('[id$="-regular-date-wrap"]'),
      calPopover: container
        .find(".dpbs-calendar-popover")
        .not(".dpbs-cal-popover--suite-start, .dpbs-cal-popover--suite-end"),
      calTitle: container
        .find(".dpbs-cal-title")
        .not(".dpbs-suite-start-title, .dpbs-suite-end-title"),
      calDays: container
        .find(".dpbs-cal-days")
        .not(".dpbs-suite-start-days, .dpbs-suite-end-days"),
      calNav: container
        .find(".dpbs-cal-nav-btn")
        .not(".dpbs-suite-start-nav, .dpbs-suite-end-nav"),
      priceLine: container.find(".dpbs-price-line"),
      priceDisplay: container.find('[id$="-price-display"]'),
      priceSuffix: container.find('[id$="-price-suffix"]'),
      taxLine: container.find('[id$="-tax-line"]'),
      gstNote: container.find('[id$="-gst-note"]'),
      formMessage: container.find(".dpbs-form-message"),
      submitBtn: container.find(".dpbs-submit-btn"),
      suiteFields: container.find(".dpbs-suite-field"),
      suiteStartDate: container.find(".dpbs-suite-start-date"),
      suiteEndDate: container.find(".dpbs-suite-end-date"),
      managerSeats: container.find(".dpbs-manager-seats"),
      suiteStartDateField: container.find(".dpbs-date-field--suite").first(),
      suiteEndDateField: container.find(".dpbs-date-field--suite").last(),
      suiteStartCalPopover: container.find(".dpbs-cal-popover--suite-start"),
      suiteEndCalPopover: container.find(".dpbs-cal-popover--suite-end"),
      suiteStartCalTitle: container.find(".dpbs-suite-start-title"),
      suiteEndCalTitle: container.find(".dpbs-suite-end-title"),
      suiteStartCalDays: container.find(".dpbs-suite-start-days"),
      suiteEndCalDays: container.find(".dpbs-suite-end-days"),
    };

    this.init();
  }

  DPBSInstance.prototype = {
    init: function () {
      if (this.initialized) return;
      this.initialized = true;

      if (this.els.calPopover.length)
        this.els.calPopover.appendTo(document.body);
      if (this.els.suiteStartCalPopover.length)
        this.els.suiteStartCalPopover.appendTo(document.body);
      if (this.els.suiteEndCalPopover.length)
        this.els.suiteEndCalPopover.appendTo(document.body);

      this.bindEvents();
      this.loadPreselected();
      this.toggleSuiteMode();
    },

    // =============================================
    // PRIVATE SUITES MODE
    // =============================================
    isSuiteMode: function () {
      var suiteId =
        typeof dpbs_obj !== "undefined" ? dpbs_obj.suite_service_id : null;
      if (!suiteId) return false;
      return String(this.els.service.val()) === String(suiteId);
    },

    toggleSuiteMode: function () {
      if (!this.els.suiteFields.length) return;

      if (this.isSuiteMode()) {
        this.closeCalendar();
        this.els.regularDateWrap.hide(); // Hides parent to remove empty grid space
        this.els.date.prop("required", false);
        this.els.suiteFields.show();
        this.els.suiteStartDate.prop("required", true);
        this.els.suiteEndDate.prop("required", true);

        // FIX: Change button text to Inquire and update price suffix
        this.els.submitBtn.text("Inquire");
        this.updateSuitePrice();
      } else {
        this.els.suiteFields.hide();
        this.els.suiteStartDate.prop("required", false);
        this.els.suiteEndDate.prop("required", false);
        this.els.regularDateWrap.show();
        this.els.date.prop("required", true);

        // Revert button text and price suffix
        this.els.submitBtn.text("Book Now");
        if (this.els.priceSuffix.length) this.els.priceSuffix.text("/ Seat");
        this.updatePrice();
      }
    },

    // =============================================
    // EVENT BINDINGS
    // =============================================
    bindEvents: function () {
      var self = this;

      this.els.city.on("change", function () {
        self.selectedDate = "";
        self.els.date.val("");
        self.loadLocations(function () {
          if (self.isSuiteMode()) self.updateSuitePrice();
          else {
            self.updatePrice();
            self.loadCalendar();
          }
        });
      });

      this.els.service.on("change", function () {
        self.selectedDate = "";
        self.els.date.val("");
        self._basePrice = 0;
        self.toggleSuiteMode();
        self.loadCitiesForService(function () {
          self.loadLocations(function () {
            if (self.isSuiteMode()) self.updateSuitePrice();
            else {
              self.updatePrice();
              self.loadCalendar();
            }
          });
        });
      });

      this.els.location.on("change", function () {
        self.selectedDate = "";
        self.els.date.val("");
        if (self.isSuiteMode()) self.updateSuitePrice();
        else {
          self.updatePrice();
          self.loadCalendar();
        }
      });

      this.els.seats.on("change", function () {
        self.updateSeatsInfo();
        // NEW: keep the tax breakdown in sync with the selected seat count
        // (suite mode already recomputes via updateSuitePrice's own flow).
        if (!self.isSuiteMode() && self._basePrice > 0) {
          var seatsCount = parseInt(self.els.seats.val()) || 1;
          self.updateTaxLine(self._basePrice * seatsCount);
        }
      });

      // Regular Date Calendar
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
        self.calDate.setMonth(
          self.calDate.getMonth() + ($(this).data("dir") === "prev" ? -1 : 1),
        );
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

      // Suite Start Date Calendar
      this.els.suiteStartDateField.on(
        "click.dpbs-ss touchend.dpbs-ss",
        function (e) {
          if ($(e.target).closest(".dpbs-cal-popover--suite-start").length)
            return;
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
          self.toggleSuiteStartCalendar();
        },
      );

      this.els.suiteStartCalPopover.on(
        "click",
        ".dpbs-suite-start-nav",
        function (e) {
          e.preventDefault();
          e.stopPropagation();
          self.suiteStartCalDate.setMonth(
            self.suiteStartCalDate.getMonth() +
              ($(this).data("dir") === "prev" ? -1 : 1),
          );
          self.loadSuiteStartCalendar();
        },
      );

      this.els.suiteStartCalPopover.on(
        "click.dpbs-ss-day touchend.dpbs-ss-day",
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
          var d = $(this).data("date");
          self.els.suiteStartDate.val(d);
          self.closeSuiteStartCalendar();
          self.clearFormMessage();
          if (!self.els.suiteEndDate.val() || self.els.suiteEndDate.val() < d)
            self.els.suiteEndDate.val(d);
          self.updateSuitePrice(); // Recalculate price on date change
        },
      );

      // Suite End Date Calendar
      this.els.suiteEndDateField.on(
        "click.dpbs-se touchend.dpbs-se",
        function (e) {
          if ($(e.target).closest(".dpbs-cal-popover--suite-end").length)
            return;
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
          self.toggleSuiteEndCalendar();
        },
      );

      this.els.suiteEndCalPopover.on(
        "click",
        ".dpbs-suite-end-nav",
        function (e) {
          e.preventDefault();
          e.stopPropagation();
          self.suiteEndCalDate.setMonth(
            self.suiteEndCalDate.getMonth() +
              ($(this).data("dir") === "prev" ? -1 : 1),
          );
          self.loadSuiteEndCalendar();
        },
      );

      this.els.suiteEndCalPopover.on(
        "click.dpbs-se-day touchend.dpbs-se-day",
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
          var d = $(this).data("date");
          if (
            self.els.suiteStartDate.val() &&
            d < self.els.suiteStartDate.val()
          ) {
            self.showFormMessage(
              "End date cannot be before start date.",
              "error",
            );
            return;
          }
          self.els.suiteEndDate.val(d);
          self.closeSuiteEndCalendar();
          self.clearFormMessage();
          self.updateSuitePrice(); // Recalculate price on date change
        },
      );

      this.els.form.on("submit", function (e) {
        e.preventDefault();
        self.handleFormSubmit();
      });
    },

    // =============================================
    // PRESELECTED VALUES & VALIDATION
    // =============================================
    loadPreselected: function () {
      var self = this;
      var preService = this.container.data("pre-service") || "";
      var preCity = this.container.data("pre-city") || "";
      var preLocation = this.container.data("pre-location") || "";

      if (preService) this.els.service.val(preService);
      if (preCity) {
        this.els.city.val(preCity);
        this.loadLocations(function () {
          if (preLocation) self.els.location.val(preLocation);
          if (self.isSuiteMode()) self.updateSuitePrice();
          else {
            self.updatePrice();
            self.loadCalendar();
          }
        });
      } else if (preService && this.els.location.val()) {
        if (self.isSuiteMode()) self.updateSuitePrice();
        else {
          self.updatePrice();
          self.loadCalendar();
        }
      }
    },

    isValidEmail: function (email) {
      return /^[a-zA-Z0-9._%+\-]+@[a-zA-Z0-9.\-]+\.[a-zA-Z]{2,}$/.test(email);
    },
    isValidIndianPhone: function (phone) {
      return /^[6-9]\d{9}$/.test(phone.replace(/[\s\-\+\(\)]/g, ""));
    },

    // =============================================
    // DROPDOWNS & PRICING
    // =============================================
    loadCitiesForService: function (callback) {
      var self = this,
        serviceId = this.els.service.val(),
        currentCity = this.els.city.val();
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
          )
            self.els.city.val(currentCity);
          else {
            self.els.city.val("");
            self.els.location.html('<option value="">Select Location</option>');
          }
          if (typeof callback === "function") callback();
        },
      );
    },

    loadLocations: function (callback) {
      var self = this,
        cityId = this.els.city.val(),
        serviceId = this.els.service.val();
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

    // NEW: shows subtotal + tax = total for whatever amount is currently
    // priced, so the customer sees the tax they'll pay before submitting.
    // Hidden entirely when tax is disabled server-side or nothing is priced.
    updateTaxLine: function (subtotal) {
      if (!this.els.taxLine || !this.els.taxLine.length) return;
      if (!this._taxEnabled || !this._taxRate || !subtotal) {
        this.els.taxLine.hide().text("");
        return;
      }
      var taxAmt = subtotal * (this._taxRate / 100);
      var total = subtotal + taxAmt;
      this.els.taxLine
        .text(
          "+ " +
            (this._taxLabel || "Tax") +
            " (" +
            this._taxRate +
            "%): ₹" +
            taxAmt.toFixed(2) +
            "  |  You pay: ₹" +
            total.toFixed(2),
        )
        .hide();
    },

    updatePrice: function () {
      var self = this,
        sid = this.els.service.val(),
        lid = this.els.location.val();
      if (!sid || !lid) {
        this.els.priceDisplay.text("0.00");
        this.updateTaxLine(0);
        this.els.priceLine.removeClass("is-visible");
        return;
      }
      $.post(
        dpbs_obj.ajax_url,
        { action: "dpbs_get_price", service_id: sid, location_id: lid },
        function (response) {
          var res = JSON.parse(response);
          if (res.success) {
            self._basePrice = parseFloat(res.price);
            self._taxEnabled = !!res.tax_enabled;
            self._taxRate = parseFloat(res.tax_rate) || 0;
            self._taxLabel = res.tax_label;
            self.els.priceDisplay.text(self._basePrice.toFixed(2));
            self.els.gstNote.text(
              self._taxEnabled
                ? " + " +
                    (self._taxLabel || "GST") +
                    " (" +
                    self._taxRate +
                    "%)"
                : "",
            );
            var seatsCount = parseInt(self.els.seats.val()) || 1;
            self.updateTaxLine(self._basePrice * seatsCount);
            self.updateSeatsInfo();
            self.els.priceLine.addClass("is-visible");
          }
        },
      );
    },

    updateSuitePrice: function () {
      var startDateStr = this.els.suiteStartDate.val();
      var endDateStr = this.els.suiteEndDate.val();

      if (this._basePrice <= 0) {
        var sid = this.els.service.val(),
          lid = this.els.location.val(),
          self = this;
        if (!sid || !lid) {
          this.els.priceDisplay.text("0.00");
          if (this.els.priceSuffix.length) this.els.priceSuffix.text("/ Stay");
          this.updateTaxLine(0);
          this.els.priceLine.removeClass("is-visible");
          return;
        }
        $.post(
          dpbs_obj.ajax_url,
          { action: "dpbs_get_price", service_id: sid, location_id: lid },
          function (response) {
            var res = JSON.parse(response);
            if (res.success) {
              self._basePrice = parseFloat(res.price);
              self._taxEnabled = !!res.tax_enabled;
              self._taxRate = parseFloat(res.tax_rate) || 0;
              self._taxLabel = res.tax_label;
              self.els.gstNote.text(
                self._taxEnabled
                  ? " + " +
                      (self._taxLabel || "GST") +
                      " (" +
                      self._taxRate +
                      "%)"
                  : "",
              );
              self.updateSuitePrice();
            }
          },
        );
        return;
      }

      this.els.priceLine.addClass("is-visible");

      if (startDateStr && endDateStr && endDateStr >= startDateStr) {
        var start = new Date(startDateStr + "T00:00:00");
        var end = new Date(endDateStr + "T00:00:00");
        var diffTime = Math.abs(end - start);
        var diffDays = Math.ceil(diffTime / (1000 * 60 * 60 * 24)) + 1; // Inclusive of both days
        var total = this._basePrice * diffDays;

        this.els.priceDisplay.text(total.toFixed(2));
        if (this.els.priceSuffix.length)
          this.els.priceSuffix.text(
            "/ " + diffDays + " Day" + (diffDays > 1 ? "s" : ""),
          );
        this.updateTaxLine(total);
      } else {
        this.els.priceDisplay.text(this._basePrice.toFixed(2));
        if (this.els.priceSuffix.length) this.els.priceSuffix.text("/ Stay");
        this.updateTaxLine(this._basePrice);
      }
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
          if (currentVal > avail) this.els.seats.val(avail);
        } else {
          this.els.seats.find("option").prop("disabled", true);
        }
      } else {
        this.els.seatsInfo.text("");
        this.els.seats.find("option").prop("disabled", false);
      }
    },

    // =============================================
    // REGULAR CALENDAR LOGIC
    // =============================================
    toggleCalendar: function () {
      var sid = this.els.service.val(),
        lid = this.els.location.val();
      if (!sid || !lid) {
        var errorMsg = "Please select a Service and Location first.";
        this.showFormMessage(errorMsg, "error");
        if (this.isInHeader()) alert(errorMsg);
        return;
      }
      if (this.calendarOpen) this.closeCalendar();
      else this.openCalendar();
    },

    isInHeader: function () {
      var $el = this.container,
        selectors = [
          "header",
          ".elementor-location-header",
          ".site-header",
          "[data-elementor-location='header']",
          ".mobile-header",
          ".elementor-mobile",
        ];
      for (var i = 0; i < selectors.length; i++) {
        if ($el.closest(selectors[i]).length) return true;
      }
      return false;
    },

    getScrollParents: function (el) {
      var parents = [],
        node = el ? el.parentElement : null;
      while (
        node &&
        node !== document.body &&
        node !== document.documentElement
      ) {
        var style = window.getComputedStyle(node);
        if (/(auto|scroll)/.test(style.overflowY + " " + style.overflow))
          parents.push(node);
        node = node.parentElement;
      }
      parents.push(window);
      return parents;
    },

    openCalendar: function () {
      var viewportWidth =
        window.innerWidth || document.documentElement.clientWidth;
      this.calendarOpen = true;
      if (viewportWidth <= 768) {
        this._useMobileModal = true;
        this._openMobileModal();
      } else {
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
      if (this.els.calDays.children().length === 0) this.loadCalendar();
      $(document).off(
        "click.dpbs-close-" + this.id + " touchend.dpbs-close-" + this.id,
      );
      $(document).on(
        "click.dpbs-close-" + this.id + " touchend.dpbs-close-" + this.id,
        function (e) {
          var $target = $(e.target);
          if (e.type === "click" && self._touchHandled) return;
          if (
            !$target.closest(self.els.dateField).length &&
            !$target.closest(self.els.calPopover).length
          )
            self.closeCalendar();
        },
      );
      this._scrollParents = this.getScrollParents(this.els.dateField[0]);
      $(this._scrollParents).off("scroll.dpbs-" + this.id);
      $(this._scrollParents).on("scroll.dpbs-" + this.id, function () {
        if (self.calendarOpen) self.positionCalendar();
      });
    },

    _openMobileModal: function () {
      var self = this,
        scrollParent = null,
        $p = this.container.parent();
      while ($p.length && $p[0] !== document.body) {
        if ($p[0].scrollHeight > $p[0].clientHeight) {
          scrollParent = $p[0];
          break;
        }
        $p = $p.parent();
      }
      if (scrollParent) {
        this._savedScrollTopModal = scrollParent.scrollTop;
        scrollParent._dpbs_old_overflow = scrollParent.style.overflow;
        scrollParent.style.overflow = "hidden";
        scrollParent.scrollTop = 0;
        this._scrollParentModal = scrollParent;
      }
      var m =
        '<div id="dpbs-mm-' +
        this.id +
        '" style="position:absolute;top:0;left:0;right:0;bottom:0;height:100vh;height:100dvh;z-index:2147483647;background:rgba(0,0,0,0.6);display:flex;flex-direction:column;align-items:center;justify-content:center;padding: 60px 16px 16px;box-sizing:border-box;"><div style="background:#fff;border-radius:16px;width:100%;max-width:340px;box-shadow:0 25px 60px rgba(0,0,0,0.4);overflow:hidden;font-family:-apple-system,BlinkMacSystemFont,Segoe UI,Roboto,sans-serif;"><div style="display:flex;align-items:center;justify-content:space-between;padding:16px;border-bottom:1px solid #f0ede6;"><button type="button" class="dpbs-mm-prev" style="background:#f5f3ed;border:none;cursor:pointer;width:44px;height:44px;border-radius:8px;display:flex;align-items:center;justify-content:center;font-size:16px;color:#444;">&laquo;</button><span class="dpbs-mm-title" style="font-weight:700;font-size:15px;color:#1f1f1f;"></span><button type="button" class="dpbs-mm-next" style="background:#f5f3ed;border:none;cursor:pointer;width:44px;height:44px;border-radius:8px;display:flex;align-items:center;justify-content:center;font-size:16px;color:#444;">&raquo;</button></div><div style="padding:12px 16px 8px;"><div style="display:grid;grid-template-columns:repeat(7,1fr);gap:2px;text-align:center;margin-bottom:8px;"><div style="font-size:10px;font-weight:800;color:#e8521e;padding:4px 0;">Su</div><div style="font-size:10px;font-weight:800;color:#aaa;padding:4px 0;">Mo</div><div style="font-size:10px;font-weight:800;color:#aaa;padding:4px 0;">Tu</div><div style="font-size:10px;font-weight:800;color:#aaa;padding:4px 0;">We</div><div style="font-size:10px;font-weight:800;color:#aaa;padding:4px 0;">Th</div><div style="font-size:10px;font-weight:800;color:#aaa;padding:4px 0;">Fr</div><div style="font-size:10px;font-weight:800;color:#e8521e;padding:4px 0;">Sa</div></div><div class="dpbs-mm-days" style="display:grid;grid-template-columns:repeat(7,1fr);gap:2px;text-align:center;"></div></div><div style="padding:8px 16px 16px;text-align:center;"><button type="button" class="dpbs-mm-close" style="background:#e8521e;color:#fff;border:none;padding:12px 32px;border-radius:8px;font-size:15px;font-weight:600;cursor:pointer;">Close</button></div></div></div>';
      this._mobileModal = $(m).appendTo(scrollParent || this.container);
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
        if ($(e.target).is("#dpbs-mm-" + self.id)) self.closeCalendar();
      });
      this._loadCalendarIntoModal();
    },

    _loadCalendarIntoModal: function () {
      var self = this,
        sid = this.els.service.val(),
        lid = this.els.location.val();
      if (!sid || !lid || !this._mobileModal) return;
      var $days = this._mobileModal.find(".dpbs-mm-days"),
        $title = this._mobileModal.find(".dpbs-mm-title");
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
      var month = this.calDate.getMonth(),
        year = this.calDate.getFullYear(),
        firstDay = new Date(year, month, 1).getDay(),
        daysInMonth = new Date(year, month + 1, 0).getDate(),
        html = "";
      for (var i = 0; i < firstDay; i++)
        html += '<div style="min-height:44px;"></div>';
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
          },
          dayOfWeek = new Date(year, month, d).getDay();
        var isDisabled = dayData.status === "past" || dayData.status === "full",
          isSelected = dateStr === this.selectedDate;
        var baseStyle =
            "min-height:44px;display:flex;align-items:center;justify-content:center;font-size:13px;border-radius:8px;cursor:pointer;position:relative;",
          finalStyle = "",
          cls = "dpbs-mm-day";
        if (isDisabled) {
          finalStyle =
            baseStyle +
            "color:#d4d0c8;cursor:not-allowed;text-decoration:line-through;";
          cls += " dpbs-mm-disabled";
        } else if (isSelected) {
          finalStyle =
            baseStyle +
            "background:#e8521e;color:#fff;font-weight:700;box-shadow:0 3px 10px rgba(232, 82, 30, 0.3);";
        } else if (dayOfWeek === 0 || dayOfWeek === 6)
          finalStyle = baseStyle + "color:#e8521e;";
        else finalStyle = baseStyle + "color:#2b2b2b;";
        var dot = "";
        if (dayData.status === "limited" && !isDisabled)
          dot =
            '<span style="position:absolute;bottom:2px;left:50%;transform:translateX(-50%);width:5px;height:5px;border-radius:50%;background:#3b82f6;"></span>';
        else if (dayData.status === "full")
          dot =
            '<span style="position:absolute;bottom:2px;left:50%;transform:translateX(-50%);width:5px;height:5px;border-radius:50%;background:#ef4444;"></span>';
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
      if (
        this._scrollParentModal &&
        !this.suiteStartCalendarOpen &&
        !this.suiteEndCalendarOpen
      ) {
        this._scrollParentModal.style.overflow =
          this._scrollParentModal._dpbs_old_overflow || "";
        this._scrollParentModal.scrollTop = this._savedScrollTopModal || 0;
        this._scrollParentModal = null;
      }
      this.els.calPopover.removeClass("is-open");
      if (this.els.calPopover[0])
        this.els.calPopover[0].style.removeProperty("display");
      $(document).off("click.dpbs-close-" + this.id);
      $(document).off("touchend.dpbs-close-" + this.id);
      if (this._scrollParents) {
        $(this._scrollParents).off("scroll.dpbs-" + this.id);
        this._scrollParents = null;
      }
    },

    positionCalendar: function () {
      this._positionCal(this.els.date, this.els.calPopover);
    },

    loadCalendar: function () {
      var self = this,
        sid = this.els.service.val(),
        lid = this.els.location.val();
      if (!sid || !lid || this.isSuiteMode()) {
        this.els.calDays.html("");
        return;
      }
      this.els.calDays.html(
        '<div style="text-align:center;padding:20px;color:#999;">Loading...</div>',
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
            self.renderCalendar(res.calendar);
            if (self.calendarOpen && !self._useMobileModal)
              setTimeout(function () {
                self.positionCalendar();
              }, 10);
          }
        },
      ).fail(function () {
        self.els.calDays.html(
          '<div style="text-align:center;padding:20px;color:#c0392b;">Failed to load calendar.</div>',
        );
      });
    },

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
      var month = this.calDate.getMonth(),
        year = this.calDate.getFullYear();
      this.els.calTitle.text(monthNames[month] + " " + year);
      var firstDay = new Date(year, month, 1).getDay(),
        daysInMonth = new Date(year, month + 1, 0).getDate(),
        html = "";
      for (var i = 0; i < firstDay; i++)
        html += '<div class="dpbs-cal-day is-empty"></div>';
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
          },
          classes = "dpbs-cal-day",
          dotHtml = "",
          dayOfWeek = new Date(year, month, d).getDay();
        if (dayOfWeek === 0 || dayOfWeek === 6) classes += " is-weekend";
        if (dayData.status === "past") classes += " is-disabled";
        if (dayData.status === "full") {
          classes += " is-disabled";
          dotHtml = '<span class="dpbs-dot dpbs-dot-full"></span>';
        }
        if (dayData.status === "limited")
          dotHtml = '<span class="dpbs-dot dpbs-dot-limited"></span>';
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
    // SUITE CALENDAR LOGIC
    // =============================================
    toggleSuiteStartCalendar: function () {
      if (this.suiteStartCalendarOpen) this.closeSuiteStartCalendar();
      else this.openSuiteStartCalendar();
    },
    toggleSuiteEndCalendar: function () {
      if (this.suiteEndCalendarOpen) this.closeSuiteEndCalendar();
      else this.openSuiteEndCalendar();
    },

    openSuiteStartCalendar: function () {
      this.suiteStartCalendarOpen = true;
      if ((window.innerWidth || document.documentElement.clientWidth) <= 768)
        this._openSuiteMobileModal("start");
      else this._openSuitePopover("start");
    },

    openSuiteEndCalendar: function () {
      if (!this.els.suiteStartDate.val()) {
        this.showFormMessage("Please select a Start Date first.", "error");
        return;
      }
      this.suiteEndCalendarOpen = true;
      if ((window.innerWidth || document.documentElement.clientWidth) <= 768)
        this._openSuiteMobileModal("end");
      else this._openSuitePopover("end");
    },

    _openSuitePopover: function (which) {
      var self = this,
        $field =
          which === "start"
            ? this.els.suiteStartDateField
            : this.els.suiteEndDateField;
      var $popover =
        which === "start"
          ? this.els.suiteStartCalPopover
          : this.els.suiteEndCalPopover;
      var evtNs = which === "start" ? "dpbs-ssc" : "dpbs-sec";
      var scrollNs = which === "start" ? "dpbs-ss" : "dpbs-se";

      $popover.css({
        "z-index": "99999998",
        transform: "none",
        "-webkit-transform": "none",
      });
      $popover.addClass("is-open");
      $popover[0].style.setProperty("display", "block", "important");
      this._positionCal(
        which === "start" ? this.els.suiteStartDate : this.els.suiteEndDate,
        $popover,
      );

      var $days =
        which === "start"
          ? this.els.suiteStartCalDays
          : this.els.suiteEndCalDays;
      if ($days.children().length === 0)
        this[
          "loadSuite" + (which === "start" ? "Start" : "End") + "Calendar"
        ]();

      $(document).off(
        "click." + evtNs + "-" + this.id + " touchend." + evtNs + "-" + this.id,
      );
      $(document).on(
        "click." + evtNs + "-" + this.id + " touchend." + evtNs + "-" + this.id,
        function (e) {
          var $target = $(e.target);
          if (e.type === "click" && self._touchHandled) return;
          if (
            !$target.closest($field).length &&
            !$target.closest($popover).length
          )
            self[
              "closeSuite" + (which === "start" ? "Start" : "End") + "Calendar"
            ]();
        },
      );

      var scrollParents = this.getScrollParents($field[0]);
      $(scrollParents).off("scroll." + scrollNs + "-" + this.id);
      $(scrollParents).on("scroll." + scrollNs + "-" + this.id, function () {
        self._positionCal(
          which === "start" ? self.els.suiteStartDate : self.els.suiteEndDate,
          $popover,
        );
      });
      if (which === "start") this._suiteStartScrollParents = scrollParents;
      else this._suiteEndScrollParents = scrollParents;
    },

    _openSuiteMobileModal: function (which) {
      var self = this,
        calDate =
          which === "start" ? this.suiteStartCalDate : this.suiteEndCalDate;
      var selectedVal =
        which === "start"
          ? this.els.suiteStartDate.val()
          : this.els.suiteEndDate.val();
      var minDate = which === "end" ? this.els.suiteStartDate.val() : null;
      var maxDate = null;
      if (which === "end" && minDate) {
        var minParts = minDate.split("-");
        var minD = new Date(minParts[0], minParts[1] - 1, minParts[2]);
        var maxD = new Date(minD.getTime() + 30 * 24 * 60 * 60 * 1000); // +30 days
        maxDate =
          maxD.getFullYear() +
          "-" +
          String(maxD.getMonth() + 1).padStart(2, "0") +
          "-" +
          String(maxD.getDate()).padStart(2, "0");
      }
      var modalId = "dpbs-suite-" + which + "-mm-" + this.id;
      var scrollParent = null,
        $p = this.container.parent();
      while ($p.length && $p[0] !== document.body) {
        if ($p[0].scrollHeight > $p[0].clientHeight) {
          scrollParent = $p[0];
          break;
        }
        $p = $p.parent();
      }
      if (scrollParent) {
        this._savedScrollTopModal = scrollParent.scrollTop;
        scrollParent._dpbs_old_overflow = scrollParent.style.overflow;
        scrollParent.style.overflow = "hidden";
        scrollParent.scrollTop = 0;
        this._scrollParentModal = scrollParent;
      }

      var navCls = "dpbs-suite-" + which + "-mm-nav",
        titleCls = "dpbs-suite-" + which + "-mm-title",
        daysCls = "dpbs-suite-" + which + "-mm-days";
      var m =
        '<div id="' +
        modalId +
        '" style="position:absolute;top:0;left:0;right:0;bottom:0;height:100vh;height:100dvh;z-index:2147483647;background:rgba(0,0,0,0.6);display:flex;flex-direction:column;align-items:center;justify-content:center;padding:60px 16px 16px;box-sizing:border-box;"><div style="background:#fff;border-radius:16px;width:100%;max-width:340px;box-shadow:0 25px 60px rgba(0,0,0,0.4);overflow:hidden;font-family:-apple-system,BlinkMacSystemFont,Segoe UI,Roboto,sans-serif;"><div style="display:flex;align-items:center;justify-content:space-between;padding:16px;border-bottom:1px solid #f0ede6;"><button type="button" class="' +
        navCls +
        '" data-dir="prev" style="background:#f5f3ed;border:none;cursor:pointer;width:44px;height:44px;border-radius:8px;display:flex;align-items:center;justify-content:center;font-size:16px;color:#444;">&laquo;</button><span class="' +
        titleCls +
        '" style="font-weight:700;font-size:15px;color:#1f1f1f;"></span><button type="button" class="' +
        navCls +
        '" data-dir="next" style="background:#f5f3ed;border:none;cursor:pointer;width:44px;height:44px;border-radius:8px;display:flex;align-items:center;justify-content:center;font-size:16px;color:#444;">&raquo;</button></div><div style="padding:12px 16px 8px;"><div style="display:grid;grid-template-columns:repeat(7,1fr);gap:2px;text-align:center;margin-bottom:8px;"><div style="font-size:10px;font-weight:800;color:#e8521e;padding:4px 0;">Su</div><div style="font-size:10px;font-weight:800;color:#aaa;padding:4px 0;">Mo</div><div style="font-size:10px;font-weight:800;color:#aaa;padding:4px 0;">Tu</div><div style="font-size:10px;font-weight:800;color:#aaa;padding:4px 0;">We</div><div style="font-size:10px;font-weight:800;color:#aaa;padding:4px 0;">Th</div><div style="font-size:10px;font-weight:800;color:#aaa;padding:4px 0;">Fr</div><div style="font-size:10px;font-weight:800;color:#e8521e;padding:4px 0;">Sa</div></div><div class="' +
        daysCls +
        '" style="display:grid;grid-template-columns:repeat(7,1fr);gap:2px;text-align:center;"></div></div><div style="padding:8px 16px 16px;text-align:center;"><button type="button" class="dpbs-suite-' +
        which +
        '-mm-close" style="background:#e8521e;color:#fff;border:none;padding:12px 32px;border-radius:8px;font-size:15px;font-weight:600;cursor:pointer;">Close</button></div></div></div>';
      var $modal = $(m).appendTo(scrollParent || this.container);
      if (which === "start") this._suiteStartMobileModal = $modal;
      else this._suiteEndMobileModal = $modal;
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
      $modal
        .find("." + titleCls)
        .text(monthNames[calDate.getMonth()] + " " + calDate.getFullYear());
      this._renderSuiteMobileDays(
        $modal.find("." + daysCls),
        calDate,
        selectedVal,
        minDate,
        maxDate,
      );

      $modal.on("click", "." + navCls, function (e) {
        e.preventDefault();
        e.stopPropagation();
        calDate.setMonth(
          calDate.getMonth() + ($(this).data("dir") === "prev" ? -1 : 1),
        );
        $modal
          .find("." + titleCls)
          .text(monthNames[calDate.getMonth()] + " " + calDate.getFullYear());
        var cMin = which === "end" ? self.els.suiteStartDate.val() : null;
        self._renderSuiteMobileDays(
          $modal.find("." + daysCls),
          calDate,
          which === "start"
            ? self.els.suiteStartDate.val()
            : self.els.suiteEndDate.val(),
          cMin,
          maxDate,
        );
      });

      $modal.on(
        "click",
        "." + daysCls + " .dpbs-mm-day:not(.dpbs-mm-disabled)",
        function (e) {
          e.preventDefault();
          e.stopPropagation();
          var d = $(this).data("date");
          if (which === "end") {
            if (
              self.els.suiteStartDate.val() &&
              d < self.els.suiteStartDate.val()
            ) {
              alert("End date cannot be before start date.");
              return;
            }
            self.els.suiteEndDate.val(d);
            self.closeSuiteEndCalendar();
          } else {
            self.els.suiteStartDate.val(d);
            if (!self.els.suiteEndDate.val() || self.els.suiteEndDate.val() < d)
              self.els.suiteEndDate.val(d);
            self.closeSuiteStartCalendar();
          }
          self.clearFormMessage();
          self.updateSuitePrice();
        },
      );

      $modal.on("click", ".dpbs-suite-" + which + "-mm-close", function (e) {
        e.preventDefault();
        e.stopPropagation();
        self[
          "closeSuite" + (which === "start" ? "Start" : "End") + "Calendar"
        ]();
      });
      $modal.on("click", function (e) {
        if ($(e.target).is("#" + modalId))
          self[
            "closeSuite" + (which === "start" ? "Start" : "End") + "Calendar"
          ]();
      });
    },

    closeSuiteStartCalendar: function () {
      this.suiteStartCalendarOpen = false;
      if (this._suiteStartMobileModal) {
        this._suiteStartMobileModal.remove();
        this._suiteStartMobileModal = null;
      }
      if (
        this._scrollParentModal &&
        !this.calendarOpen &&
        !this.suiteEndCalendarOpen
      ) {
        this._scrollParentModal.style.overflow =
          this._scrollParentModal._dpbs_old_overflow || "";
        this._scrollParentModal.scrollTop = this._savedScrollTopModal || 0;
        this._scrollParentModal = null;
      }
      this.els.suiteStartCalPopover.removeClass("is-open");
      if (this.els.suiteStartCalPopover[0])
        this.els.suiteStartCalPopover[0].style.removeProperty("display");
      $(document).off("click.dpbs-ssc-" + this.id);
      $(document).off("touchend.dpbs-ssc-" + this.id);
      if (this._suiteStartScrollParents) {
        $(this._suiteStartScrollParents).off("scroll.dpbs-ss-" + this.id);
        this._suiteStartScrollParents = null;
      }
    },

    closeSuiteEndCalendar: function () {
      this.suiteEndCalendarOpen = false;
      if (this._suiteEndMobileModal) {
        this._suiteEndMobileModal.remove();
        this._suiteEndMobileModal = null;
      }
      if (
        this._scrollParentModal &&
        !this.calendarOpen &&
        !this.suiteStartCalendarOpen
      ) {
        this._scrollParentModal.style.overflow =
          this._scrollParentModal._dpbs_old_overflow || "";
        this._scrollParentModal.scrollTop = this._savedScrollTopModal || 0;
        this._scrollParentModal = null;
      }
      this.els.suiteEndCalPopover.removeClass("is-open");
      if (this.els.suiteEndCalPopover[0])
        this.els.suiteEndCalPopover[0].style.removeProperty("display");
      $(document).off("click.dpbs-sec-" + this.id);
      $(document).off("touchend.dpbs-sec-" + this.id);
      if (this._suiteEndScrollParents) {
        $(this._suiteEndScrollParents).off("scroll.dpbs-se-" + this.id);
        this._suiteEndScrollParents = null;
      }
    },

    loadSuiteStartCalendar: function () {
      this._renderSuiteCalendar(
        this.els.suiteStartCalDays,
        this.els.suiteStartCalTitle,
        this.suiteStartCalDate,
        this.els.suiteStartDate.val(),
        null,
        null,
      );
    },
    loadSuiteEndCalendar: function () {
      var minDate = this.els.suiteStartDate.val(),
        maxDate = null;
      if (minDate) {
        var minParts = minDate.split("-");
        var minD = new Date(minParts[0], minParts[1] - 1, minParts[2]);
        var maxD = new Date(minD.getTime() + 30 * 24 * 60 * 60 * 1000);
        maxDate =
          maxD.getFullYear() +
          "-" +
          String(maxD.getMonth() + 1).padStart(2, "0") +
          "-" +
          String(maxD.getDate()).padStart(2, "0");
      }
      this._renderSuiteCalendar(
        this.els.suiteEndCalDays,
        this.els.suiteEndCalTitle,
        this.suiteEndCalDate,
        this.els.suiteEndDate.val(),
        minDate,
        maxDate,
      );
    },

    _renderSuiteCalendar: function (
      $daysContainer,
      $titleContainer,
      calDate,
      selectedDate,
      minDate,
      maxDate,
    ) {
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
      var month = calDate.getMonth(),
        year = calDate.getFullYear(),
        today = new Date();
      today.setHours(0, 0, 0, 0);
      $titleContainer.text(monthNames[month] + " " + year);
      var firstDay = new Date(year, month, 1).getDay(),
        daysInMonth = new Date(year, month + 1, 0).getDate(),
        html = "";
      for (var i = 0; i < firstDay; i++)
        html += '<div class="dpbs-cal-day is-empty"></div>';
      for (var d = 1; d <= daysInMonth; d++) {
        var dateStr =
          year +
          "-" +
          String(month + 1).padStart(2, "0") +
          "-" +
          String(d).padStart(2, "0");
        var classes = "dpbs-cal-day",
          dayOfWeek = new Date(year, month, d).getDay(),
          currentDate = new Date(year, month, d);
        if (dayOfWeek === 0 || dayOfWeek === 6) classes += " is-weekend";
        if (currentDate < today) classes += " is-disabled";
        if (minDate && dateStr < minDate) classes += " is-disabled";
        if (maxDate && dateStr > maxDate) classes += " is-disabled"; // 1-Month Max Restriction
        if (dateStr === selectedDate) classes += " is-selected";
        html +=
          '<div class="' +
          classes +
          '" data-date="' +
          dateStr +
          '">' +
          d +
          "</div>";
      }
      $daysContainer.html(html);
    },

    _renderSuiteMobileDays: function (
      $container,
      calDate,
      selectedDate,
      minDate,
      maxDate,
    ) {
      var month = calDate.getMonth(),
        year = calDate.getFullYear(),
        today = new Date();
      today.setHours(0, 0, 0, 0);
      var firstDay = new Date(year, month, 1).getDay(),
        daysInMonth = new Date(year, month + 1, 0).getDate(),
        html = "";
      for (var i = 0; i < firstDay; i++)
        html += '<div style="min-height:44px;"></div>';
      for (var d = 1; d <= daysInMonth; d++) {
        var dateStr =
          year +
          "-" +
          String(month + 1).padStart(2, "0") +
          "-" +
          String(d).padStart(2, "0");
        var dayOfWeek = new Date(year, month, d).getDay(),
          currentDate = new Date(year, month, d);
        var isDisabled =
          currentDate < today ||
          (minDate && dateStr < minDate) ||
          (maxDate && dateStr > maxDate); // 1-Month Max Restriction
        var isSelected = dateStr === selectedDate;
        var baseStyle =
            "min-height:44px;display:flex;align-items:center;justify-content:center;font-size:13px;border-radius:8px;cursor:pointer;position:relative;",
          finalStyle = "",
          cls = "dpbs-mm-day";
        if (isDisabled) {
          finalStyle =
            baseStyle +
            "color:#d4d0c8;cursor:not-allowed;text-decoration:line-through;";
          cls += " dpbs-mm-disabled";
        } else if (isSelected) {
          finalStyle =
            baseStyle +
            "background:#e8521e;color:#fff;font-weight:700;box-shadow:0 3px 10px rgba(232, 82, 30, 0.3);";
        } else if (dayOfWeek === 0 || dayOfWeek === 6)
          finalStyle = baseStyle + "color:#e8521e;";
        else finalStyle = baseStyle + "color:#2b2b2b;";
        html +=
          '<div class="' +
          cls +
          '" data-date="' +
          dateStr +
          '" style="' +
          finalStyle +
          '">' +
          d +
          "</div>";
      }
      $container.html(html);
    },

    _positionCal: function ($input, $popover) {
      if (!$input.length || !$input[0] || !$popover.length) return;
      var rect = $input[0].getBoundingClientRect(),
        pw = $popover.outerWidth() || 300,
        ph = $popover.outerHeight() || 360;
      var vw = window.innerWidth || document.documentElement.clientWidth,
        vh = window.innerHeight || document.documentElement.clientHeight;
      if (vw <= 768) {
        pw = Math.min(vw - 40, 320);
        $popover.css("width", pw + "px");
      }
      var left = rect.left;
      if (left + pw > vw - 10) left = vw - pw - 10;
      left = Math.max(10, left);
      var spaceBelow = vh - rect.bottom - 10,
        spaceAbove = rect.top - 10,
        top;
      if (ph <= spaceBelow) top = rect.bottom + 5;
      else if (ph <= spaceAbove) top = rect.top - ph - 5;
      else top = spaceBelow >= spaceAbove ? rect.bottom + 5 : rect.top - ph - 5;
      top = Math.max(10, Math.min(top, vh - 50));
      $popover.css({
        top: top + "px",
        left: left + "px",
        position: "fixed",
        "z-index": "99999998",
        transform: "none",
        "-webkit-transform": "none",
      });
    },

    // =============================================
    // FORM MESSAGES & SUBMISSIONS
    // =============================================
    showFormMessage: function (text, type) {
      this.els.formMessage.text(text).removeClass("is-error is-success");
      if (type === "error") this.els.formMessage.addClass("is-error");
      if (type === "success") this.els.formMessage.addClass("is-success");
    },
    clearFormMessage: function () {
      this.els.formMessage.text("").removeClass("is-error is-success");
    },

    handleFormSubmit: function () {
      var self = this;
      this.clearFormMessage();
      if (this.isSuiteMode()) {
        this.handleSuiteFormSubmit();
        return;
      }
      var service = this.els.service.val(),
        city = this.els.city.val(),
        location = this.els.location.val();
      var fullName = this.els.fullName.val().trim(),
        phone = this.els.phone.val().trim(),
        email = this.els.email.val().trim();
      var seats = parseInt(this.els.seats.val()) || 0,
        date = this.selectedDate,
        company = this.els.company.val().trim();
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
            // NEW: carry the price breakdown through so we can show it in
            // the confirmation message once payment is verified.
            self.bookingData.subtotal = res.subtotal;
            self.bookingData.tax_amount = res.tax_amount;
            self.bookingData.tax_label = res.tax_label;
            self.bookingData.tax_rate = res.tax_rate;
            self.bookingData.total = res.total;
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

    handleSuiteFormSubmit: function () {
      var self = this;
      var service = this.els.service.val(),
        city = this.els.city.val(),
        location = this.els.location.val();
      var fullName = this.els.fullName.val().trim(),
        phone = this.els.phone.val().trim(),
        email = this.els.email.val().trim();
      var seats = parseInt(this.els.seats.val()) || 0,
        company = this.els.company.val().trim();
      var startDate = this.els.suiteStartDate.val(),
        endDate = this.els.suiteEndDate.val();
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
        return;
      }
      if (!endDate) {
        this.showFormMessage("Please select an End Date.", "error");
        return;
      }
      if (endDate < startDate) {
        this.showFormMessage("End date cannot be before start date.", "error");
        return;
      }
      // Max 30 days validation
      var start = new Date(startDate + "T00:00:00"),
        end = new Date(endDate + "T00:00:00");
      var diffDays =
        Math.round(Math.abs(end - start) / (1000 * 60 * 60 * 24)) + 1;
      if (diffDays > 30) {
        this.showFormMessage(
          "Private suite bookings cannot exceed 30 days.",
          "error",
        );
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
      var self = this,
        options = {
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
            // NEW: build a confirmation message that includes the price
            // breakdown (subtotal + tax = total) when tax data is available.
            var bd = self.bookingData || {};
            var confirmMsg = "Booking confirmed!";
            if (bd.subtotal !== undefined && bd.total !== undefined) {
              confirmMsg += " Subtotal: ₹" + parseFloat(bd.subtotal).toFixed(2);
              if (bd.tax_amount && parseFloat(bd.tax_amount) > 0) {
                confirmMsg +=
                  " + " +
                  (bd.tax_label || "Tax") +
                  " (" +
                  bd.tax_rate +
                  "%): ₹" +
                  parseFloat(bd.tax_amount).toFixed(2);
              }
              confirmMsg +=
                " | Total Paid: ₹" + parseFloat(bd.total).toFixed(2);
            }
            self.showFormMessage(confirmMsg, "success");
            self.els.form[0].reset();
            self.selectedDate = "";
            self.els.date.val("");
            self.els.priceDisplay.text("0.00");
            self.updateTaxLine(0);
            self.els.seatsInfo.text("");
            self.els.calDays.html("");
            self.els.seats.find("option").prop("disabled", false);
            setTimeout(function () {
              self.loadCalendar();
            }, 1000);
            // NEW: refresh the page 5 seconds after a successful booking so
            // the customer lands on a fully reset form/calendar state.
            setTimeout(function () {
              window.location.reload();
            }, 5000);
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
      this.closeSuiteStartCalendar();
      this.closeSuiteEndCalendar();
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
        "dpbs-auto-" + Date.now() + "-" + Math.floor(Math.random() * 1e6),
      key = instanceId;
    if (instances[key] && instances[key].container[0] !== $container[0])
      key =
        instanceId + "-" + Date.now() + "-" + Math.floor(Math.random() * 1e6);
    instances[key] = new DPBSInstance($container, key);
  };

  function initAllForms() {
    $(".dpbs-booking-instance").each(function () {
      window.initDPBSForm(this);
    });
  }
  $(document).ready(initAllForms);
  $(window).on("load", function () {
    setTimeout(initAllForms, 100);
    setTimeout(initAllForms, 500);
    setTimeout(initAllForms, 1000);
  });

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

  $(window).on("resize orientationchange", function () {
    $.each(instances, function (id, instance) {
      if (instance.calendarOpen && !instance._useMobileModal)
        setTimeout(function () {
          instance.positionCalendar();
        }, 100);
      if (instance.suiteStartCalendarOpen)
        setTimeout(function () {
          instance._positionCal(
            instance.els.suiteStartDate,
            instance.els.suiteStartCalPopover,
          );
        }, 100);
      if (instance.suiteEndCalendarOpen)
        setTimeout(function () {
          instance._positionCal(
            instance.els.suiteEndDate,
            instance.els.suiteEndCalPopover,
          );
        }, 100);
    });
  });
})(jQuery);
