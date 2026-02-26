<?php

use WPDK\Utils;

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'WP_List_Table' ) ) {
	require_once( ABSPATH . 'wp-admin/includes/class-wp-list-table.php' );
}

/**
 * Logs Class
 */
class WP_List_Table_Logs extends WP_List_Table {

	private int $items_per_page = 20;

	public function __construct( $args = array() ) {
		parent::__construct( array(
			'singular' => 'log',
			'plural'   => 'logs',
			'ajax'     => false,
		) );
	}

	private function current_user_can_manage_logs() {
		return current_user_can( 'edit_posts' );
	}

	private function delete_logs_by_ids( $ids ) {
		global $wpdb;
		$ids = array_filter( array_map( 'absint', (array) $ids ) );
		if ( empty( $ids ) ) {
			return;
		}
		$placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
		$wpdb->query( $wpdb->prepare( "UPDATE " . TINYPRESS_TABLE_REPORTS . " SET is_cleared = 1 WHERE id IN ($placeholders)", $ids ) );
	}

	private function clear_all_logs() {
		global $wpdb;
		$wpdb->query( "UPDATE " . TINYPRESS_TABLE_REPORTS . " SET is_cleared = 1" );
	}

    private function process_actions() {
        if ( ! $this->current_user_can_manage_logs() ) {
            return;
        }

        $action = $this->current_action();
        if ( empty( $action ) && ! empty( $_REQUEST['action2'] ) && $_REQUEST['action2'] !== '-1' ) {
            $action = sanitize_text_field( wp_unslash( $_REQUEST['action2'] ) );
        }
        if ( empty( $action ) ) {
            return;
        }

        if ( $action === 'delete' ) {
            check_admin_referer( 'tinypress_delete_log' );
            $id = isset( $_GET['log'] ) ? absint( $_GET['log'] ) : 0;
            $this->delete_logs_by_ids( array( $id ) );
            set_transient( 'tinypress_log_message', array( 'type' => 'error', 'message' => __( 'Log entry deleted successfully.', 'tinypress' ) ), 30 );
        } elseif ( $action === 'bulk-delete' ) {
            if ( ! isset( $_SERVER['REQUEST_METHOD'] ) || strtoupper( $_SERVER['REQUEST_METHOD'] ) !== 'POST' ) {
                return;
            }
            check_admin_referer( 'bulk-' . $this->_args['plural'] );
            $ids = isset( $_POST['log'] ) ? (array) $_POST['log'] : array();
            $count = count( $ids );
            $this->delete_logs_by_ids( $ids );
            set_transient( 'tinypress_log_message', array( 'type' => 'error', 'message' => sprintf( __( '%d log entries deleted successfully.', 'tinypress' ), $count ) ), 30 );
        } elseif ( $action === 'clear_logs' ) {
            check_admin_referer( 'tinypress_clear_logs' );
            $this->clear_all_logs();
            set_transient( 'tinypress_log_message', array( 'type' => 'error', 'message' => __( 'All logs have been cleared.', 'tinypress' ) ), 30 );
        }

        $redirect_url = admin_url( 'edit.php?post_type=tinypress_link&page=tinypress-logs' );
        if ( headers_sent() ) {
            echo '<script>window.location.href=' . wp_json_encode( $redirect_url ) . ';</script>';
            echo '<noscript><meta http-equiv="refresh" content="0;url=' . esc_url( $redirect_url ) . '" /></noscript>';
            exit;
        }
        wp_safe_redirect( $redirect_url );
        exit;
    }

	/**
	 * Return all report data
	 *
	 * @return array|object|stdClass[]|null
	 */
	private function get_reports_data() {

		global $wpdb;

		$all_posts = $wpdb->get_results( "SELECT id,post_id,user_id,user_ip,user_location, datetime FROM " . TINYPRESS_TABLE_REPORTS . " WHERE is_cleared = 0 ORDER BY datetime DESC", ARRAY_A );
		$all_posts = array_map( function ( $post ) {

			if ( empty( $post_id = Utils::get_args_option( 'post_id', $post ) ) || 0 == $post_id ) {
				return [];
			}

			$this_post = get_post( $post_id );

			if ( ! $this_post instanceof WP_Post || 'tinypress_link' != $this_post->post_type ) {
				return [];
			}

			$post['post_title'] = $this_post->post_title;

			return $post;
		}, $all_posts );

		return array_filter( $all_posts );
	}


	/**
	 * Prepare Items
	 *
	 * @return void
	 */
	function prepare_items() {
		$this->process_actions();

		$reports_data          = $this->get_reports_data();
		$this->_column_headers = array( $this->get_columns(), [], [] );
		$current_page          = $this->get_pagenum();
		$total_items           = count( $reports_data );
		$reports_data          = array_slice( $reports_data, ( ( $current_page - 1 ) * $this->items_per_page ), $this->items_per_page );

		$this->set_pagination_args( array(
			'total_items' => $total_items,
			'per_page'    => $this->items_per_page,
		) );

		$this->items = $reports_data;
	}


