/**
 * City Location Filter — Front-End Logic
 * v2.0.0
 *  - Layout changed from carousel/grid to stacked Spaces / City / Location
 *    dropdowns + a Proceed button, per updated design.
 *  - Core data flow is unchanged:
 *      1) Spaces <select> change -> AJAX fetch of cities+locations for that
 *         service (same REST endpoint / same response shape as before).
 *      2) City <select> change -> populates the Location <select> from the
 *         already-fetched data (no extra request, same as the old
 *         city-card-click behaviour).
 *      3) Location <select> change -> arms the Proceed button.
 *      4) Proceed click -> navigates to the same URL the old area-card
 *         anchors used to point to (service permalink + ?location=slug).
 */
(function ($) {
  "use strict";

  var PLACEHOLDER_CITY = "— Select City —";
  var PLACEHOLDER_LOCATION = "— Select Location —";

  /* ======================================================================
       STATE HELPERS
       Each .city-container keeps its current "cities" payload (with
       nested locations) and service URL in a jQuery .data() store so the
       city -> location population never needs a network round-trip.
       ====================================================================== */
  function getState($wrapper) {
    return $wrapper.data("cfsState") || { cities: [], serviceUrl: "" };
  }

  function setState($wrapper, state) {
    $wrapper.data("cfsState", state);
  }

  function findCity(state, citySlug) {
    var found = null;
    $.each(state.cities, function (i, city) {
      if (city.slug === citySlug) {
        found = city;
        return false;
      }
    });
    return found;
  }

  function buildLocationUrl(serviceUrl, locationSlug) {
    if (!serviceUrl) return "#";
    var sep = serviceUrl.indexOf("?") === -1 ? "?" : "&";
    return serviceUrl + sep + "location=" + encodeURIComponent(locationSlug);
  }

  /* ======================================================================
       POPULATE: CITY SELECT (from a cities[] payload)
       ====================================================================== */
  function populateCitySelect($wrapper, cities) {
    var $city = $wrapper.find(".cfs-city-select");
    var $location = $wrapper.find(".cfs-location-select");
    var $proceed = $wrapper.find(".cfs-proceed-btn");

    $city.empty();

    if (!cities || !cities.length) {
      $city.append($("<option>", { value: "", text: "No cities available" }));
      $city.prop("disabled", true);
      resetLocationSelect($wrapper);
      return;
    }

    $city.append($("<option>", { value: "", text: PLACEHOLDER_CITY }));
    $.each(cities, function (i, city) {
      $city.append($("<option>", { value: city.slug, text: city.title }));
    });
    $city.prop("disabled", false);

    resetLocationSelect($wrapper);
    $proceed.prop("disabled", true);
  }

  /* ======================================================================
       POPULATE: LOCATION SELECT (from the chosen city's locations[])
       ====================================================================== */
  function populateLocationSelect($wrapper, locations) {
    var $location = $wrapper.find(".cfs-location-select");
    var $proceed = $wrapper.find(".cfs-proceed-btn");

    $location.empty();

    if (!locations || !locations.length) {
      $location.append(
        $("<option>", { value: "", text: "No locations available" }),
      );
      $location.prop("disabled", true);
      $proceed.prop("disabled", true);
      return;
    }

    $location.append($("<option>", { value: "", text: PLACEHOLDER_LOCATION }));
    $.each(locations, function (i, loc) {
      $location.append($("<option>", { value: loc.slug, text: loc.title }));
    });
    $location.prop("disabled", false);
    $proceed.prop("disabled", true);
  }

  function resetLocationSelect($wrapper) {
    var $location = $wrapper.find(".cfs-location-select");
    $location.empty();
    $location.append($("<option>", { value: "", text: PLACEHOLDER_LOCATION }));
    $location.prop("disabled", true);
  }

  /* ======================================================================
       1. CITY SELECT CHANGE
       ====================================================================== */
  $(document).on("change", ".cfs-city-select", function () {
    var $wrapper = $(this).closest(".city-container");
    var state = getState($wrapper);
    var citySlug = $(this).val();

    if (!citySlug) {
      resetLocationSelect($wrapper);
      $wrapper.find(".cfs-proceed-btn").prop("disabled", true);
      return;
    }

    var city = findCity(state, citySlug);
    populateLocationSelect($wrapper, city ? city.locations : []);
  });

  /* ======================================================================
       2. LOCATION SELECT CHANGE
       ====================================================================== */
  $(document).on("change", ".cfs-location-select", function () {
    var $wrapper = $(this).closest(".city-container");
    var $proceed = $wrapper.find(".cfs-proceed-btn");
    $proceed.prop("disabled", !$(this).val());
  });

  /* ======================================================================
       3. PROCEED CLICK
       ====================================================================== */
  $(document).on("click", ".cfs-proceed-btn", function () {
    var $btn = $(this);
    if ($btn.prop("disabled")) return;

    var $wrapper = $btn.closest(".city-container");
    var state = getState($wrapper);
    var citySlug = $wrapper.find(".cfs-city-select").val();
    var locationSlug = $wrapper.find(".cfs-location-select").val();

    if (!citySlug || !locationSlug) return;

    var city = findCity(state, citySlug);
    if (!city) return;

    var loc = null;
    $.each(city.locations, function (i, l) {
      if (l.slug === locationSlug) {
        loc = l;
        return false;
      }
    });
    if (!loc) return;

    window.location.href = buildLocationUrl(state.serviceUrl, loc.slug);
  });

  /* ======================================================================
       4. SERVICE DROPDOWN CHANGE — fetch filtered data via REST
       ====================================================================== */
  $(document).on("change", ".cfs-service-select", function () {
    var $select = $(this);
    var $wrapper = $("#" + $select.data("wrapper"));
    var serviceId = parseInt($select.val(), 10);

    var $empty = $wrapper.find(".cfs-no-results");
    var $loading = $wrapper.find(".cfs-loading");
    var $city = $wrapper.find(".cfs-city-select");
    var $proceed = $wrapper.find(".cfs-proceed-btn");

    if (!serviceId) {
      $wrapper.attr("data-service-id", "0").attr("data-service-slug", "");
      $empty.hide();
      $loading.hide();
      setState($wrapper, { cities: [], serviceUrl: "" });
      populateCitySelect($wrapper, []);
      $proceed.prop("disabled", true);
      if (window.history.replaceState) {
        window.history.replaceState(null, "", window.location.pathname);
      }
      return;
    }

    $empty.hide();
    $loading.show();
    $city.prop("disabled", true);
    $proceed.prop("disabled", true);

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

        setState($wrapper, {
          cities: response.cities || [],
          serviceUrl: response.service_url || "",
        });

        $loading.hide();

        if (!response.cities || response.cities.length === 0) {
          $empty
            .html(
              'No locations available for "<strong>' +
                response.service_title +
                '</strong>".',
            )
            .show();
          populateCitySelect($wrapper, []);
          return;
        }

        populateCitySelect($wrapper, response.cities);

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
        populateCitySelect($wrapper, []);
      },
    });
  });

  /* ======================================================================
       5. DOM READY — hydrate state from server-rendered bootstrap JSON
       ====================================================================== */
  $(document).ready(function () {
    $(".city-container[data-service-id]").each(function () {
      var $wrapper = $(this);
      var $dataScript = $wrapper.find(".cfs-bootstrap-data");
      var bootstrap = { cities: [], serviceUrl: "" };

      if ($dataScript.length) {
        try {
          var parsed = JSON.parse($dataScript.text());
          bootstrap = {
            cities: parsed.cities || [],
            serviceUrl: parsed.serviceUrl || "",
          };
        } catch (e) {
          bootstrap = { cities: [], serviceUrl: "" };
        }
      }

      setState($wrapper, bootstrap);

      // City/location selects are already server-rendered with correct
      // options and disabled states; just make sure Proceed starts locked.
      $wrapper.find(".cfs-proceed-btn").prop("disabled", true);
    });
  });
})(jQuery);
