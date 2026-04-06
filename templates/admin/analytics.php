<?php

/**
 * Admin: Analytics
 */

use WPDK\Utils;

$post_id = get_the_ID();

global $wpdb;

// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- Custom table; TINYPRESS_TABLE_REPORTS is a safe constant; aggregation query not suitable for caching
$reports = $wpdb->get_results($wpdb->prepare("SELECT DATE(datetime) AS DateOnly, COUNT(*) AS ClickCount FROM " . TINYPRESS_TABLE_REPORTS . " WHERE post_id =%d AND is_cleared = 0 GROUP BY DATE(datetime) ORDER BY DATE(datetime)", $post_id), ARRAY_A);

$data  = array();

if (!empty($reports)) {
    // Create a map of dates to click counts
    $clicks_by_date = array();
    foreach ($reports as $report) {
        $clicks_by_date[$report['DateOnly']] = (int) $report['ClickCount'];
    }
    
    // Get the first and last dates
    $first_date = new DateTime($reports[0]['DateOnly'] . ' 12:00:00', new DateTimeZone('UTC'));
    $last_date = new DateTime($reports[count($reports) - 1]['DateOnly'] . ' 12:00:00', new DateTimeZone('UTC'));
    
    // Fill in all dates between first and last with 0 for missing dates
    $current_date = clone $first_date;
    while ($current_date <= $last_date) {
        $date_string = $current_date->format('Y-m-d');
        $timestamp = $current_date->getTimestamp();
        $click_count = isset($clicks_by_date[$date_string]) ? $clicks_by_date[$date_string] : 0;
        $data[] = array( (int) $timestamp * 1000, $click_count );
        $current_date->modify('+1 day');
    }
}

// Enqueue analytics script and pass data
wp_enqueue_script('tinypress-analytics');
wp_localize_script('tinypress-analytics', 'tinypressAnalytics', array(
    'chartData' => $data,
    'postId'    => $post_id,
    'nonce'     => wp_create_nonce('tinypress_reset_analytics_nonce')
));

?>
<div class="tinypress-meta-analytics">
    <div id="chart">
        <div class="toolbar">
            <div class="filter-buttons">
                <span class="btn date-filter today" data-filter="today"><?php esc_html_e('Today', 'tinypress'); ?></span>
                <span class="btn date-filter last_7_days" data-filter="last_7_days"><?php esc_html_e('Last 7 Days', 'tinypress'); ?></span>
                <span class="btn date-filter last_1_month" data-filter="last_1_month"><?php esc_html_e('Last 1 Month', 'tinypress'); ?></span>
                <span class="btn date-filter last_1_year" data-filter="last_1_year"><?php esc_html_e('Last 1 Year', 'tinypress'); ?></span>
            </div>
        </div>
        <button id="reset-analytics" class="button button-secondary" data-action="reset-analytics">
            <span class="reset-text"><?php esc_html_e("Reset Today's Analytics", 'tinypress'); ?></span>
        </button>
        <div id="chart-timeline"></div>
    </div>
</div>
