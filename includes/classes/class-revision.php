<?php
/**
 * Revision Shortlink Support Class
 * 
 * @author deji98
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class TINYPRESS_Revisions
 */
class TINYPRESS_Revisions {

	protected static $_instance = null;

	/**
	 * TINYPRESS_Revisions constructor.
	 */
	function __construct() {
		add_action( 'init', array( $this, 'init_hooks' ), 5 );
		add_action( 'init', array( $this, 'sync_revision_shortlinks_status' ), 1 );
	}

	/**
	 * Initialize revision shortlink hooks
	 */
	public function init_hooks() {
		if ( ! function_exists( 'rvy_in_revision_workflow' ) ) {
			return;
		}

		add_filter( 'revisionary_unrevisioned_postmeta', array( $this, 'tinypress_exclude_slug_from_revision_copy' ) );

		add_action( 'revisionary_new_revision', array( $this, 'tinypress_create_revision_unique_shortlink' ), 10, 2 );

		add_action( 'revision_applied', array( $this, 'tinypress_cleanup_revision_link_on_approval' ), 10, 2 );

		add_action( 'rvy_delete_revision', array( $this, 'tinypress_cleanup_revision_link_on_delete' ), 10, 2 );
	}

	/**
	 * Suspend or restore revision shortlinks based on PP Revisions plugin state.
	 *
	 * When PP Revisions is deactivated, all revision shortlinks are suspended
	 * (post_status changed to 'tinypress_suspended') so they no longer appear
	 * in the All Shortlinks table.
	 *
	 * When PP Revisions is reactivated, suspended revision shortlinks are
	 * restored to 'publish' status.
	 */
	public function sync_revision_shortlinks_status() {
		$is_active  = function_exists( 'rvy_in_revision_workflow' );
		$was_active = get_option( 'tinypress_pp_revisions_was_active', null );

		$is_first_run = is_null( $was_active );
		$was_active   = ( '1' === $was_active );

		if ( ! $is_first_run && $is_active === $was_active ) {
			return;
		}

		global $wpdb;

		if ( ! $is_active ) {
			$link_ids = $wpdb->get_col( $wpdb->prepare(
				"SELECT p.ID FROM {$wpdb->posts} p
				INNER JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id
				WHERE p.post_type = %s
				AND p.post_status = %s
				AND pm.meta_key = %s
				AND pm.meta_value = %s",
				'tinypress_link',
				'publish',
				'is_revision_link',
				'1'
			) );

			if ( ! empty( $link_ids ) ) {
				$ids_placeholder = implode( ',', array_fill( 0, count( $link_ids ), '%d' ) );
				$wpdb->query( $wpdb->prepare(
					"UPDATE {$wpdb->posts} SET post_status = 'tinypress_suspended' WHERE ID IN ({$ids_placeholder})",
					array_map( 'intval', $link_ids )
				) );
				foreach ( $link_ids as $id ) {
					clean_post_cache( (int) $id );
				}
			}
		} elseif ( $is_active && ! $was_active ) {
			$link_ids = $wpdb->get_col( $wpdb->prepare(
				"SELECT p.ID FROM {$wpdb->posts} p
				INNER JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id
				WHERE p.post_type = %s
				AND p.post_status = %s
				AND pm.meta_key = %s
				AND pm.meta_value = %s",
				'tinypress_link',
				'tinypress_suspended',
				'is_revision_link',
				'1'
			) );

			if ( ! empty( $link_ids ) ) {
				$ids_placeholder = implode( ',', array_fill( 0, count( $link_ids ), '%d' ) );
				$wpdb->query( $wpdb->prepare(
					"UPDATE {$wpdb->posts} SET post_status = 'publish' WHERE ID IN ({$ids_placeholder})",
					array_map( 'intval', $link_ids )
				) );
				foreach ( $link_ids as $id ) {
					clean_post_cache( (int) $id );
				}
			}
		}

		update_option( 'tinypress_pp_revisions_was_active', $is_active ? '1' : '0', true );
	}

	/**
	 * Exclude tiny_slug from being copied during revision creation
	 *
	 * @param array $excluded_keys Meta keys to exclude from revision copy
	 * @return array
	 */
	public function tinypress_exclude_slug_from_revision_copy( $excluded_keys ) {
		$excluded_keys['tiny_slug'] = true;
		$excluded_keys['tinypress_meta_side_*'] = true;

		return $excluded_keys;
	}

	/**
	 * Create a unique shortlink for a revision
	 *
	 * @param int    $revision_id     The ID of the newly created revision
	 * @param string $revision_status The revision status
	 * @return void
	 */
	public function tinypress_create_revision_unique_shortlink( $revision_id, $revision_status ) {
		$revision_id = absint( $revision_id );

		if ( empty( $revision_id ) || ! get_post( $revision_id ) ) {
			return;
		}

		$unique_slug = tinypress_create_url_slug();

		update_post_meta( $revision_id, 'tiny_slug', $unique_slug );

		// Create a tinypress_link entry if revision autolist behavior requires it
		$revision_autolist = \WPDK\Utils::get_option( 'tinypress_revision_autolist', 'on_revision_creation' );

		if ( in_array( $revision_autolist, array( 'on_revision_creation', 'on_revision_creation_or_first_use' ), true ) ) {
			$this->create_revision_link_entry( $revision_id );
		}
	}

