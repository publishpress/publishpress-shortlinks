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

			echo '<div class="single-link copy-link hint--top" data-tiny_slug="' . esc_attr( tinypress_get_tinyurl( $post_id ) ) . '" aria-label="' . tinypress()::get_text_hint() . '" data-text-copied="' . tinypress()::get_text_copied() . '">';
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
				
				if ( ! empty( $source_post_id ) ) {
					$source_post_type = Utils::get_meta( 'source_post_type', $post_id );
					$post_type_obj = get_post_type_object( $source_post_type );
					$post_type_label = $post_type_obj ? $post_type_obj->labels->singular_name : $source_post_type;
					$title_html .= ' <span class="tinypress-auto-badge">' . esc_html( sprintf( __( 'Auto: %s', 'tinypress' ), $post_type_label ) ) . '</span>';
				}
				
				echo $title_html;
				break;

			case 'short-link':
				echo tinypress_get_tiny_slug_copier( $post_id, false, array( 'wrapper_class' => 'mini' ) );
				break;

			case 'click-count':

				global $wpdb;

				$click_count = $wpdb->get_var( "SELECT COUNT(*) FROM " . TINYPRESS_TABLE_REPORTS . " WHERE post_id = $post_id" );

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
	 * Add columns on Schedules listing
	 *
	 * @return string[]
	 */
	function add_columns( $columns ) {
		$new_columns = array(
			'cb'           => Utils::get_args_option( 'cb', $columns ),
			'link-title'   => esc_html__( 'Link Title', 'tinypress' ),
			'short-link'   => esc_html__( 'Shorten Link', 'tinypress' ),
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