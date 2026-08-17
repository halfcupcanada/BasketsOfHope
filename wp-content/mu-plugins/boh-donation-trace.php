<?php
/**
 * Plugin Name: BoH donation trace (temporary)
 * Description: Logs every Give donation processing attempt to mail.log so
 *              we can see why Stripe charges are not going through.
 *              Remove this file once Stripe is verified working.
 */
defined("ABSPATH") || exit;

add_action("give_pre_process_donation", function () {
    error_log("[BoH trace] give_pre_process_donation fired. POST keys: " . implode(",", array_keys($_POST)));
    error_log("[BoH trace] payment-mode: " . ($_POST["payment-mode"] ?? "(none)"));
    error_log("[BoH trace] give-form-id: " . ($_POST["give-form-id"] ?? "(none)"));
    error_log("[BoH trace] give_first: " . ($_POST["give_first"] ?? "(missing)"));
    error_log("[BoH trace] give_email: " . ($_POST["give_email"] ?? "(missing)"));
});

add_action("give_donation_form_validate_user_details_required_fields", function () {
    error_log("[BoH trace] user-fields validation running");
}, 1);

add_action("give_pre_process_failure", function () {
    error_log("[BoH trace] give_pre_process_failure fired");
});

// Capture errors at the very end
add_action("shutdown", function () {
    if (function_exists("give_get_errors")) {
        $errs = give_get_errors();
        if (!empty($errs)) {
            error_log("[BoH trace] give_get_errors at shutdown: " . json_encode($errs));
        }
    }
});

add_filter("wp_redirect", function ($location, $status) {
    if (strpos($location, "give") !== false || strpos($location, "donation") !== false) {
        error_log("[BoH trace] redirect to: $location (status $status)");
    }
    return $location;
}, 10, 2);
