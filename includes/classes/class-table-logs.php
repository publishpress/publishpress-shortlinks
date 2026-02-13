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


	/**
	 * Return all report data
	 *
	 * @return array|object|stdClass[]|null
	 */
	private function get_reports_data() {

		global $wpdb;

		$all_posts = $wpdb->get_results( "SELECT post_id,user_id,user_ip,user_location, datetime FROM " . TINYPRESS_TABLE_REPORTS, ARRAY_A );
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
			'title'      => esc_html__( 'Title', 'tinypress' ),
			'short_link' => esc_html__( 'Shortlink', 'tinypress' ),
			'details'    => esc_html__( 'Details', 'tinypress' ),
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

		return sprintf( '<div class="post-title"><a href="post.php?post=%s&action=edit">%s</a></div>',
			Utils::get_args_option( 'post_id', $item ),
			Utils::get_args_option( 'post_title', $item )
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
		$user_display_name  = esc_html__( 'Someone', 'tinypress' );
		$user_location_name = esc_html__( 'Earth', 'tinypress' );
		$user_visited_time  = Utils::get_args_option( 'datetime', $item );
		$user_visited_time  = date( 'jS M Y, h:i a', strtotime( $user_visited_time ) );

		if ( 0 != $user_id ) {
			$user_obj          = get_user_by( 'ID', $user_id );
			$user_display_name = $user_obj instanceof WP_User ? ucfirst( $user_obj->display_name ) : $user_display_name;
		}

		if ( ! empty( $geoplugin_city = Utils::get_args_option( 'geoplugin_city', $user_location ) ) ) {
			$user_location_name = $geoplugin_city;
		} else if ( ! empty( $geoplugin_region_name = Utils::get_args_option( 'geoplugin_regionName', $user_location ) ) ) {
			$user_location_name = $geoplugin_region_name;
		} else if ( ! empty( $geoplugin_continent_name = Utils::get_args_option( 'geoplugin_continentName', $user_location ) ) ) {
			$user_location_name = $geoplugin_continent_name;
		}

		return sprintf( '<div class="report-details">%s</div>',
			esc_html( sprintf( __( '%s from %s visited at %s', 'tinypress' ), $user_display_name, $user_location_name, $user_visited_time ) )
		);
	}
}