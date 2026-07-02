/**
 * DPBS Frontend - Multi-Instance Support
 * Each form instance has its own isolated state
 */
(function ($) {
  "use strict";

  // Store instances by their ID
  var instances = {};

  /**
   * DPBS Instance Class - Isolated state per form
   */
  function DPBSInstance(container) {
    this.container = container;
    this.id = container.data("instance-id");
    this.calDate = new Date();
    this.selectedDate = "";
    this.bookingData = null;
    this.initialized = false;
    this.calendarOpen = false;

    // Cache jQuery elements
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

      // Service change - re-filter City (and consequently Location) to only
      // those that actually offer the selected service
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

      // Seats change (dropdown)
      this.els.seats.on("change", function () {
        self.updateSeatsInfo();
      });

      // Date field click
      this.els.date.on("click", function (e) {
        e.preventDefault();
        e.stopPropagation();
        self.toggleCalendar();
      });

      // Calendar navigation (delegated)
      this.container.on("click", ".dpbs-cal-nav", function (e) {
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

      // Calendar day click (delegated)
      this.container.on(
        "click",
        ".dpbs-cal-day:not(.is-empty):not(.is-disabled)",
        function (e) {
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

      // Form submission
      this.els.form.on("submit", function (e) {
        e.preventDefault();
        self.handleFormSubmit();
      });
    },

    // =============================================
    // PRESELECTED VALUES (FIXED - reads from data attributes)
    // =============================================

    loadPreselected: function () {
      var self = this;

      // Read from instance data attributes (not global dpbs_obj which gets overwritten)
      var preService = this.container.data("pre-service") || "";
      var preCity = this.container.data("pre-city") || "";
      var preLocation = this.container.data("pre-location") || "";

      // Step 1: Set service FIRST (synchronous)
      if (preService) {
        this.els.service.val(preService);
      }

      // Step 2: Set city and load locations (async)
      if (preCity) {
        this.els.city.val(preCity);
        this.loadLocations(function () {
          // Step 3: Set location AFTER locations are loaded
          if (preLocation) {
            self.els.location.val(preLocation);
          }
          // Step 4: Now both service and location should be set - update price & calendar
          self.updatePrice();
          self.loadCalendar();
        });
      } else if (preService && this.els.location.val()) {
        // Edge case: service pre-selected and location already has value
        self.updatePrice();
        self.loadCalendar();
      }
    },

    // =============================================
    // VALIDATION HELPERS
    // =============================================

    isValidEmail: function (email) {
      var regex = /^[a-zA-Z0-9._%+\-]+@[a-zA-Z0-9.\-]+\.[a-zA-Z]{2,}$/;
      return regex.test(email);
    },

    isValidIndianPhone: function (phone) {
      var cleaned = phone.replace(/[\s\-\+\(\)]/g, "");
      var regex = /^[6-9]\d{9}$/;
      return regex.test(cleaned);
    },

    // =============================================
    // CITIES (filtered by selected Service)
    // =============================================

    loadCitiesForService: function (callback) {
      var self = this;
      var serviceId = this.els.service.val();
      var currentCity = this.els.city.val();

      if (!serviceId) {
        // No service selected yet - nothing to filter by, just clear location
        this.els.location.html('<option value="">Select Location</option>');
        if (typeof callback === "function") callback();
        return;
      }

      $.post(
        dpbs_obj.ajax_url,
        {
          action: "dpbs_get_cities_for_service",
          service_id: serviceId,
        },
        function (response) {
          self.els.city.html(response);

          // Keep the previously selected city if it's still a valid option
          // for this service, otherwise reset city + location
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
        {
          action: "dpbs_get_price",
          service_id: sid,
          location_id: lid,
        },
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
    // SEATS INFO (UPDATED for dropdown)
    // =============================================

    updateSeatsInfo: function () {
      if (!this.selectedDate) {
        this.els.seatsInfo.text("");
        // Re-enable all options when no date selected
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

          // Disable options that exceed available seats
          this.els.seats.find("option").each(function () {
            var optVal = parseInt($(this).val());
            if (optVal > avail) {
              $(this).prop("disabled", true);
            } else {
              $(this).prop("disabled", false);
            }
          });

          // If current selection is now disabled, select the max available
          if (currentVal > avail) {
            this.els.seats.val(avail);
          }
        } else {
          // No seats available - disable all
          this.els.seats.find("option").prop("disabled", true);
        }
      } else {
        this.els.seatsInfo.text("");
        // Re-enable all options
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
        this.showFormMessage(
          "Please select a Service and Location first.",
          "error",
        );
        return;
      }

      if (this.calendarOpen) {
        this.closeCalendar();
      } else {
        this.openCalendar();
      }
    },

    openCalendar: function () {
      var self = this;

      this.calendarOpen = true;
      this.els.calPopover.addClass("is-open");
      this.positionCalendar();

      if (this.els.calDays.children().length === 0) {
        this.loadCalendar();
      }

      $(document).off("click.dpbs-" + this.id);

      $(document).on("click.dpbs-" + this.id, function (e) {
        if (
          !$(e.target).closest(self.els.dateField).length &&
          !$(e.target).closest(self.els.calPopover).length
        ) {
          self.closeCalendar();
        }
      });
    },

    closeCalendar: function () {
      this.calendarOpen = false;
      this.els.calPopover.removeClass("is-open");
      $(document).off("click.dpbs-" + this.id);
    },

    positionCalendar: function () {
      var dateInput = this.els.date;
      var popover = this.els.calPopover;
      var offset = dateInput.offset();

      popover.css({
        top: offset.top + dateInput.outerHeight() + 5 + "px",
        left: Math.min(offset.left, $(window).width() - 320) + "px",
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
              self.positionCalendar();
            }
          }
        },
      );
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

      // Validation
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
        this.showFormMessage(
          "Please enter your Full Name (min 2 characters).",
          "error",
        );
        this.els.fullName.focus();
        return;
      }
      if (!phone) {
        this.showFormMessage("Please enter your Phone Number.", "error");
        this.els.phone.focus();
        return;
      }
      if (!this.isValidIndianPhone(phone)) {
        this.showFormMessage(
          "Enter a valid 10-digit Indian phone number (e.g. 9876543210).",
          "error",
        );
        this.els.phone.focus();
        return;
      }
      if (!email) {
        this.showFormMessage("Please enter your Email Address.", "error");
        this.els.email.focus();
        return;
      }
      if (!this.isValidEmail(email)) {
        this.showFormMessage(
          "Please enter a valid Email Address (e.g. you@example.com).",
          "error",
        );
        this.els.email.focus();
        return;
      }
      if (!date) {
        this.showFormMessage(
          "Please select a Date from the calendar.",
          "error",
        );
        return;
      }
      if (seats < 1) {
        this.showFormMessage("Please select at least 1 seat.", "error");
        this.els.seats.focus();
        return;
      }

      // Check seat availability
      var dayEl = this.els.calDays.find(
        '.dpbs-cal-day[data-date="' + date + '"]',
      );
      if (dayEl.length) {
        var availSeats = parseInt(dayEl.data("seats"));
        if (isNaN(availSeats) || availSeats < 1) {
          this.showFormMessage(
            "This date is fully booked. Please choose another date.",
            "error",
          );
          return;
        }
        if (seats > availSeats) {
          this.showFormMessage(
            "Only " +
              availSeats +
              " seat" +
              (availSeats !== 1 ? "s" : "") +
              " available for this date.",
            "error",
          );
          return;
        }
      }

      // Process
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
        theme: {
          color: "#e8521e",
        },
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
            self.showFormMessage("Booking confirmed successfully!", "success");
            self.els.form[0].reset();
            self.selectedDate = "";
            self.els.date.val("");
            self.els.priceDisplay.text("0.00");
            self.els.seatsInfo.text("");
            self.els.calDays.html("");
            // Re-enable all seat options after reset
            self.els.seats.find("option").prop("disabled", false);
            setTimeout(function () {
              self.loadCalendar();
            }, 1000);
          } else {
            self.showFormMessage(
              "Payment verification failed. Please contact support with your payment ID: " +
                paymentId,
              "error",
            );
          }
          self.els.submitBtn.prop("disabled", false);
        },
      ).fail(function () {
        self.showFormMessage(
          "Verification request failed. Please contact support.",
          "error",
        );
        self.els.submitBtn.prop("disabled", false);
      });
    },
  };

  // =============================================
  // GLOBAL INITIALIZATION
  // =============================================

  window.initDPBSForm = function (container) {
    if (!container) return;

    var $container = $(container);
    var instanceId = $container.data("instance-id");

    if (instances[instanceId]) return;

    instances[instanceId] = new DPBSInstance($container);
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
    setTimeout(initAllForms, 200);
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
      if (shouldInit) {
        initAllForms();
      }
    });

    $(document).ready(function () {
      observer.observe(document.body, {
        childList: true,
        subtree: true,
      });
    });
  }

  // Reposition open calendars on scroll/resize
  $(window).on("scroll resize", function () {
    $.each(instances, function (id, instance) {
      if (instance.calendarOpen) {
        instance.positionCalendar();
      }
    });
  });
})(jQuery);