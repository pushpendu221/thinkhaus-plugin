jQuery(document).ready(function ($) {
  // ===========================================================
  // POPUP MODAL CONTROLS (Tour & Booking)
  // ===========================================================

  // Open Tour Popup
  // Open Tour Popup
  $("#tour-open-popup").on("click", function (e) {
    e.preventDefault();
    $("#tour-popup-overlay").addClass("is-active");
    $("body").css("overflow", "hidden");

    $("#jd_service_detail_section").addClass("jd_service_detail_section_class");
  });

  // Open Booking Popup
  $("#hbs-open-popup").on("click", function (e) {
    e.preventDefault();
    $("#hbs-popup-overlay").addClass("is-active");
    $("body").css("overflow", "hidden");

    $("#jd_service_detail_section").addClass("jd_service_detail_section_class");
  });

  // Close on X button
  $(".popup-close-btn").on("click", function () {
    $(this).closest(".popup-overlay").removeClass("is-active");
    $("body").css("overflow", "auto");

    $("#jd_service_detail_section").removeClass(
      "jd_service_detail_section_class",
    );
  });

  // Close on overlay click
  $(".popup-overlay").on("click", function (e) {
    if ($(e.target).hasClass("popup-overlay")) {
      $(this).removeClass("is-active");
      $("body").css("overflow", "auto");

      $("#jd_service_detail_section").removeClass(
        "jd_service_detail_section_class",
      );
    }
  });

  // Close on ESC
  $(document).on("keydown", function (e) {
    if (e.key === "Escape" && $(".popup-overlay.is-active").length) {
      $(".popup-overlay.is-active").removeClass("is-active");
      $("body").css("overflow", "auto");

      $("#jd_service_detail_section").removeClass(
        "jd_service_detail_section_class",
      );
    }
  });
});

jQuery(function ($) {
  const $cta = $(".bar");
  const $stopPoint = $("#sticky-stop");

  if (!$cta.length || !$stopPoint.length) {
    return;
  }

  let ticking = false;

  function toggleCta() {
    const stopTop = $stopPoint.offset().top;
    const scrollBottom = $(window).scrollTop() + $(window).height();

    if (scrollBottom >= stopTop) {
      $cta.addClass("is-hidden");
    } else {
      $cta.removeClass("is-hidden");
    }

    ticking = false;
  }

  $(window).on("scroll resize", function () {
    if (!ticking) {
      window.requestAnimationFrame(function () {
        toggleCta();
      });

      ticking = true;
    }
  });

  toggleCta();
});
