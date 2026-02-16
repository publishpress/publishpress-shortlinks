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
			add_action( 'pre_get_posts', array( $this, 'tinypress_filter_shortlink_preview_visibility' ) );
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

			$post_to_check = $link_id;

			if ( ! empty( $target_url ) && 'tinypress_link' == get_post_type( $link_id ) ) {
				// Try to get post ID from the URL
				$extracted_post_id = url_to_postid( $target_url );
				
				// If url_to_postid fails, try parsing query string for ?p= format
				if ( ! $extracted_post_id ) {
					$url_parts = parse_url( $target_url );
					if ( isset( $url_parts['query'] ) ) {
						parse_str( $url_parts['query'], $query_vars );
						if ( isset( $query_vars['p'] ) ) {
							$extracted_post_id = intval( $query_vars['p'] );
						}
					}
				}
				
				if ( $extracted_post_id ) {
					$post_to_check = $extracted_post_id;
				}
			}
			
			if ( ( empty( $target_url ) && 'tinypress_link' != get_post_type( $link_id ) ) || $post_to_check != $link_id ) {
				$post_status = get_post_status( $post_to_check );
				$post_object = get_post( $post_to_check );
				
				if ( ! $post_object ) {
				} else {
					$can_view_post = false;
					if ( is_user_logged_in() ) {
						$current_user_id = get_current_user_id();
						if ( $current_user_id == $post_object->post_author || current_user_can( 'edit_post', $post_to_check ) ) {
							$can_view_post = true;
						}
					}
					
					if ( ! $can_view_post ) {
						$allowed_statuses = Utils::get_option( 'tinypress_allowed_post_statuses' );
						
						if ( ! is_array( $allowed_statuses ) || empty( $allowed_statuses ) ) {
							$allowed_statuses = array();
						}

						if ( ! in_array( $post_status, $allowed_statuses ) ) {
							global $wp_query;
							$wp_query->set_404();
							status_header( 404 );
							nocache_headers();
							include( get_query_template( '404' ) );
							die();
						}
					}
					
					if ( $post_status !== 'publish' ) {
						$this->display_non_published_post( $post_to_check );
						die();
					}
				}
				
				if ( empty( $target_url ) && 'tinypress_link' != get_post_type( $link_id ) ) {
					$target_url = get_permalink( $link_id );
				}
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
		 * Display non-published post directly without redirecting
		 *
		 * @param int $link_id
		 * @return void
		 */
		protected function display_non_published_post( $link_id ) {
			global $wp_query, $post;
			
			// Get the post
			$post = get_post( $link_id );
			
			if ( ! $post ) {
				wp_die( esc_html__( 'Post not found.', 'tinypress' ) );
			}
			
			// Set up the query to display this post
			$wp_query->is_single = true;
			$wp_query->is_singular = true;
			$wp_query->is_404 = false;
			$wp_query->queried_object = $post;
			$wp_query->queried_object_id = $post->ID;
			$wp_query->posts = array( $post );
			$wp_query->post = $post;
			$wp_query->post_count = 1;
			
			setup_postdata( $post );
			
			status_header( 200 );
			include( get_query_template( 'single' ) );
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

			if ( is_user_logged_in() ) {
				$current_user_id = get_current_user_id();
				$post = get_post( $link_id );
				
				if ( $post && ( $current_user_id == $post->post_author || current_user_can( 'edit_post', $link_id ) ) ) {
					return;
				}
			}

			$get_ip_address = tinypress_get_ip_address();
			$curr_user_id   = is_user_logged_in() ? get_current_user_id() : 0;

			// Prevent duplicate tracking: check if this IP already tracked this link in the last 60 seconds
			$recent_track = $wpdb->get_var( $wpdb->prepare(
				"SELECT COUNT(*) FROM " . TINYPRESS_TABLE_REPORTS . "
				WHERE post_id = %d 
				AND user_ip = %s 
				AND datetime > DATE_SUB(NOW(), INTERVAL 60 SECOND)",
				$link_id,
				$get_ip_address
			) );

			if ( $recent_track > 0 ) {
				return;
			}

			$location_info  = array(
				'geoplugin_city'          => null,
				'geoplugin_region'        => null,
				'geoplugin_regionName'    => null,
				'geoplugin_countryCode'   => null,
				'geoplugin_countryName'   => null,
				'geoplugin_continentName' => null,
				'geoplugin_latitude'      => null,
				'geoplugin_longitude'     => null,
			);

			// Try to get geolocation data, but don't fail if it's unavailable
			$get_user_data = @file_get_contents( 'http://www.geoplugin.net/json.gp?ip=' . $get_ip_address );

			if ( $get_user_data ) {
				$user_data     = json_decode( $get_user_data, true );
				$location_keys = array_keys( $location_info );
				$location_info = array_merge( $location_info, array_intersect_key( $user_data, array_flip( $location_keys ) ) );
			}

			$wpdb->insert( TINYPRESS_TABLE_REPORTS,
				array(
					'user_id'       => $curr_user_id,
					'post_id'       => $link_id,
					'user_ip'       => $get_ip_address,
					'user_location' => json_encode( $location_info ),
				),
				array( '%d', '%d', '%s', '%s' )
			);

			do_action( 'tinypress_after_redirect_track', $link_id );
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

			// Fire action before tracking to allow auto-list links to be created first
			do_action( 'tinypress_before_redirect_track', $link_id );

			// After the action, check if a tinypress_link entry was created for this post
			// If so, use that ID for tracking instead of the source post ID
			$tracking_id = $link_id;
			if ( get_post_type( $link_id ) !== 'tinypress_link' ) {
				global $wpdb;
				$tinypress_link_id = $wpdb->get_var( $wpdb->prepare(
					"SELECT post_id FROM {$wpdb->postmeta} 
					WHERE meta_key = 'source_post_id' 
					AND meta_value = %d 
					AND post_id IN (SELECT ID FROM {$wpdb->posts} WHERE post_type = 'tinypress_link')",
					$link_id
				) );
				if ( $tinypress_link_id ) {
					$tracking_id = (int) $tinypress_link_id;
				}
			}

			$this->track_redirection( $tracking_id );

			$this->do_redirection( $link_id );
		}


		/**
		 * Redirection Controller
		 *
		 * @return void
		 */
		public function redirection_controller() {

			$link_prefix      = Utils::get_option( 'tinypress_link_prefix' );
			$link_prefix_slug = Utils::get_option( 'tinypress_link_prefix_slug', 'go' );
			$tiny_slug_1      = trim( $this->get_request_uri(), '/' );

			$is_prefix_request = ( '1' == $link_prefix && strpos( $tiny_slug_1, $link_prefix_slug ) !== false );

			if ( ! $is_prefix_request && ( is_single() || is_archive() ) ) {
				return;
			}

			$tiny_slug_2 = ( '1' == $link_prefix ) ? str_replace( $link_prefix_slug . '/', '', $tiny_slug_1 ) : $tiny_slug_1;
			$tiny_slug_3 = explode( '?', $tiny_slug_2 );
			$tiny_slug_4 = $tiny_slug_3[0] ?? '';
			$link_id     = tinypress()->tiny_slug_to_post_id( $tiny_slug_4 );

			if ( ! empty( $link_id ) && $link_id !== 0 ) {

				$is_shortlink_request = false;
				
				if ( '1' == $link_prefix ) {
					$is_shortlink_request = ( strpos( $tiny_slug_1, $link_prefix_slug ) !== false );
				} else {
					$is_shortlink_request = is_404();
				}
				
				if ( $is_shortlink_request && ! is_page( $tiny_slug_4 ) ) {

					if ( '1' == $link_prefix && strpos( $tiny_slug_1, $link_prefix_slug ) === false ) {
						wp_die( esc_html__( 'This link is not containing the right prefix slug.', 'tinypress' ) );
					}

					$this->check_protection( $link_id );
				}
			}
		}


		/**
		 * Filters the main query to determine post visibility for TinyPress shortlink previews.
		 *
		 * @param WP_Query $query
		 * @return void
		 */
		public function tinypress_filter_shortlink_preview_visibility( $query ) {
			if ( ! $query->is_main_query() ) {
				return;
			}
			
			if ( ! isset( $_GET['preview'] ) || $_GET['preview'] !== 'true' ) {
				return;
			}
			
			$allowed_statuses = Utils::get_option( 'tinypress_allowed_post_statuses' );

            if ( ! is_array( $allowed_statuses ) || empty( $allowed_statuses ) ) {
                $allowed_statuses = array();
            }
			
			if ( is_user_logged_in() && current_user_can( 'edit_posts' ) ) {
				$query->set( 'post_status', 'any' );
			} else {
				$query->set( 'post_status', $allowed_statuses );
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