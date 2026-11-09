<?php
/** Adapter integration checks. Run only in a disposable shortlinks_mcp_* database. */
define('REST_REQUEST', true);
require $argv[1];
if (strpos(DB_NAME, 'shortlinks_mcp_') !== 0) {
    throw new RuntimeException('An isolated database is required.');
}
require $argv[2] . '/vendor/autoload.php';
wp_set_current_user(1);
$assertions = 0;
function check($ok, $message) {
    global $assertions;
    ++$assertions;
    if (!$ok) { throw new RuntimeException($message); }
}
function callShortlinks($name, $input = []) {
    $ability = wp_get_ability('publishpress-shortlinks/' . $name);
    check($ability !== null, 'Missing ability: ' . $name);
    $tool = \WP\MCP\Domain\Tools\McpTool::fromAbility($ability);
    check(!is_wp_error($tool), 'Invalid tool: ' . $name);
    return $tool->execute($input);
}
function success($value, $label) {
    check(!is_wp_error($value), $label . (is_wp_error($value) ? ': ' . $value->get_error_message() : ''));
    return $value;
}
// Upgrade the native reports schema from a pre-destination table without losing rows.
$wpdb->insert(TINYPRESS_TABLE_REPORTS, ['user_id' => '99123', 'post_id' => '0', 'user_ip' => '192.0.2.1', 'user_location' => '', 'redirect_destination' => '']);
$wpdb->query($wpdb->prepare('ALTER TABLE %i DROP COLUMN redirect_destination', TINYPRESS_TABLE_REPORTS));
update_option('tinypress_reports_schema_version', '2.0.0');
TINYPRESS_Main::instance()->create_data_table();
check(get_option('tinypress_reports_schema_version') === '3.0.0', 'Destination analytics schema version mismatch');
check((bool) $wpdb->get_var($wpdb->prepare('SHOW COLUMNS FROM %i LIKE %s', TINYPRESS_TABLE_REPORTS, 'redirect_destination')), 'Native schema upgrade missed destination column');
check((int) $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM %i WHERE user_id = %s', TINYPRESS_TABLE_REPORTS, '99123')) === 1, 'Native schema migration lost unrelated rows');

$settings = success(callShortlinks('get-plugin-settings'), 'Settings discovery');
check(count((array) $settings['fields']) > 15, 'Native settings were not discovered');
foreach ((array) $settings['fields'] as $key => $field) {
    check(!is_wp_error(\PublishPress\Shortlinks\Mcp\Registry::validate($settings['settings']->$key, $field['schema'])), 'Native settings format mismatch: ' . $key);
    if ($key === 'tinypress_autolist_post_types') { continue; }
    $before = get_option('tinypress_settings', []);
    success(callShortlinks('update-plugin-settings', ['expected_hash' => $settings['expected_hash'], 'settings' => (object) [$key => $settings['settings']->$key], 'dry_run' => true]), 'Preview ' . $key);
    check(get_option('tinypress_settings', []) === $before, 'Preview changed settings');
    $settings = success(callShortlinks('update-plugin-settings', ['expected_hash' => $settings['expected_hash'], 'settings' => (object) [$key => $settings['settings']->$key]]), 'Save ' . $key);
    check(is_wp_error(callShortlinks('update-plugin-settings', ['expected_hash' => $settings['expected_hash'], 'settings' => (object) [$key => null]])), 'Null accepted: ' . $key);
}
$discovery = success(callShortlinks('get-link'), 'Link schema');
check(isset($discovery['fields']->target_url), 'Missing native link field');
$link = success(callShortlinks('save-link', ['settings' => (object) ['target_url' => 'https://example.test/destination', 'post_title' => 'Fixture link', 'tiny_slug' => 'fixture-link']]), 'Create link');
check($link['complete'] && get_post_meta($link['id'], 'target_url', true) === 'https://example.test/destination', 'Native save mismatch');
foreach ((array) $link['fields'] as $key => $field) {
    check(!is_wp_error(\PublishPress\Shortlinks\Mcp\Registry::validate($link['settings']->$key, $field['schema'])), 'Link format mismatch: ' . $key);
}
// Round-trip every discovered local field through native sanitizers and persistence.
update_post_meta($link['id'], '_mcp_unrelated', ['keep' => true]);
$link = success(callShortlinks('get-link', ['id' => $link['id']]), 'Fresh local token');
foreach ((array) $link['fields'] as $key => $field) {
    $value = $link['settings']->$key;
    $preview = success(callShortlinks('save-link', ['id' => $link['id'], 'expected_hash' => $link['expected_hash'], 'settings' => (object) [$key => $value], 'dry_run' => true]), 'Local preview: ' . $key);
    $link = success(callShortlinks('save-link', ['id' => $link['id'], 'expected_hash' => $link['expected_hash'], 'settings' => (object) [$key => $value]]), 'Local save: ' . $key);
    check($link['complete'], 'Local write unconfirmed: ' . $key);
    check(get_post_meta($link['id'], '_mcp_unrelated', true) === ['keep' => true], 'Unrelated metadata lost');
}
$category = success(callShortlinks('save-category', ['name' => 'Fixture category']), 'Category create');
$link = success(callShortlinks('set-link-categories', ['id' => $link['id'], 'expected_hash' => $link['expected_hash'], 'category_ids' => [$category['id']]]), 'Category assignment');
check($link['complete'] && $link['category_ids'] === [$category['id']], 'Native category assignment mismatch');
$link = success(callShortlinks('set-link-favorite', ['id' => $link['id'], 'expected_hash' => $link['expected_hash'], 'favorite' => true]), 'Favorite set');
check($link['favorite'], 'Native favorite mismatch');
$second = success(callShortlinks('save-link', ['settings' => (object) ['target_url' => 'https://example.test/second', 'post_title' => 'Second fixture', 'tiny_slug' => 'fixture-second']]), 'Second link');
$rows = [['id' => $link['id'], 'expected_hash' => $link['expected_hash'], 'settings' => (object) ['tiny_notes' => 'bulk one']], ['id' => $second['id'], 'expected_hash' => $second['expected_hash'], 'settings' => (object) ['tiny_notes' => 'bulk two']]];
$badRows = $rows;
$badRows[1]['settings'] = (object) ['unknown' => true];
$before = get_post_meta($link['id']);
check(is_wp_error(callShortlinks('bulk-update-links', ['items' => $badRows])), 'Mixed invalid batch accepted');
check(get_post_meta($link['id']) === $before, 'Invalid batch partially saved');
$batch = success(callShortlinks('bulk-update-links', ['items' => $rows]), 'Bulk save');
check($batch['complete'], 'Bulk result incomplete');
$link = success(callShortlinks('get-link', ['id' => $link['id']]), 'After bulk');
$analytics = success(callShortlinks('get-link-analytics', ['id' => $link['id']]), 'Analytics read');
check($analytics['items'] === [], 'Synthetic fixture unexpectedly has visitors');
success(callShortlinks('reset-link-analytics', ['id' => $link['id'], 'expected_hash' => $analytics['expected_hash'], 'dry_run' => true]), 'Analytics reset preview');
if ($settings['pro']) {
    success(callShortlinks('get-link-health', ['id' => $link['id']]), 'Native health read');
    success(callShortlinks('check-link-health', ['id' => $link['id'], 'expected_hash' => $link['expected_hash'], 'dry_run' => true]), 'Native health preview');
    $health = success(callShortlinks('check-link-health', ['id' => $link['id'], 'expected_hash' => $link['expected_hash']]), 'Native health save with isolated HTTP');
    check($health['result']->status !== 'unchecked', 'Native health result was not persisted');
    $link = success(callShortlinks('get-link', ['id' => $link['id']]), 'After native health');
}
check(is_wp_error(callShortlinks('save-link', ['id' => (string) $link['id'], 'expected_hash' => $link['expected_hash'], 'settings' => (object) []])), 'Numeric string ID accepted');
check(is_wp_error(callShortlinks('save-link', ['id' => $link['id'], 'expected_hash' => str_repeat('0', 64), 'settings' => (object) ['tiny_notes' => 'stale']])), 'Stale save accepted');
$before = get_post_meta($link['id']);
check(is_wp_error(callShortlinks('save-link', ['id' => $link['id'], 'expected_hash' => $link['expected_hash'], 'settings' => (object) ['link_status' => 'false', 'tiny_notes' => 'do not save']])), 'Malformed mixed patch accepted');
check($before === get_post_meta($link['id']), 'Malformed patch wrote metadata');
$link = success(callShortlinks('save-link', ['id' => $link['id'], 'expected_hash' => $link['expected_hash'], 'settings' => (object) ['link_status' => false]]), 'Disable native switcher');
check(get_post_meta($link['id'], 'link_status', true) === '0', 'False switcher must use native zero storage');
check(\WPDK\Utils::get_meta('link_status', $link['id'], '1') === '0', 'Disabled link fell back to enabled');
$link = success(callShortlinks('save-link', ['id' => $link['id'], 'expected_hash' => $link['expected_hash'], 'settings' => (object) ['link_status' => true]]), 'Enable native switcher');
check(TINYPRESS_Meta_boxes::get_toggle_value($link['id'], 'redirection_no_follow', 'redirection_no_follow_use_global', true) === '1', 'Missing inheritance ignored global toggle');
$deny = static function ($caps, $cap) { return $cap === 'tinypress_edit_shortlinks' ? ['do_not_allow'] : $caps; };
add_filter('map_meta_cap', $deny, PHP_INT_MAX, 2);
check(is_wp_error(callShortlinks('save-link', ['id' => $link['id'], 'expected_hash' => $link['expected_hash'], 'settings' => (object) ['tiny_notes' => 'denied']])), 'Administrator bypassed denied capability');
remove_filter('map_meta_cap', $deny, PHP_INT_MAX);
check(!isset($link['fields']->link_password) && !isset($settings['fields']->tinypress_global_link_password), 'Secret discovered');
if ($settings['pro'] && isset($link['fields']->dynamic_redirect_rules)) {
    $link = success(callShortlinks('get-link', ['id' => $link['id']]), 'Routing token');
    $rules = [(object) ['id' => 'fixture-mobile', 'enabled' => true, 'name' => 'Mobile visitors', 'destination_url' => 'https://example.test/mobile', 'country_mode' => 'include', 'countries' => ['US'], 'devices' => ['mobile'], 'referrer_mode' => 'include', 'referrer_domains' => 'example.com']];
    $routed = success(callShortlinks('save-link', ['id' => $link['id'], 'expected_hash' => $link['expected_hash'], 'settings' => (object) ['dynamic_redirect_enabled' => true, 'dynamic_redirect_rules' => $rules]]), 'Native routing save');
    check($routed['complete'] && $routed['settings']->dynamic_redirect_rules[0]->enabled === true, 'Native routing representation mismatch');
    $before = get_post_meta($link['id']);
    $badRules = [(object) ['enabled' => 'false', 'destination_url' => 'https://example.test/invalid']];
    check(is_wp_error(callShortlinks('save-link', ['id' => $link['id'], 'expected_hash' => $routed['expected_hash'], 'settings' => (object) ['dynamic_redirect_rules' => $badRules, 'tiny_notes' => 'must not save']])), 'Malformed nested routing accepted');
    check(get_post_meta($link['id']) === $before, 'Malformed routing patch partially saved');
    $link = $routed;
}

// Native copy, publication and lifecycle services.
delete_post_meta($link['id'], '_mcp_unrelated');
$link = success(callShortlinks('get-link', ['id' => $link['id']]), 'Copy source token');
$copy = success(callShortlinks('copy-link', ['id' => $link['id'], 'expected_hash' => $link['expected_hash']]), 'Native copy');
check($copy['complete'] && $copy['status'] === 'draft', 'Native copy did not create an independent draft');
$copy = success(callShortlinks('set-link-status', ['id' => $copy['id'], 'expected_hash' => $copy['expected_hash'], 'status' => 'publish']), 'Publish copy');
check($copy['status'] === 'publish', 'Native publish failed');
success(callShortlinks('transition-link', ['id' => $copy['id'], 'expected_hash' => $copy['expected_hash'], 'operation' => 'trash']), 'Trash copy');
$copy = success(callShortlinks('get-link', ['id' => $copy['id']]), 'Trash token');
success(callShortlinks('transition-link', ['id' => $copy['id'], 'expected_hash' => $copy['expected_hash'], 'operation' => 'restore']), 'Restore copy');

// Source reads, metadata grants and revision preview destinations remain scoped.
$source = wp_insert_post(['post_type' => 'post', 'post_status' => 'draft', 'post_title' => 'Source fixture']);
update_post_meta($second['id'], 'source_post_id', $source);
$denySource = static function ($caps, $cap, $userId, $args) use ($source) {
    return $cap === 'read_post' && (int) ($args[0] ?? 0) === $source ? ['do_not_allow'] : $caps;
};
add_filter('map_meta_cap', $denySource, PHP_INT_MAX, 4);
check(is_wp_error(callShortlinks('get-link', ['id' => $second['id']])), 'Unreadable source leaked link data');
remove_filter('map_meta_cap', $denySource, PHP_INT_MAX);
update_post_meta($second['id'], 'is_revision_link', '1');
update_post_meta($second['id'], 'target_url', 'https://example.test/?preview=true&_wpnonce=synthetic-secret');
$revision = success(callShortlinks('get-link', ['id' => $second['id']]), 'Revision link');
check(!isset($revision['settings']->target_url) && strpos(wp_json_encode($revision), 'synthetic-secret') === false, 'Preview nonce leaked');
check(is_wp_error(callShortlinks('save-link', ['id' => $second['id'], 'expected_hash' => $revision['expected_hash'], 'settings' => (object) ['target_url' => 'https://example.test/override']])), 'Revision destination policy bypassed');
$link = success(callShortlinks('get-link', ['id' => $link['id']]), 'Metadata permission token');
$denyMeta = static function ($caps, $cap, $userId, $args) use ($link) {
    return $cap === 'edit_post_meta' && (int) ($args[0] ?? 0) === $link['id'] ? ['do_not_allow'] : $caps;
};
add_filter('map_meta_cap', $denyMeta, PHP_INT_MAX, 4);
check(is_wp_error(callShortlinks('save-link', ['id' => $link['id'], 'expected_hash' => $link['expected_hash'], 'settings' => (object) ['tiny_notes' => 'denied']])), 'Denied metadata permission bypassed');
remove_filter('map_meta_cap', $denyMeta, PHP_INT_MAX);

$scopeFile = getenv('SHORTLINKS_MCP_CAPABILITIES_FILE');
if ($scopeFile && is_readable($scopeFile)) {
    require_once $scopeFile;
    update_option('cme_capabilities_application_password_capabilities', true);
    PP_Capabilities_Application_Password_Capabilities::instance();
    $scopeUser = is_multisite() ? wp_insert_user(['user_login' => 'mcp_scope_admin', 'user_pass' => 'synthetic-only', 'role' => 'administrator']) : 1;
    wp_set_current_user($scopeUser);
    $password = WP_Application_Passwords::create_new_application_password($scopeUser, ['name' => 'Shortlinks isolated scope']);
    check(!is_wp_error($password), 'Scoped password creation failed');
    $uuid = $password[1]['uuid'];
    $key = pp_capabilities_application_password_storage_key($scopeUser, $uuid);
    update_option(pp_capabilities_application_password_option_name(), [$key => ['user_id' => $scopeUser, 'uuid' => $uuid, 'denied' => ['tinypress_manage_shortlink_settings' => false, 'tinypress_edit_shortlinks' => false]]]);
    do_action('application_password_did_authenticate', get_userdata($scopeUser), $password[1]);
    check(is_wp_error(callShortlinks('get-plugin-settings')), 'Application-password settings restriction bypassed');
    check(is_wp_error(callShortlinks('save-link', ['id' => $link['id'], 'expected_hash' => $link['expected_hash'], 'settings' => (object) ['tiny_notes' => 'scope denied']])), 'Application-password edit restriction bypassed');
    update_option(pp_capabilities_application_password_option_name(), []);
    update_option('cme_capabilities_application_password_capabilities', false);
    WP_Application_Passwords::delete_application_password($scopeUser, $uuid);
    unset($password);
    wp_set_current_user(1);
}
$editorPost = wp_insert_post(['post_type' => 'post', 'post_status' => 'draft', 'post_title' => 'Editor Short String fixture']);
$editorState = success(callShortlinks('get-post-shortlink', ['id' => $editorPost]), 'Editor Short String read');
$editorSaved = success(callShortlinks('set-post-shortlink', ['id' => $editorPost, 'expected_hash' => $editorState['expected_hash'], 'tiny_slug' => 'fixture-editor-post']), 'Editor Short String save');
check($editorSaved['complete'] && $editorSaved['tiny_slug'] === 'fixture-editor-post', 'Native editor/mirror synchronization mismatch');
check(get_post_field('post_title', $editorPost) === 'Editor Short String fixture', 'Native Short String edit changed post content');

// Native mirror hooks cannot bypass source metadata restrictions.
update_post_meta($second['id'], 'is_revision_link', '0');
$mirrorToken = success(callShortlinks('get-link', ['id' => $second['id']]), 'Mirror metadata token');
$denySourceMeta = static function ($caps, $cap, $userId, $args) use ($source) {
    return $cap === 'edit_post_meta' && (int) ($args[0] ?? 0) === $source ? ['do_not_allow'] : $caps;
};
add_filter('map_meta_cap', $denySourceMeta, PHP_INT_MAX, 4);
check(is_wp_error(callShortlinks('save-link', ['id' => $second['id'], 'expected_hash' => $mirrorToken['expected_hash'], 'settings' => (object) ['tiny_slug' => 'fixture-denied-source']])), 'Native source hook bypassed denied metadata permission');
remove_filter('map_meta_cap', $denySourceMeta, PHP_INT_MAX);

// Actual chart reset retains audit rows and does not export visitor data.
$wpdb->insert(TINYPRESS_TABLE_REPORTS, ['user_id' => 'fixture-user', 'post_id' => (string) $link['id'], 'user_ip' => '192.0.2.9', 'user_location' => 'sensitive fixture referrer', 'redirect_destination' => 'https://example.test/target']);
$analytics = success(callShortlinks('get-link-analytics', ['id' => $link['id']]), 'Stored chart read');
check(count($analytics['items']) === 1 && $analytics['items'][0]['clicks'] === 1, 'Native click aggregation mismatch');
if ($settings['pro']) {
    check(count($analytics['destination_items']) === 1 && $analytics['destination_items'][0]['clicks'] === 1, 'Native Pro destination counts missing');
    check(!isset($analytics['destination_items'][0]['destination']), 'Analytics exported raw destination logs');
}
check(strpos(wp_json_encode($analytics), '192.0.2.9') === false && strpos(wp_json_encode($analytics), 'fixture-user') === false, 'Visitor identifier leaked');
$reset = success(callShortlinks('reset-link-analytics', ['id' => $link['id'], 'expected_hash' => $analytics['expected_hash']]), 'Native reset');
check($reset['complete'] && !$reset['items'], 'Native chart reset mismatch');
check((int) $wpdb->get_var($wpdb->prepare('SELECT is_cleared FROM ' . TINYPRESS_TABLE_REPORTS . ' WHERE post_id = %d LIMIT 1', $link['id'])) === 0, 'Chart reset cleared native audit log');

// A denied metadata save reports its existing post honestly.
$link = success(callShortlinks('get-link', ['id' => $link['id']]), 'Fault token');
$failMeta = static function ($check, $id, $key) use ($link) {
    return (int) $id === $link['id'] && $key === 'tiny_notes' ? false : $check;
};
add_filter('update_post_metadata', $failMeta, 10, 3);
$partial = success(callShortlinks('save-link', ['id' => $link['id'], 'expected_hash' => $link['expected_hash'], 'settings' => (object) ['tiny_notes' => 'Do not store fault value']]), 'Fault outcome');
remove_filter('update_post_metadata', $failMeta);
check(!$partial['complete'] && $partial['id'] === $link['id'], 'Native metadata failure reported as complete');

// Legacy form globals cannot add settings or metadata outside the tool contract.
$link = success(callShortlinks('get-link', ['id' => $link['id']]), 'Request token');
$savedPost = $_POST;
$_POST = ['tinypress_meta_main' => ['target_url' => 'https://example.test/injected'], 'tinypress_settings' => ['tinypress_link_prefix_slug' => 'injected']];
try {
    $result = success(callShortlinks('save-link', ['id' => $link['id'], 'expected_hash' => $link['expected_hash'], 'settings' => (object) ['tiny_notes' => 'Only validated text']]), 'Isolated request save');
    check(get_post_meta($link['id'], 'target_url', true) === 'https://example.test/destination', 'Form payload injected an unvalidated target');
    check(isset($_POST['tinypress_meta_main']), 'Caller request context was not restored');
} finally {
    $_POST = $savedPost;
}

// Native internal-title choices use the object destination, including create-time inputs.
$internal = success(callShortlinks('save-link', ['settings' => (object) ['post_title' => 'Internal destination', 'target_url' => home_url('/article'), 'tiny_slug' => 'fixture-internal', 'autolink_alt_text' => 'post_title']]), 'Internal label create');
check($internal['complete'] && in_array('post_title', $internal['fields']->autolink_alt_text['schema']['enum'], true), 'Internal native label choice missing');
check($internal['settings']->autolink_alt_text === 'post_title', 'Internal label choice did not round-trip');
check(is_wp_error(callShortlinks('save-link', ['id' => $link['id'], 'expected_hash' => success(callShortlinks('get-link', ['id' => $link['id']]), 'External label token')['expected_hash'], 'settings' => (object) ['autolink_alt_text' => 'post_title']])), 'External destination accepted an internal-only label choice');

// New object identity may exist after denial, but no unauthorized metadata may be written.
$denyCreateMeta = static function ($caps, $cap, $userId, $args) {
    return $cap === 'edit_post_meta' && ($args[1] ?? '') === 'target_url' ? ['do_not_allow'] : $caps;
};
add_filter('map_meta_cap', $denyCreateMeta, PHP_INT_MAX, 4);
$deniedCreate = success(callShortlinks('save-link', ['settings' => (object) ['post_title' => 'Denied metadata fixture', 'target_url' => 'https://example.test/denied-meta', 'tiny_slug' => 'fixture-denied-meta']]), 'Denied metadata partial result');
remove_filter('map_meta_cap', $denyCreateMeta, PHP_INT_MAX);
check(!$deniedCreate['complete'] && isset($deniedCreate['id']), 'New-object denial did not report partial identity');
check(get_post_meta($deniedCreate['id'], 'target_url', true) === '' && get_post_meta($deniedCreate['id'], 'tiny_slug', true) === '', 'Native creator wrote unauthorized metadata');

$settingsToken = success(callShortlinks('get-plugin-settings'), 'Duplicate settings token');
$before = get_option('tinypress_settings');
$duplicateTypes = [(object) ['post_type' => 'post', 'behavior' => 'never'], (object) ['post_type' => 'post', 'behavior' => 'on_create']];
check(is_wp_error(callShortlinks('update-plugin-settings', ['expected_hash' => $settingsToken['expected_hash'], 'settings' => (object) ['tinypress_autolist_post_types' => $duplicateTypes, 'tinypress_link_prefix_slug' => 'must-not-save']])), 'Duplicate native list identifiers accepted');
check(get_option('tinypress_settings') === $before, 'Duplicate list mixed patch partially saved');

if ($settings['pro']) {
    // Native dynamic validation must not lazily generate a missing slug on a preview.
    $previewLink = success(callShortlinks('save-link', ['settings' => (object) ['post_title' => 'Preview purity fixture', 'target_url' => 'https://example.test/preview']]), 'Preview fixture');
    delete_post_meta($previewLink['id'], 'tiny_slug');
    $previewLink = success(callShortlinks('get-link', ['id' => $previewLink['id']]), 'Missing slug token');
    $before = get_post_meta($previewLink['id']);
    success(callShortlinks('save-link', ['id' => $previewLink['id'], 'expected_hash' => $previewLink['expected_hash'], 'settings' => (object) ['dynamic_redirect_rules' => [(object) ['enabled' => true, 'destination_url' => 'https://example.test/rule']]], 'dry_run' => true]), 'Pure native routing preview');
    check(get_post_meta($previewLink['id']) === $before, 'Native routing preview generated metadata');
    set_transient('tinypress_autolink_rules', ['fixture'], 600);
    wp_cache_set('tinypress_autolink_rules', ['fixture'], 'tinypress_autolink');
    success(callShortlinks('clear-autolink-cache', ['dry_run' => true]), 'Cache preview');
    check(get_transient('tinypress_autolink_rules') !== false, 'Cache preview deleted data');
    $cleared = success(callShortlinks('clear-autolink-cache'), 'Native cache invalidation');
    check($cleared['complete'], 'Native cache invalidation unconfirmed');
}

// Every native operation capability must remain enforceable on an Administrator.
$capLink = success(callShortlinks('get-link', ['id' => $link['id']]), 'Capability matrix token');
$capabilityCases = [
    ['tinypress_view_shortlinks', 'get-link', ['id' => $link['id']]],
    ['tinypress_create_shortlinks', 'save-link', ['settings' => (object) ['target_url' => 'https://example.test/capability'], 'dry_run' => true]],
    ['tinypress_edit_shortlinks', 'save-link', ['id' => $link['id'], 'expected_hash' => $capLink['expected_hash'], 'settings' => (object) ['tiny_notes' => 'denied'], 'dry_run' => true]],
    ['tinypress_delete_shortlinks', 'transition-link', ['id' => $link['id'], 'expected_hash' => $capLink['expected_hash'], 'operation' => 'trash', 'dry_run' => true]],
    ['tinypress_view_shortlink_analytics', 'get-link-analytics', ['id' => $link['id']]],
    ['tinypress_manage_shortlink_settings', 'get-plugin-settings', []],
];
foreach ($capabilityCases as $case) {
    $denyCapability = static function ($caps, $cap) use ($case) { return $cap === $case[0] ? ['do_not_allow'] : $caps; };
    add_filter('map_meta_cap', $denyCapability, PHP_INT_MAX, 2);
    check(is_wp_error(callShortlinks($case[1], $case[2])), 'Denied capability bypassed: ' . $case[0]);
    remove_filter('map_meta_cap', $denyCapability, PHP_INT_MAX);
}
$denyAssignment = static function ($caps, $cap) { return $cap === 'assign_term' ? ['do_not_allow'] : $caps; };
add_filter('map_meta_cap', $denyAssignment, PHP_INT_MAX, 2);
check(is_wp_error(callShortlinks('set-link-categories', ['id' => $link['id'], 'expected_hash' => $capLink['expected_hash'], 'category_ids' => [$category['id']], 'dry_run' => true])), 'Denied individual category assignment bypassed');
remove_filter('map_meta_cap', $denyAssignment, PHP_INT_MAX);
$lockUser = wp_insert_user(['user_login' => 'mcp_editor_lock', 'user_pass' => 'synthetic-only', 'role' => 'editor']);
update_post_meta($link['id'], '_edit_lock', time() . ':' . $lockUser);
$lockToken = success(callShortlinks('get-link', ['id' => $link['id']]), 'Locked link read');
check(is_wp_error(callShortlinks('save-link', ['id' => $link['id'], 'expected_hash' => $lockToken['expected_hash'], 'settings' => (object) ['tiny_notes' => 'locked'], 'dry_run' => true])), 'Native editor lock bypassed');
delete_post_meta($link['id'], '_edit_lock');

// Legacy inheritance storage is projected through the same resolver as native editors.
$legacy = success(callShortlinks('save-link', ['settings' => (object) ['post_title' => 'Legacy inheritance fixture', 'target_url' => 'https://example.test/legacy', 'tiny_slug' => 'fixture-legacy-inheritance']]), 'Legacy fixture');
update_post_meta($legacy['id'], 'redirection_no_follow_use_global', ['1']);
update_post_meta($legacy['id'], 'redirection_sponsored_use_global', []);
update_post_meta($legacy['id'], 'redirection_sponsored', '1');
$legacy = success(callShortlinks('get-link', ['id' => $legacy['id']]), 'Legacy storage discovery');
check($legacy['settings']->redirection_no_follow_use_global === '1', 'Legacy inheritance array was coerced to text');
check($legacy['settings']->redirection_sponsored_use_global === 'enabled', 'Legacy local mode differs from native editor');
$legacy = success(callShortlinks('save-link', ['id' => $legacy['id'], 'expected_hash' => $legacy['expected_hash'], 'settings' => (object) ['tiny_notes' => 'Keep legacy modes']]), 'Unrelated legacy patch');
check(get_post_meta($legacy['id'], 'redirection_no_follow_use_global', true) === ['1'], 'Unrelated save rewrote legacy storage');
if ($settings['pro']) {
    update_post_meta($legacy['id'], 'autolink_min_usage_use_global', ['1']);
    update_post_meta($legacy['id'], 'expired_notice_title_use_global', []);
    update_post_meta($legacy['id'], 'expired_notice_title', 'Local title');
    $legacy = success(callShortlinks('get-link', ['id' => $legacy['id']]), 'Pro legacy storage discovery');
    check($legacy['settings']->autolink_min_usage_use_global === '1' && $legacy['settings']->expired_notice_title_use_global === 'custom', 'Pro inheritance mode/schema conflict');
}

// Native role selectors and Pro block exclusions support older saved representations.
$oldSettings = get_option('tinypress_settings');
$legacySettings = $oldSettings;
$legacySettings['tinypress_role_view'] = ['administrator' => true, 'editor' => true, 'subscriber' => false];
if ($settings['pro']) {
    $legacySettings['tinypress_autolink_excluded_blocks'] = ['core/paragraph', ['name' => 'core/heading', 'enabled' => false]];
}
update_option('tinypress_settings', $legacySettings);
$legacyRead = success(callShortlinks('get-plugin-settings'), 'Legacy selector read');
check($legacyRead['settings']->tinypress_role_view === ['administrator', 'editor'], 'Native role-map discovery mismatch');
if ($settings['pro']) {
    check($legacyRead['settings']->tinypress_autolink_excluded_blocks[0]->name === 'core/paragraph' && $legacyRead['settings']->tinypress_autolink_excluded_blocks[0]->enabled === true, 'Legacy block name list was not projected through native sanitizer');
}
update_option('tinypress_settings', $oldSettings);

// Credential-bearing URLs must not leak through legacy flags, repeater values or defaults.
update_post_meta($legacy['id'], 'target_url', 'https://name:synthetic-password@example.test/path');
$redacted = success(callShortlinks('get-link', ['id' => $legacy['id']]), 'URL credential read');
check(!isset($redacted['settings']->target_url) && $redacted['fields']->target_url['redacted'], 'URL credentials exposed in configuration');
check(strpos(wp_json_encode($redacted), 'synthetic-password') === false, 'URL password appeared outside settings');
update_post_meta($legacy['id'], 'is_revision_link', '0');
update_post_meta($legacy['id'], 'target_url', 'https://example.test/?preview=true&preview_nonce=synthetic-preview-secret');
$redacted = success(callShortlinks('get-link', ['id' => $legacy['id']]), 'Legacy preview read');
check(strpos(wp_json_encode($redacted), 'synthetic-preview-secret') === false, 'Missing revision flag exposed a preview nonce');
update_post_meta($legacy['id'], 'target_url', 'https://example.test/?preview=true&amp;preview_nonce=synthetic-entity-secret');
$entityRead = success(callShortlinks('get-link', ['id' => $legacy['id']]), 'Entity-encoded credential read');
check(strpos(wp_json_encode($entityRead), 'synthetic-entity-secret') === false, 'Entity-encoded preview nonce leaked');
$nativeSections = WPDK_Settings::$args['sections']['tinypress_meta_main'];
$injectedDefault = false;
foreach (WPDK_Settings::$args['sections']['tinypress_meta_main'] as &$section) {
    if (!isset($section['fields'])) { continue; }
    foreach ($section['fields'] as &$field) {
        if (($field['id'] ?? '') === 'target_url') {
            $field['default'] = 'https://default-user:synthetic-default-secret@example.test/path';
            $injectedDefault = true;
        }
    }
    unset($field);
}
unset($section);
check($injectedDefault, 'Default credential fixture did not reach native fields');
$defaultRead = success(callShortlinks('get-link'), 'Default URL credential read');
check(strpos(wp_json_encode($defaultRead), 'synthetic-default-secret') === false, 'URL credentials leaked through defaults');
WPDK_Settings::$args['sections']['tinypress_meta_main'] = $nativeSections;
if ($settings['pro']) {
    update_post_meta($legacy['id'], 'dynamic_redirect_rules', [['id' => 'legacy-secret-rule', 'enabled' => '1', 'name' => 'Legacy rule', 'destination_url' => 'https://user:synthetic-rule-secret@example.test/private', 'country_mode' => 'any', 'countries' => [], 'devices' => [], 'referrer_mode' => 'any', 'referrer_domains' => '']]);
    $redacted = success(callShortlinks('get-link', ['id' => $legacy['id']]), 'Nested URL credential read');
    check(strpos(wp_json_encode($redacted), 'synthetic-rule-secret') === false && in_array('dynamic_redirect_rules.0.destination_url', $redacted['redacted_fields'], true), 'Nested routing URL credentials leaked');
}

// Recheck grants after native sanitizers, before indirect post-save hooks execute.
update_post_meta($legacy['id'], 'target_url', 'https://example.test/legacy');
$lateToken = success(callShortlinks('get-link', ['id' => $legacy['id']]), 'Late permission token');
$denyLateMeta = static function ($caps, $cap, $userId, $args) use ($legacy) {
    return $cap === 'edit_post_meta' && (int) ($args[0] ?? 0) === $legacy['id'] ? ['do_not_allow'] : $caps;
};
$revokeDuringClean = static function ($data) use ($denyLateMeta) {
    add_filter('map_meta_cap', $denyLateMeta, PHP_INT_MAX, 4);
    return $data;
};
add_filter('pb_settings_tinypress_meta_main_save', $revokeDuringClean, PHP_INT_MAX);
$before = get_post_meta($legacy['id']);
check(is_wp_error(callShortlinks('save-link', ['id' => $legacy['id'], 'expected_hash' => $lateToken['expected_hash'], 'settings' => (object) ['post_title' => 'Do not save revoked edit', 'tiny_notes' => 'denied']])), 'Native sanitizer bypassed a revoked metadata grant');
check(get_post_meta($legacy['id']) === $before && get_post_field('post_title', $legacy['id']) === 'Legacy inheritance fixture', 'Revoked edit wrote data before authorization');
remove_filter('pb_settings_tinypress_meta_main_save', $revokeDuringClean, PHP_INT_MAX);
remove_filter('map_meta_cap', $denyLateMeta, PHP_INT_MAX);

if ($settings['pro']) {
    $reportDay = current_time('Y-m-d');
    $report = success(callShortlinks('get-link-report', ['ids' => [$link['id']], 'start_date' => $reportDay, 'end_date' => $reportDay]), 'Native scoped report');
    check($report['stats']->total_clicks === 1 && $report['stats']->unique_visitors === 1 && $report['stats']->active_links === 1, 'Native report metrics or object scope mismatch');
    check(strpos(wp_json_encode($report), '192.0.2.9') === false && strpos(wp_json_encode($report), 'fixture-user') === false, 'Native report exported visitor identifiers');
    check(is_wp_error(callShortlinks('get-link-report', ['ids' => [$link['id'], 99999999], 'start_date' => $reportDay, 'end_date' => $reportDay])), 'Report accepted an unauthorized mixed object scope');
    check(is_wp_error(callShortlinks('get-link-report', ['ids' => [$link['id']], 'start_date' => '2026-02-30', 'end_date' => $reportDay])), 'Invalid report date accepted');
}

// Role delegation authority is checked again after settings sanitizers run.
$roleToken = success(callShortlinks('get-plugin-settings'), 'Late role permission token');
$roleBefore = get_option('tinypress_settings');
$denyDelegation = static function ($caps, $cap) { return $cap === 'manage_options' ? ['do_not_allow'] : $caps; };
$revokeDelegation = static function ($data) use ($denyDelegation) {
    add_filter('map_meta_cap', $denyDelegation, PHP_INT_MAX, 2);
    return $data;
};
add_filter('pb_settings_tinypress_settings_save', $revokeDelegation, PHP_INT_MAX);
check(is_wp_error(callShortlinks('update-plugin-settings', ['expected_hash' => $roleToken['expected_hash'], 'settings' => (object) ['tinypress_role_view' => ['administrator']]])), 'Native sanitizer bypassed revoked role delegation authority');
remove_filter('pb_settings_tinypress_settings_save', $revokeDelegation, PHP_INT_MAX);
remove_filter('map_meta_cap', $denyDelegation, PHP_INT_MAX);
check(get_option('tinypress_settings') === $roleBefore, 'Revoked role authority changed native settings');

// Bulk operations must report the successful prefix honestly if later permissions change.
$partialA = success(callShortlinks('save-link', ['settings' => (object) ['post_title' => 'Partial A', 'target_url' => 'https://example.test/partial-a']]), 'Partial A');
$partialB = success(callShortlinks('save-link', ['settings' => (object) ['post_title' => 'Partial B', 'target_url' => 'https://example.test/partial-b']]), 'Partial B');
$denySecond = static function ($caps, $cap, $userId, $args) use ($partialB) { return $cap === 'edit_post_meta' && (int) ($args[0] ?? 0) === (int) $partialB['id'] ? ['do_not_allow'] : $caps; };
$revokeSecond = static function ($id) use ($partialA, $denySecond) {
    if ((int) $id === (int) $partialA['id']) { add_filter('map_meta_cap', $denySecond, PHP_INT_MAX, 4); }
};
add_action('save_post_tinypress_link', $revokeSecond, 10, 1);
$partialBatch = success(callShortlinks('bulk-update-links', ['items' => [['id' => $partialA['id'], 'expected_hash' => $partialA['expected_hash'], 'settings' => (object) ['tiny_notes' => 'First saved']], ['id' => $partialB['id'], 'expected_hash' => $partialB['expected_hash'], 'settings' => (object) ['tiny_notes' => 'Must not save']]]]), 'Partial batch result');
remove_action('save_post_tinypress_link', $revokeSecond);
remove_filter('map_meta_cap', $denySecond, PHP_INT_MAX);
check(!$partialBatch['complete'] && $partialBatch['applied_ids'] === [$partialA['id']] && $partialBatch['failed_id'] === $partialB['id'], 'Partial batch result hid a successful write: ' . json_encode($partialBatch));
check(get_post_meta($partialB['id'], 'tiny_notes', true) === '', 'Bulk write bypassed revoked second-object permission');

// Subscriber role cannot acquire settings authority through dynamic role helpers.
$subscriber = wp_insert_user(['user_login' => 'mcp_subscriber', 'user_pass' => 'synthetic-only', 'role' => 'subscriber']);
wp_set_current_user($subscriber);
check(is_wp_error(callShortlinks('get-plugin-settings')), 'Subscriber read administrative settings');
check(is_wp_error(callShortlinks('save-link', ['settings' => (object) ['target_url' => 'https://example.test/denied']])), 'Subscriber created a link');
wp_set_current_user(1);

$bootstrap = dirname(__DIR__, 2) . '/includes/Mcp/bootstrap.php';
ob_start();
require $bootstrap;
check(ob_get_clean() === '', 'Duplicate bootstrap emitted output');
$command = escapeshellarg(PHP_BINARY) . ' -r ' . escapeshellarg('define("ABSPATH", "/isolated/"); require ' . var_export($bootstrap, true) . '; echo "missing-api-ok";');
check(trim((string) shell_exec($command)) === 'missing-api-ok', 'Missing API bootstrap compatibility failed');
foreach (wp_get_abilities() as $ability) {
    if (strpos($ability->get_name(), 'publishpress-shortlinks/') === 0) {
        $meta = $ability->get_meta();
        check(empty($meta['public']) && empty($meta['show_in_rest']) && empty($meta['mcp']['public']), 'Public tool discovery enabled');
    }
}
echo 'Shortlinks ' . getenv('SHORTLINKS_MCP_MODE') . ': ' . $assertions . " assertions passed.\n";
