<?php

/**
 * Admin: Analytics
 */

use WPDK\Utils;

if (! current_user_can('tinypress_view_shortlink_analytics')) {
    return;
}

$post_id = get_the_ID();
$today   = current_time('Y-m-d');

global $wpdb;

$uncleared_condition = "(is_cleared = 0 OR is_cleared IS NULL OR is_cleared = '')";

// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- Custom table; TINYPRESS_TABLE_REPORTS is a safe constant; aggregation query not suitable for caching
$reports = $wpdb->get_results($wpdb->prepare("SELECT DATE(datetime) AS DateOnly, COUNT(*) AS ClickCount FROM " . TINYPRESS_TABLE_REPORTS . " WHERE post_id = %d AND " . $uncleared_condition . " GROUP BY DATE(datetime) ORDER BY DATE(datetime)", $post_id), ARRAY_A);

$data  = array();

// Index click counts by date string for gap-filling
$click_map = array();
foreach ($reports as $report) {
    $click_map[$report['DateOnly']] = (int) $report['ClickCount'];
}

// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- Custom table; TINYPRESS_TABLE_REPORTS is a safe constant; grouped visitor data powers the in-page analytics summary.
$visitor_rows = $wpdb->get_results($wpdb->prepare(
    "SELECT DATE(datetime) AS DateOnly, user_id, user_ip
    FROM " . TINYPRESS_TABLE_REPORTS . "
    WHERE post_id = %d AND " . $uncleared_condition . "
    GROUP BY DATE(datetime), user_id, user_ip
    ORDER BY DATE(datetime)",
    $post_id
), ARRAY_A);

$visitors_by_date = array();
foreach ($visitor_rows as $visitor_row) {
    $visitor_date = $visitor_row['DateOnly'];
    $visitor_id   = ! empty($visitor_row['user_ip'])
        ? 'ip:' . $visitor_row['user_ip']
        : (! empty($visitor_row['user_id']) ? 'user:' . $visitor_row['user_id'] : '');

    if (empty($visitor_id)) {
        continue;
    }

    if (! isset($visitors_by_date[$visitor_date])) {
        $visitors_by_date[$visitor_date] = array();
    }

    $visitors_by_date[$visitor_date][] = wp_hash($visitor_id);
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
    'chartDataByDate'    => $click_map,
    'visitorsByDate'     => $visitors_by_date,
    'firstDataDate'      => ! empty($click_map) ? array_key_first($click_map) : '',
    'lastDataDate'       => ! empty($click_map) ? array_key_last($click_map) : '',
    'postId'             => $post_id,
    'todayDate'          => $today,
    'defaultCustomStart' => date('Y-m-d', strtotime('-29 days', strtotime($today))),
    'defaultCustomEnd'   => $today,
    'nonce'              => wp_create_nonce('tinypress_reset_analytics_nonce'),
    'resetTodayText'     => esc_html__("Reset Today's Analytics", 'tinypress'),
    'resetYesterdayText' => esc_html__("Reset Yesterday's Analytics", 'tinypress'),
    'resetWeekText'      => esc_html__("Reset Week's Analytics", 'tinypress'),
    'resetLast30Text'    => esc_html__('Reset Last 30 Days Analytics', 'tinypress'),
    'resetMonthText'     => esc_html__("Reset Month's Analytics", 'tinypress'),
    'resetLastMonthText' => esc_html__("Reset Last Month's Analytics", 'tinypress'),
    'resetYearText'      => esc_html__("Reset Year's Analytics", 'tinypress'),
    'resetLast2YearsText' => esc_html__('Reset Last 2 Years Analytics', 'tinypress'),
    'resetCustomText'    => esc_html__("Reset Custom Range Analytics", 'tinypress'),
    'resetAllTimeText'   => esc_html__("Reset All Time Analytics", 'tinypress'),
    'resetConfirmText'   => esc_html__("Are you sure you want to reset the analytics for this period? This action cannot be undone.", 'tinypress'),
    'showingDataText'    => esc_html__('Showing data from %1$s to %2$s', 'tinypress'),
    'noDataText'         => esc_html__('No click data available for this period.', 'tinypress'),
    'clickSingularText'  => esc_html__('click', 'tinypress'),
    'clickPluralText'    => esc_html__('clicks', 'tinypress'),
    'chartDescriptions'  => array(
        'day'   => esc_html__('Each bar shows total clicks for one day.', 'tinypress'),
        'week'  => esc_html__('Each bar shows total clicks for a 7-day period.', 'tinypress'),
        'month' => esc_html__('Each bar shows total clicks for one month.', 'tinypress'),
        'year'  => esc_html__('Each bar shows total clicks for one year.', 'tinypress'),
    ),
));

