<?php
/*
Plugin Name: Alternate Contact Info
Plugin URI: http://wordpress.org/extend/plugins/alternate-contact-info/
Description: Allows entry and verification of secondary email address and cell phone number.
Version: 0.0
Author: Casey Bisson
Author URI: http://maisonbisson.com/
*/

if ( !function_exists('get_user_by_email') ) :
function get_user_by_email( $email ) {
	return( _get_user_by_email( $email ));
}
endif;

function _get_user_by_email( $email ) {
	global $wpdb;

	$user_id = wp_cache_get( $email, 'useremail' );

	if( false !== $user_id )
		return get_userdata( $user_id );

	if( ( $user = $wpdb->get_row( $wpdb->prepare("SELECT * FROM $wpdb->users WHERE user_email = %s", $email ))) || ( $user = $wpdb->get_row( $wpdb->prepare("SELECT u.* FROM ( SELECT user_id FROM $wpdb->usermeta WHERE meta_key = 'email_alt' and meta_value = %s LIMIT 1 ) m JOIN $wpdb->users u ON m.user_id = u.ID LIMIT 1", $email ))) ){
		_fill_user( $user );
	
		wp_cache_add( get_usermeta( $user->ID, 'email_alt'), $user->ID, 'useremail');
	
		return $user;
	}else{
		return false;
	}
}

function get_user_by_phone( $phone ) {
	global $wpdb;

	$phone = sanitize_phone( $phone );

	$user_id = wp_cache_get( $phone, 'userphone' );

	if( false !== $user_id )
		return get_userdata( $user_id );

	if( $user = $wpdb->get_row( $wpdb->prepare( "SELECT u.* FROM ( SELECT user_id FROM $wpdb->usermeta WHERE meta_key = 'phone' and meta_value = %s LIMIT 1 ) m JOIN $wpdb->users u ON m.user_id = u.ID LIMIT 1", $phone )) ){
		_fill_user( $user );
	
		wp_cache_add( get_usermeta( $user->ID, 'phone'), $user->ID, 'userphone');
	
		return $user;
	}else{
		return false;
	}
}

function sanitize_phone( $phone ){
	$phone = preg_replace( '/[^0-9]/', '', $phone);
	if( strlen( $phone ) > 10 )
		return $phone;

	if( strlen( $phone ) == 10 )
		return '1'. $phone;

	return FALSE;
}

function ac_delete_waiting( $user_id, $field, $ticket ) {
	global $wptix;

	switch( $field ){
		case 'email':
		case 'email_alt':
		case 'phone':
			break;
		default:
			return( FALSE );
	}

	delete_usermeta( $user_id, $field .'_waiting' );
	$wptix->delete_ticket( $ticket );
}

function ac_user_profile_ob_start() {
	ob_start( 'ac_user_profile_ob_content' );
}