	/**
	 * Create a tinypress_link entry for a revision post
	 *
	 * @param int $revision_id The revision post ID
	 * @return int|WP_Error The tinypress_link post ID or WP_Error
	 */
	public function create_revision_link_entry( $revision_id ) {
		$revision = get_post( $revision_id );

		if ( ! $revision ) {
			return new WP_Error( 'invalid_revision', esc_html__( 'Invalid revision ID.', 'tinypress' ) );
		}

		// Check if a tinypress_link entry already exists for this revision
		$existing = $this->get_revision_link_entry( $revision_id );
		if ( $existing ) {
			return $existing;
		}

		$tiny_slug = get_post_meta( $revision_id, 'tiny_slug', true );
		if ( empty( $tiny_slug ) ) {
			$tiny_slug = tinypress_create_url_slug();
			update_post_meta( $revision_id, 'tiny_slug', $tiny_slug );
		}

		$link_args = array(
			'post_title'  => $revision->post_title,
			'post_type'   => 'tinypress_link',
			'post_status' => 'publish',
			'post_author' => $revision->post_author,
		);

		$link_id = wp_insert_post( $link_args );

		if ( is_wp_error( $link_id ) ) {
			return $link_id;
		}

		$target_url = get_permalink( $revision_id );

		update_post_meta( $link_id, 'target_url', esc_url_raw( $target_url ) );
		update_post_meta( $link_id, 'tiny_slug', $tiny_slug );
		update_post_meta( $link_id, 'source_post_id', $revision_id );
		update_post_meta( $link_id, 'source_post_type', $revision->post_type );
		update_post_meta( $link_id, 'is_revision_link', '1' );
		update_post_meta( $link_id, 'redirection', 302 );

		return $link_id;
	}

	/**
	 * Find the tinypress_link entry for a given revision
	 *
	 * @param int $revision_id The revision post ID
	 * @return int|null The tinypress_link post ID or null
	 */
	public function get_revision_link_entry( $revision_id ) {
		global $wpdb;

		$link_id = $wpdb->get_var( $wpdb->prepare(
			"SELECT pm.post_id FROM {$wpdb->postmeta} pm
			INNER JOIN {$wpdb->posts} p ON pm.post_id = p.ID
			WHERE pm.meta_key = %s
			AND pm.meta_value = %d
			AND p.post_type = %s",
			'source_post_id',
			$revision_id,
			'tinypress_link'
		) );

		return $link_id ? (int) $link_id : null;
	}

	/**
	 * Clean up tinypress_link entry when a revision is approved and merged into the original post.
	 *
	 * @param int    $published_post_id The original (published) post ID
	 * @param object $revision          The revision object
	 * @return void
	 */
	public function tinypress_cleanup_revision_link_on_approval( $published_post_id, $revision ) {
		$revision_id = is_object( $revision ) ? absint( $revision->ID ) : absint( $revision );

		if ( empty( $revision_id ) ) {
			return;
		}

		$link_id = $this->get_revision_link_entry( $revision_id );

		if ( $link_id ) {
			wp_delete_post( $link_id, true );
		}
	}

	/**
	 * Clean up tinypress_link entry when a revision is deleted.
	 *
	 * @param int $revision_id       The deleted revision post ID
	 * @param int $published_post_id The original (published) post ID
	 * @return void
	 */
	public function tinypress_cleanup_revision_link_on_delete( $revision_id, $published_post_id ) {
		$revision_id = absint( $revision_id );

		if ( empty( $revision_id ) ) {
			return;
		}

		$link_id = $this->get_revision_link_entry( $revision_id );

		if ( $link_id ) {
			wp_delete_post( $link_id, true );
		}
	}

	/**
	 * Check if a post is a PP Revisions revision.
	 * Used by the autolist to skip revisions from normal post type autolist flow.
	 *
	 * @param int $post_id
	 * @return bool
	 */
	public static function tinypress_is_pp_revision( $post_id ) {
		if ( ! function_exists( 'rvy_in_revision_workflow' ) ) {
			return false;
		}

		return (bool) rvy_in_revision_workflow( $post_id );
	}

	/**
	 * Get the revision autolist behavior setting
	 *
	 * @return string
	 */
	public static function tinypress_get_revision_autolist_behavior() {
		return \WPDK\Utils::get_option( 'tinypress_revision_autolist', 'on_revision_creation' );
	}

	/**
	 * @return TINYPRESS_Revisions
	 */
	public static function instance() {
		if ( is_null( self::$_instance ) ) {
			self::$_instance = new self();
		}

		return self::$_instance;
	}
}

TINYPRESS_Revisions::instance();

/**
 * Public API functions
 * These provide a cleaner interface for other classes to interact with revision functionality.
 */
function tinypress_is_pp_revision( $post_id ) {
	return TINYPRESS_Revisions::tinypress_is_pp_revision( $post_id );
}

function tinypress_get_revision_autolist_behavior() {
	return TINYPRESS_Revisions::tinypress_get_revision_autolist_behavior();
}

function tinypress_get_revision_link_entry( $revision_id ) {
	return TINYPRESS_Revisions::instance()->get_revision_link_entry( $revision_id );
}

function tinypress_create_revision_link_entry( $revision_id ) {
	return TINYPRESS_Revisions::instance()->create_revision_link_entry( $revision_id );
}
