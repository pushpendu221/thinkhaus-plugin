/**
 * Co-Working Forms — front-end behaviour.
 * Vanilla JS (no framework dependency beyond jQuery being present in WP).
 */
(function () {
  "use strict";

  var MONTH_NAMES = [
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
  var DOW = ["Su", "Mo", "Tu", "We", "Th", "Fr", "Sa"];
  var MOBILE_BREAKPOINT = 768;

  var dateFieldState = new WeakMap();
  var mobileModalMap = new Map();

  function getState(wrap) {
    if (!dateFieldState.has(wrap)) {
      dateFieldState.set(wrap, {
        year: new Date().getFullYear(),
        month: new Date().getMonth() + 1,
        selected: null,
        isDesktopOpen: false,
        popoverEl: wrap.querySelector("[data-cwf-calendar]"), // Cache permanently
        popover: null,
        scrollParents: null,
        _scrollHandler: null,
        _requestId: 0,
        isMobileOpen: false,
        mobileModal: null,
        scrollParentEl: null,
        savedScrollTop: 0,
      });
    }
    return dateFieldState.get(wrap);
  }

  function pad(n) {
    return n < 10 ? "0" + n : "" + n;
  }

  function isMobile() {
    return (
      (window.innerWidth || document.documentElement.clientWidth) <=
      MOBILE_BREAKPOINT
    );
  }

  function getScrollParents(el) {
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
  }

  function findScrollParent(el) {
    var node = el ? el.parentElement : null;
    while (
      node &&
      node !== document.body &&
      node !== document.documentElement
    ) {
      if (node.scrollHeight > node.clientHeight) {
        return node;
      }
      node = node.parentElement;
    }
    return null;
  }

  /* ---------------- Modal open/close ---------------- */

  function openModalFor(slug) {
    var modal = document.getElementById("cwf-modal-" + slug);
    if (modal) {
      modal.classList.add("is-open");
      modal.setAttribute("aria-hidden", "false");
    }
  }

  function closeModal(overlay) {
    if (!overlay) return;
    overlay.classList.remove("is-open");
    overlay.setAttribute("aria-hidden", "true");
  }

  /* ---------------- Calendar popover (desktop) ---- */

  function initPopoverStructure(popover, state) {
    var dowHtml = DOW.map(function (d, i) {
      var w = i === 0 || i === 6 ? " is-weekend" : "";
      return '<div class="cwf-cal-dow' + w + '">' + d + "</div>";
    }).join("");

    popover.innerHTML =
      '<div class="cwf-cal-header">' +
      '<button type="button" class="cwf-cal-nav-btn" data-cwf-prev>&laquo;</button>' +
      '<span class="cwf-cal-title">' +
      MONTH_NAMES[state.month - 1] +
      " " +
      state.year +
      "</span>" +
      '<button type="button" class="cwf-cal-nav-btn" data-cwf-next>&raquo;</button>' +
      "</div>" +
      '<div class="cwf-cal-body">' +
      '<div class="cwf-cal-dow-row">' +
      dowHtml +
      "</div>" +
      '<div class="cwf-cal-grid" data-cwf-cal-grid></div>' +
      "</div>";
  }

  function positionPopover(popover, input) {
    var rect = input.getBoundingClientRect();
    var popWidth = 320;
    var popHeight = popover.offsetHeight || 320;

    var top = rect.bottom + 8;
    var left = rect.left;

    if (left + popWidth > window.innerWidth - 16)
      left = window.innerWidth - popWidth - 16;
    if (top + popHeight > window.innerHeight - 16)
      top = rect.top - popHeight - 8;
    if (left < 16) left = 16;
    if (top < 16) top = 16;

    popover.style.top = top + "px";
    popover.style.left = left + "px";
  }

  function openDesktopPopover(wrap, state, slug, popover, input) {
    state.popover = popover;
    popover._cwfWrap = wrap;
    popover._cwfInput = input;

    // Always ensure it's in the body for safe z-indexing.
    // If it's already there, this does nothing visually.
    document.body.appendChild(popover);

    initPopoverStructure(popover, state);

    state.isDesktopOpen = true;
    popover.classList.add("is-open");
    popover.style.position = "fixed";
    popover.style.zIndex = "99999999";
    popover.style.transform = "none";
    popover.style.webkitTransform = "none";

    positionPopover(popover, input);
    loadMonth(slug, state, popover, input);

    var scrollHandler = function () {
      if (state.isDesktopOpen) positionPopover(popover, input);
    };
    state._scrollHandler = scrollHandler;
    state.scrollParents = getScrollParents(input);
    state.scrollParents.forEach(function (sp) {
      sp.addEventListener("scroll", scrollHandler, { passive: true });
    });
  }

  function closeDesktopPopover(wrap) {
    var state = getState(wrap);
    var popover = state.popover;
    if (!popover) return;

    popover.classList.remove("is-open");
    popover.style.position = "";
    popover.style.top = "";
    popover.style.left = "";
    popover.style.zIndex = "";
    popover.style.transform = "";
    popover.style.webkitTransform = "";
    delete popover._cwfWrap;
    delete popover._cwfInput;

    // We intentionally DO NOT move the popover back to the wrapper.
    // Leaving it hidden in <body> prevents DOM re-insertion layout shifts (the "jump").

    if (state.scrollParents && state._scrollHandler) {
      state.scrollParents.forEach(function (sp) {
        sp.removeEventListener("scroll", state._scrollHandler);
      });
      state.scrollParents = null;
      state._scrollHandler = null;
    }
    state.isDesktopOpen = false;
    state.popover = null;
  }

  function closeAllPopovers() {
    document
      .querySelectorAll(".cwf-calendar-popover.is-open")
      .forEach(function (p) {
        var wrap = p._cwfWrap;
        if (wrap) {
          closeDesktopPopover(wrap);
        } else {
          p.classList.remove("is-open");
          p.style.position = "";
          p.style.top = "";
          p.style.left = "";
          p.style.zIndex = "";
        }
      });
  }

  /* ---------------- Calendar mobile modal ---------------- */

  function openMobileModal(wrap, state, slug) {
    state.isMobileOpen = true;

    var sp = findScrollParent(wrap);
    if (sp) {
      state.savedScrollTop = sp.scrollTop;
      sp._cwf_old_overflow = sp.style.overflow;
      sp.style.overflow = "hidden";
      sp.scrollTop = 0;
      state.scrollParentEl = sp;
    }

    var modal = document.createElement("div");
    modal.className = "cwf-mobile-modal";
    modal.setAttribute("data-cwf-mobile-modal", "");

    var dowHtml = DOW.map(function (d, i) {
      var w = i === 0 || i === 6 ? ' is-weekend"' : '"';
      return '<div class="cwf-mm-dow' + w + ">" + d + "</div>";
    }).join("");

    modal.innerHTML =
      '<div class="cwf-mm-card">' +
      '<div class="cwf-mm-header">' +
      '<button type="button" class="cwf-mm-nav" data-cwf-mm-prev>&laquo;</button>' +
      '<span class="cwf-mm-title">' +
      MONTH_NAMES[state.month - 1] +
      " " +
      state.year +
      "</span>" +
      '<button type="button" class="cwf-mm-nav" data-cwf-mm-next>&raquo;</button>' +
      "</div>" +
      '<div class="cwf-mm-body">' +
      '<div class="cwf-mm-dow-row">' +
      dowHtml +
      "</div>" +
      '<div class="cwf-mm-days" data-cwf-mm-days></div>' +
      "</div>" +
      '<div class="cwf-mm-footer">' +
      '<button type="button" class="cwf-mm-close-btn" data-cwf-mm-close>Close</button>' +
      "</div>" +
      "</div>";

    (sp || document.body).appendChild(modal);
    mobileModalMap.set(modal, wrap);
    state.mobileModal = modal;

    loadMonthIntoModal(wrap, state, slug);
  }

  function closeAllMobileModals() {
    mobileModalMap.forEach(function (wrap, modal) {
      var state = getState(wrap);
      if (state.scrollParentEl) {
        state.scrollParentEl.style.overflow =
          state.scrollParentEl._cwf_old_overflow || "";
        state.scrollParentEl.scrollTop = state.savedScrollTop || 0;
        state.scrollParentEl = null;
      }
      state.isMobileOpen = false;
      state.mobileModal = null;
      modal.remove();
    });
    mobileModalMap.clear();
  }

  function loadMonthIntoModal(wrap, state, slug) {
    var modal = state.mobileModal;
    if (!modal) return;
    var daysContainer = modal.querySelector("[data-cwf-mm-days]");
    if (!daysContainer) return;

    daysContainer.innerHTML =
      '<div style="grid-column:1/-1;padding:20px;color:#999;font-size:13px;">Loading...</div>';

    var title = modal.querySelector(".cwf-mm-title");
    if (title)
      title.textContent = MONTH_NAMES[state.month - 1] + " " + state.year;

    state._requestId++;
    var requestId = state._requestId;

    var body = new URLSearchParams();
    body.append("action", "cwf_calendar_" + slug.replace(/-/g, "_"));
    body.append("nonce", window.cwfFrontend.nonce);
    body.append("year", state.year);
    body.append("month", state.month);

    fetch(window.cwfFrontend.ajaxUrl, {
      method: "POST",
      headers: { "Content-Type": "application/x-www-form-urlencoded" },
      body: body.toString(),
    })
      .then(function (res) {
        return res.json();
      })
      .then(function (json) {
        if (state._requestId !== requestId) return;
        if (json.success) {
          renderModalDays(json.data, state, daysContainer, modal);
        } else {
          daysContainer.innerHTML =
            '<div style="grid-column:1/-1;padding:20px;color:#c0392b;font-size:13px;">Could not load calendar.</div>';
        }
      })
      .catch(function () {
        if (state._requestId !== requestId) return;
        daysContainer.innerHTML =
          '<div style="grid-column:1/-1;padding:20px;color:#c0392b;font-size:13px;">Network error.</div>';
      });
  }

  function renderModalDays(data, state, container, modal) {
    var year = data.year;
    var month = data.month;
    var days = data.days;
    var firstDow = new Date(year, month - 1, 1).getDay();
    var daysInMonth = Object.keys(days).length;

    var title = modal.querySelector(".cwf-mm-title");
    if (title) title.textContent = MONTH_NAMES[month - 1] + " " + year;

    var html = "";
    for (var i = 0; i < firstDow; i++) {
      html += '<div style="min-height:44px;"></div>';
    }

    for (var d = 1; d <= daysInMonth; d++) {
      var dateStr = year + "-" + pad(month) + "-" + pad(d);
      var info = days[dateStr] || { status: "available", selectable: true };
      var dow = new Date(year, month - 1, d).getDay();
      var cls = "cwf-mm-day";
      var style = "";

      if (!info.selectable) {
        cls += " cwf-mm-disabled";
        style =
          "color:#d4d0c8;cursor:not-allowed;text-decoration:line-through;";
      } else if (state.selected === dateStr) {
        cls += " cwf-mm-selected";
        style =
          "background:#e8521e;color:#fff;font-weight:700;box-shadow:0 3px 10px rgba(232,82,30,0.3);";
      } else if (dow === 0 || dow === 6) {
        style = "color:#e8521e;";
      } else {
        style = "color:#2b2b2b;";
      }

      var dot = "";
      if (info.status === "limited" && info.selectable) {
        dot =
          '<span style="position:absolute;bottom:2px;left:50%;transform:translateX(-50%);width:5px;height:5px;border-radius:50%;background:#3b82f6;"></span>';
      } else if (info.status === "full") {
        dot =
          '<span style="position:absolute;bottom:2px;left:50%;transform:translateX(-50%);width:5px;height:5px;border-radius:50%;background:#ef4444;"></span>';
      }

      html +=
        '<div class="' +
        cls +
        '" data-cwf-mm-date="' +
        dateStr +
        '" style="min-height:44px;display:flex;align-items:center;justify-content:center;font-size:13px;border-radius:8px;cursor:pointer;position:relative;' +
        style +
        '">' +
        d +
        dot +
        "</div>";
    }

    container.innerHTML = html;
  }

  /* ---------------- Toggle date field ---------------- */

  function toggleDateField(wrap) {
    var state = getState(wrap);
    var input = wrap.querySelector("input");
    var popover = state.popoverEl; // Use cached reference
    var form = wrap.closest(".cwf-form");
    if (!input || !popover || !form) return;

    var slug = form.getAttribute("data-cwf-slug");

    var isOpen = state.isDesktopOpen || state.isMobileOpen;

    closeAllPopovers();
    closeAllMobileModals();

    if (!isOpen) {
      if (isMobile()) {
        openMobileModal(wrap, state, slug);
      } else {
        openDesktopPopover(wrap, state, slug, popover, input);
      }
    }
  }
  /* ---------------- Load month (desktop popover) ---------------- */

  function loadMonth(slug, state, popover, input) {
    if (!window.cwfFrontend || !window.cwfFrontend.ajaxUrl) {
      var grid = popover.querySelector("[data-cwf-cal-grid]");
      if (grid)
        grid.innerHTML =
          '<div style="grid-column:1/-1;padding:20px;color:#c0392b;">Calendar assets did not load on this page.</div>';
      return;
    }

    var grid = popover.querySelector("[data-cwf-cal-grid]");
    if (grid)
      grid.innerHTML =
        '<div style="grid-column:1/-1;padding:20px;text-align:center;color:#999;font-size:13px;">Loading...</div>';

    var title = popover.querySelector(".cwf-cal-title");
    if (title)
      title.textContent = MONTH_NAMES[state.month - 1] + " " + state.year;

    state._requestId++;
    var requestId = state._requestId;

    var body = new URLSearchParams();
    body.append("action", "cwf_calendar_" + slug.replace(/-/g, "_"));
    body.append("nonce", window.cwfFrontend.nonce);
    body.append("year", state.year);
    body.append("month", state.month);

    fetch(window.cwfFrontend.ajaxUrl, {
      method: "POST",
      headers: { "Content-Type": "application/x-www-form-urlencoded" },
      body: body.toString(),
    })
      .then(function (res) {
        return res.json();
      })
      .then(function (json) {
        if (state._requestId !== requestId) return;
        if (json.success) {
          renderCalendarDays(json.data, state, popover);
          if (popover._cwfInput) positionPopover(popover, popover._cwfInput);
        } else {
          if (grid)
            grid.innerHTML =
              '<div style="grid-column:1/-1;padding:20px;color:#c0392b;font-size:13px;">Could not load calendar.</div>';
        }
      })
      .catch(function () {
        if (state._requestId !== requestId) return;
        if (grid)
          grid.innerHTML =
            '<div style="grid-column:1/-1;padding:20px;color:#c0392b;font-size:13px;">Network error.</div>';
      });
  }

  function renderCalendarDays(data, state, popover) {
    var year = data.year;
    var month = data.month;
    var days = data.days;

    var firstDow = new Date(year, month - 1, 1).getDay();
    var daysInMonth = Object.keys(days).length;

    var grid = popover.querySelector("[data-cwf-cal-grid]");
    if (!grid) return;

    var title = popover.querySelector(".cwf-cal-title");
    if (title) title.textContent = MONTH_NAMES[month - 1] + " " + year;

    var html = "";
    for (var i = 0; i < firstDow; i++) {
      html += '<div class="cwf-cal-day is-empty"></div>';
    }

    for (var d = 1; d <= daysInMonth; d++) {
      var dateStr = year + "-" + pad(month) + "-" + pad(d);
      var info = days[dateStr] || { status: "available", selectable: true };
      var dow = new Date(year, month - 1, d).getDay();
      var classes = "cwf-cal-day";

      if (dow === 0 || dow === 6) classes += " is-weekend";
      if (!info.selectable) classes += " is-disabled";
      if (state.selected === dateStr) classes += " is-selected";

      var dot = "";
      if (info.status === "limited")
        dot = '<span class="cwf-dot cwf-dot-limited"></span>';
      else if (info.status === "full")
        dot = '<span class="cwf-dot cwf-dot-full"></span>';

      html +=
        '<button type="button" class="' +
        classes +
        '" data-date="' +
        dateStr +
        '"' +
        (info.selectable ? "" : " disabled") +
        ">" +
        d +
        dot +
        "</button>";
    }

    grid.innerHTML = html;
  }

  /* ---------------- Field-level validation UI ---------------- */

  var FULL_NAME_REGEX = /^[A-Za-z]+(?:\s+[A-Za-z]+)+$/;
  var PHONE_REGEX = /^[0-9]{10}$/;

  function getFieldWrap(control) {
    return control && control.closest(".cwf-field");
  }

  function getFieldErrorEl(wrap) {
    return wrap && wrap.querySelector(".cwf-field-error-msg");
  }

  function showFieldError(control, message) {
    var wrap = getFieldWrap(control);
    var errEl = getFieldErrorEl(wrap);
    control.classList.add("cwf-input-error");
    control.style.borderColor = "#d63638";
    if (errEl) {
      errEl.textContent = message;
      errEl.style.display = "block";
    }
  }

  function clearFieldError(control) {
    var wrap = getFieldWrap(control);
    var errEl = getFieldErrorEl(wrap);
    control.classList.remove("cwf-input-error");
    control.style.borderColor = "";
    if (errEl) {
      errEl.textContent = "";
      errEl.style.display = "none";
    }
  }

  function clearAllFieldErrors(form) {
    form
      .querySelectorAll("input[name], select[name], textarea[name]")
      .forEach(clearFieldError);
  }

  function validateCwfForm(form) {
    clearAllFieldErrors(form);
    var isValid = true;
    var firstInvalidControl = null;

    form
      .querySelectorAll("input[name], select[name], textarea[name]")
      .forEach(function (control) {
        var key = control.getAttribute("name");
        var value = (control.value || "").trim();

        if (control.hasAttribute("required") && !value) {
          showFieldError(control, "This field is required.");
          isValid = false;
          firstInvalidControl = firstInvalidControl || control;
          return;
        }

        if (key === "full_name" && value && !FULL_NAME_REGEX.test(value)) {
          showFieldError(
            control,
            "Please enter your full name (first and last name).",
          );
          isValid = false;
          firstInvalidControl = firstInvalidControl || control;
        }

        if (key === "phone_number" && value && !PHONE_REGEX.test(value)) {
          showFieldError(
            control,
            "Please enter a valid 10-digit phone number.",
          );
          isValid = false;
          firstInvalidControl = firstInvalidControl || control;
        }
      });

    if (firstInvalidControl) firstInvalidControl.focus();
    return isValid;
  }

  /* ---------------- Form submit ---------------- */

  function submitCwfForm(form) {
    var slug = form.getAttribute("data-cwf-slug");
    var msgEl = form.querySelector(".cwf-form-message");
    var submitBtn = form.querySelector(".cwf-submit-btn");

    if (msgEl) {
      msgEl.textContent = "";
      msgEl.className = "cwf-form-message";
    }

    if (!validateCwfForm(form)) return;

    if (!window.cwfFrontend || !window.cwfFrontend.ajaxUrl) {
      if (msgEl) {
        msgEl.textContent =
          "This form's scripts did not load on this page. Please reload and try again.";
        msgEl.className = "cwf-form-message is-error";
      }
      return;
    }

    var formData = new FormData(form);
    formData.append("action", "cwf_submit_" + slug.replace(/-/g, "_"));

    if (submitBtn) submitBtn.disabled = true;

    fetch(window.cwfFrontend.ajaxUrl, {
      method: "POST",
      body: formData,
    })
      .then(function (res) {
        return res.json();
      })
      .then(function (json) {
        if (submitBtn) submitBtn.disabled = false;
        if (!msgEl) return;
        if (json.success) {
          msgEl.textContent = json.data.message;
          msgEl.className = "cwf-form-message is-success";
          form.reset();
        } else {
          msgEl.textContent =
            json.data && json.data.message
              ? json.data.message
              : "Something went wrong.";
          msgEl.className = "cwf-form-message is-error";
        }
      })
      .catch(function () {
        if (submitBtn) submitBtn.disabled = false;
        if (msgEl) {
          msgEl.textContent = "Network error. Please try again.";
          msgEl.className = "cwf-form-message is-error";
        }
      });
  }

  /* ---------------- Delegated listeners ---------------- */

  document.addEventListener("click", function (e) {
    /* ---- CWF form modal open/close ---- */
    var openBtn = e.target.closest("[data-cwf-open]");
    if (openBtn) {
      openModalFor(openBtn.getAttribute("data-cwf-open"));
      return;
    }

    var closeBtn = e.target.closest("[data-cwf-close]");
    if (closeBtn) {
      closeModal(closeBtn.closest(".cwf-modal-overlay"));
      return;
    }

    var overlay =
      e.target.classList && e.target.classList.contains("cwf-modal-overlay")
        ? e.target
        : null;
    if (overlay) {
      closeModal(overlay);
      return;
    }

    /* ---- Mobile calendar modal interactions ---- */
    var mobileModal = e.target.closest("[data-cwf-mobile-modal]");
    if (mobileModal) {
      var mmWrap = mobileModalMap.get(mobileModal);
      if (!mmWrap) return;

      var mmPrev = e.target.closest("[data-cwf-mm-prev]");
      if (mmPrev) {
        e.stopPropagation();
        var mmState = getState(mmWrap);
        mmState.month--;
        if (mmState.month < 1) {
          mmState.month = 12;
          mmState.year--;
        }
        var mmForm = mmWrap.closest(".cwf-form");
        var mmSlug = mmForm && mmForm.getAttribute("data-cwf-slug");
        loadMonthIntoModal(mmWrap, mmState, mmSlug);
        return;
      }

      var mmNext = e.target.closest("[data-cwf-mm-next]");
      if (mmNext) {
        e.stopPropagation();
        var mmState2 = getState(mmWrap);
        mmState2.month++;
        if (mmState2.month > 12) {
          mmState2.month = 1;
          mmState2.year++;
        }
        var mmForm2 = mmWrap.closest(".cwf-form");
        var mmSlug2 = mmForm2 && mmForm2.getAttribute("data-cwf-slug");
        loadMonthIntoModal(mmWrap, mmState2, mmSlug2);
        return;
      }

      var mmDay = e.target.closest("[data-cwf-mm-date]");
      if (mmDay && !mmDay.classList.contains("cwf-mm-disabled")) {
        e.stopPropagation();
        var mmState3 = getState(mmWrap);
        var mmDate = mmDay.getAttribute("data-cwf-mm-date");
        mmState3.selected = mmDate;
        var mmInput = mmWrap.querySelector("input");
        if (mmInput) {
          mmInput.value = mmDate;
          mmInput.dispatchEvent(new Event("change", { bubbles: true }));
        }
        closeAllMobileModals();
        closeAllPopovers();
        return;
      }

      var mmClose = e.target.closest("[data-cwf-mm-close]");
      if (mmClose) {
        e.stopPropagation();
        closeAllMobileModals();
        closeAllPopovers();
        return;
      }

      if (e.target === mobileModal) {
        closeAllMobileModals();
        closeAllPopovers();
        return;
      }
      return;
    }

    /* ---- Desktop calendar popover interactions ---- */
    // FIX: Grouping popover checks so clicks INSIDE the popover can NEVER fall through to the outside click logic
    var popoverEl = e.target.closest(".cwf-calendar-popover");
    if (popoverEl) {
      e.stopPropagation();

      var prevBtn = e.target.closest("[data-cwf-prev]");
      if (prevBtn) {
        var prevWrap =
          popoverEl._cwfWrap || popoverEl.closest("[data-cwf-date-field]");
        if (prevWrap) {
          var prevState = getState(prevWrap);
          prevState.month--;
          if (prevState.month < 1) {
            prevState.month = 12;
            prevState.year--;
          }
          var prevForm = prevWrap.closest(".cwf-form");
          var prevSlug = prevForm && prevForm.getAttribute("data-cwf-slug");
          var prevInput = prevWrap.querySelector("input");
          loadMonth(prevSlug, prevState, popoverEl, prevInput);
        }
        return;
      }

      var nextBtn = e.target.closest("[data-cwf-next]");
      if (nextBtn) {
        var nextWrap =
          popoverEl._cwfWrap || popoverEl.closest("[data-cwf-date-field]");
        if (nextWrap) {
          var nextState = getState(nextWrap);
          nextState.month++;
          if (nextState.month > 12) {
            nextState.month = 1;
            nextState.year++;
          }
          var nextForm = nextWrap.closest(".cwf-form");
          var nextSlug = nextForm && nextForm.getAttribute("data-cwf-slug");
          var nextInput = nextWrap.querySelector("input");
          loadMonth(nextSlug, nextState, popoverEl, nextInput);
        }
        return;
      }

      var dayBtn = e.target.closest(".cwf-cal-day[data-date]");
      if (
        dayBtn &&
        !dayBtn.disabled &&
        !dayBtn.classList.contains("is-disabled")
      ) {
        var dayWrap =
          popoverEl._cwfWrap || popoverEl.closest("[data-cwf-date-field]");
        if (dayWrap) {
          var dayInput = dayWrap.querySelector("input");
          var dayState = getState(dayWrap);
          var date = dayBtn.getAttribute("data-date");
          dayState.selected = date;
          if (dayInput) {
            dayInput.value = date;
            dayInput.dispatchEvent(new Event("change", { bubbles: true }));
          }
          closeAllPopovers();
          closeAllMobileModals();
        }
        return;
      }

      // Clicked inside popover padding/empty space, do nothing safely
      return;
    }

    // Click is strictly OUTSIDE the popover
    var dateFieldClick = e.target.closest("[data-cwf-date-field]");
    if (dateFieldClick) {
      e.stopPropagation();
      toggleDateField(dateFieldClick);
      return;
    }

    // Click is completely outside the date field and popover
    closeAllPopovers();
    closeAllMobileModals();
  });

  document.addEventListener("submit", function (e) {
    var form = e.target;
    if (!form.classList || !form.classList.contains("cwf-form")) return;
    e.preventDefault();
    submitCwfForm(form);
  });

  function maybeClearOnEdit(e) {
    var control = e.target.closest(
      ".cwf-field input[name], .cwf-field select[name], .cwf-field textarea[name]",
    );
    if (control && control.classList.contains("cwf-input-error")) {
      clearFieldError(control);
    }
  }
  document.addEventListener("input", maybeClearOnEdit);
  document.addEventListener("change", maybeClearOnEdit);
})();
