jQuery(document).ready(function ($) {
  /* =============================================
       LOCATION LOADER (reusable)
       ============================================= */
  function loadLocations(citySelect, locSelect) {
    let cityId = $(citySelect).val();
    if (!cityId) {
      $(locSelect).html('<option value="">— Select Location —</option>');
      return;
    }
    $.post(
      psi_admin_obj.ajax_url,
      {
        action: "psi_get_locations",
        city_id: cityId,
      },
      function (response) {
        $(locSelect).html(response);
      },
    );
  }

  /* Pricing section cascade */
  $("#psi_price_city").change(function () {
    loadLocations("#psi_price_city", "#psi_price_location");
  });

  /* Seats section cascade */
  $("#psi_seats_city").change(function () {
    loadLocations("#psi_seats_city", "#psi_seats_location");
  });

  /* =============================================
       SAVE PRICE RULE
       ============================================= */
  $("#save_psi_price").click(function () {
    let cid = $("#psi_price_city").val();
    let lid = $("#psi_price_location").val();
    let price = $("#psi_price_amount").val();
    if (!cid || !lid || !price) {
      alert("Please select city, location and enter price.");
      return;
    }
    $.post(
      psi_admin_obj.ajax_url,
      {
        action: "psi_save_price_rule",
        nonce: psi_admin_obj.nonce,
        city_id: cid,
        location_id: lid,
        price: price,
      },
      function (html) {
        $("#psi_price_list").html(html);
        $("#psi_price_amount").val("");
      },
    );
  });

  /* =============================================
       SAVE SEAT RULE
       ============================================= */
  $("#save_psi_seats").click(function () {
    let cid = $("#psi_seats_city").val();
    let lid = $("#psi_seats_location").val();
    let seats = $("#psi_seats_amount").val();
    if (!cid || !lid || !seats) {
      alert("Please select city, location and enter number of seats.");
      return;
    }
    $.post(
      psi_admin_obj.ajax_url,
      {
        action: "psi_save_seat_rule",
        nonce: psi_admin_obj.nonce,
        city_id: cid,
        location_id: lid,
        seats: seats,
      },
      function (html) {
        $("#psi_seats_list").html(html);
        $("#psi_seats_amount").val("");
      },
    );
  });

  /* =============================================
       DELETE RULE (price or seats)
       ============================================= */
  $(document).on("click", ".hbs-price-delete", function (e) {
    e.preventDefault();
    let btn = $(this);
    let type = btn.data("type");
    let id = btn.data("id");
    if (!confirm("Delete this rule?")) return;

    $.post(
      psi_admin_obj.ajax_url,
      {
        action: "psi_delete_rule",
        nonce: psi_admin_obj.nonce,
        type: type,
        id: id,
      },
      function (html) {
        if (type === "price") {
          $("#psi_price_list").html(html);
        } else {
          $("#psi_seats_list").html(html);
        }
      },
    );
  });
});
