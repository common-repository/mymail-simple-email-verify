<?php
/*
Plugin Name: Simple Email Verify for MyMail
Plugin URI: https://evp.to/mymail?utm_campaign=wporg&utm_source=Simple+Email+Verify+for+MyMail
Description: Verifies your subscribers email addresses
Version: 0.4
Author: EverPress
Author URI: https://everpress.co

License: GPLv2 or later
*/


define( 'MYMAIL_SEV_VERSION', '0.4' );
define( 'MYMAIL_SEV_REQUIRED_VERSION', '2.1.13' );

class MyMailSimpleEmailVerify {

	private $plugin_path;
	private $plugin_url;

	/**
	 *
	 */
	public function __construct() {

		$this->plugin_path = plugin_dir_path( __FILE__ );
		$this->plugin_url  = plugin_dir_url( __FILE__ );

		register_activation_hook( __FILE__, array( &$this, 'activate' ) );
		register_deactivation_hook( __FILE__, array( &$this, 'deactivate' ) );

		load_plugin_textdomain( 'mymail-sev', false, dirname( plugin_basename( __FILE__ ) ) . '/lang' );

		add_action( 'init', array( &$this, 'init' ), 1 );
	}


	/**
	 *
	 *
	 * @param unknown $network_wide
	 */
	public function activate( $network_wide ) {

		if ( function_exists( 'mymail' ) ) {

			mymail_notice( sprintf( __( 'Define your verification options on the %s!', 'mymail-sev' ), '<a href="options-general.php?page=newsletter-settings&mymail_remove_notice=sev#sev">Settings Page</a>' ), '', false, 'sev' );

			$defaults = array(
				'sev_import'        => false,
				'sev_check_mx'      => true,
				'sev_check_smtp'    => false,
				'sev_check_error'   => __( 'Sorry, your email address is not accepted!', 'mymail-sev' ),
				'sev_dep'           => false,
				'sev_dep_error'     => __( 'Sorry, your email address is not accepted!', 'mymail-sev' ),
				'sev_domains'       => '',
				'sev_domains_error' => __( 'Sorry, your email address is not accepted!', 'mymail-sev' ),
			);

			$mymail_options = mymail_options();

			foreach ( $defaults as $key => $value ) {
				if ( ! isset( $mymail_options[ $key ] ) ) {
					mymail_update_option( $key, $value );
				}
			}
		}

	}


	/**
	 *
	 *
	 * @param unknown $network_wide
	 */
	public function deactivate( $network_wide ) {}


	/**
	 *
	 */
	public function init() {

		if ( ! function_exists( 'mymail' ) ) {

			add_action( 'admin_notices', array( $this, 'notice' ) );

		} else {

			if ( is_admin() ) {

				add_filter( 'mymail_setting_sections', array( &$this, 'settings_tab' ) );
				add_action( 'mymail_section_tab_sev', array( &$this, 'settings' ) );

				add_filter( 'mymail_verify_options', array( &$this, 'verify_options' ) );
			}

			add_action( 'mymail_verify_subscriber', array( $this, 'verify_subscriber' ) );
			add_action( 'wp_version_check', array( $this, 'get_dea_domains' ) );

		}

		if ( function_exists( 'mailster' ) ) {

			add_action(
				'admin_notices',
				function() {

					$name = 'Simple Email Verify for MyMail';
					$slug = 'mailster-email-verify/mailster-email-verify.php';

					$install_url = wp_nonce_url( self_admin_url( 'update.php?action=install-plugin&plugin=' . dirname( $slug ) ), 'install-plugin_' . dirname( $slug ) );

					$search_url = add_query_arg(
						array(
							's'    => $slug,
							'tab'  => 'search',
							'type' => 'term',
						),
						admin_url( 'plugin-install.php' )
					);

					?>
			<div class="error">
				<p>
				<strong><?php echo esc_html( $name ); ?></strong> is deprecated in Mailster and no longer maintained! Please switch to the <a href="<?php echo esc_url( $search_url ); ?>">new version</a> as soon as possible or <a href="<?php echo esc_url( $install_url ); ?>">install it now!</a>
				</p>
			</div>
					<?php

				}
			);
		}

	}


	/**
	 *
	 *
	 * @param unknown $entry
	 * @return unknown
	 */
	public function verify_subscriber( $entry ) {

		if ( ! isset( $entry['email'] ) ) {
			return $entry;
		}
		if ( ! mymail_option( 'sev_import' ) && defined( 'MYMAIL_DO_BULKIMPORT' ) && MYMAIL_DO_BULKIMPORT ) {
			return $entry;
		}

		$is_valid = $this->verify( $entry['email'] );
		if ( is_wp_error( $is_valid ) ) {
			return $is_valid;
		}

		return $entry;

	}


