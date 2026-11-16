<?php
/**
 * Dev and tests only (mounted by dev/compose.forms.yml): while the option `lb_dev_mail_log` is an array, every email
 * of wp_mail() is appended to it instead of being sent, so the tests read what would have left. Fluent Forms' limit of
 * five submissions every thirty seconds from one address is off: tests/wp/forms.test.mjs sends more.
 */
add_filter( 'fluentform/prevent_malicious_attacks', '__return_false' );

add_filter(
	'pre_wp_mail',
	static function ( $result, $atts ) {
		$log = get_option( 'lb_dev_mail_log', null );
		if ( ! is_array( $log ) ) {
			return $result;
		}
		$to = $atts['to'] ?? '';
		$log[] = array(
			'to'      => is_array( $to ) ? implode( ', ', $to ) : (string) $to,
			'subject' => (string) ( $atts['subject'] ?? '' ),
			'message' => (string) ( $atts['message'] ?? '' ),
		);
		update_option( 'lb_dev_mail_log', $log, false );
		return true;
	},
	10,
	2
);
