<?php
/**
 * Admin: Analytics
 */

use WPDK\Utils;

$post_id = get_the_ID();

global $wpdb;

$reports = $wpdb->get_results( $wpdb->prepare( "SELECT DATE(datetime) AS DateOnly, COUNT(*) AS ClickCount FROM " . TINYPRESS_TABLE_REPORTS . " WHERE post_id =%d AND is_cleared = 0 GROUP BY DATE(datetime) ORDER BY DATE(datetime)", $post_id ), ARRAY_A );

$data  = array();

foreach ( $reports as $report ) {
	// Create DateTime object in UTC to avoid timezone issues
	$date = new DateTime( $report['DateOnly'] . ' 12:00:00', new DateTimeZone('UTC') );
	$timestamp = $date->getTimestamp();
	$data[] = array( (int) $timestamp * 1000, (int) $report['ClickCount'] );
}

// Enqueue analytics script and pass data
wp_enqueue_script( 'tinypress-analytics' );
wp_localize_script( 'tinypress-analytics', 'tinypressAnalytics', array(
	'chartData' => $data,
	'postId'    => $post_id,
	'nonce'     => wp_create_nonce( 'tinypress_reset_analytics_nonce' )
) );

?>
<div class="tinypress-meta-analytics">
    <div id="chart">
        <div class="toolbar">
            <div class="filter-buttons">
                <span class="btn date-filter today active" data-filter="today">Today</span>
                <span class="btn date-filter last_7_days" data-filter="last_7_days">Last 7 Days</span>
                <span class="btn date-filter last_1_month" data-filter="last_1_month">Last 1 Month</span>
                <span class="btn date-filter last_1_year" data-filter="last_1_year">Last 1 Year</span>
            </div>
        </div>
        <button id="reset-analytics" class="button button-secondary" data-action="reset-analytics">
            <span class="reset-text">Reset Today's Analytics</span>
        </button>
        <div id="chart-timeline"></div>
    </div>
</div>