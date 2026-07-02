/**
 * City Location Filter — Front-End Logic
 * v1.0.6
 *  - Removed city-name span (stamp image already has the name baked in)
 *  - Dynamic item count: caps visible items at actual city count (fixes 2-city stretch)
 *  - Destroys theme-initialised Owl instances before our own init (fixes loop duplication)
 *  - 400ms init delay to beat Elementor + theme Owl auto-init
 */
(function ($) {
  "use strict";

  /* ======================================================================
       CAROUSEL DEFAULTS — items set dynamically per initCarousel call
       ====================================================================== */
  function getCarouselOptions(itemCount) {
    // Never show more slots than we have cities — prevents stretching/looping artefacts
    var max1 = Math.min(itemCount, 1);
    var max2 = Math.min(itemCount, 2);
    var max4 = Math.min(itemCount, 4);

    return {
      loop: false, // NEVER loop — prevents ghost duplicate cards
      margin: 24,
      nav: itemCount > max4, // only show nav arrows if there are more cities than visible
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
       Fully tears down any existing Owl instance (including theme-initiated
       ones) before re-initialising with our settings.
       ====================================================================== */
  function initCarousel($el, itemCount, delay) {
    if (typeof $.fn.owlCarousel === "undefined") return;

    delay = delay || 0;
    itemCount = itemCount || $el.children(".item").length;

    function doInit() {
      // Hard-destroy any Owl instance on this element (ours OR theme's)
      if ($el.hasClass("owl-loaded")) {
        try {
          $el.trigger("destroy.owl.carousel");
        } catch (e) {}
        $el.removeClass(
          "owl-loaded owl-drag owl-hidden owl-grab owl-rtl owl-refresh owl-text-select-on",
        );
        // Remove Owl-injected wrappers but keep our .item elements
        var $items = $el.find(".item").detach();
        $el.empty().append($items);
      }

      $el.owlCarousel(getCarouselOptions(itemCount));
      // Trigger resize so Owl re-measures after any late layout paint
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
       2. CITY CARD CLICK — show matching area-grid, hide others
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
       3. SERVICE DROPDOWN CHANGE — fetch filtered data via REST
       ====================================================================== */
  $(document).on("change", ".cfs-service-select", function () {
    var $select = $(this);
    var $wrapper = $("#" + $select.data("wrapper"));
    var serviceId = parseInt($select.val(), 10);

    var $carousel = $wrapper.find(".worklocation-slider");
    var $areas = $wrapper.find(".cityfilter-areas");
    var $empty = $wrapper.find(".cfs-no-results");
    var $loading = $wrapper.find(".cfs-loading");
    var $hint = $wrapper.find(".cfs-city-hint");
    var $citiesWrap = $wrapper.find(".cfs-cities-wrap");

    if (!serviceId) {
      $wrapper.attr("data-service-id", "0").attr("data-service-slug", "");
      $empty.hide();
      $loading.hide();
      $hint.hide();
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
    $loading.show();
    // Fade out cities section during fetch to prevent layout flicker
    $citiesWrap.css({ opacity: 0, transition: "opacity 0.15s ease" });

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
          return;
        }

        var carouselHTML = "";
        var areasHTML = "";
        var cityCount = response.cities.length;

        $.each(response.cities, function (idx, city) {
          // No city-name span — the stamp image already contains the city name
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

        // 150ms delay: AJAX path has no theme Owl race, just needs paint
        initCarousel($carousel, cityCount, 150);
        $loading.hide();
        $hint.show();
        // Fade back in after carousel is initialised
        setTimeout(function () {
          $citiesWrap.css({ opacity: 1 });
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
        $empty.text("Error loading locations. Please try again.").show();
      },
    });
  });

  /* ======================================================================
       4. DOM READY — init server-side-rendered carousels
       400ms delay beats Elementor's own Owl auto-init which runs at ~300ms
       ====================================================================== */
  $(document).ready(function () {
    $(".city-container[data-service-id]").each(function () {
      var sid = parseInt($(this).attr("data-service-id"), 10);
      var $this = $(this);

      if (sid > 0) {
        var $carousel = $this.find(".worklocation-slider");
        var itemCount = $carousel.children(".item").length;

        if (itemCount > 0) {
          initCarousel($carousel, itemCount, 400);
          $this.find(".cfs-city-hint").show();
        }
      }
    });
  });
})(jQuery);
