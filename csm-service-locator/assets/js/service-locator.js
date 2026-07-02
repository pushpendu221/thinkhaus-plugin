/**
 * CSM Service Locator — front end.
 *
 * Flow:
 *   1. Pick a City      -> populates Location dropdown
 *                           AND immediately loads Services for that City alone.
 *   2. Pick a Location  -> reloads Services, now filtered by City + Location.
 *   3. Click a Service  -> loads service-detail listings filtered by
 *                           City + Location (if set) + Service, rendered
 *                           in the same card style.
 */
(function () {
  "use strict";

  var config = window.csmLocatorData || {};
  var restUrl = config.restUrl;
  var i18n = config.i18n || {};

  document.addEventListener("DOMContentLoaded", function () {
    var root = document.getElementById("csm-locator");
    if (!root || !restUrl) {
      return;
    }

    var citySelect = document.getElementById("csm-city-select");
    var locationSelect = document.getElementById("csm-location-select");
    var loader = document.getElementById("csm-loader");
    var loaderText = loader.querySelector(".csm-loader-text");
    var emptyState = document.getElementById("csm-services-empty");
    var servicesEl = document.getElementById("csm-services-results");
    var detailsWrap = document.getElementById("csm-service-details-wrap");
    var detailsHeading = document.getElementById("csm-detail-heading");
    var detailsEl = document.getElementById("csm-service-details-results");
    var backToServicesBtn = document.getElementById("csm-back-to-services");

    var serviceOwlRef = { active: false };
    var detailOwlRef = { active: false };

    var locationRequest = null;
    var serviceRequest = null;
    var detailRequest = null;

    var selectedServiceId = null;

    /* ---------- small utilities ---------- */

    function showLoader(text) {
      loaderText.textContent = text || i18n.loading || "Loading…";
      loader.hidden = false;
    }

    function hideLoader() {
      loader.hidden = true;
    }

    function escapeHtml(value) {
      var div = document.createElement("div");
      div.textContent =
        value === null || value === undefined ? "" : String(value);
      return div.innerHTML;
    }

    function jq() {
      return window.jQuery;
    }

    function destroySlider(container, ref) {
      if (ref.active && jq()) {
        jq()(container).trigger("destroy.owl.carousel");
        jq()(container).removeClass("owl-loaded owl-drag");
      }
      ref.active = false;
    }

    function initSlider(container, ref) {
      if (!jq() || !jq().fn.owlCarousel) {
        return;
      }
      jq()(container).owlCarousel({
        loop: false,
        margin: 20,
        nav: true,
        dots: true,
        slideBy: 1,
        navText: [
          '<i class="fa-solid fa-arrow-left"></i>',
          '<i class="fa-solid fa-arrow-right"></i>',
        ],
        responsive: {
          0: { items: 1 },
          768: { items: 2.5 },
          1200: { items: 3.5 },
        },
      });
      ref.active = true;
    }

    function renderSlider(container, ref, items, renderFn) {
      destroySlider(container, ref);
      container.innerHTML = "";
      items.forEach(function (entry) {
        container.appendChild(renderFn(entry));
      });
      container.hidden = items.length === 0;
      if (items.length) {
        initSlider(container, ref);
      }
    }

    /* ---------- card templates ---------- */

    function renderServiceCard(service) {
      var item = document.createElement("div");
      item.className = "item";

      var isSelected = String(service.id) === String(selectedServiceId);

      item.innerHTML =
        '<div class="workspace-card csm-service-card' +
        (isSelected ? " is-selected" : "") +
        '" data-service-id="' +
        escapeHtml(service.id) +
        '" role="button" tabindex="0">' +
        '<a class="card-arrow" href="' +
        escapeHtml(service.permalink || "#") +
        '"><i class="fa-solid fa-arrow-right"></i></a>' +
        '<div class="card-content">' +
        "<h3>" +
        escapeHtml(service.title) +
        "</h3>" +
        "<p>" +
        escapeHtml(service.excerpt) +
        "</p>" +
        (service.price
          ? '<div class="price">Starting at ' +
            escapeHtml(service.price) +
            "</div>"
          : "") +
        "</div>" +
        '<div class="card-image">' +
        (service.image
          ? '<img src="' +
            escapeHtml(service.image) +
            '" alt="' +
            escapeHtml(service.title) +
            '">'
          : "") +
        "</div>" +
        "</div>";

      var card = item.querySelector(".csm-service-card");
      var arrow = item.querySelector(".card-arrow");

      arrow.addEventListener("click", function (e) {
        e.stopPropagation();
      });

      function selectThisService() {
        selectedServiceId = service.id;
        var allCards = servicesEl.querySelectorAll(".csm-service-card");
        for (var i = 0; i < allCards.length; i++) {
          allCards[i].classList.remove("is-selected");
        }
        card.classList.add("is-selected");
        servicesEl.hidden = true;
        loadServiceDetails(service);
      }

      card.addEventListener("click", selectThisService);
      card.addEventListener("keydown", function (e) {
        if (e.key === "Enter" || e.key === " ") {
          e.preventDefault();
          selectThisService();
        }
      });

      return item;
    }

    function renderDetailCard(detail) {
      var fields = detail.fields || {};
      var address = fields.location_address
        ? fields.location_address.value
        : "";
      var hours = fields.hours_of_operation
        ? fields.hours_of_operation.value
        : "";

      var item = document.createElement("div");
      item.className = "item";

      item.innerHTML =
        '<div class="workspace-card csm-detail-card">' +
        '<a class="card-arrow" href="' +
        escapeHtml(detail.permalink || "#") +
        '"><i class="fa-solid fa-arrow-right"></i></a>' +
        '<div class="card-content">' +
        "<h3>" +
        escapeHtml(detail.title) +
        "</h3>" +
        (address ? "<p>" + escapeHtml(address) + "</p>" : "") +
        (hours
          ? '<p class="csm-hours"><i class="fa-regular fa-clock"></i> ' +
            escapeHtml(hours) +
            "</p>"
          : "") +
        (detail.price
          ? '<div class="price">' + escapeHtml(detail.price) + "</div>"
          : "") +
        "</div>" +
        '<div class="card-image">' +
        (detail.image
          ? '<img src="' +
            escapeHtml(detail.image) +
            '" alt="' +
            escapeHtml(detail.title) +
            '">'
          : "") +
        "</div>" +
        "</div>";

      return item;
    }

    /* ---------- data loading ---------- */

    function loadLocations(cityId) {
      locationSelect.innerHTML =
        '<option value="">' + (i18n.loading || "Loading…") + "</option>";
      locationSelect.disabled = true;

      if (locationRequest) {
        locationRequest.abort();
      }
      locationRequest = new AbortController();

      fetch(restUrl + "locations/" + encodeURIComponent(cityId), {
        signal: locationRequest.signal,
      })
        .then(function (res) {
          return res.json();
        })
        .then(function (locations) {
          locationSelect.innerHTML =
            '<option value="">— Select Location —</option>';
          locations.forEach(function (loc) {
            var opt = document.createElement("option");
            opt.value = loc.id;
            opt.textContent = loc.title;
            locationSelect.appendChild(opt);
          });
          locationSelect.disabled = locations.length === 0;
        })
        .catch(function (err) {
          if (err.name !== "AbortError") {
            locationSelect.innerHTML =
              '<option value="">— Select Location —</option>';
            locationSelect.disabled = true;
          }
        });
    }

    function loadServices(cityId, locationId) {
      if (serviceRequest) {
        serviceRequest.abort();
      }
      serviceRequest = new AbortController();

      detailsWrap.hidden = true;
      selectedServiceId = null;
      emptyState.hidden = true;
      showLoader(i18n.loading);

      var params = new URLSearchParams({ city_id: cityId });
      if (locationId) {
        params.set("location_id", locationId);
      }

      fetch(restUrl + "services?" + params.toString(), {
        signal: serviceRequest.signal,
      })
        .then(function (res) {
          return res.json();
        })
        .then(function (services) {
          hideLoader();
          if (!services.length) {
            servicesEl.hidden = true;
            emptyState.hidden = false;
            emptyState.textContent =
              i18n.noServices || "No services found for this selection.";
            return;
          }
          renderSlider(servicesEl, serviceOwlRef, services, renderServiceCard);
        })
        .catch(function (err) {
          if (err.name !== "AbortError") {
            hideLoader();
            emptyState.hidden = false;
            emptyState.textContent =
              i18n.noServices || "No services found for this selection.";
          }
        });
    }

    function loadServiceDetails(service) {
      if (detailRequest) {
        detailRequest.abort();
      }
      detailRequest = new AbortController();

      showLoader(i18n.loading);

      var params = new URLSearchParams({
        city_id: citySelect.value,
        service_id: service.id,
      });
      if (locationSelect.value) {
        params.set("location_id", locationSelect.value);
      }

      fetch(restUrl + "service-details?" + params.toString(), {
        signal: detailRequest.signal,
      })
        .then(function (res) {
          return res.json();
        })
        .then(function (details) {
          hideLoader();
          detailsWrap.hidden = false;
          detailsHeading.textContent = service.title;

          if (!details.length) {
            detailsEl.hidden = true;
            detailsHeading.textContent =
              service.title +
              " — " +
              (i18n.noDetails || "No listings found yet.");
            return;
          }
          renderSlider(detailsEl, detailOwlRef, details, renderDetailCard);
        })
        .catch(function (err) {
          if (err.name !== "AbortError") {
            hideLoader();
          }
        });
    }

    /* ---------- events ---------- */

    citySelect.addEventListener("change", function () {
      var cityId = this.value;

      detailsWrap.hidden = true;
      servicesEl.hidden = true;
      servicesEl.innerHTML = "";

      if (!cityId) {
        locationSelect.innerHTML =
          '<option value="">— Select City First —</option>';
        locationSelect.disabled = true;
        emptyState.hidden = false;
        emptyState.textContent =
          i18n.selectCity || "Select a city to see available services.";
        return;
      }

      loadLocations(cityId);
      loadServices(cityId, "");
    });

    locationSelect.addEventListener("change", function () {
      var cityId = citySelect.value;
      if (!cityId) {
        return;
      }
      loadServices(cityId, this.value);
    });

    if (backToServicesBtn) {
      backToServicesBtn.addEventListener("click", function () {
        if (detailRequest) {
          detailRequest.abort();
        }
        detailsWrap.hidden = true;
        selectedServiceId = null;
        var allCards = servicesEl.querySelectorAll(".csm-service-card");
        for (var i = 0; i < allCards.length; i++) {
          allCards[i].classList.remove("is-selected");
        }
        servicesEl.hidden = false;
      });
    }
  });
})();
