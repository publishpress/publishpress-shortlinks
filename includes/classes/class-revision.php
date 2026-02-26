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
		add_action( 'init', array( $this, 'migrate_revision_link_meta' ), 2 );
		add_action( 'init', array( $this, 'migrate_legacy_revision_slugs' ), 3 );
		add_action( 'init', array( $this, 'sync_revision_shortlinks_status' ), 4 );
		add_action( 'admin_notices', array( $this, 'show_legacy_migration_notice' ) );
		add_action( 'admin_init', array( $this, 'redirect_after_activation' ) );
		add_action( 'wp_ajax_tinypress_dismiss_migration_notice', array( $this, 'dismiss_migration_notice' ) );
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

		add_action( 'before_delete_post', array( $this, 'tinypress_cleanup_revision_link_before_delete' ), 10, 1 );

		add_filter( 'revisionary_enabled_post_types', array( $this, 'tinypress_exclude_from_revisions' ) );
	}

	/**
	 * One-time migration: backfill is_revision_link meta for existing tinypress_link
	 * entries that have a source_post_id pointing to a PP Revisions revision but
	 * were created before is_revision_link meta was introduced.
	 */
	public function migrate_revision_link_meta() {
		if ( ! function_exists( 'rvy_in_revision_workflow' ) ) {
			return;
		}

		if ( get_option( 'tinypress_revision_meta_migrated', false ) ) {
			return;
		}

		global $wpdb;

		$entries = $wpdb->get_results( $wpdb->prepare(
			"SELECT pm.post_id, pm.meta_value AS source_post_id, source_post.post_mime_type AS source_mime
			FROM {$wpdb->postmeta} pm
			INNER JOIN {$wpdb->posts} p ON pm.post_id = p.ID
			LEFT JOIN {$wpdb->postmeta} pm2 ON pm.post_id = pm2.post_id AND pm2.meta_key = %s
			LEFT JOIN {$wpdb->posts} source_post ON CAST(pm.meta_value AS UNSIGNED) = source_post.ID
			WHERE p.post_type = %s
			AND pm.meta_key = %s
			AND pm2.meta_key IS NULL",
			'is_revision_link',
			'tinypress_link',
			'source_post_id'
		) );

		$revision_mime_types = array(
			'draft-revision', 'pending-revision', 'future-revision',
			'revision-deferred', 'revision-needs-work', 'revision-rejected',
		);

		if ( ! empty( $entries ) ) {
			foreach ( $entries as $entry ) {
				$source_id = absint( $entry->source_post_id );
				if ( $source_id && in_array( $entry->source_mime, $revision_mime_types, true ) && rvy_in_revision_workflow( $source_id ) ) {
					update_post_meta( (int) $entry->post_id, 'is_revision_link', '1' );
				} else {
					update_post_meta( (int) $entry->post_id, 'is_revision_link', '0' );
				}
			}
		}

		update_option( 'tinypress_revision_meta_migrated', '1', true );
	}

	/**
	 * One-time migration: find PP Revisions posts that do not have a
	 * corresponding tinypress_link entry, and create one.
	 */
	public function migrate_legacy_revision_slugs() {
		if ( ! function_exists( 'rvy_in_revision_workflow' ) ) {
			return;
		}

		if ( get_option( 'tinypress_legacy_revision_slugs_migrated', false ) ) {
			return;
		}

		global $wpdb;

		// Find all PP Revisions posts that do NOT have a tinypress_link entry
		$orphaned_revisions = $wpdb->get_results(
			"SELECT p.ID AS revision_id,
				pm_slug.meta_value AS tiny_slug,
				parent_slug.meta_value AS parent_slug
			FROM {$wpdb->posts} p
			LEFT JOIN {$wpdb->postmeta} pm_slug
				ON p.ID = pm_slug.post_id AND pm_slug.meta_key = 'tiny_slug'
			LEFT JOIN {$wpdb->postmeta} parent_slug
				ON p.post_parent = parent_slug.post_id AND parent_slug.meta_key = 'tiny_slug'
			LEFT JOIN (
				SELECT CAST(pm_src.meta_value AS UNSIGNED) AS source_id
				FROM {$wpdb->postmeta} pm_src
				INNER JOIN {$wpdb->posts} lp ON pm_src.post_id = lp.ID
				WHERE pm_src.meta_key = 'source_post_id'
				AND lp.post_type = 'tinypress_link'
			) existing_links ON existing_links.source_id = p.ID
			WHERE p.post_mime_type IN (
				'draft-revision', 'pending-revision', 'future-revision',
				'revision-deferred', 'revision-needs-work', 'revision-rejected'
			)
			AND existing_links.source_id IS NULL"
		);

		$migrated_count = 0;

		if ( ! empty( $orphaned_revisions ) ) {
			foreach ( $orphaned_revisions as $entry ) {
				$revision_id = absint( $entry->revision_id );

				if ( ! rvy_in_revision_workflow( $revision_id ) ) {
					continue;
				}

				$slug        = $entry->tiny_slug;
				$parent_slug = $entry->parent_slug;
				$slug_changed = false;

				if ( empty( $slug ) ) {
					// Case 1: No slug at all — generate one
					$new_slug = tinypress_create_url_slug();
					update_post_meta( $revision_id, 'tiny_slug', $new_slug );
					$slug_changed = true;
				} elseif ( ! empty( $parent_slug ) && $slug === $parent_slug ) {
					// Case 2: Shared slug with parent — generate a unique one
					$new_slug = tinypress_create_url_slug();
					update_post_meta( $revision_id, 'tiny_slug', $new_slug );
					$slug_changed = true;
				}

				// Create a tinypress_link entry for this revision
				$result = $this->create_revision_link_entry( $revision_id );

				if ( ! is_wp_error( $result ) && $slug_changed ) {
					$migrated_count++;
				}
			}
		}

		update_option( 'tinypress_legacy_revision_slugs_migrated', '1', true );

		if ( $migrated_count > 0 ) {
			update_option( 'tinypress_legacy_migration_count', $migrated_count, true );
		}

	}

	/**
	 * Show a dismissible admin notice after legacy revision slug migration.
	 */
	public function show_legacy_migration_notice() {
		$count = get_option( 'tinypress_legacy_migration_count', 0 );

		$screen = get_current_screen();

		if ( empty( $count ) ) {
			return;
		}

		if ( ! $screen || 'edit-tinypress_link' !== $screen->id ) {
			return;
		}

		printf(
			'<div class="notice notice-success is-dismissible tinypress-migration-notice" data-nonce="%s"><p>%s</p></div>',
			esc_attr( wp_create_nonce( 'tinypress_dismiss_migration' ) ),
			sprintf(
				/* translators: %d: number of revision shortlinks migrated */
				esc_html__( '%d revision shortlink(s) were updated with unique links. Previously, these revisions shared the same shortlink as their parent post.', 'tinypress' ),
				absint( $count )
			)
		);

		echo '<script>
			jQuery(function($) {
				$(".tinypress-migration-notice").on("click", ".notice-dismiss", function() {
					$.post(ajaxurl, {
						action: "tinypress_dismiss_migration_notice",
						nonce: $(".tinypress-migration-notice").data("nonce")
					});
				});
			});
		</script>';
	}

	/**
	 * AJAX handler to dismiss the legacy migration notice.
	 */
	public function dismiss_migration_notice() {
		if ( ! wp_verify_nonce( sanitize_text_field( $_POST['nonce'] ?? '' ), 'tinypress_dismiss_migration' ) ) {
			wp_send_json_error();
		}
		delete_option( 'tinypress_legacy_migration_count' );
		wp_send_json_success();
	}

	/**
	 * Redirect to All Shortlinks page after plugin activation.
	 */
	public function redirect_after_activation() {
		if ( get_transient( 'tinypress_activation_redirect' ) ) {
			delete_transient( 'tinypress_activation_redirect' );

			if ( ! is_network_admin() && ! isset( $_GET['activate-multi'] ) ) {
				wp_safe_redirect( admin_url( 'edit.php?post_type=tinypress_link' ) );
				exit;
			}
		}
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
		$revision_autolist = \WPDK\Utils::get_option( 'tinypress_revision_autolist', 'on_revision_creation_or_first_use' );

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

		$parent_id = absint( $revision->post_parent );
		if ( $parent_id ) {
			global $wpdb;
			$parent_link = $wpdb->get_var( $wpdb->prepare(
				"SELECT pm.post_id FROM {$wpdb->postmeta} pm
				INNER JOIN {$wpdb->posts} p ON pm.post_id = p.ID
				WHERE pm.meta_key = %s
				AND pm.meta_value = %d
				AND p.post_type = %s
				LIMIT 1",
				'source_post_id',
				$parent_id,
				'tinypress_link'
			) );

			if ( ! $parent_link ) {
				return 0;
			}
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
	 * Clean up tinypress_link entry when any revision post is about to be deleted.
	 *
	 * @param int $post_id The post ID being deleted
	 * @return void
	 */
	public function tinypress_cleanup_revision_link_before_delete( $post_id ) {
		$post_id = absint( $post_id );

		if ( empty( $post_id ) || ! rvy_in_revision_workflow( $post_id ) ) {
			return;
		}

		$link_id = $this->get_revision_link_entry( $post_id );

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
	 * Exclude tinypress_link post type from PP Revisions
	 *
	 * @param array $post_types Enabled post types
	 * @return array
	 */
	public function tinypress_exclude_from_revisions( $post_types ) {
		unset( $post_types['tinypress_link'] );
 
		return $post_types;
	}

	/**
	 * Get the revision autolist behavior setting
	 *
	 * @return string
	 */
	public static function tinypress_get_revision_autolist_behavior() {
		return \WPDK\Utils::get_option( 'tinypress_revision_autolist', 'on_revision_creation_or_first_use' );
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
