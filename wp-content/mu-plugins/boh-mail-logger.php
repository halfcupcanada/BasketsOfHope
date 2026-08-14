<?php
/**
 * Plugin Name: BoH Mail Logger (dev only)
 * Description: Intercepts every wp_mail call and writes the message to
 *              wp-content/mail.log. Bypasses real SMTP so local dev can
 *              verify form-submission emails without a working MTA.
 *              Active only when WP_DEBUG is true or hostname is localhost.
 */

defined( 'ABSPATH' ) || exit;

add_filter( 'pre_wp_mail', function ( $null, $atts ) {
	$host = $_SERVER['HTTP_HOST'] ?? php_uname( 'n' );
	$is_local = ( $host === 'localhost' || str_starts_with( $host, 'localhost:' ) || str_ends_with( $host, '.test' ) || str_ends_with( $host, '.local' ) );
	if ( ! $is_local && ! ( defined( 'WP_DEBUG' ) && WP_DEBUG ) ) {
		return $null; // let real wp_mail run
	}

	$to      = is_array( $atts['to'] ?? [] ) ? implode( ', ', $atts['to'] ) : (string) ( $atts['to'] ?? '' );
	$subject = (string) ( $atts['subject'] ?? '' );
	$message = (string) ( $atts['message'] ?? '' );
	$headers = $atts['headers'] ?? '';
	if ( is_array( $headers ) ) { $headers = implode( "\n", $headers ); }

	$entry = "\n" . str_repeat( '=', 72 ) . "\n";
	$entry .= '[' . gmdate( 'Y-m-d H:i:s' ) . " UTC] wp_mail()\n";
	$entry .= 'To:      ' . $to . "\n";
	$entry .= 'Subject: ' . $subject . "\n";
	$entry .= "Headers:\n" . trim( (string) $headers ) . "\n";
	$entry .= str_repeat( '-', 72 ) . "\n";
	$entry .= rtrim( $message ) . "\n";

	$log = WP_CONTENT_DIR . '/mail.log';
	@file_put_contents( $log, $entry, FILE_APPEND | LOCK_EX );

	return true; // short-circuit; mark as sent
}, 10, 2 );
