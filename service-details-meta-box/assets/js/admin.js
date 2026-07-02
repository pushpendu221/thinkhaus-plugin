/* ==========================================================================
   Service Details Meta Box — Admin JavaScript
   File: assets/js/admin.js

   Globals injected by wp_localize_script() in the plugin:
     window.csmData.restBase         — e.g. https://site.com/wp-json/wp/v2/service/
     window.csmData.restNonce        — WP REST nonce
     window.csmData.fieldKeys        — array of meta key strings
     window.csmData.locationMap      — { cityId: [ { id, title }, … ] }
     window.csmData.savedRecords     — { serviceId: { fieldKey: value, … }, … }
                                        Per-service data already saved for
                                        THIS location.
     window.csmData.currentServiceId — the service id currently linked to
                                        this location (int, 0 if none)

   v3.3.0 CHANGE
   -------------
   Each location can now hold a DIFFERENT set of field values per linked
   Service. `serviceRecords` (seeded from csmData.savedRecords) is the
   in-memory source of truth for every service touched during this edit
   session. Switching the Service dropdown:
     1. Captures whatever is currently on screen into serviceRecords for
        the PREVIOUSLY selected service (so it's never lost).
     2. If we already have a record for the NEWLY selected service
        (either saved previously, or already fetched once this session),
        it's restored straight from memory — no REST call, no overwrite
        of other services' data.
     3. Otherwise (first time this service is linked to this location),
        the service's own defaults are fetched via REST as a starting
        template.
   A hidden field (#csm_service_records_field) is kept in sync with the
   full serviceRecords map and submitted alongside the form so PHP can
   persist everything together.
   ========================================================================== */

