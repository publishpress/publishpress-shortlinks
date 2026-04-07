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

// Index click counts by date string for gap-filling
$click_map = array();
foreach ($reports as $report) {
    $click_map[$report['DateOnly']] = (int) $report['ClickCount'];
}

// Fill in zero-click days between first click and today
if (!empty($click_map)) {
    $start = new DateTime(array_key_first($click_map), new DateTimeZone('UTC'));
    $end   = new DateTime('now', new DateTimeZone('UTC'));

    $current = clone $start;
    while ($current <= $end) {
        $key       = $current->format('Y-m-d');
        $count     = isset($click_map[$key]) ? $click_map[$key] : 0;
        $ts        = (new DateTime($key . ' 12:00:00', new DateTimeZone('UTC')))->getTimestamp();
        $data[]    = array( (int) $ts * 1000, $count );
        $current->modify('+1 day');
    }
}

// Enqueue analytics script and pass data
wp_enqueue_script('tinypress-analytics');
wp_localize_script('tinypress-analytics', 'tinypressAnalytics', array(
    'chartData'          => $data,
    'postId'             => $post_id,
    'nonce'              => wp_create_nonce('tinypress_reset_analytics_nonce'),
    'resetTodayText'     => esc_html__("Reset Today's Analytics", 'tinypress'),
    'resetWeekText'      => esc_html__("Reset Week's Analytics", 'tinypress'),
    'resetMonthText'     => esc_html__("Reset Month's Analytics", 'tinypress'),
    'resetYearText'      => esc_html__("Reset Year's Analytics", 'tinypress'),
    'resetConfirmText'  => esc_html__("Are you sure you want to reset the analytics for this period? This action cannot be undone.", 'tinypress'),
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