	/**
	 *
	 *
	 * @param unknown $email
	 * @return unknown
	 */
	public function verify( $email ) {

		list( $user, $domain ) = explode( '@', $email );

		// check for whitelisted
		$whitelisted_domains = explode( "\n", mymail_option( 'sev_whitelist', '' ) );
		if ( in_array( $domain, $whitelisted_domains ) ) {
			return true; }

		// check for domains
		$blacklisted_domains = explode( "\n", mymail_option( 'sev_domains', '' ) );
		if ( in_array( $domain, $blacklisted_domains ) ) {
			return new WP_Error( 'sev_domains_error', mymail_option( 'sev_domains_error' ), 'email' ); }

		// check DEP
		if ( $dea_domains = $this->get_dea_domains( false ) ) {
			if ( in_array( $domain, $dea_domains ) ) {
				return new WP_Error( 'sev_dep_error', mymail_option( 'sev_dep_error' ), 'email' ); }
		}

		// check MX record
		if ( mymail_option( 'sev_check_mx' ) && function_exists( 'checkdnsrr' ) ) {
			if ( ! checkdnsrr( $domain, 'MX' ) ) {
				return new WP_Error( 'sev_check_error', mymail_option( 'sev_check_error' ), 'email' ); }
		}

		// check via SMTP server
		if ( mymail_option( 'sev_check_smtp' ) ) {

			require_once $this->plugin_path . '/classes/smtp-validate-email.php';

			$from = mymail_option( 'from' );

			$validator    = new SMTP_Validate_Email( $email, $from );
			$smtp_results = $validator->validate();
			$valid        = ! ! array_sum( $smtp_results['domains'][ $domain ]['mxs'] );

			if ( ! $valid ) {
				return new WP_Error( 'sev_check_error', mymail_option( 'sev_check_error' ), 'email' ); }
		}

		return true;

	}


	/**
	 *
	 *
	 * @param unknown $check (optional)
	 * @return unknown
	 */
	public function get_dea_domains() {

		if ( ! mymail_option( 'sev_dep' ) ) {
			return array();
		}

		$file = $this->plugin_path . '/dea.txt';
		if ( ! file_exists( $file ) ) {
			mymail_update_option( 'sev_dep', false );
			return array();
		}
		$raw     = file_get_contents( $file );
		$domains = explode( "\n", $raw );
		return $domains;

	}


	/**
	 *
	 *
	 * @param unknown $settings
	 * @return unknown
	 */
	public function settings_tab( $settings ) {

		$position = 3;
		$settings = array_slice( $settings, 0, $position, true ) +
			array( 'sev' => __( 'Email Verification', 'mymail-sev' ) ) +
			array_slice( $settings, $position, null, true );

		return $settings;
	}


