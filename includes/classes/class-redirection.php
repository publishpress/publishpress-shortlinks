<?php
/**
 * Class Redirection
 *
 * @author Pluginbazar
 */

use WPDK\Utils;

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'TINYPRESS_Redirection' ) ) {
	/**
	 * Class TINYPRESS_Redirection
	 */
	class TINYPRESS_Redirection {

		protected static $_instance = null;

		/**
		 * TINYPRESS_Redirection constructor.
		 */
		function __construct() {
			add_action( 'template_redirect', array( $this, 'redirection_controller' ) );
		}


		/**
		 * Do the redirection
		 *
		 * @param $link_id
		 *
		 * @return void
		 */
		function do_redirection( $link_id ) {

			$tags       = array();
			$target_url = Utils::get_meta( 'target_url', $link_id );

			if ( empty( $target_url ) && 'tinypress_link' != get_post_type( $link_id ) ) {
				$target_url = get_permalink( $link_id );
			}

			$redirection_method   = Utils::get_meta( 'redirection_method', $link_id );
			$redirection_method   = $redirection_method ? $redirection_method : 302;
			$no_follow            = Utils::get_meta( 'redirection_no_follow', $link_id );
			$sponsored            = Utils::get_meta( 'redirection_sponsored', $link_id );
			$parameter_forwarding = Utils::get_meta( 'redirection_parameter_forwarding', $link_id );

			if ( '1' == $parameter_forwarding ) {

				$parameters = wp_unslash( $_GET );

				if ( isset( $parameters['password'] ) ) {
					unset( $parameters['password'] );
				}

				if ( ! empty( $parameters ) ) {
					$target_url = $target_url . '?' . http_build_query( $parameters );
				}
			}

			if ( '1' == $no_follow ) {
				$tags[] = 'noindex';
				$tags[] = 'nofollow';
			}

			if ( '1' == $sponsored ) {
				$tags[] = 'sponsored';
			}

			if ( ! empty( $tags ) ) {
				header( 'X-Robots-Tag: ' . implode( ', ', $tags ), true );
			}

			header( 'Cache-Control: no-store, no-cache, must-revalidate, max-age=0' );
			header( 'Cache-Control: post-check=0, pre-check=0', false );
			header( 'Pragma: no-cache' );
			header( 'Expires: Mon, 10 Oct 1975 08:09:15 GMT' );
			header( 'X-Redirect-Powered-By: TinyPress ' . TINYPRESS_PLUGIN_VERSION . ' https://pluginbazar.com' );

			header( "Location: $target_url", true, $redirection_method );

			die();
		}


		/**
		 * Track the redirection
		 *
		 * @param $link_id
		 *
		 * @return void
		 */
		function track_redirection( $link_id ) {

			global $wpdb;

			$get_ip_address = tinypress_get_ip_address();
			$curr_user_id   = is_user_logged_in() ? get_current_user_id() : 0;
			$get_user_data  = @file_get_contents( 'http://www.geoplugin.net/json.gp?ip=' . $get_ip_address );

			if ( ! $get_user_data ) {
				return;
			}

			$user_data     = json_decode( $get_user_data, true );
			$location_info = array(
				'geoplugin_city',
				'geoplugin_region',
				'geoplugin_regionName',
				'geoplugin_countryCode',
				'geoplugin_countryName',
				'geoplugin_continentName',
				'geoplugin_latitude',
				'geoplugin_longitude',
			);
			$location_info = array_merge( array_fill_keys( $location_info, null ), array_intersect_key( $user_data, array_flip( $location_info ) ) );

			$wpdb->insert( TINYPRESS_TABLE_REPORTS,
				array(
					'user_id'       => $curr_user_id,
					'post_id'       => $link_id,
					'user_ip'       => $get_ip_address,
					'user_location' => json_encode( $location_info ),
				),
				array( '%d', '%d', '%s', '%s' )
			);
		}


		/**
		 * Check security protocols for the redirection
		 *
		 * @param $link_id
		 *
		 * @return void
		 */
		function check_protection( $link_id ) {

			$current_url          = site_url( $this->get_request_uri() );
			$password_protection  = Utils::get_meta( 'password_protection', $link_id );
			$link_password        = Utils::get_meta( 'link_password', $link_id );
			$expiration_date      = Utils::get_meta( 'expiration_date', $link_id );
			$link_status          = Utils::get_meta( 'link_status', $link_id, '1' );
			$password_check_nonce = wp_create_nonce( 'password_check' );

			if ( parse_url( $current_url, PHP_URL_QUERY ) ) {
				$password_checked_url = $current_url . '&password=' . $password_check_nonce;
			} else {
				$password_checked_url = $current_url . '?password=' . $password_check_nonce;
			}

			if ( 'tinypress_link' == get_post_type( $link_id ) ) {
				// Check if the link status is enabled
				if ( '1' != $link_status ) {
					wp_die( esc_html__( 'This link is not active.', 'tinypress' ) );
				}

				// Check if the link is expired or not
				if ( ! empty( $expiration_date ) ) {
					$expiration_date = $expiration_date . ' 23:59:59';

					if ( current_time( 'd-m-Y G:i:s' ) > $expiration_date ) {
						wp_die( esc_html__( 'This link is expired.', 'tinypress' ) );
					}
				}
			}

			// Check the password protection for this link
			if ( '1' != $password_protection ) {
				$this->redirect_url( $link_id );
			}

			?>
            <script>
                if ('<?php echo esc_attr( $link_password ); ?>' === prompt("Password:")) {
                    window.location.href = '<?php echo esc_url( $password_checked_url ); ?>';
                } else {
                    window.location.href = '<?php echo esc_url( $current_url ); ?>';
                }
            </script>
			<?php

			$password_nonce = isset( $_GET['password'] ) ? sanitize_text_field( $_GET['password'] ) : '';

			if ( wp_verify_nonce( $password_nonce, 'password_check' ) ) {
				$this->redirect_url( $link_id );
			}

			die();
		}


		/**
		 * Redirect to target URL
		 *
		 * @return void
		 */
		function redirect_url( $link_id ) {

			// Track redirection
			$this->track_redirection( $link_id );

			// Do the redirection
			$this->do_redirection( $link_id );
		}


		/**
		 * Redirection Controller
		 *
		 * @return void
		 */
		public function redirection_controller() {

			if ( is_single() || is_archive() ) {
				return;
			}

			$link_prefix      = Utils::get_option( 'tinypress_link_prefix' );
			$link_prefix_slug = Utils::get_option( 'tinypress_link_prefix_slug', 'go' );

			$tiny_slug_1 = trim( $this->get_request_uri(), '/' );
			$tiny_slug_2 = ( '1' == $link_prefix ) ? str_replace( $link_prefix_slug . '/', '', $tiny_slug_1 ) : $tiny_slug_1;
			$tiny_slug_3 = explode( '?', $tiny_slug_2 );
			$tiny_slug_4 = $tiny_slug_3[0] ?? '';
			$link_id     = tinypress()->tiny_slug_to_post_id( $tiny_slug_4 );

			if ( ! empty( $link_id ) && $link_id !== 0 && is_404() && ! is_page( $tiny_slug_4 ) ) {

				if ( '1' == $link_prefix && strpos( $tiny_slug_1, $link_prefix_slug ) === false ) {
					wp_die( esc_html__( 'This link is not containing the right prefix slug.', 'tinypress' ) );
				}

				$this->check_protection( $link_id );
			}
		}


		/**
		 * Return request URI from SERVER
		 *
		 * @return string
		 */
		protected function get_request_uri() {

			$current_url = isset( $_SERVER ['SCRIPT_URI'] ) ? sanitize_text_field( $_SERVER ['SCRIPT_URI'] ) : '';

			if ( empty( $current_url ) ) {
				$current_url = isset( $_SERVER ['REQUEST_URI'] ) ? sanitize_text_field( $_SERVER ['REQUEST_URI'] ) : '';
			}

			return str_replace( site_url(), '', $current_url );
		}


		/**
		 * @return TINYPRESS_Redirection
		 */
		public static function instance() {
			if ( is_null( self::$_instance ) ) {
				self::$_instance = new self();
			}

			return self::$_instance;
		}
	}
}