(function () {
  "use strict";

  /* ── Bail if our wrapper isn't on this screen ── */
  if (!document.getElementById("csm-wrap")) return;

  const {
    restBase,
    restNonce,
    fieldKeys,
    locationMap,
    savedRecords,
    currentServiceId,
  } = window.csmData || {};

  /* ── DOM refs ── */
  const serviceDropdown = document.getElementById("csm_service_dropdown");
  const cityDropdown = document.getElementById("csm_city_dropdown");
  const locationDropdown = document.getElementById("csm_location_dropdown");
  const loadingEl = document.getElementById("csm-loading");
  const hiddenRecordsField = document.getElementById(
    "csm_service_records_field",
  );

  const videoInput = document.getElementById("csm_field_video_url");
  const videoPreview = document.getElementById("csm-video-preview");
  const videoFrame = document.getElementById("csm-video-frame");

  const mapInput = document.getElementById("csm_field_google_location");
  const mapPreview = document.getElementById("csm-map-preview");
  const mapFrame = document.getElementById("csm-map-frame");

  /* ======================================================================
	   PER-SERVICE RECORD STATE
	   ====================================================================== */

  // Deep-ish clone of the PHP-provided saved records so we never mutate
  // the original localized object by reference. Guard against it coming
  // through as an Array (e.g. PHP's empty-array → JSON `[]` quirk) —
  // adding string-keyed service IDs to an Array behaves unpredictably,
  // so we always want a plain Object here.
  let serviceRecords = {};
  try {
    const cloned = JSON.parse(JSON.stringify(savedRecords || {}));
    serviceRecords =
      cloned && typeof cloned === "object" && !Array.isArray(cloned)
        ? cloned
        : {};
  } catch (e) {
    serviceRecords = {};
  }

  /**
   * Defensive: if a literal two-character escape sequence (backslash +
   * n/r/t) shows up as plain TEXT in a value instead of an actual line
   * break/tab, convert it back. Mirrors csm_normalize_stray_escapes() on
   * the PHP side, as a belt-and-suspenders fix for stray escapes
   * surviving a JSON round-trip.
   */
  function normalizeStrayEscapes(str) {
    if (typeof str !== "string") return str;
    return str
      .replace(/\\r\\n/g, "\r\n")
      .replace(/\\n/g, "\n")
      .replace(/\\r/g, "\r")
      .replace(/\\t/g, "\t");
  }

  // Track which service's data is currently displayed in the fields.
  let activeServiceId = currentServiceId ? String(currentServiceId) : "";

  function getFieldEl(key) {
    return document.querySelector('[data-meta-key="' + key + '"]');
  }

  /** Read whatever is currently visible in the field inputs. */
  function readVisibleFields() {
    const obj = {};
    (fieldKeys || []).forEach(function (key) {
      const el = getFieldEl(key);
      obj[key] = el ? el.value : "";
    });
    return obj;
  }

  /** Write a record's values into the visible fields (with flash + previews). */
  function writeVisibleFields(record) {
    (fieldKeys || []).forEach(function (key) {
      const el = getFieldEl(key);
      if (!el) return;
      const newVal = normalizeStrayEscapes((record && record[key]) || "");
      if (el.value !== newVal) {
        el.value = newVal;
        flashField(el);
      }
    });
    updateVideoPreview(
      normalizeStrayEscapes((record && record["video_url"]) || ""),
    );
    updateMapPreview(
      normalizeStrayEscapes((record && record["google_location"]) || ""),
    );
  }

  /** Snapshot the currently visible fields into serviceRecords for the active service. */
  function captureActiveFields() {
    if (!activeServiceId) return;
    serviceRecords[activeServiceId] = readVisibleFields();
  }

  /**
   * UTF-8-safe base64 encode. We deliberately store the hidden field as
   * base64 of the JSON (not raw JSON text) so its value can never
   * interact with WordPress's automatic addslashes()/stripslashes()
   * escaping of submitted form data — base64's alphabet has no quotes
   * or backslashes, so there is nothing for that escaping to corrupt.
   */
  function utf8ToBase64(str) {
    return window.btoa(unescape(encodeURIComponent(str)));
  }

  /** Push the in-memory serviceRecords map into the hidden form field. */
  function syncHiddenField() {
    if (hiddenRecordsField) {
      hiddenRecordsField.value = utf8ToBase64(JSON.stringify(serviceRecords));
    }
  }

  // Keep the in-memory record live-updated as the user types, in addition
  // to capturing on dropdown-change/submit — belt and suspenders so a
  // direct "Update" click always reflects the latest edits.
  (fieldKeys || []).forEach(function (key) {
    const el = getFieldEl(key);
    if (!el) return;
    el.addEventListener("input", function () {
      captureActiveFields();
    });
  });

  // Make sure the hidden field reflects reality as soon as the page loads.
  syncHiddenField();

  /* ======================================================================
	   UTILITY: flash-highlight a field when it's been auto-populated
	   ====================================================================== */
  function flashField(el) {
    el.classList.remove("csm-populated");
    // Force reflow so the animation restarts even if already applied.
    void el.offsetWidth;
    el.classList.add("csm-populated");
  }

  /* ======================================================================
	   VIDEO EMBED
	   Supports: YouTube (youtube.com/watch?v=, youtu.be/, embed/)
	             Vimeo   (vimeo.com/{id})
	   ====================================================================== */
  function getVideoEmbedUrl(url) {
    if (!url) return null;

    // YouTube
    const ytMatch = url.match(
      /(?:youtube\.com\/(?:watch\?v=|embed\/)|youtu\.be\/)([A-Za-z0-9_-]{11})/,
    );
    if (ytMatch) {
      return "https://www.youtube.com/embed/" + ytMatch[1];
    }

    // Vimeo
    const vmMatch = url.match(/vimeo\.com\/(\d+)/);
    if (vmMatch) {
      return "https://player.vimeo.com/video/" + vmMatch[1];
    }

    return null;
  }

  function updateVideoPreview(url) {
    const embedUrl = getVideoEmbedUrl(url);
    if (embedUrl) {
      videoFrame.src = embedUrl;
      videoPreview.style.display = "block";
    } else {
      videoFrame.src = "";
      videoPreview.style.display = "none";
    }
  }

  /* ======================================================================
	   GOOGLE MAP EMBED
	   Handles share links, coordinate links, and raw embed URLs
	   ====================================================================== */
  function getMapEmbedUrl(url) {
    if (!url || url.length < 10) return null;

    // Already an embed URL → use as-is (or fix trailing /maps?)
    if (
      url.includes("google.com/maps/embed") ||
      url.includes("maps.google.com/maps?")
    ) {
      return url.replace("/maps?", "/maps/embed?");
    }

    // Share link with coordinates: /maps/@LAT,LNG,…
    const coordMatch = url.match(/[@/](-?\d+\.\d+),(-?\d+\.\d+)/);
    if (coordMatch) {
      return (
        "https://maps.google.com/maps?q=" +
        coordMatch[1] +
        "," +
        coordMatch[2] +
        "&output=embed"
      );
    }

    // Generic fallback — search by the URL's query string or full URL
    return (
      "https://maps.google.com/maps?q=" +
      encodeURIComponent(url) +
      "&output=embed"
    );
  }

  function updateMapPreview(url) {
    const embedUrl = getMapEmbedUrl(url);
    if (embedUrl) {
      mapFrame.src = embedUrl;
      mapPreview.style.display = "block";
    } else {
      mapFrame.src = "";
      mapPreview.style.display = "none";
    }
  }

  /* ======================================================================
	   SERVICE DROPDOWN → switch between per-service records for this location
	   ====================================================================== */
  if (serviceDropdown) {
    serviceDropdown.addEventListener("change", function () {
      // 1. Preserve whatever is currently on screen under the service
      //    we're switching AWAY from, before touching anything.
      captureActiveFields();

      const newServiceId = this.value;

      // Placeholder ("— Select a Service —") chosen: clear the fields,
      // but the previous service's data is already safe in
      // serviceRecords from the capture above.
      if (!newServiceId) {
        activeServiceId = "";
        (fieldKeys || []).forEach(function (key) {
          const el = getFieldEl(key);
          if (el) el.value = "";
        });
        updateVideoPreview("");
        updateMapPreview("");
        syncHiddenField();
        return;
      }

      // 2. We already have a record for this service on THIS location
      //    (saved previously, or fetched earlier this session) —
      //    restore it from memory. No REST call, nothing overwritten.
      if (serviceRecords[newServiceId]) {
        activeServiceId = newServiceId;
        writeVisibleFields(serviceRecords[newServiceId]);
        syncHiddenField();
        return;
      }

      // 3. First time this service has been linked to this location —
      //    fetch the service's own defaults as a starting template.
      loadingEl.style.display = "inline";

      /*
       * GET /wp-json/wp/v2/service/{id}?_fields=meta
       *
       * SCF stores values under the plain field name (no underscore prefix),
       * which matches our register_post_meta() keys, so data.meta[key] works.
       */
      fetch(restBase + newServiceId + "?_fields=meta", {
        headers: {
          "X-WP-Nonce": restNonce,
          "Content-Type": "application/json",
        },
      })
        .then(function (res) {
          if (!res.ok) throw new Error("HTTP " + res.status);
          return res.json();
        })
        .then(function (data) {
          const meta = data.meta || {};

          activeServiceId = newServiceId;
          // Seed this service's record for this location with the
          // service's defaults — the user can edit from here, and it
          // will be saved as THIS location's data for THIS service.
          serviceRecords[newServiceId] = Object.assign({}, meta);

          writeVisibleFields(serviceRecords[newServiceId]);
          syncHiddenField();
        })
        .catch(function (err) {
          console.error("[CSM] REST fetch error:", err);
          // eslint-disable-next-line no-alert
          window.alert(
            "Could not fetch Service data.\n\n" +
              "Please check:\n" +
              '1. The "service" CPT has show_in_rest => true\n' +
              "2. SCF field names have no leading underscore\n" +
              "3. You are logged in as an Editor or Administrator",
          );
        })
        .finally(function () {
          loadingEl.style.display = "none";
        });
    });
  }

  /* ======================================================================
	   CITY DROPDOWN → Populate location child dropdown
	   ====================================================================== */
  if (cityDropdown && locationDropdown) {
    cityDropdown.addEventListener("change", function () {
      const cityId = this.value;

      // Reset location dropdown
      locationDropdown.innerHTML =
        '<option value="">— Select Location —</option>';

      if (!cityId || !locationMap[cityId]) {
        locationDropdown.disabled = true;
        return;
      }

      locationMap[cityId].forEach(function (loc) {
        const option = document.createElement("option");
        option.value = loc.id;
        option.textContent = loc.title;
        locationDropdown.appendChild(option);
      });

      locationDropdown.disabled = false;
    });
  }

  /* ======================================================================
	   LIVE PREVIEWS — bind on input/change events + initialise on page load
	   ====================================================================== */
  if (videoInput) {
    videoInput.addEventListener("input", function () {
      updateVideoPreview(this.value);
    });
    // Initialise on page load (editing an existing post with saved value)
    updateVideoPreview(videoInput.value);
  }

  if (mapInput) {
    mapInput.addEventListener("change", function () {
      updateMapPreview(this.value);
    });
    updateMapPreview(mapInput.value);
  }

  /* ======================================================================
	   FINAL SAFETY NET — make sure the hidden field is current right before
	   the post form is submitted, regardless of what else has happened.
	   ====================================================================== */
  const postForm = document.getElementById("post");
  if (postForm) {
    postForm.addEventListener("submit", function () {
      captureActiveFields();
      syncHiddenField();
    });
  }
})();
