/**
 * DPBS Frontend - Multi-Instance Support
 * FIXED: Calendar close issue + Mobile calendar visibility
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
      calNav: container.find(".dpbs-cal-nav"),
      priceDisplay: container.find('[id$="-price-display"]'),
      formMessage: container.find(".dpbs-form-message"),
      submitBtn: container.find(".dpbs-submit-btn"),
    };

    this.init();
  }

  DPBSInstance.prototype = {
    init: function () {
      if (this.initialized) return;
      this.initialized = true;

      // Move calendar to body to escape any transform/overflow containment
      if (this.els.calPopover.length) {
        this.els.calPopover.appendTo(document.body);
      }

      this.bindEvents();
      this.loadPreselected();
    },

    bindEvents: function () {
      var self = this;

      // City change
      this.els.city.on("change", function () {
        self.selectedDate = "";
        self.els.date.val("");
        self.loadLocations(function () {
          self.updatePrice();
          self.loadCalendar();
        });
      });

      // Service change
      this.els.service.on("change", function () {
        self.selectedDate = "";
        self.els.date.val("");
        self.loadCitiesForService(function () {
          self.loadLocations(function () {
            self.updatePrice();
            self.loadCalendar();
          });
        });
      });

      // Location change
      this.els.location.on("change", function () {
        self.selectedDate = "";
        self.els.date.val("");
        self.updatePrice();
        self.loadCalendar();
      });

      // Seats change
      this.els.seats.on("change", function () {
        self.updateSeatsInfo();
      });

      // ============================================
      // FIX: Simplified date field click/touch handling
      // Only bind to the date INPUT, not the wrapper
      // Use a flag to prevent double-fire from touch+click
      // ============================================
      this.els.date.on("click.dpbs-date touchend.dpbs-date", function (e) {
        // If touchend just fired, skip the subsequent click
        if (e.type === "click" && self._touchHandled) {
          return;
        }

        // Mark that touchend fired
        if (e.type === "touchend") {
          self._touchHandled = true;
          setTimeout(function () {
            self._touchHandled = false;
          }, 400);
        }

        e.preventDefault();
        e.stopPropagation();
        self.toggleCalendar();
      });

      // Calendar navigation (popover is now in body, so delegate from it)
      this.els.calPopover.on("click", ".dpbs-cal-nav", function (e) {
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

      // ============================================
      // FIX: Calendar day selection - handle both click and touchend
      // with proper double-fire prevention
      // ============================================
      this.els.calPopover.on(
        "click.dpbs-day touchend.dpbs-day",
        ".dpbs-cal-day:not(.is-empty):not(.is-disabled)",
        function (e) {
          // Prevent double-fire
          if (e.type === "click" && self._touchHandled) {
            return;
          }
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

          // Close calendar immediately
          self.closeCalendar();

          self.updateSeatsInfo();
          self.clearFormMessage();
        },
      );

      // Form submission
      this.els.form.on("submit", function (e) {
        e.preventDefault();
        self.handleFormSubmit();
      });
    },

    // =============================================
    // PRESELECTED VALUES
    // =============================================

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

    // =============================================
    // VALIDATION HELPERS
    // =============================================

    isValidEmail: function (email) {
      return /^[a-zA-Z0-9._%+\-]+@[a-zA-Z0-9.\-]+\.[a-zA-Z]{2,}$/.test(email);
    },

    isValidIndianPhone: function (phone) {
      return /^[6-9]\d{9}$/.test(phone.replace(/[\s\-\+\(\)]/g, ""));
    },

    // =============================================
    // CITIES
    // =============================================

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

    // =============================================
    // LOCATIONS
    // =============================================

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

    // =============================================
    // PRICE
    // =============================================

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

    // =============================================
    // SEATS INFO
    // =============================================

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

    // =============================================
    // CALENDAR - Toggle
    // =============================================

    toggleCalendar: function () {
      var sid = this.els.service.val();
      var lid = this.els.location.val();

      if (!sid || !lid) {
        var errorMsg = "Please select a Service and Location first.";
        this.showFormMessage(errorMsg, "error");
        // Alert fallback for header placements where message might be hidden
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
      var self = this;

      this.calendarOpen = true;

      // Set z-index and clear any transforms that could cause issues
      this.els.calPopover.css({
        "z-index": "99999999",
        transform: "none",
        "-webkit-transform": "none",
      });

      // Add the class that makes it visible (CSS controls display)
      this.els.calPopover.addClass("is-open");

      // Position after it's visible so we can measure it
      this.positionCalendar();

      if (this.els.calDays.children().length === 0) {
        this.loadCalendar();
      }

      // Close on outside click
      $(document).off(
        "click.dpbs-close-" + this.id + " touchend.dpbs-close-" + this.id,
      );
      $(document).on(
        "click.dpbs-close-" + this.id + " touchend.dpbs-close-" + this.id,
        function (e) {
          var $target = $(e.target);
          // Skip if touch was just handled
          if (e.type === "click" && self._touchHandled) return;

          var clickedOutside =
            !$target.closest(self.els.dateField).length &&
            !$target.closest(self.els.calPopover).length;

          if (clickedOutside) {
            self.closeCalendar();
          }
        },
      );

      // Reposition on scroll
      this._scrollParents = this.getScrollParents(this.els.dateField[0]);
      $(this._scrollParents).off("scroll.dpbs-" + this.id);
      $(this._scrollParents).on("scroll.dpbs-" + this.id, function () {
        if (self.calendarOpen) {
          self.positionCalendar();
        }
      });
    },

    closeCalendar: function () {
      this.calendarOpen = false;

      // Remove the visibility class - this is what hides it via CSS
      this.els.calPopover.removeClass("is-open");

      // Clean up event listeners
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
      if (viewportWidth <= 600) {
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
        // Fits below
        top = rect.bottom + 5;
      } else if (popoverHeight <= spaceAbove) {
        // Fits above
        top = rect.top - popoverHeight - 5;
      } else {
        // Doesn't fit either way - use larger space
        top =
          spaceBelow >= spaceAbove
            ? rect.bottom + 5
            : rect.top - popoverHeight - 5;
      }

      // Final clamp
      top = Math.max(10, Math.min(top, viewportHeight - 50));

      // ============================================
      // FIX: Do NOT set display: block here!
      // The CSS class .is-open controls visibility.
      // Setting inline display overrides the class removal.
      // ============================================
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

      if (!sid || !lid) {
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
            if (self.calendarOpen) {
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
        html += '<div class="cwf-cal-day is-empty"></div>';
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
        var classes = "cwf-cal-day dpbs-cal-day";
        var dotHtml = "";
        var dayOfWeek = new Date(year, month, d).getDay();

        if (dayOfWeek === 6) classes += " is-weekend";
        if (dayData.status === "past") classes += " is-disabled";
        if (dayData.status === "full") {
          classes += " is-disabled";
          dotHtml = '<span class="cwf-dot cwf-dot-full"></span>';
        }
        if (dayData.status === "limited") {
          dotHtml = '<span class="cwf-dot cwf-dot-limited"></span>';
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

    // Handle cloned elements with same instance-id
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
      if (instance.calendarOpen) {
        setTimeout(function () {
          instance.positionCalendar();
        }, 100);
      }
    });
  });
})(jQuery);
