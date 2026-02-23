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
			add_action( 'wp_insert_post', array( $this, 'tinypress_handle_post_created' ), 10, 3 );
			add_action( 'updated_post_meta', array( $this, 'sync_tiny_slug_to_source' ), 10, 4 );
			add_action( 'updated_post_meta', array( $this, 'tinypress_sync_source_to_link' ), 10, 4 );
			add_action( 'save_post_tinypress_link', array( $this, 'tinypress_sync_on_link_save' ), 20, 3 );
            add_action( 'save_post', array( $this, 'sync_link_title_from_source' ), 10, 3 );
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
				"SELECT pm.post_id FROM {$wpdb->postmeta} pm
				INNER JOIN {$wpdb->posts} p ON pm.post_id = p.ID
				WHERE pm.meta_key = %s
				AND pm.meta_value = %d
				AND p.post_type = %s",
				'source_post_id',
				$post_id,
				'tinypress_link'
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

			update_post_meta( $link_id, 'target_url', esc_url_raw( get_permalink( $post_id ) ) );
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

			update_post_meta( $link_id, 'target_url', esc_url_raw( get_permalink( $post_id ) ) );
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

			if ( function_exists( 'tinypress_is_pp_revision' ) && tinypress_is_pp_revision( $post->ID ) ) {
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

			if ( function_exists( 'tinypress_is_pp_revision' ) && tinypress_is_pp_revision( $post_id ) ) {
				// But allow first-use creation if revision autolist behavior includes it
				if ( function_exists( 'tinypress_get_revision_autolist_behavior' ) ) {
					$rev_behavior = tinypress_get_revision_autolist_behavior();
					if ( in_array( $rev_behavior, array( 'on_first_use', 'on_revision_creation_or_first_use' ), true ) ) {
						if ( ! tinypress_get_revision_link_entry( $post_id ) ) {
							tinypress_create_revision_link_entry( $post_id );
						}
					}
				}
				return;
			}

			$behavior = $this->get_post_type_behavior( $post->post_type );

			if ( ! in_array( $behavior, array( 'on_first_use', 'on_first_use_or_on_create' ) ) ) {
				return;
			}

			$existing_link_id = $this->get_existing_tinypress_link_entry( $post_id );

			if ( ! $existing_link_id ) {
				$this->tinypress_create_link_entry( $post_id );
			}
		}

		/**
		 * Handle post creation event (On Create behavior)
		 *
		 * @param int $post_id
		 * @param WP_Post $post
		 * @param bool $update
		 *
		 * @return void
		 */
		function tinypress_handle_post_created( $post_id, $post, $update ) {
			if ( $update ) {
				return;
			}

			if ( $post->post_type === 'tinypress_link' || $post->post_type === 'attachment' ) {
				return;
			}

			if ( function_exists( 'tinypress_is_pp_revision' ) && tinypress_is_pp_revision( $post_id ) ) {
				return;
			}

			$behavior = $this->get_post_type_behavior( $post->post_type );

			if ( ! in_array( $behavior, array( 'on_create', 'on_first_use_or_on_create' ) ) ) {
				return;
			}

			$existing_link_id = $this->get_existing_tinypress_link_entry( $post_id );

			if ( ! $existing_link_id ) {
				$this->tinypress_create_link_entry( $post_id );
			}
		}

		/**
		 * Sync the tinypress_link post title with the source post title
		 *
		 * @param int $post_id
		 * @param WP_Post $post
		 * @param string $post_before
		 *
		 * @return void
		 */
		function sync_link_title_from_source( $post_id, $post, $post_before ) {
			if ( $post->post_type === 'tinypress_link' || $post->post_type === 'attachment' ) {
				return;
			}

			if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
				return;
			}

			if ( isset( $post_before->post_title ) && $post_before->post_title === $post->post_title ) {
				return;
			}

			$link_id = $this->get_existing_tinypress_link_entry( $post_id );

			if ( ! $link_id ) {
				return;
			}

			wp_update_post( array(
				'ID'         => $link_id,
				'post_title' => $post->post_title,
			) );
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

			$link_target_url = Utils::get_meta( 'target_url', $post_id );
			$source_target_url = Utils::get_meta( 'target_url', $source_post_id );

			if ( ! empty( $link_target_url ) && $link_target_url !== $source_target_url ) {
				update_post_meta( $source_post_id, 'target_url', $link_target_url );
			}
		}

		/**
		 * Sync tiny_slug and target_url changes from tinypress_link back to source post
		 *
		 * @param int $meta_id
		 * @param int $post_id
		 * @param string $meta_key
		 * @param mixed $meta_value
		 *
		 * @return void
		 */
		function sync_tiny_slug_to_source( $meta_id, $post_id, $meta_key, $meta_value ) {
			if ( ! in_array( $meta_key, array( 'tiny_slug', 'target_url' ) ) ) {
				return;
			}

			if ( get_post_type( $post_id ) !== 'tinypress_link' ) {
				return;
			}

			$source_post_id = Utils::get_meta( 'source_post_id', $post_id );

			if ( empty( $source_post_id ) ) {
				return;
			}

			$current_source_value = Utils::get_meta( $meta_key, $source_post_id );

			if ( $current_source_value !== $meta_value ) {
				remove_action( 'updated_post_meta', array( $this, 'sync_tiny_slug_to_source' ), 10 );
				update_post_meta( $source_post_id, $meta_key, $meta_value );
				add_action( 'updated_post_meta', array( $this, 'sync_tiny_slug_to_source' ), 10, 4 );
			}
		}

		/**
		 * Sync tiny_slug and target_url changes from source post back to tinypress_link
		 *
		 * @param int $meta_id
		 * @param int $post_id
		 * @param string $meta_key
		 * @param mixed $meta_value
		 *
		 * @return void
		 */
		function tinypress_sync_source_to_link( $meta_id, $post_id, $meta_key, $meta_value ) {
			if ( ! in_array( $meta_key, array( 'tiny_slug', 'target_url' ) ) ) {
				return;
			}

			// Skip if this is a tinypress_link post
			if ( get_post_type( $post_id ) === 'tinypress_link' ) {
				return;
			}

			$link_post_id = $this->get_existing_tinypress_link_entry( $post_id );

			if ( empty( $link_post_id ) ) {
				return;
			}

			$current_link_value = Utils::get_meta( $meta_key, $link_post_id );

			if ( $current_link_value !== $meta_value ) {
				remove_action( 'updated_post_meta', array( $this, 'tinypress_sync_source_to_link' ), 10 );
				update_post_meta( $link_post_id, $meta_key, $meta_value );
				add_action( 'updated_post_meta', array( $this, 'tinypress_sync_source_to_link' ), 10, 4 );
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
