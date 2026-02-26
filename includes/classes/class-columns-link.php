<?php

use WPDK\Utils;

/**
 * Class Link Columns
 */
class TINYPRESS_Column_link {

	protected static $_instance = null;

	/**
	 * TINYPRESS_Column_link Constructor.
	 */
	function __construct() {
		add_filter( 'manage_tinypress_link_posts_columns', array( $this, 'add_columns' ), 16, 1 );
		add_action( 'manage_tinypress_link_posts_custom_column', array( $this, 'columns_content' ), 10, 2 );
		add_filter( 'post_row_actions', array( $this, 'remove_row_actions' ), 10, 2 );

		foreach ( get_post_types( array( 'public' => true ) ) as $post_type ) {
			if ( ! in_array( $post_type, array( 'attachment', 'tinypress_link' ) ) ) {
				add_filter( 'manage_' . $post_type . '_posts_columns', array( $this, 'tinypress_copy_columns' ) );
				add_action( 'manage_' . $post_type . '_posts_custom_column', array( $this, 'tinypress_copy_content' ), 10, 2 );
			}
		}

		if ( function_exists( 'rvy_in_revision_workflow' ) ) {
			add_filter( 'manage_revisionary-q_columns', array( $this, 'add_revision_shortlink_column' ), 20, 1 );
			add_action( 'revisionary_list_table_custom_col', array( $this, 'display_revision_shortlink_column' ), 10, 2 );
		}
	}

	/**
	 * Add shortlink column to revision listings
	 *
	 * @param array $columns
	 * @return array
	 */
	public function add_revision_shortlink_column( $columns ) {
		if ( ! Utils::get_option( 'tinypress_revision_column_enabled', true ) ) {
			return $columns;
		}

		$columns['tinypress-revision-shortlink'] = esc_html__( 'Shortlink', 'tinypress' );
		return $columns;
	}

	/**
	 * Display revision shortlink column content
	 *
	 * @param string $column
	 * @param WP_Post $post
	 * @return void
	 */
	public function display_revision_shortlink_column( $column, $post ) {
		if ( 'tinypress-revision-shortlink' !== $column ) {
			return;
		}

		$post_id = $post->ID;

		$tiny_slug = get_post_meta( $post_id, 'tiny_slug', true );

		if ( empty( $tiny_slug ) ) {
			echo '<span class="tinypress-no-shortlink">' . esc_html__( 'No shortlink', 'tinypress' ) . '</span>';
			return;
		}

		echo '<div class="tinypress-column-content">';
		echo '<div class="single-link copy-link hint--top" data-tiny_slug="' . esc_attr( tinypress_get_tinyurl( $post_id ) ) . '" aria-label="' . esc_attr( tinypress()::get_text_hint() ) . '" data-text-copied="' . esc_attr( tinypress()::get_text_copied() ) . '">';
		echo '<span class="dashicons dashicons-admin-links"></span>';
		echo '</div>';
		echo '</div>';
	}

	/**
	 * tinypress_copy_columns
	 *
	 * @param $columns
	 *
	 * @return array
	 */

	function tinypress_copy_columns( $columns ) {

		$columns['tinypress-link'] = esc_html__( 'Shortlinks', 'tinypress' );

		return $columns;
	}

	/**
	 *tinypress_copy_content
	 *
	 * @param $column
	 * @param $post_id
	 *
	 * @return void
	 */

	function tinypress_copy_content( $column_name, $post_id ) {

		if ( 'tinypress-link' == $column_name ) {

			echo '<div class="tinypress-column-content">';

			echo '<div class="single-link copy-link hint--top" data-tiny_slug="' . esc_attr( tinypress_get_tinyurl( $post_id ) ) . '" aria-label="' . esc_attr( tinypress()::get_text_hint() ) . '" data-text-copied="' . esc_attr( tinypress()::get_text_copied() ) . '">';
			echo '<span class="dashicons dashicons-admin-links"></span>';
			echo '</div>';

			echo '</div>';
		}
	}


	/**
	 * Remove row actions for Schedules post type
	 *
	 * @param $actions
	 *
	 * @return mixed
	 */
	function remove_row_actions( $actions ) {
		global $post;

		if ( $post->post_type === 'tinypress_link' ) {
			unset( $actions['inline hide-if-no-js'] );
			unset( $actions['view'] );
			unset( $actions['edit'] );
			unset( $actions['trash'] );
			unset( $actions['create_revision'] );
			unset( $actions['create_draft_revision'] );
			unset( $actions['edit_revision'] );
			unset( $actions['view_revision'] );
		}

		return $actions;
	}