?>
<div class="tinypress-meta-analytics">
    <div class="tinypress-reports-filter tinypress-analytics-filter" data-chart-filter>
        <label for="tinypress-analytics-date-range"><?php esc_html_e('Date Range:', 'tinypress'); ?></label>
        <select name="date_range" id="tinypress-analytics-date-range">
            <option value="today"><?php esc_html_e('Today', 'tinypress'); ?></option>
            <option value="yesterday"><?php esc_html_e('Yesterday', 'tinypress'); ?></option>
            <option value="last_7_days"><?php esc_html_e('Last 7 Days', 'tinypress'); ?></option>
            <option value="last_30_days" selected><?php esc_html_e('Last 30 Days', 'tinypress'); ?></option>
            <option value="this_month"><?php esc_html_e('This Month', 'tinypress'); ?></option>
            <option value="last_month"><?php esc_html_e('Last Month', 'tinypress'); ?></option>
            <option value="this_year"><?php esc_html_e('This Year', 'tinypress'); ?></option>
            <option value="last_2_years"><?php esc_html_e('Last 2 Years', 'tinypress'); ?></option>
            <option value="custom"><?php esc_html_e('Custom Range', 'tinypress'); ?></option>
            <option value="all_time"><?php esc_html_e('All Time', 'tinypress'); ?></option>
        </select>

        <span class="tinypress-reports-custom-dates">
            <label for="tinypress-analytics-custom-start"><?php esc_html_e('From:', 'tinypress'); ?></label>
            <input
                type="date"
                id="tinypress-analytics-custom-start"
                name="custom_start"
                value="<?php echo esc_attr(date('Y-m-d', strtotime('-29 days', strtotime($today)))); ?>"
                max="<?php echo esc_attr($today); ?>"
            />
            <label for="tinypress-analytics-custom-end"><?php esc_html_e('To:', 'tinypress'); ?></label>
            <input
                type="date"
                id="tinypress-analytics-custom-end"
                name="custom_end"
                value="<?php echo esc_attr($today); ?>"
                max="<?php echo esc_attr($today); ?>"
            />
        </span>

        <button type="button" class="button tinypress-analytics-apply"><?php esc_html_e('Apply', 'tinypress'); ?></button>
        <button type="button" id="reset-analytics" class="button button-secondary" data-action="reset-analytics">
            <span class="reset-text"><?php esc_html_e('Reset Last 30 Days Analytics', 'tinypress'); ?></span>
        </button>
    </div>

    <p class="description tinypress-analytics-range-description"></p>

    <div class="tinypress-reports-cards tinypress-analytics-cards">
        <div class="tinypress-report-card">
            <span class="dashicons dashicons-chart-bar"></span>
            <div class="tinypress-report-card-content">
                <h3 data-summary-metric="totalClicks">0</h3>
                <p><?php esc_html_e('Total Clicks', 'tinypress'); ?></p>
            </div>
        </div>

        <div class="tinypress-report-card">
            <span class="dashicons dashicons-admin-users"></span>
            <div class="tinypress-report-card-content">
                <h3 data-summary-metric="uniqueVisitors">0</h3>
                <p><?php esc_html_e('Unique Visitors', 'tinypress'); ?></p>
            </div>
        </div>

        <div class="tinypress-report-card">
            <span class="dashicons dashicons-calendar-alt"></span>
            <div class="tinypress-report-card-content">
                <h3 data-summary-metric="clickDays">0</h3>
                <p><?php esc_html_e('Days With Clicks', 'tinypress'); ?></p>
            </div>
        </div>

        <div class="tinypress-report-card">
            <span class="dashicons dashicons-performance"></span>
            <div class="tinypress-report-card-content">
                <h3 data-summary-metric="avgClicksPerDay">0</h3>
                <p><?php esc_html_e('Avg. per Click Day', 'tinypress'); ?></p>
            </div>
        </div>
    </div>

    <div id="chart" class="tinypress-report-section tinypress-report-chart tinypress-analytics-chart-section">
        <h2><?php esc_html_e('Clicks Over Time', 'tinypress'); ?></h2>
        <p class="tinypress-report-section-description" data-chart-description>
            <?php esc_html_e('Each bar shows total clicks for one day.', 'tinypress'); ?>
        </p>
        <div class="tinypress-chart-container">
            <div id="chart-timeline"></div>
        </div>
        <p class="tinypress-no-data" data-chart-no-data hidden>
            <?php esc_html_e('No click data available for this period.', 'tinypress'); ?>
        </p>
    </div>
</div>