	/**
	 *
	 */
	public function settings() {

		?>
	<table class="form-table">
		<tr valign="top">
			<th scope="row"><?php _e( 'Simple Checks', 'mymail-sev' ); ?></th>
			<td>
			<p><label><input type="hidden" name="mymail_options[sev_check_mx]" value=""><input type="checkbox" name="mymail_options[sev_check_mx]" value="1" <?php checked( mymail_option( 'sev_check_mx' ) ); ?>><?php _e( 'Check MX record', 'mymail' ); ?></label><br><span class="description"><?php _e( 'Check the domain for an existing MX record.', 'mymail-sev' ); ?></span>
			</p>
			<p><label><input type="hidden" name="mymail_options[sev_check_smtp]" value=""><input type="checkbox" name="mymail_options[sev_check_smtp]" value="1" <?php checked( mymail_option( 'sev_check_smtp' ) ); ?>><?php _e( 'Validate via SMTP', 'mymail' ); ?></label><br><span class="description"><?php _e( 'Connects the domain\'s SMTP server to check if the address really exists.', 'mymail-sev' ); ?></span></p>
			<p><strong><?php _e( 'Error Message', 'mymail-sev' ); ?>:</strong>
			<input type="text" name="mymail_options[sev_check_error]" value="<?php echo esc_attr( mymail_option( 'sev_check_error' ) ); ?>" class="large-text"></p>
			</td>
		</tr>
		<tr valign="top">
			<th scope="row"><?php _e( 'Disposable Email Provider', 'mymail-sev' ); ?></th>
			<td>
			<p><label><input type="hidden" name="mymail_options[sev_dep]" value=""><input type="checkbox" name="mymail_options[sev_dep]" value="1" <?php checked( mymail_option( 'sev_dep' ) ); ?>><?php _e( 'reject email addresses from disposable email providers (DEP).', 'mymail' ); ?></label></p>
			<p><strong><?php _e( 'Error Message', 'mymail-sev' ); ?>:</strong>
			<input type="text" name="mymail_options[sev_dep_error]" value="<?php echo esc_attr( mymail_option( 'sev_dep_error' ) ); ?>" class="large-text"></p>
			</td>
		</tr>
		<tr valign="top">
			<th scope="row"><?php _e( 'Reject Domains', 'mymail-sev' ); ?></th>
			<td>
			<p><?php _e( 'List of blacklisted domains. One domain each line.', 'mymail-sev' ); ?><br>
			<textarea name="mymail_options[sev_domains]" placeholder="<?php echo "blacklisted.com\nblacklisted.co.uk\nblacklisted.de"; ?>" class="code large-text" rows="10"><?php echo esc_attr( mymail_option( 'sev_domains' ) ); ?></textarea></p>
			<p><strong><?php _e( 'Error Message', 'mymail-sev' ); ?>:</strong>
			<input type="text" name="mymail_options[sev_domains_error]" value="<?php echo esc_attr( mymail_option( 'sev_domains_error' ) ); ?>" class="large-text"></p>
			</td>
		</tr>
		<tr valign="top">
			<th scope="row"><?php _e( 'White listed Domains', 'mymail-sev' ); ?></th>
			<td>
			<p><?php _e( 'List domains which bypass the above rules. One domain each line.', 'mymail-sev' ); ?><br>
			<textarea name="mymail_options[sev_whitelist]" placeholder="<?php echo "whitelisted.com\nwhitelisted.co.uk\nwhitelisted.de"; ?>" class="code large-text" rows="10"><?php echo esc_attr( mymail_option( 'sev_whitelist' ) ); ?></textarea></p>
			</td>
		</tr>
	</table>
	<table class="form-table">
		<tr valign="top">
			<th scope="row"><?php _e( 'Import', 'mymail-sev' ); ?></th>
			<td><p><label><input type="hidden" name="mymail_options[sev_import]" value=""><input type="checkbox" name="mymail_options[sev_import]" value="1" <?php checked( mymail_option( 'sev_import' ) ); ?>> use for import</label></p>
			<p class="description">This will significantly increase import time because for every subscriber WordPress needs to verify the email address on the given domain. It's better to import a cleaned list.</p>
			</td>
		</tr>
	</table>
		<?php
	}


	/**
	 *
	 *
	 * @param unknown $options
	 * @return unknown
	 */
	public function verify_options( $options ) {

		$options['sev_domains'] = trim( preg_replace( '/(?:(?:\r\n|\r|\n|\s)\s*){2}/s', "\n", $options['sev_domains'] ) );
		$options['sev_domains'] = explode( "\n", $options['sev_domains'] );
		$options['sev_domains'] = array_unique( $options['sev_domains'] );
		sort( $options['sev_domains'] );
		$options['sev_domains'] = implode( "\n", $options['sev_domains'] );

		$options['sev_whitelist'] = trim( preg_replace( '/(?:(?:\r\n|\r|\n|\s)\s*){2}/s', "\n", $options['sev_whitelist'] ) );
		$options['sev_whitelist'] = explode( "\n", $options['sev_whitelist'] );
		$options['sev_whitelist'] = array_unique( $options['sev_whitelist'] );
		sort( $options['sev_whitelist'] );
		$options['sev_whitelist'] = implode( "\n", $options['sev_whitelist'] );

		if ( $options['sev_dep'] ) {
			$this->get_dea_domains();
		}

		return $options;
	}


	/**
	 *
	 */
	public function notice() {
		?>
	<div id="message" class="error">
	  <p>
	   <strong>Simple Email Verify for MyMail</strong> requires the <a href="https://evp.to/mymail?utm_campaign=wporg&utm_source=Simple+Email+Verify+for+MyMail">MyMail Newsletter Plugin</a>, at least version <strong><?php echo MYMAIL_SEV_REQUIRED_VERSION; ?></strong>. Plugin deactivated.
	  </p>
	</div>
		<?php
	}


}


new MyMailSimpleEmailVerify();