	/**
	 * Add columns content
	 *
	 * @param $column_id
	 * @param $post_id
	 */
	function columns_content( $column_id, $post_id ) {
		switch ( $column_id ) {
			case 'link-title':
				$source_post_id = Utils::get_meta( 'source_post_id', $post_id );
				$title_html = '<strong><a class="row-title" href="' . esc_url( get_edit_post_link( $post_id ) ) . '">' . get_the_title( $post_id ) . '</a></strong>';

				$is_revision_link = get_post_meta( $post_id, 'is_revision_link', true );
				if ( '1' !== $is_revision_link && ! empty( $source_post_id ) && function_exists( 'rvy_in_revision_workflow' ) ) {
					$source_post = get_post( absint( $source_post_id ) );
					$is_revision_link = $source_post && rvy_in_revision_workflow( $source_post->ID ) ? '1' : '0';
				}
				if ( '1' === $is_revision_link ) {
					$title_html .= ' <span class="tinypress-revision-badge">' . esc_html__( 'rev', 'tinypress' ) . '</span>';
				}

				echo $title_html;
				break;

			case 'short-link':
				echo tinypress_get_tiny_slug_copier( $post_id, false, array( 'wrapper_class' => 'mini' ) );
				break;

			case 'link-type':
				$link_type = $this->get_link_type( $post_id );
				$badge_class = 'internal' === $link_type ? 'internal-badge' : 'external-badge';
				$badge_text = 'internal' === $link_type ? esc_html__( 'Internal', 'tinypress' ) : esc_html__( 'External', 'tinypress' );
				$tooltip_text = 'internal' === $link_type ? esc_html__( 'This links to your post', 'tinypress' ) : esc_html__( 'This links to an external website', 'tinypress' );

				$is_revision_type = get_post_meta( $post_id, 'is_revision_link', true );
				$source_post_id_for_type = Utils::get_meta( 'source_post_id', $post_id );

				if ( '1' !== $is_revision_type && ! empty( $source_post_id_for_type ) && function_exists( 'rvy_in_revision_workflow' ) ) {
					$source_post_for_type = get_post( absint( $source_post_id_for_type ) );
					$is_revision_type = $source_post_for_type && rvy_in_revision_workflow( $source_post_for_type->ID ) ? '1' : '0';
				}

				if ( '1' === $is_revision_type ) {
					$badge_class = 'revision-badge';
					$badge_text = esc_html__( 'Revision', 'tinypress' );
					$tooltip_text = esc_html__( 'This links to a revision', 'tinypress' );

					// Try to get the specific revision status for a more detailed tooltip
					if ( ! empty( $source_post_id_for_type ) ) {
						$source_post_for_type = isset( $source_post_for_type ) ? $source_post_for_type : get_post( $source_post_id_for_type );
						if ( $source_post_for_type && ! empty( $source_post_for_type->post_mime_type ) ) {
							$revision_status = $source_post_for_type->post_mime_type;
							$status_labels = array(
								'draft-revision'       => __( 'Not yet submitted', 'tinypress' ),
								'pending-revision'     => __( 'Submitted for approval', 'tinypress' ),
								'future-revision'      => __( 'Scheduled', 'tinypress' ),
								'revision-deferred'    => __( 'Deferred', 'tinypress' ),
								'revision-needs-work'  => __( 'Needs work', 'tinypress' ),
								'revision-rejected'    => __( 'Rejected', 'tinypress' ),
							);
							if ( isset( $status_labels[ $revision_status ] ) ) {
								$tooltip_text = esc_html( sprintf( __( 'Revision: %s', 'tinypress' ), $status_labels[ $revision_status ] ) );
							}
						}
					}
				}

				echo '<span class="tinypress-link-type-badge ' . esc_attr( $badge_class ) . ' pp-tooltips-library" data-toggle="tooltip">' . $badge_text . '<span class="tinypress tooltip-text">' . $tooltip_text . '</span></span>';
				break;

			case 'click-count':

				global $wpdb;

				$click_count = $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM " . TINYPRESS_TABLE_REPORTS . " WHERE post_id = %d", $post_id ) );

				echo '<div class="click-count">' . esc_html( sprintf( __( 'Clicked %s times', 'tinypress' ), $click_count ) ) . '</div>';
				break;

			case 'link-actions':

				echo '<div class="link-actions">';

				echo '<a href="' . esc_url( get_edit_post_link( $post_id ) ) . '" class="action action-edit">' . esc_html__( 'Edit', 'tinypress' ) . '</a>';
				echo '<a href="' . esc_url( get_delete_post_link( $post_id ) ) . '" class="action action-delete">' . esc_html__( 'Delete', 'tinypress' ) . '</a>';

				echo '</div>';

				break;

			default:
				break;
		}
	}

	/**
	 * Determine if a link is internal or external
	 *
	 * @param int $post_id The post ID of the tinypress_link
	 *
	 * @return string 'internal' or 'external'
	 */
	function get_link_type( $post_id ) {
		$target_url = Utils::get_meta( 'target_url', $post_id );

		if ( empty( $target_url ) ) {
			return 'external';
		}

		// Get the site domain
		$site_url = get_site_url();
		$site_host = wp_parse_url( $site_url, PHP_URL_HOST );
		$target_host = wp_parse_url( $target_url, PHP_URL_HOST );

		// Remove www. prefix for comparison (so www.example.com and example.com are treated the same)
		$site_host = preg_replace( '/^www\./', '', $site_host );
		$target_host = preg_replace( '/^www\./', '', $target_host );

		return ( $site_host === $target_host ) ? 'internal' : 'external';
	}

	/**
	 * Add columns on Schedules listing
	 *
	 * @return string[]
	 */
	function add_columns( $columns ) {
		$new_columns = array(
			'cb'           => Utils::get_args_option( 'cb', $columns ),
			'link-title'   => esc_html__( 'Link Title', 'tinypress' ),
			'short-link'   => esc_html__( 'Shortlink', 'tinypress' ),
			'link-type'    => esc_html__( 'Link Type', 'tinypress' ),
			'click-count'  => esc_html__( 'Stats', 'tinypress' ),
			'link-actions' => esc_html__( 'Actions', 'tinypress' ),
		);

		return apply_filters( 'TINYPRESS/Filters/link_columns', $new_columns, $columns );
	}


	/**
	 * @return TINYPRESS_Column_link
	 */
	public static function instance() {
		if ( is_null( self::$_instance ) ) {
			self::$_instance = new self();
		}

		return self::$_instance;
	}
}