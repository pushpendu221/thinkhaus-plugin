/**
 * Admin-side availability calendar.
 *
 * Click a date to cycle: Available -> Limited -> Full -> Available.
 *
 * Design notes (read this if debugging "wrong dates showing" issues):
 * - This calendar NEVER trusts a snapshot baked into the page HTML.
 *   Every time it renders a month, it fetches that month's statuses fresh
 *   from the server via AJAX. This guarantees what you see always matches
 *   what's actually stored, even if the page itself is cached by a
 *   browser/CDN/page-cache plugin.
 * - Each click saves ONE date via its own AJAX call (read-modify-write on
 *   the server side, scoped to that single date). There is no client-built
 *   "whole month/year" JSON blob sent on a page-level form submit, so a
 *   stale or corrupted local map can never overwrite the real stored data.
 * - The UI updates optimistically when you click, then ROLLS BACK to the
 *   previous status if the save request fails, so the screen never shows
 *   a state that isn't actually persisted.
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
  var DOW = ["Sun", "Mon", "Tue", "Wed", "Thu", "Fri", "Sat"];
  var CYCLE = ["available", "limited", "full"];

  document.addEventListener("DOMContentLoaded", function () {
    var container = document.getElementById("cwf-admin-calendar");
    if (!container) {
      return;
    }

    var actionSlug = container.getAttribute("data-action-slug");
    var nonce = container.getAttribute("data-nonce");
    var indicator = document.getElementById("cwf-cal-save-indicator");

    var today = new Date();
    var state = {
      year: today.getFullYear(),
      month: today.getMonth() + 1, // 1-12
      days: {}, // dateStr -> status, populated fresh from server each render
      todayStr: "",
    };

    function pad(n) {
      return n < 10 ? "0" + n : "" + n;
    }

    function showIndicator(text, isError) {
      if (!indicator) {
        return;
      }
      indicator.textContent = text;
      indicator.className =
        "cwf-autosave-indicator" + (isError ? " is-error" : " is-success");
      if (text) {
        window.clearTimeout(indicator._timeout);
        indicator._timeout = window.setTimeout(function () {
          indicator.textContent = "";
          indicator.className = "cwf-autosave-indicator";
        }, 2000);
      }
    }

    function ajaxPost(action, extraParams) {
      var body = new URLSearchParams();
      body.append("action", action);
      body.append("nonce", nonce);
      Object.keys(extraParams || {}).forEach(function (key) {
        body.append(key, extraParams[key]);
      });

      return fetch(window.cwfAdmin.ajaxUrl, {
        method: "POST",
        headers: { "Content-Type": "application/x-www-form-urlencoded" },
        body: body.toString(),
      }).then(function (res) {
        return res.json();
      });
    }

    function fetchMonth(year, month) {
      container.innerHTML =
        '<div class="cwf-cal-loading">Loading calendar&hellip;</div>';

      return ajaxPost("cwf_admin_calendar_fetch_" + actionSlug, {
        year: year,
        month: month,
      })
        .then(function (json) {
          if (json && json.success) {
            state.days = json.data.days || {};
            state.todayStr = json.data.today || "";
            renderGrid();
          } else {
            container.innerHTML =
              '<div class="cwf-cal-error">Could not load calendar. Please refresh the page.</div>';
          }
        })
        .catch(function () {
          container.innerHTML =
            '<div class="cwf-cal-error">Network error loading calendar.</div>';
        });
    }

    function renderGrid() {
      var year = state.year,
        month = state.month;
      var firstDow = new Date(year, month - 1, 1).getDay();
      var daysInMonth = new Date(year, month, 0).getDate();

      var html = '<div class="cwf-admin-cal-header">';
      html +=
        '<button type="button" class="cwf-admin-cal-nav" data-prev aria-label="Previous month">&#8249;</button>';
      html +=
        '<span class="cwf-admin-cal-month-label">' +
        MONTH_NAMES[month - 1] +
        " " +
        year +
        "</span>";
      html +=
        '<button type="button" class="cwf-admin-cal-nav" data-next aria-label="Next month">&#8250;</button>';
      html += "</div>";

      html += '<div class="cwf-admin-cal-grid">';
      DOW.forEach(function (d) {
        html += '<div class="cwf-admin-cal-dow">' + d + "</div>";
      });
      for (var i = 0; i < firstDow; i++) {
        html += '<div class="cwf-admin-cal-day is-empty"></div>';
      }
      for (var d = 1; d <= daysInMonth; d++) {
        var dateStr = year + "-" + pad(month) + "-" + pad(d);
        var status = state.days[dateStr] || "available";
        var isPast = !!state.todayStr && dateStr < state.todayStr;

        var classes = "cwf-admin-cal-day status-" + status;
        if (isPast) {
          classes += " is-past";
        }

        var dot = "";
        if (status === "limited") {
          dot = '<span class="dot dot-limited"></span>';
        }
        if (status === "full") {
          dot = '<span class="dot dot-full"></span>';
        }

        html +=
          '<button type="button" class="' +
          classes +
          '" data-date="' +
          dateStr +
          '"' +
          (isPast
            ? ' disabled aria-disabled="true" title="Past date — locked"'
            : "") +
          ">" +
          d +
          dot +
          "</button>";
      }
      html += "</div>";

      container.innerHTML = html;

      container
        .querySelector("[data-prev]")
        .addEventListener("click", function () {
          state.month--;
          if (state.month < 1) {
            state.month = 12;
            state.year--;
          }
          fetchMonth(state.year, state.month);
        });
      container
        .querySelector("[data-next]")
        .addEventListener("click", function () {
          state.month++;
          if (state.month > 12) {
            state.month = 1;
            state.year++;
          }
          fetchMonth(state.year, state.month);
        });

      container
        .querySelectorAll(".cwf-admin-cal-day[data-date]:not(.is-past)")
        .forEach(function (dayBtn) {
          dayBtn.addEventListener("click", function () {
            handleDayClick(dayBtn);
          });
        });
    }

    function handleDayClick(dayBtn) {
      var date = dayBtn.getAttribute("data-date");
      var previousStatus = state.days[date] || "available";
      var nextIndex = (CYCLE.indexOf(previousStatus) + 1) % CYCLE.length;
      var nextStatus = CYCLE[nextIndex];

      // Optimistic update.
      state.days[date] = nextStatus;
      updateDayButton(dayBtn, nextStatus);
      showIndicator("Saving…", false);
      dayBtn.disabled = true;

      ajaxPost("cwf_admin_calendar_set_" + actionSlug, {
        date: date,
        status: nextStatus,
      })
        .then(function (json) {
          dayBtn.disabled = false;
          if (json && json.success) {
            // Trust the server's confirmed value (defends against any
            // race condition from rapid double-clicks).
            state.days[date] = json.data.status;
            updateDayButton(dayBtn, json.data.status);
            showIndicator("Saved", false);
          } else {
            // Roll back — never show a status that didn't actually save.
            state.days[date] = previousStatus;
            updateDayButton(dayBtn, previousStatus);
            showIndicator("Could not save — reverted", true);
          }
        })
        .catch(function () {
          dayBtn.disabled = false;
          state.days[date] = previousStatus;
          updateDayButton(dayBtn, previousStatus);
          showIndicator("Network error — reverted", true);
        });
    }

    function updateDayButton(dayBtn, status) {
      var day = dayBtn.getAttribute("data-date").split("-")[2];
      dayBtn.className = "cwf-admin-cal-day status-" + status;
      var dot = "";
      if (status === "limited") {
        dot = '<span class="dot dot-limited"></span>';
      }
      if (status === "full") {
        dot = '<span class="dot dot-full"></span>';
      }
      dayBtn.innerHTML = parseInt(day, 10) + dot;
    }

    fetchMonth(state.year, state.month);
  });
})();
