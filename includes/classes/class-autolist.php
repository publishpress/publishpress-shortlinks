<?php
/**
 * Class Auto List
 * Handles automatic listing of post type shortlinks in the All Links table
 *
 * @author Deji98
 */

use WPDK\Utils;

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'TINYPRESS_AutoList' ) ) {
	/**
	 * Class TINYPRESS_AutoList
	 */
	class TINYPRESS_AutoList {

		protected static $_instance = null;

		/**
		 * TINYPRESS_AutoList constructor.
		 */
		function __construct() {
			add_action( 'transition_post_status', array( $this, 'tinypress_handle_post_publish' ), 10, 3 );
			add_action( 'tinypress_before_redirect_track', array( $this, 'tinypress_handle_first_use' ), 10, 1 );
			add_action( 'updated_post_meta', array( $this, 'sync_tiny_slug_to_source' ), 10, 4 );
			add_action( 'save_post_tinypress_link', array( $this, 'tinypress_sync_on_link_save' ), 20, 3 );
		}

		/**
		 * Check if auto-listing is enabled
		 *
		 * @return bool
		 */
		function is_autolist_enabled() {
			$settings = get_option( 'tinypress_settings', array() );
			$enabled = isset( $settings['tinypress_autolist_enabled'] ) && '1' == $settings['tinypress_autolist_enabled'];
			return $enabled;
		}

		/**
		 * Get behavior for a specific post type
		 *
		 * @param string $post_type
		 *
		 * @return string|null
		 */
		function get_post_type_behavior( $post_type ) {
			if ( ! $this->is_autolist_enabled() ) {
				return null;
			}

			$settings = get_option( 'tinypress_settings', array() );
			$post_type_settings = isset( $settings['tinypress_autolist_post_types'] ) ? $settings['tinypress_autolist_post_types'] : array();

			if ( empty( $post_type_settings ) || ! is_array( $post_type_settings ) ) {
				return null;
			}

			foreach ( $post_type_settings as $setting ) {
				if ( isset( $setting['post_type'] ) && $setting['post_type'] === $post_type ) {
					$behavior = isset( $setting['behavior'] ) ? $setting['behavior'] : null;
					return $behavior;
				}
			}

			return null;
		}

		/**
		 * Check if a tinypress_link entry already exists for this post
		 *
		 * @param int $post_id
		 *
		 * @return int|null Post ID of existing tinypress_link or null
		 */
		function get_existing_tinypress_link_entry( $post_id ) {
			global $wpdb;

			$link_id = $wpdb->get_var( $wpdb->prepare(
				"SELECT post_id FROM {$wpdb->postmeta} 
				WHERE meta_key = 'source_post_id' 
				AND meta_value = %d 
				AND post_id IN (SELECT ID FROM {$wpdb->posts} WHERE post_type = 'tinypress_link')",
				$post_id
			) );

			return $link_id ? (int) $link_id : null;
		}

		/**
		 * Create a tinypress_link entry for a post type
		 *
		 * @param int $post_id
		 *
		 * @return int|WP_Error
		 */
		function tinypress_create_link_entry( $post_id ) {
			$post = get_post( $post_id );

			if ( ! $post || $post->post_type === 'tinypress_link' ) {
				return new WP_Error( 'invalid_post', esc_html__( 'Invalid post ID or post type.', 'tinypress' ) );
			}

			$existing_link_id = $this->get_existing_tinypress_link_entry( $post_id );
			if ( $existing_link_id ) {
				return $existing_link_id;
			}

			$tiny_slug = Utils::get_meta( 'tiny_slug', $post_id );
			if ( empty( $tiny_slug ) ) {
				$tiny_slug = tinypress_create_url_slug();
				update_post_meta( $post_id, 'tiny_slug', $tiny_slug );
			}

			$link_args = array(
				'post_title'  => $post->post_title,
				'post_type'   => 'tinypress_link',
				'post_status' => 'publish',
				'post_author' => $post->post_author,
			);

			$link_id = wp_insert_post( $link_args );

			if ( is_wp_error( $link_id ) ) {
				return $link_id;
			}

			update_post_meta( $link_id, 'target_url', get_permalink( $post_id ) );
			update_post_meta( $link_id, 'tiny_slug', $tiny_slug );
			update_post_meta( $link_id, 'source_post_id', $post_id );
			update_post_meta( $link_id, 'source_post_type', $post->post_type );
			update_post_meta( $link_id, 'redirection', 302 );

			return $link_id;
		}

		/**
		 * Update existing tinypress_link entry when source post is updated
		 *
		 * @param int $link_id
		 * @param int $post_id
		 *
		 * @return void
		 */
		function tinypress_update_link_entry( $link_id, $post_id ) {
			$post = get_post( $post_id );

			if ( ! $post ) {
				return;
			}

			wp_update_post( array(
				'ID'         => $link_id,
				'post_title' => $post->post_title,
			) );

			update_post_meta( $link_id, 'target_url', get_permalink( $post_id ) );
		}

		/**
		 * Handle post publish event (On Publish behavior)
		 *
		 * @param string $new_status
		 * @param string $old_status
		 * @param WP_Post $post
		 *
		 * @return void
		 */
		function tinypress_handle_post_publish( $new_status, $old_status, $post ) {
			if ( $post->post_type === 'tinypress_link' || $post->post_type === 'attachment' ) {
				return;
			}

			$behavior = $this->get_post_type_behavior( $post->post_type );

			if ( $behavior !== 'on_publish' ) {
				return;
			}

			if ( $new_status === 'publish' ) {
				$existing_link_id = $this->get_existing_tinypress_link_entry( $post->ID );

				if ( $existing_link_id ) {
					$this->tinypress_update_link_entry( $existing_link_id, $post->ID );
				} else {
					$this->tinypress_create_link_entry( $post->ID );
				}
			}
		}

		/**
		 * Handle first use event (On First Use behavior)
		 *
		 * @param int $post_id
		 *
		 * @return void
		 */
		function tinypress_handle_first_use( $post_id ) {
			$post = get_post( $post_id );

			if ( ! $post || $post->post_type === 'tinypress_link' || $post->post_type === 'attachment' ) {
				return;
			}

			$behavior = $this->get_post_type_behavior( $post->post_type );

			if ( $behavior !== 'on_first_use' ) {
				return;
			}

			$existing_link_id = $this->get_existing_tinypress_link_entry( $post_id );

			if ( ! $existing_link_id ) {
				$this->tinypress_create_link_entry( $post_id );
			}
		}

		/**
		 * Sync tiny_slug when tinypress_link is saved
		 *
		 * @param int $post_id
		 * @param WP_Post $post
		 * @param bool $update
		 *
		 * @return void
		 */
		function tinypress_sync_on_link_save( $post_id, $post, $update ) {
			if ( ! $update ) {
				return;
			}

			if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
				return;
			}

			$source_post_id = Utils::get_meta( 'source_post_id', $post_id );

			if ( empty( $source_post_id ) ) {
				return;
			}

			$link_tiny_slug = Utils::get_meta( 'tiny_slug', $post_id );
			$source_tiny_slug = Utils::get_meta( 'tiny_slug', $source_post_id );

			if ( ! empty( $link_tiny_slug ) && $link_tiny_slug !== $source_tiny_slug ) {
				update_post_meta( $source_post_id, 'tiny_slug', $link_tiny_slug );
			}
		}

		/**
		 * Sync tiny_slug changes from tinypress_link back to source post
		 *
		 * @param int $meta_id
		 * @param int $post_id
		 * @param string $meta_key
		 * @param mixed $meta_value
		 *
		 * @return void
		 */
		function sync_tiny_slug_to_source( $meta_id, $post_id, $meta_key, $meta_value ) {
			if ( $meta_key !== 'tiny_slug' ) {
				return;
			}

			if ( get_post_type( $post_id ) !== 'tinypress_link' ) {
				return;
			}

			$source_post_id = Utils::get_meta( 'source_post_id', $post_id );

			if ( empty( $source_post_id ) ) {
				return;
			}

			$current_source_slug = Utils::get_meta( 'tiny_slug', $source_post_id );

			if ( $current_source_slug !== $meta_value ) {
				remove_action( 'updated_post_meta', array( $this, 'sync_tiny_slug_to_source' ), 10 );
				update_post_meta( $source_post_id, 'tiny_slug', $meta_value );
				add_action( 'updated_post_meta', array( $this, 'sync_tiny_slug_to_source' ), 10, 4 );
			}
		}

		/**
		 * @return TINYPRESS_AutoList
		 */
		public static function instance() {
			if ( is_null( self::$_instance ) ) {
				self::$_instance = new self();
			}

			return self::$_instance;
		}
	}
}