	/**
	 * Return columns
	 *
	 * @return array
	 */
	function get_columns() {
		return array(
			'cb'         => '<input type="checkbox" />',
			'title'      => esc_html__( 'Title', 'tinypress' ),
			'short_link' => esc_html__( 'Shortlink', 'tinypress' ),
			'details'    => esc_html__( 'Details', 'tinypress' ),
		);
	}

	function column_cb( $item ) {
		$id = Utils::get_args_option( 'id', $item );
		return sprintf( '<input type="checkbox" name="log[]" value="%s" />', esc_attr( $id ) );
	}

	protected function get_bulk_actions() {
		return array(
			'bulk-delete' => esc_html__( 'Delete', 'tinypress' ),
		);
	}

	/**
	 * Column Title
	 *
	 * @param $item
	 *
	 * @return string
	 */
	function column_title( $item ) {
		$post_id = Utils::get_args_option( 'post_id', $item );
		$log_id  = Utils::get_args_option( 'id', $item );
		$title   = Utils::get_args_option( 'post_title', $item );

		$actions = array();
		if ( $this->current_user_can_manage_logs() && ! empty( $log_id ) ) {
			$actions['edit'] = '<a href="' . esc_url( 'post.php?post=' . esc_attr( $post_id ) . '&action=edit' ) . '">' . esc_html__( 'Edit', 'tinypress' ) . '</a>';
			$delete_url = wp_nonce_url( add_query_arg( array( 'action' => 'delete', 'log' => $log_id ) ), 'tinypress_delete_log' );
			$actions['delete'] = '<a href="' . esc_url( $delete_url ) . '" onclick="return confirm(\'' . esc_js( __( 'Are you sure you want to delete this log entry?', 'tinypress' ) ) . '\');">' . esc_html__( 'Delete', 'tinypress' ) . '</a>';
		}

		$revision_badge = '';
		if ( ! empty( $post_id ) ) {
			$is_revision_link = get_post_meta( $post_id, 'is_revision_link', true );
			if ( '1' !== $is_revision_link && function_exists( 'rvy_in_revision_workflow' ) ) {
				$source_post_id = Utils::get_meta( 'source_post_id', $post_id );
				if ( ! empty( $source_post_id ) ) {
					$source_post = get_post( absint( $source_post_id ) );
					$is_revision_link = $source_post && rvy_in_revision_workflow( $source_post->ID ) ? '1' : '0';
				}
			}
			if ( '1' === $is_revision_link ) {
				$revision_badge = ' <span class="tinypress-revision-badge">' . esc_html__( 'rev', 'tinypress' ) . '</span>';
			}
		}

		return sprintf( '<div class="post-title"><a href="post.php?post=%s&action=edit">%s</a>%s%s</div>',
			esc_attr( $post_id ),
			esc_html( $title ),
			$revision_badge,
			$this->row_actions( $actions )
		);
	}


	/**
	 * Column Short Link
	 *
	 * @param $item
	 *
	 * @return string
	 */
	function column_short_link( $item ) {
		return tinypress_get_tiny_slug_copier( Utils::get_args_option( 'post_id', $item ), false, array( 'wrapper_class' => 'mini' ) );
	}


	/**
	 * @param $item
	 *
	 * @return string
	 */
	function column_details( $item ) {

		$user_id            = Utils::get_args_option( 'user_id', $item );
		$user_location      = Utils::get_args_option( 'user_location', $item );
		$user_location      = json_decode( $user_location, true );
		$user_display_name  = '';
		$user_visited_time  = Utils::get_args_option( 'datetime', $item );
		$user_visited_time  = mysql2date( get_option( 'date_format' ) . ', ' . get_option( 'time_format' ), $user_visited_time );

		if ( 0 != $user_id ) {
			$user_obj          = get_user_by( 'ID', $user_id );
			$user_display_name = $user_obj instanceof WP_User ? ucfirst( $user_obj->display_name ) : '';
		}

		$user_location_name = '';
		if ( ! empty( $geoplugin_city = Utils::get_args_option( 'geoplugin_city', $user_location ) ) ) {
			$user_location_name = $geoplugin_city;
		} else if ( ! empty( $geoplugin_region_name = Utils::get_args_option( 'geoplugin_regionName', $user_location ) ) ) {
			$user_location_name = $geoplugin_region_name;
		} else if ( ! empty( $geoplugin_continent_name = Utils::get_args_option( 'geoplugin_continentName', $user_location ) ) ) {
			$user_location_name = $geoplugin_continent_name;
		}

		$details_text = '';
		if ( ! empty( $user_display_name ) && ! empty( $user_location_name ) ) {
			$details_text = sprintf( __( '%s from %s visited at %s', 'tinypress' ), $user_display_name, $user_location_name, $user_visited_time );
		} else {
			$details_text = sprintf( __( 'Visited at %s', 'tinypress' ), $user_visited_time );
		}

		return sprintf( '<div class="report-details">%s</div>', esc_html( $details_text ) );
	}
}