function ac_user_profile_ob_content( $content ) {
	global $user_id;

	$email_waiting = $email_alt_waiting = $phone_waiting = '';
	
	$notice_email = '<p>Any change to your email address must be confirmed. You will receive a message at your new address with instructions.</p>';

	$notice_phone = '<p>A text message will be sent to your phone to confirm phone number changes. Standard messaging rates apply.</p>';
	
	if( $waiting = get_usermeta( $user_id, 'email_waiting' ))
		if( time() > $waiting['arg']['expire'] )
			ac_delete_waiting( $user_id, 'email', $waiting['ticket'] );
		else
			$email_waiting = '<p class="awaiting-confirmation">Awaiting confirmation of <code>'. $waiting['arg']['val'] .'</code>.<br />Message sent '. $waiting['arg']['sent'] .'. Re-enter the address to send a new confirmation email.</p>';

	if( $waiting = get_usermeta( $user_id, 'email_alt_waiting' ))
		if( time() > $waiting['arg']['expire'] )
			ac_delete_waiting( $user_id, 'email_alt', $waiting['ticket'] );
		else
			$email_alt_waiting = '<p class="awaiting-confirmation">Awaiting confirmation of <code>'. $waiting['arg']['val'] .'</code>.<br />Message sent '. $waiting['arg']['sent'] .'. Re-enter the address to send a new confirmation email.</p>';

	if( $waiting = get_usermeta( $user_id, 'phone_waiting' ))
		if( time() > $waiting['arg']['expire'] )
			ac_delete_waiting( $user_id, 'phone', $waiting['ticket'] );
		else
			$phone_waiting = '<p class="awaiting-confirmation">Awaiting confirmation of <code>'. $waiting['arg']['val'] .'</code>.<br />SMS text message sent '. $waiting['arg']['sent'] .'.</p><p>Have your confirmation code? Enter it here: <input type="text" name="phone_confirmation" id="phone_confirmation" value="" class="regular-text phone-confirm-code" /><p>Or re-enter the phone number to send a new confirmation message.</p>';
	$content = preg_replace( '/<tr>[^<]*<th><label for="email">.+?<\/label><\/th>[^<]*<td><input type="text" name="email" id="email" [^>]*>.+?<\/td>[^<]*<\/tr>/s', '<tr>
	<th><label for="email">E-mail</label></th>
	<td><input type="text" name="email" id="email" value="'. get_usermeta( $user_id, 'user_email' ) .'"  />'. $email_waiting . $notice_email .'</td>
<tr>
	<th><label for="email">Alternate E-mail</label></th>
	<td><input type="text" name="email_alt" id="email_alt" value="'. get_usermeta( $user_id, 'email_alt' )  .'" />'. $email_alt_waiting . $notice_email .'</td>
</tr>
<tr>
	<th><label for="email">Cell Phone</label></th>
	<td><input type="text" name="phone" id="phone" value="'. get_usermeta( $user_id, 'phone' )  .'" />'. $phone_waiting . $notice_phone .'</td>
</tr>
', $content );

	return( $content );
}

function ac_user_profile_save() {
	global $errors, $wpdb, $wptix, $current_user, $current_site;
	$errors = new WP_Error();

	// is there a wptix confirmation code in the mix?
	if( isset( $_POST['phone_confirmation'] ) && ( !empty( $_POST['phone_confirmation'] ))){
		if( ( $ticket = $wptix->is_ticket( $_POST['phone_confirmation'] )) && $ticket->arg['user_id'] == $current_user->ID ){
			$wptix->do_ticket( $_POST['phone_confirmation'] );
		}else{
			$errors->add( 'user_phone', __( "<strong>ERROR</strong>: The confirmation code is invalid." ), array( 'form-field' => 'phone_confirmation' ) );
			return;
		}
	}

	// is the primary email address changing?
	if( isset( $_POST['email'] ) && ( $current_user->user_email != sanitize_email( $_POST['email'] ))) {

		$_POST['email'] = sanitize_email( $_POST['email'] );

		// confirm that the submitted email address is valid
		if ( !is_email( $_POST['email'] ) ) {
			$errors->add( 'user_email', __( "<strong>ERROR</strong>: The e-mail address isn't correct." ), array( 'form-field' => 'email' ) );
			return;
		}

		// confirm that it's not used elsewhere
		if( _get_user_by_email( $_POST['email'] )){
			$errors->add( 'user_email', __( "<strong>ERROR</strong>: The e-mail address is already used." ), array( 'form-field' => 'email' ) );
			return;
		}

		// delete any previous addys waiting to be confirmed
		if( $waiting = get_usermeta( $current_user->ID, 'email_waiting' ))
			ac_delete_waiting( $current_user->ID, 'email', $waiting['ticket'] );

		// notify user to confirm new addy
		ac_notify_email( $current_user, $_POST['email'], 'email' );

		// reset the email address to the current
		$_POST['email'] = $current_user->user_email;
	}

	// is the alternate email address changing?
	if( isset( $_POST['email_alt'] ) && ( $current_user->email_alt != sanitize_email( $_POST['email_alt'] ))) {

		$_POST['email_alt'] = sanitize_email( $_POST['email_alt'] );

		// confirm that the submitted email address is valid
		if ( !is_email( $_POST['email_alt'] ) ) {
			$errors->add( 'user_email', __( "<strong>ERROR</strong>: The e-mail address isn't correct." ), array( 'form-field' => 'email_alt' ) );
			return;
		}

		// confirm that it's not used elsewhere
		if( _get_user_by_email( $_POST['email_alt'] )){
			$errors->add( 'user_email', __( "<strong>ERROR</strong>: The e-mail address is already used." ), array( 'form-field' => 'email_alt' ) );
			return;
		}

		// delete any previous addys waiting to be confirmed
		if( $waiting = get_usermeta( $current_user->ID, 'email_alt_waiting' ))
			ac_delete_waiting( $current_user->ID, 'email_alt', $waiting['ticket'] );

		// notify user to confirm new addy
		ac_notify_email( $current_user, $_POST['email_alt'], 'email_alt' );
	}

	// is the phone number changing?
	if( isset( $_POST['phone'] ) && ( $current_user->phone != sanitize_phone( $_POST['phone'] ))) {

		$_POST['phone'] = sanitize_phone( $_POST['phone'] );

		// if the sanitized phone number is empty, then fail
		if ( empty( $_POST['phone'] ) ) {
			$errors->add( 'user_phone', __( "<strong>ERROR</strong>: The phone number appears too short. Please enter a complete phone number in the form of 1-XXX-XXX-XXXX" ), array( 'form-field' => 'phone' ) );
			return;
		}

		// confirm that it's not used elsewhere
		if( get_user_by_phone( $_POST['phone'] )){
			$errors->add( 'user_phone', __( "<strong>ERROR</strong>: The phone number is already used." ), array( 'form-field' => 'phone' ) );
			return;
		}

		// delete any previous phones waiting to be confirmed
		if( $waiting = get_usermeta( $current_user->ID, 'phone_waiting' ))
			ac_delete_waiting( $current_user->ID, 'phone', $waiting['ticket'] );

		// notify user to confirm new addy
		if( ! ac_notify_phone( $current_user, $_POST['phone'], 'phone' )){
			$errors->add( 'user_phone', __( "<strong>ERROR</strong>: The confirmation message could not be sent. The entered phone number may not be a valid cell phone, please check and try again." ), array( 'form-field' => 'phone' ) );
			return;
		}
	}


//print_r( $wpdb->queries );
//die();

}



function ac_notify_phone( $user, $phone, $field ){
	global $wpdb, $wptix, $current_site;

	require_once( dirname(__FILE__).'/sms-conf.php' ); // attempt to fetch the optional config file

	// make the challenge/response code
	$secret = $wptix->generate_string(5, '123456789ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz-!');
	
	$ticket = $wptix->register_ticket( 'ac_confirm_change', $secret, array( 'user_id' => (int) $user->ID, 'val' => $phone, 'field' => $field, 'sent' => date_i18n( get_option('date_format') ) .' at '. date_i18n( get_option('time_format') ), 'expire' => time() + 259200	 ));

	// generate the sms text message
	$content = apply_filters( 'ac_confirm_phone_content', __("Your ###SITENAME### phone confirmation code is: ###ADMIN_URL###"), $ticket );

	$content = str_replace( array(
		'###ADMIN_URL###',
		'###SITENAME###',
	), 
	array(
		$ticket->ticket,
		get_site_option( 'site_name' ),
	), $content);

	// send SMS confirmation
	$wpsms = new wpSMS( $ac_sms_api_id, $ac_sms_user, $ac_sms_pass );
	if( !$wpsms->send( $content, $phone )){
		$wptix->delete_ticket( $secret );
		return FALSE;
	}

	// also store the ticket in the user meta table
	update_usermeta( (int) $user->ID, $field .'_waiting', (array) $ticket );

	return TRUE;
}

function ac_notify_email( $user, $email, $field ){
	global $wpdb, $wptix, $current_site;

	// make the challenge/response code
	$secret = $wptix->generate_md5();
	
	// register the ticket
	$ticket = $wptix->register_ticket( 'ac_confirm_change', $secret, array( 'user_id' => (int) $user->ID, 'val' => $email, 'field' => $field, 'sent' => date_i18n( get_option('date_format') ) .' at '. date_i18n( get_option('time_format') ), 'expire' => time() + 259200	 ));

	if( false === $ticket ) {
		do_action( 'wptix_debug', 'register_ticket returned false in ' .  __FUNCTION__ . ', user: ' . print_r($user, true) );
		return false;
	}

	// generate an email message
	$content = apply_filters( 'ac_confirm_email_content', __("
You recently requested to add or change an email address associated with your ###SITENAME### account.
If you initiated this request please click the link below confirm the change.

If you did not request this action, ignore this email. Nothing will happen and the link will automatically expire in 3 days.

###ADMIN_URL###

This email has been sent to ###EMAIL###
"), $ticket );

	$missing = array();
	if( empty($ticket->url) ) {
		$missing[] = 'ticket URL';
	} 
	if( empty($email) ) {
		$missing[] = 'email';
	}

	if( count($missing) ) {
		$missing = implode( ' and ', $missing );
		do_action( 'wptix_debug', "Missing $missing in " . __FUNCTION__ . ', user: ' . print_r($user, true) );
		return false;
	}

	$content = str_replace( array(
		'###ADMIN_URL###',
		'###EMAIL###',
		'###SITENAME###',
		'###SITEURL###',
	), 
	array(
		$ticket->url,
		$email,
		get_site_option( 'site_name' ),
		'http://' . $current_site->domain . $current_site->path,
	), $content);

	wp_mail( $email, sprintf(__('[%s] New Email Address'), get_option('blogname')), $content );

	// also store the ticket in the user meta table
	update_usermeta( (int) $user->ID, $field .'_waiting', (array) $ticket );
}

function ac_confirm_change( $args, $ticket ) {
	if ( !is_user_logged_in() )
		auth_redirect();

	global $current_user;

	if( $current_user->ID == $args['user_id'] ){
		if( time() > $args['expire'] ){
			ac_delete_waiting( $current_user->ID, $args['field'], $ticket->ticket );
			die( wp_redirect( admin_url( 'profile.php' )));
		}
	
		if( 'email' == $args['field'] ){
			// delete any cache entry for the old email
			wp_cache_delete( $current_user->user_email, 'useremail');

			// update the user table
			require_once(ABSPATH . WPINC . '/registration.php');
			$user->ID = $current_user->ID;
			$user->user_email = $args['val'];
			wp_update_user( get_object_vars( $user ));
		}else{
			// delete any cache entry for the old data
			wp_cache_delete( $current_user->email_alt, 'useremail');
			wp_cache_delete( $current_user->phone, 'userphone');

			update_usermeta( (int) $current_user->ID, (string) $args['field'], $args['val'] );
		}

		ac_delete_waiting( $current_user->ID, $args['field'], $ticket->ticket );

		die( wp_redirect( add_query_arg( array('updated' => 'true'), admin_url( 'profile.php' ))));
	}else{
		die( wp_redirect( admin_url( 'profile.php' )));
	}
}

function ac_init(){
	add_action( 'personal_options_update', 'ac_user_profile_save' );
	add_action( 'edit_user_profile_update', 'ac_user_profile_save' );

	if( strpos( $_SERVER['PHP_SELF'], 'profile.php' ) || strpos( $_SERVER['PHP_SELF'], 'user-edit.php' )) {
		add_action( 'admin_init', 'ac_user_profile_ob_start', 2 );
	
		// remove WPMU action
		remove_action( 'admin_init', 'update_profile_email', 10 );
		remove_action( 'admin_init', 'profile_page_email_warning_ob_start', 10 );
		remove_action( 'admin_notices', 'new_user_email_admin_notice', 10 );
	
	}

	// remove WPMU action
	remove_action( 'personal_options_update', 'send_confirmation_on_profile_email', 10 );
}
add_action( 'admin_init', 'ac_init', 1 );
add_action( 'ac_confirm_change', 'ac_confirm_change', 5, 2 );
