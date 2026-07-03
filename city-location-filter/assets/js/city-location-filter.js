/**
 * City Location Filter — Front-End Logic
 * v1.0.7
 *  - Fixed FOUC: .cfs-cities-wrap is hidden by CSS, revealed after carousel init
 *  - Added .cfs-init-loader handling for initial page load
 *  - Dynamic item count: caps visible items at actual city count
 *  - Destroys theme-initialised Owl instances before our own init
 */
(function ($) {
  "use strict";

  /* ======================================================================
       CAROUSEL DEFAULTS
       ====================================================================== */
  function getCarouselOptions(itemCount) {
    var max1 = Math.min(itemCount, 1);
    var max2 = Math.min(itemCount, 2);
    var max4 = Math.min(itemCount, 4);

    return {
      loop: false,
      margin: 24,
      nav: itemCount > max4,
      dots: itemCount > 1,
      autoplay: false,
      responsive: {
        0: { items: max1 },
        600: { items: max2 },
        1024: { items: max4 },
      },
    };
  }

  /* ======================================================================
       1. INIT CAROUSEL
       ====================================================================== */
  function initCarousel($el, itemCount, delay) {
    if (typeof $.fn.owlCarousel === "undefined") return;

    delay = delay || 0;
    itemCount = itemCount || $el.children(".item").length;

    function doInit() {
      // Destroy any existing Owl instance
      if ($el.hasClass("owl-loaded")) {
        try {
          $el.trigger("destroy.owl.carousel");
        } catch (e) {}
        $el.removeClass(
          "owl-loaded owl-drag owl-hidden owl-grab owl-rtl owl-refresh owl-text-select-on",
        );
        var $items = $el.find(".item").detach();
        $el.empty().append($items);
      }

      $el.owlCarousel(getCarouselOptions(itemCount));

      // Trigger resize so Owl re-measures
      setTimeout(function () {
        $(window).trigger("resize.owl.carousel");
      }, 50);
    }

    if (delay > 0) {
      setTimeout(doInit, delay);
    } else {
      doInit();
    }
  }

  /* ======================================================================
       2. REVEAL CITIES WRAPPER — called after carousel is ready
       ====================================================================== */
  function revealCities($wrapper) {
    var $citiesWrap = $wrapper.find(".cfs-cities-wrap");
    var $initLoader = $wrapper.find(".cfs-init-loader");

    // Hide the initial loader first
    $initLoader.hide();

    // Small delay to ensure carousel has painted, then reveal
    setTimeout(function () {
      $citiesWrap.addClass("is-ready");
    }, 50);
  }

  /* ======================================================================
       3. CITY CARD CLICK
       ====================================================================== */
  $(document).on("click", ".city-card", function (e) {
    e.preventDefault();

    var $wrapper = $(this).closest(".city-container");
    var citySlug = $(this).data("city");
    var $areas = $wrapper.find(".cityfilter-areas");
    var $hint = $wrapper.find(".cfs-city-hint");

    $wrapper.find(".city-card").removeClass("active");
    $(this).addClass("active");

    $wrapper.find(".area-grid").removeClass("active-grid");
    var $target = $wrapper.find(".area-grid#" + citySlug);
    if ($target.length) {
      $target.addClass("active-grid");
      $areas.addClass("areas-visible");
      $hint.hide();
    }
  });

  /* ======================================================================
       4. SERVICE DROPDOWN CHANGE — fetch filtered data via REST
       ====================================================================== */
  $(document).on("change", ".cfs-service-select", function () {
    var $select = $(this);
    var $wrapper = $("#" + $select.data("wrapper"));
    var serviceId = parseInt($select.val(), 10);

    var $carousel = $wrapper.find(".worklocation-slider");
    var $areas = $wrapper.find(".cityfilter-areas");
    var $empty = $wrapper.find(".cfs-no-results");
    var $loading = $wrapper.find(".cfs-loading:not(.cfs-init-loader)");
    var $hint = $wrapper.find(".cfs-city-hint");
    var $citiesWrap = $wrapper.find(".cfs-cities-wrap");
    var $initLoader = $wrapper.find(".cfs-init-loader");

    if (!serviceId) {
      $wrapper.attr("data-service-id", "0").attr("data-service-slug", "");
      $empty.hide();
      $loading.hide();
      $initLoader.hide();
      $hint.hide();
      $citiesWrap.removeClass("is-ready").hide();
      if ($carousel.hasClass("owl-loaded")) {
        try {
          $carousel.trigger("destroy.owl.carousel");
        } catch (e) {}
        $carousel.removeClass("owl-loaded owl-drag");
      }
      $carousel.html("");
      $areas.html("").removeClass("areas-visible");
      if (window.history.replaceState) {
        window.history.replaceState(null, "", window.location.pathname);
      }
      return;
    }

    $empty.hide();
    $hint.hide();
    $initLoader.hide();
    $loading.show();

    // Hide cities wrapper during fetch
    $citiesWrap.removeClass("is-ready");

    $.ajax({
      url: cfsConfig.restBase,
      method: "GET",
      data: { service_id: serviceId },
      beforeSend: function (xhr) {
        if (cfsConfig.restNonce) {
          xhr.setRequestHeader("X-WP-Nonce", cfsConfig.restNonce);
        }
      },
      success: function (response) {
        $wrapper.attr("data-service-id", response.service_id);
        $wrapper.attr("data-service-slug", response.service_slug);

        if ($carousel.hasClass("owl-loaded")) {
          try {
            $carousel.trigger("destroy.owl.carousel");
          } catch (e) {}
          $carousel.removeClass("owl-loaded owl-drag");
        }
        $carousel.html("");
        $areas.html("").removeClass("areas-visible");

        if (!response.cities || response.cities.length === 0) {
          $empty
            .html(
              'No locations available for "<strong>' +
                response.service_title +
                '</strong>".',
            )
            .show();
          $loading.hide();
          $citiesWrap.removeClass("is-ready").hide();
          return;
        }

        // Make sure the wrapper is visible but still at opacity 0
        $citiesWrap.show();

        var carouselHTML = "";
        var areasHTML = "";
        var cityCount = response.cities.length;

        $.each(response.cities, function (idx, city) {
          var imgTag = city.image
            ? '<img src="' + city.image + '" alt="' + city.title + '">'
            : "";

          carouselHTML +=
            '<div class="item">' +
            '<div class="worklocation-card">' +
            '<a href="javascript:void(0)" class="city-card" data-city="' +
            city.slug +
            '">' +
            imgTag +
            "</a>" +
            "</div>" +
            "</div>";

          areasHTML += '<div class="area-grid" id="' + city.slug + '">';
          $.each(city.locations, function (j, loc) {
            var locImg = loc.image
              ? '<img src="' +
                loc.image +
                '" alt="' +
                loc.title +
                '" loading="lazy">'
              : "";
            var locURL = response.service_url + "?location=" + loc.slug;

            areasHTML +=
              '<a href="' +
              locURL +
              '" class="area-card" title="' +
              loc.title +
              '">' +
              locImg +
              '<span class="area-name">' +
              loc.title +
              "</span>" +
              "</a>";
          });
          areasHTML += "</div>";
        });

        $carousel.html(carouselHTML);
        $areas.html(areasHTML);

        // Init carousel then reveal
        initCarousel($carousel, cityCount, 150);
        $loading.hide();
        $hint.show();

        // Reveal after a short delay for paint
        setTimeout(function () {
          $citiesWrap.addClass("is-ready");
        }, 200);

        if (window.history.replaceState) {
          var newURL =
            window.location.pathname +
            "?servicetype=" +
            response.service_slug +
            "/";
          window.history.replaceState(null, "", newURL);
        }
      },
      error: function () {
        $loading.hide();
        $citiesWrap.removeClass("is-ready").hide();
        $empty.text("Error loading locations. Please try again.").show();
      },
    });
  });

  /* ======================================================================
       5. DOM READY — init server-side-rendered carousels
       ====================================================================== */
  $(document).ready(function () {
    $(".city-container[data-service-id]").each(function () {
      var sid = parseInt($(this).attr("data-service-id"), 10);
      var $this = $(this);

      if (sid > 0) {
        var $carousel = $this.find(".worklocation-slider");
        var itemCount = $carousel.children(".item").length;

        if (itemCount > 0) {
          // Init carousel with delay to beat theme's Owl auto-init
          initCarousel($carousel, itemCount, 400);

          // Reveal everything after carousel is ready (400ms init + 100ms buffer)
          setTimeout(function () {
            revealCities($this);
            $this.find(".cfs-city-hint").show();
          }, 550);
        } else {
          // No items — hide loader, show nothing
          $this.find(".cfs-init-loader").hide();
        }
      } else {
        // No service selected — hide loader
        $this.find(".cfs-init-loader").hide();
      }
    });
  });
})(jQuery);
