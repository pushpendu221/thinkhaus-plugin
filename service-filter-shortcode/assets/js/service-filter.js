/**
 * service-filter.js  —  Service Filter Shortcode front-end logic  v1.1.0
 * Vanilla JS, no jQuery dependency (Owl Carousel itself needs jQuery).
 *
 * URL presetting:
 *   PHP resolves the current URL to city/location IDs and writes them into
 *   data-active-city and data-active-location on the <section> wrapper.
 *   On DOMContentLoaded JS reads those attributes and:
 *     data-active-location > 0  → call loadServices() immediately.
 *     data-active-city > 0 only → dropdowns preset by PHP, no auto-load
 *                                  (user must pick a location first).
 *     both = 0                  → no preset, both dropdowns at placeholder.
 *
 * Description: comes strictly from the service post excerpt (set server-side
 * in sfs_get_services_for_location()).
 */

(function () {
  "use strict";

  /* ── Owl Carousel helper ──────────────────────────────────────────────── */
  function initCarousel(wrapperId, opts) {
    if (
      typeof jQuery === "undefined" ||
      typeof jQuery.fn.owlCarousel === "undefined"
    ) {
      console.warn(
        "[SFS] Owl Carousel not found — cards rendered without carousel.",
      );
      return;
    }
    var $el = jQuery("#" + wrapperId + "-carousel");
    if ($el.hasClass("owl-loaded")) {
      $el.trigger("destroy.owl.carousel").removeClass("owl-loaded owl-hidden");
      $el.find(".owl-stage-outer").children().unwrap();
    }
    $el.owlCarousel(opts);
  }

  /* ── Build a single card's HTML ──────────────────────────────────────── */
  // locationSlug: the post_name (URL slug) of the selected location post.
  // Appended to the service permalink as ?location={slug} so the landing
  // page can read it and know which location context the user came from.
  function buildCardHTML(svc, locationSlug) {
    var priceHTML = svc.price
      ? '<div class="price">' + escHTML(svc.price) + "</div>"
      : "";
    var imgHTML = svc.image
      ? '<img src="' +
        escAttr(svc.image) +
        '" alt="' +
        escAttr(svc.title) +
        '" loading="lazy">'
      : "";

    // Build the href: base permalink + ?location={slug}
    var baseHref = svc.permalink || "#";
    var href =
      baseHref !== "#" && locationSlug
        ? baseHref + "?location=" + encodeURIComponent(locationSlug)
        : baseHref;

    return (
      '<div class="item">' +
      '<div class="workspace-card">' +
      '<a class="card-arrow" href="' +
      escAttr(href) +
      '">' +
      '<i class="fa-solid fa-arrow-right"></i>' +
      "</a>" +
      '<div class="card-content">' +
      "<h3>" +
      escHTML(svc.title) +
      "</h3>" +
      "<p>" +
      escHTML(svc.description) +
      "</p>" +
      priceHTML +
      "</div>" +
      '<div class="card-image">' +
      imgHTML +
      "</div>" +
      "</div>" +
      "</div>"
    );
  }

  /* ── HTML escape helpers ──────────────────────────────────────────────── */
  function escHTML(s) {
    return String(s)
      .replace(/&/g, "&amp;")
      .replace(/</g, "&lt;")
      .replace(/>/g, "&gt;")
      .replace(/"/g, "&quot;");
  }
  function escAttr(s) {
    return escHTML(s);
  }

  /* ── UI state helpers ─────────────────────────────────────────────────── */
  function setLoading(uid, on) {
    var el = document.getElementById(uid + "-loading");
    if (el) el.style.display = on ? "flex" : "none";
  }
  function setEmpty(uid, on) {
    var el = document.getElementById(uid + "-empty");
    if (el) el.style.display = on ? "block" : "none";
  }
  function setCarouselVisible(uid, on) {
    var el = document.getElementById(uid + "-carousel");
    if (el) el.style.display = on ? "" : "none";
  }

  /* ── Fetch services for a location and re-render the carousel ─────────── */
  function loadServices(uid, locationId, carouselOpts) {
    if (!locationId) return;

    var carouselEl = document.getElementById(uid + "-carousel");
    if (!carouselEl) return;

    setLoading(uid, true);
    setEmpty(uid, false);
    setCarouselVisible(uid, false);

    fetch(sfsConfig.restServicesBase + locationId, {
      headers: { "X-WP-Nonce": sfsConfig.restNonce },
    })
      .then(function (res) {
        if (!res.ok) throw new Error("HTTP " + res.status);
        return res.json();
      })
      .then(function (data) {
        setLoading(uid, false);

        if (!data.services || data.services.length === 0) {
          carouselEl.innerHTML = "";
          setEmpty(uid, true);
          return;
        }

        // De-duplicate by service ID — cast to Number so "1" and 1
        // are treated as the same key. The server already deduplicates
        // but this is a second safety net for any edge cases.
        var seen = {};
        var unique = data.services.filter(function (svc) {
          var key = Number(svc.id);
          if (seen[key]) return false;
          seen[key] = true;
          return true;
        });

        var locationSlug = data.location_slug || "";
        carouselEl.innerHTML = unique
          .map(function (svc) {
            return buildCardHTML(svc, locationSlug);
          })
          .join("");
        setCarouselVisible(uid, true);
        initCarousel(uid, carouselOpts);
      })
      .catch(function (err) {
        setLoading(uid, false);
        setEmpty(uid, true);
        console.error("[SFS] loadServices error:", err);
      });
  }

  /* ── Populate Location dropdown from the pre-built locationMap ────────── */
  function populateLocations(uid, cityId, locationSelect, preselectId) {
    locationSelect.innerHTML = "";
    preselectId = preselectId || 0;

    var children =
      sfsConfig.locationMap && sfsConfig.locationMap[cityId]
        ? sfsConfig.locationMap[cityId]
        : [];

    if (children.length === 0) {
      var empty = document.createElement("option");
      empty.value = "";
      empty.textContent = "— Select Location —";
      locationSelect.appendChild(empty);
      locationSelect.disabled = true;
      return;
    }

    // Always prepend a "Select Location" placeholder so the user can
    // explicitly choose instead of auto-selecting the first child.
    var placeholder = document.createElement("option");
    placeholder.value = "";
    placeholder.textContent = "— Select Location —";
    locationSelect.appendChild(placeholder);

    locationSelect.disabled = false;

    children.forEach(function (loc) {
      var opt = document.createElement("option");
      opt.value = loc.id;
      opt.textContent = loc.title;
      if (parseInt(preselectId, 10) === parseInt(loc.id, 10)) {
        opt.selected = true;
      }
      locationSelect.appendChild(opt);
    });
  }

  /* ── Initialise all shortcode instances ───────────────────────────────── */
  function init() {
    document.querySelectorAll(".sfs-section").forEach(function (wrapper) {
      var uid = wrapper.id;
      var citySelect = wrapper.querySelector(".sfs-city-select");
      var locationSelect = wrapper.querySelector(".sfs-location-select");
      var carouselOpts = {};

      try {
        carouselOpts = JSON.parse(wrapper.dataset.carouselOpts || "{}");
      } catch (e) {}

      if (!citySelect || !locationSelect) return;

      // IDs resolved server-side from the URL.
      var activeCityId = parseInt(wrapper.dataset.activeCity, 10) || 0;
      var activeLocationId = parseInt(wrapper.dataset.activeLocation, 10) || 0;

      /* ── City change ─────────────────────────────────────────────── */
      citySelect.addEventListener("change", function () {
        var cityId = this.value;

        // Repopulate Location dropdown (no preselect — user chose manually).
        populateLocations(uid, cityId, locationSelect, 0);

        // Don't auto-load services; wait for the user to pick a location.
        // (Matches the URL behaviour: /city/delhi/ shows no cards.)
      });

      /* ── Location change ─────────────────────────────────────────── */
      locationSelect.addEventListener("change", function () {
        if (this.value) {
          loadServices(uid, this.value, carouselOpts);
        }
      });

      /* ── Initial state driven by URL ─────────────────────────────── */
      if (activeLocationId) {
        // Full preset: city + location from URL → load services immediately.
        loadServices(uid, activeLocationId, carouselOpts);
      } else if (activeCityId) {
        // City-only preset: dropdown already pre-selected by PHP.
        // Location dropdown shows placeholder — no service load yet.
        // (Nothing extra to do here; PHP already rendered the right options.)
      }
      // else: no preset — both dropdowns at placeholder, carousel stays empty.
    });
  }

  /* ── Bootstrap ───────────────────────────────────────────────────────── */
  if (typeof sfsConfig === "undefined") {
    console.warn("[SFS] sfsConfig not found. Are shortcode assets enqueued?");
    return;
  }

  if (document.readyState === "loading") {
    document.addEventListener("DOMContentLoaded", init);
  } else {
    init();
  }
})();
