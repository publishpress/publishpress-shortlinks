<?php

/** Native Shortlinks settings, records and category operations. */

namespace PublishPress\Shortlinks\Mcp;

\defined('ABSPATH') || exit;
// phpcs:disable PSR1.Methods.CamelCapsMethodName.NotCamelCaps -- Match native Shortlinks callback naming.
class Actions
{
    public static function view(): bool
    {
        return current_user_can('tinypress_view_shortlinks');
    }
    public static function settings_access(): bool
    {
        return current_user_can('tinypress_manage_shortlink_settings');
    }
    public static function register(): void
    {
        $output = ['type' => 'object'];
        Registry::add('list-categories', __('Find Shortlink categories', 'tinypress'), __('List native link categories with their current revision.', 'tinypress'), Registry::object(Registry::page()), [self::class, 'categories'], [self::class, 'view'], false, false, $output);
        Registry::add('save-category', __('Create or update a Shortlink category', 'tinypress'), __('Use native taxonomy controls and permissions. Read expected_hash from list-categories before editing; omitted fields are preserved.', 'tinypress'), Registry::object(['id' => Registry::id(), 'expected_hash' => Registry::revision(), 'name' => Registry::text(200), 'slug' => Registry::text(200), 'description' => Registry::text(10000), 'parent' => ['type' => 'integer', 'minimum' => 0], 'dry_run' => ['type' => 'boolean']]), [self::class, 'category_save'], [self::class, 'view'], true, true, $output);
        Registry::add('set-link-categories', __('Set Shortlink categories', 'tinypress'), __('Replace native category assignments with an explicit integer list. [] clears assignments; use the current get-link revision.', 'tinypress'), Registry::object(['id' => Registry::id(), 'expected_hash' => Registry::revision(), 'category_ids' => Registry::listOf(Registry::id()), 'dry_run' => ['type' => 'boolean']], ['id', 'expected_hash', 'category_ids']), [self::class, 'assign_categories'], [self::class, 'view'], true, true, $output);
        Registry::add('set-link-favorite', __('Set my Shortlink favorite', 'tinypress'), __('Set favorite true or false for the connected user only. Read the current get-link revision first.', 'tinypress'), Registry::object(['id' => Registry::id(), 'expected_hash' => Registry::revision(), 'favorite' => ['type' => 'boolean'], 'dry_run' => ['type' => 'boolean']], ['id', 'expected_hash', 'favorite']), [self::class, 'favorite'], [self::class, 'view'], true, false, $output);
        $bulkRow = Registry::object(['id' => Registry::id(), 'expected_hash' => Registry::revision(), 'settings' => Registry::object(Fields::schemas('link'))], ['id', 'expected_hash', 'settings']);
        Registry::add('bulk-update-links', __('Update multiple Shortlinks', 'tinypress'), __('Preflight up to 20 existing links before any writes. Linked source targets must not overlap. Each item uses the original get-link revision. Native writes are not transactional: inspect complete, applied_ids and failed_id. dry_run previews all items.', 'tinypress'), Registry::object(['items' => Registry::listOf($bulkRow, 20) + ['minItems' => 1], 'dry_run' => ['type' => 'boolean']], ['items']), [self::class, 'bulk'], [self::class, 'view'], true, true, $output);
        Registry::add('delete-category', __('Delete a Shortlink category', 'tinypress'), __('Delete a native category without deleting its links. Authorize every affected link and child category; read the current category revision first.', 'tinypress'), Registry::object(['id' => Registry::id(), 'expected_hash' => Registry::revision(), 'dry_run' => ['type' => 'boolean']], ['id', 'expected_hash']), [self::class, 'category_delete'], [self::class, 'view'], true, true, $output);
        Registry::add('get-link-analytics', __('Read Shortlink click counts', 'tinypress'), __('Read native daily chart counts and reset revision for one readable link. Visitor IPs, user identifiers, referrers and raw request logs are excluded.', 'tinypress'), Registry::object(['id' => Registry::id()], ['id']), [self::class, 'analytics'], [self::class, 'view'], false, false, $output);
        Registry::add('reset-link-analytics', __('Reset Shortlink chart counts', 'tinypress'), __('Mark this link analytics cleared through the native reset service. Audit logs remain. Requires current analytics expected_hash, analytics and object edit permissions. dry_run previews.', 'tinypress'), Registry::object(['id' => Registry::id(), 'expected_hash' => Registry::revision(), 'dry_run' => ['type' => 'boolean']], ['id', 'expected_hash']), [self::class, 'reset_analytics'], [self::class, 'view'], true, true, $output);
        if (class_exists('Shortlinks_Pro_Link_Checker')) {
            Registry::add('get-link-health', __('Read saved Shortlink health', 'tinypress'), __('Read the native Pro health status and response code for one readable link. Does not perform HTTP requests.', 'tinypress'), Registry::object(['id' => Registry::id()], ['id']), [self::class, 'health'], [self::class, 'view'], false, false, $output);
            Registry::add('check-link-health', __('Check Shortlink health', 'tinypress'), __('Run the active native Pro link checker for an editable link and store its result. Native URL safety and type settings apply. Requires current get-link expected_hash. dry_run does not request destinations.', 'tinypress'), Registry::object(['id' => Registry::id(), 'expected_hash' => Registry::revision(), 'dry_run' => ['type' => 'boolean']], ['id', 'expected_hash']), [self::class, 'check_health'], [self::class, 'view'], true, false, $output);
        }
        Registry::add('get-post-shortlink', __('Read a post Shortlink', 'tinypress'), __('Read the native editor Short String for an enabled post type without generating a URL or changing metadata.', 'tinypress'), Registry::object(['id' => Registry::id()], ['id']), [self::class, 'post_shortlink'], [self::class, 'view'], false, false, $output);
        Registry::add('set-post-shortlink', __('Set a post Short String', 'tinypress'), __('Update an enabled post native Short String, preserving other metabox values. All auto-listed mirror links need permission and are included in the revision. dry_run previews.', 'tinypress'), Registry::object(['id' => Registry::id(), 'tiny_slug' => Registry::text(200), 'expected_hash' => Registry::revision(), 'dry_run' => ['type' => 'boolean']], ['id', 'tiny_slug', 'expected_hash']), [self::class, 'post_shortlink_save'], [self::class, 'view'], true, true, $output);
        Registry::add('set-link-status', __('Set Shortlink publication status', 'tinypress'), __('Change a native link to publish, draft, pending or private with the current revision and native publication permissions. Source-linked records retain their native source lifecycle.', 'tinypress'), Registry::object(['id' => Registry::id(), 'expected_hash' => Registry::revision(), 'status' => ['type' => 'string', 'enum' => ['publish', 'draft', 'pending', 'private']], 'dry_run' => ['type' => 'boolean']], ['id', 'expected_hash', 'status']), [self::class, 'status'], [self::class, 'view'], true, true, $output);
        Registry::add('copy-link', __('Copy a Shortlink to a draft', 'tinypress'), __('Use the native Copy service with a new Short String and the connected user as author. Requires source edit/read, category assignment and create permissions. No password values are returned. dry_run previews without creating a draft.', 'tinypress'), Registry::object(['id' => Registry::id(), 'expected_hash' => Registry::revision(), 'dry_run' => ['type' => 'boolean']], ['id', 'expected_hash']), [self::class, 'copy'], [self::class, 'view'], true, true, $output);
        if (class_exists('Shortlinks_Pro_Autolink')) {
            Registry::add('clear-autolink-cache', __('Clear the native autolink cache', 'tinypress'), __('Invalidate native Pro autolink rules without changing links or settings. Requires native manage_options and Shortlinks settings permission. dry_run previews.', 'tinypress'), Registry::object(['dry_run' => ['type' => 'boolean']]), [self::class, 'clear_autolink_cache'], static function () {
                return self::settings_access() && current_user_can('manage_options');
            }, true, false, $output);
        }
        if (class_exists('Shortlinks_Pro_Reports')) {
            $reportDate = ['type' => 'string', 'pattern' => '^\\d{4}-\\d{2}-\\d{2}$'];
            Registry::add('get-link-report', __('Read a Shortlinks report summary', 'tinypress'), __('Read native Pro totals, distinct visitor counts and averages for up to 20 explicit readable links between start_date and end_date (YYYY-MM-DD). No IPs, account IDs, referrers or request URLs are returned. Native report totals include retained logs after chart resets.', 'tinypress'), Registry::object(['ids' => Registry::listOf(Registry::id(), 20) + ['minItems' => 1], 'start_date' => $reportDate, 'end_date' => $reportDate], ['ids', 'start_date', 'end_date']), [self::class, 'report'], [self::class, 'view'], false, false, $output);
        }
        $settings = Registry::object(Fields::schemas('settings'));
        $link = Registry::object(Fields::schemas('link'));
        Registry::add('get-plugin-settings', __('Read Shortlinks settings', 'tinypress'), __('Discover exact native settings, choices and defaults. Passwords, license data and unsupported native controls are excluded. Booleans are JSON booleans, IDs integers, selections strings and lists arrays.', 'tinypress'), Registry::object(), [self::class, 'settings_read'], [self::class, 'settings_access'], false, false, $output);
        Registry::add('update-plugin-settings', __('Update Shortlinks settings', 'tinypress'), __('Patch discovered fields using their exact schemas and current expected_hash. Omitted settings and secrets remain unchanged. dry_run previews native sanitized values.', 'tinypress'), Registry::object(['settings' => $settings, 'expected_hash' => Registry::revision(), 'dry_run' => ['type' => 'boolean']], ['settings', 'expected_hash']), [self::class, 'settings_save'], [self::class, 'settings_access'], true, true, $output);
        Registry::add('list-links', __('Find Shortlinks', 'tinypress'), __('List readable Shortlinks by title or status. Returns IDs and labels only; use get-link to inspect configuration.', 'tinypress'), Registry::object(['search' => Registry::text(200), 'status' => ['type' => 'string', 'enum' => ['publish', 'draft', 'pending', 'private', 'trash']]] + Registry::page()), [self::class, 'listing'], [self::class, 'view'], false, false, $output);
        Registry::add('get-link', __('Read a Shortlink', 'tinypress'), __('Read native per-link fields and current expected_hash; omit id to discover defaults. No link passwords, raw visitor IPs or user-agent logs are returned.', 'tinypress'), Registry::object(['id' => Registry::id()]), [self::class, 'read'], [self::class, 'view'], false, false, $output);
        Registry::add('save-link', __('Create or update a Shortlink', 'tinypress'), __('Create a published Shortlink with target_url and label, or patch id with current expected_hash. Native redirect, expiration, autolink and active Pro controls use discovered fields. All related source posts must be editable. dry_run previews. No destination URLs are fetched.', 'tinypress'), Registry::object(['id' => Registry::id(), 'expected_hash' => Registry::revision(), 'settings' => $link, 'dry_run' => ['type' => 'boolean']], ['settings']), [self::class, 'save'], [self::class, 'view'], true, true, $output);
        Registry::add('transition-link', __('Trash, restore or delete a Shortlink', 'tinypress'), __('Native Shortlink lifecycle; permanent deletion requires prior trash. Requires current expected_hash and native deletion permission. dry_run previews.', 'tinypress'), Registry::object(['id' => Registry::id(), 'expected_hash' => Registry::revision(), 'operation' => ['type' => 'string', 'enum' => ['trash', 'restore', 'delete']], 'dry_run' => ['type' => 'boolean']], ['id', 'expected_hash', 'operation']), [self::class, 'transition'], [self::class, 'view'], true, true, $output);
    }
    private static function settings_state(): array
    {
        return [(array) get_option('tinypress_settings', []), get_option('tinypress_link_prefix'), get_option('tinypress_link_prefix_slug')];
    }
    public static function settings_read(array $input = []): array
    {
        return Fields::read('settings') + ['pro' => Registry::pro(), 'expected_hash' => Registry::hash(self::settings_state())];
    }
    public static function settings_save(array $input)
    {
        if (is_wp_error($check = Registry::stale($input, self::settings_state()))) {
            return $check;
        }
        $patch = Registry::validate($input['settings'], Registry::object(Fields::schemas('settings')));
        if (is_wp_error($patch)) {
            return $patch;
        }
        if (!self::settings_patch_allowed($patch)) {
            return Registry::denied();
        }
        $clean = Fields::clean($patch, 'settings');
        if (is_wp_error($clean)) {
            return $clean;
        }
        if (!self::settings_patch_allowed($clean)) {
            return Registry::denied();
        }
        if ($input['dry_run'] ?? false) {
            return ['dry_run' => true, 'changes' => (object) $clean];
        }
        if (is_wp_error($check = Registry::stale($input, self::settings_state()))) {
            return $check;
        }
        if (!self::settings_access()) {
            return Registry::denied();
        }
        $candidate = array_merge(self::settings_state()[0], $clean);
        if ($clean) {
            update_option('tinypress_settings', $candidate);
        }
        return get_option('tinypress_settings', []) === $candidate ? self::settings_read() : Registry::unconfirmed();
    }
    private static function post(int $id)
    {
        $post = get_post($id);
        if (!$post || $post->post_type !== 'tinypress_link' || !current_user_can('read_post', $id)) {
            return null;
        }
        $source = (int) get_post_meta($id, 'source_post_id', true);
        return !$source || (get_post($source) && current_user_can('read_post', $source)) ? $post : null;
    }
    private static function state(int $id): array
    {
        $post = get_post($id);
        $source = (int) get_post_meta($id, 'source_post_id', true);
        return [$post ? (array) $post : null, get_post_meta($id), $source ? (array) get_post($source) : null, $source ? get_post_meta($source) : null, \TINYPRESS_Favorite_Links::get_favorites(), wp_get_object_terms($id, 'tinypress_link_cat', ['fields' => 'ids'])];
    }
    private static function editable(int $id): bool
    {
        if (!self::post($id) || !current_user_can('tinypress_edit_shortlinks') || !Registry::editable($id)) {
            return false;
        }
        $source = (int) get_post_meta($id, 'source_post_id', true);
        return !$source || Registry::editable($source);
    }
    public static function read(array $input)
    {
        $id = $input['id'] ?? 0;
        if ($id && !self::post($id)) {
            return Registry::denied();
        }
        $read = Fields::read('link', $id);
        if ($id && self::is_revision($id)) {
            // Native revision destinations may carry authenticated preview nonces.
            unset($read['settings']->target_url, $read['fields']->target_url);
        }
        return $read + ['short_url' => self::short_url($id), 'favorite' => in_array($id, \TINYPRESS_Favorite_Links::get_favorites(), true), 'category_ids' => $id ? array_map('intval', wp_get_object_terms($id, 'tinypress_link_cat', ['fields' => 'ids'])) : [], 'id' => $id, 'status' => $id ? get_post_status($id) : 'new', 'expected_hash' => Registry::hash(self::state($id))];
    }
    public static function listing(array $input)
    {
        // phpcs:ignore WordPress.WP.PostsPerPage.posts_per_page_numberposts, WordPress.DB.SlowDBQuery.slow_db_query_meta_value -- Bounded sentinel scan before object filtering.
        $ids = get_posts(['post_type' => 'tinypress_link', 'post_status' => $input['status'] ?? ['publish', 'draft', 'pending', 'private'], 's' => $input['search'] ?? '', 'fields' => 'ids', 'numberposts' => 1001, 'orderby' => 'ID', 'order' => 'ASC']);
        if (count($ids) > 1000) {
            return Registry::error(__('Narrow the search or use native administration for more than 1,000 links.', 'tinypress'), 413);
        }
        $items = [];
        foreach ($ids as $id) {
            if (self::post($id)) {
                $items[] = ['id' => $id, 'title' => get_post_field('post_title', $id), 'status' => get_post_status($id)];
            }
        }
        $size = $input['per_page'] ?? 20;
        $offset = (($input['page'] ?? 1) - 1) * $size;
        return ['items' => array_slice($items, $offset, $size), 'has_more' => count($items) > $offset + $size];
    }
    public static function save(array $input)
    {
        $id = $input['id'] ?? 0;
        if ($id ? (!self::editable($id) || get_post_status($id) === 'trash') : !current_user_can('tinypress_create_shortlinks')) {
            return Registry::denied();
        }
        if ($id && is_wp_error($check = Registry::stale($input, self::state($id)))) {
            return $check;
        }
        $patch = Registry::validate($input['settings'], Registry::object(Fields::schemas('link')));
        if (is_wp_error($patch)) {
            return $patch;
        }
        if (!$id && !array_key_exists('link_status', $patch)) {
            $definitions = Fields::definitions('link');
            $patch['link_status'] = (bool) $definitions['link_status']['default'];
        }
        if ($id && !self::metadata_allowed($id, $patch)) {
            return Registry::denied();
        }
        if ($id && self::is_revision($id) && (isset($patch['target_url']) || isset($patch['dynamic_redirect_rules']) || isset($patch['dynamic_redirect_enabled']))) {
            return Registry::error(__('Revision destinations and routing follow the native revision workflow.', 'tinypress'), 403);
        }
        if (!$id && empty($patch['target_url'])) {
            return Registry::error(__('Supply target_url for a new Shortlink.', 'tinypress'));
        }
        if (isset($patch['target_url'])) {
            $url = tinypress_validate_target_url($patch['target_url']);
            if (is_wp_error($url)) {
                return $url;
            }
            $patch['target_url'] = $url;
        }
        if (isset($patch['tiny_slug'])) {
            if ($patch['tiny_slug'] === '' || !preg_match('~^[a-zA-Z0-9_-]+(?:/[a-zA-Z0-9_-]+)*$~D', $patch['tiny_slug'])) {
                return Registry::error(__('Use a nonempty short path with letters, numbers, dashes or underscores.', 'tinypress'));
            }
            global $wpdb;
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.NoCaching -- Fresh uniqueness preflight; prepared query.
            $collision = $wpdb->get_var($wpdb->prepare("SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = 'tiny_slug' AND meta_value = %s AND post_id NOT IN (%d, %d) LIMIT 1", $patch['tiny_slug'], $id, (int) get_post_meta($id, 'source_post_id', true)));
            if ($wpdb->last_error || $collision) {
                return Registry::error(__('The short path is unavailable.', 'tinypress'), 409);
            }
        }
        if (isset($patch['autolink_alt_text'])) {
            $destination = $patch['target_url'] ?? ($id ? (string) get_post_meta($id, 'target_url', true) : '');
            $options = \TINYPRESS_Meta_boxes::get_autolink_alt_text_options($id, $destination);
            if (!array_key_exists($patch['autolink_alt_text'], $options)) {
                return Registry::error(__('This label choice is not available for the destination.', 'tinypress'));
            }
        }
        $clean = Fields::clean($patch, 'link', $id);
        if (is_wp_error($clean)) {
            return $clean;
        }
        if ($input['dry_run'] ?? false) {
            return ['dry_run' => true, 'changes' => (object) $clean];
        }
        if ($id && (is_wp_error($check = Registry::stale($input, self::state($id))) || !self::editable($id) || !self::metadata_allowed($id, $clean))) {
            return is_wp_error($check) ? $check : Registry::denied();
        }
        if (!$id && !current_user_can('tinypress_create_shortlinks')) {
            return Registry::denied();
        }
        $postData = ['post_type' => 'tinypress_link'];
        if (isset($clean['post_title'])) {
            $postData['post_title'] = sanitize_text_field($clean['post_title']);
            unset($clean['post_title']);
        }
        if ($id) {
            $postData['ID'] = $id;
            $result = wp_update_post(wp_slash($postData), true);
        } else {
            $postData['post_status'] = 'publish';
            $postData['post_author'] = get_current_user_id();
            $clean['tiny_slug'] = $clean['tiny_slug'] ?? tinypress_create_url_slug();
            $result = tinypress_create_shorten_url(['target_url' => $clean['target_url'], 'tiny_slug' => $clean['tiny_slug'], 'post_title' => $postData['post_title'] ?? '', 'return_id' => true, 'authorize_metadata' => static function ($newId) use ($clean) {
                foreach (array_unique(array_merge(array_keys($clean), ['target_url', 'tiny_slug', 'redirection', 'notes'])) as $key) {
                    if (!current_user_can('edit_post_meta', $newId, $key)) {
                        return false;
                    }
                }
                return self::view() && current_user_can('tinypress_create_shortlinks');
            }]);
        }
        if (is_wp_error($result)) {
            $data = $result->get_error_data();
            return is_array($data) && isset($data['id']) ? ['complete' => false, 'id' => (int) $data['id']] : $result;
        }
        $id = (int) $result;
        foreach (array_keys($clean) as $key) {
            if (!current_user_can('edit_post_meta', $id, $key)) {
                return ['complete' => false, 'id' => $id, 'message' => __('The post exists but metadata permissions changed. Read before retrying.', 'tinypress')];
            }
        }
        foreach ($clean as $key => $value) {
            update_post_meta($id, $key, wp_slash($value));
        }
        $complete = true;
        foreach ($clean as $key => $value) {
            $complete = get_post_meta($id, $key, true) == $value && $complete;
        }
        if (isset($postData['post_title'])) {
            $complete = get_post_field('post_title', $id) === $postData['post_title'] && $complete;
        }
        return self::read(['id' => $id]) + ['complete' => $complete];
    }
    public static function transition(array $input)
    {
        $id = $input['id'];
        if (!self::editable($id) || !current_user_can('tinypress_delete_shortlinks') || !current_user_can('delete_post', $id)) {
            return Registry::denied();
        }
        if (is_wp_error($check = Registry::stale($input, self::state($id)))) {
            return $check;
        }
        // Native auto-listed/revision link removal has source synchronization side effects.
        if (get_post_meta($id, 'source_post_id', true)) {
            return Registry::error(__('Manage linked source records in native administration.', 'tinypress'), 409);
        }
        $operation = $input['operation'];
        $trashed = get_post_status($id) === 'trash';
        if (($operation === 'trash') === $trashed) {
            return Registry::error(__('Read the current lifecycle state first.', 'tinypress'), 409);
        }
        if ($input['dry_run'] ?? false) {
            return ['dry_run' => true, 'id' => $id, 'operation' => $operation];
        }
        $result = $operation === 'delete' ? wp_delete_post($id, true) : ($operation === 'trash' ? wp_trash_post($id) : wp_untrash_post($id));
        $complete = $operation === 'delete' ? !get_post($id) : ($operation === 'trash' ? get_post_status($id) === 'trash' : (get_post($id) && get_post_status($id) !== 'trash'));
        return $result && $complete ? ['complete' => true, 'id' => $id] : Registry::unconfirmed();
    }

    public static function categories(array $input)
    {
        $taxonomy = get_taxonomy('tinypress_link_cat');
        if (!$taxonomy || !current_user_can($taxonomy->cap->manage_terms)) {
            return Registry::denied();
        }
        $size = $input['per_page'] ?? 20;
        $terms = get_terms(['taxonomy' => $taxonomy->name, 'hide_empty' => false, 'number' => $size + 1, 'offset' => (($input['page'] ?? 1) - 1) * $size, 'orderby' => 'term_id']);
        if (is_wp_error($terms)) {
            return $terms;
        }
        $items = [];
        foreach (array_slice($terms, 0, $size) as $term) {
            $items[] = ['id' => $term->term_id, 'name' => $term->name, 'slug' => $term->slug, 'description' => $term->description, 'parent' => $term->parent, 'expected_hash' => Registry::hash(self::category_state($term))];
        }
        return ['items' => $items, 'has_more' => count($terms) > $size];
    }
    public static function category_save(array $input)
    {
        $taxonomy = get_taxonomy('tinypress_link_cat');
        $id = $input['id'] ?? 0;
        if (!$taxonomy || !current_user_can($taxonomy->cap->manage_terms) || !current_user_can($taxonomy->cap->edit_terms) || ($id && !current_user_can('edit_term', $id))) {
            return Registry::denied();
        }
        $term = $id ? get_term($id, $taxonomy->name) : null;
        if ($id && (!$term || is_wp_error($term))) {
            return Registry::error(__('Unknown Shortlink category.', 'tinypress'), 404);
        }
        if ($id && is_wp_error($check = Registry::stale($input, self::category_state($term)))) {
            return $check;
        }
        if ((!$id && !isset($input['name'])) || (isset($input['name']) && trim(wp_strip_all_tags($input['name'])) === '')) {
            return Registry::error(__('Supply a category name.', 'tinypress'));
        }
        if (!empty($input['parent']) && (!term_exists($input['parent'], $taxonomy->name) || $input['parent'] === $id || ($id && term_is_ancestor_of($id, $input['parent'], $taxonomy->name)))) {
            return Registry::error(__('Use an existing parent without a cycle.', 'tinypress'));
        }
        $args = array_intersect_key($input, array_flip(['name', 'slug', 'description', 'parent']));
        if (isset($args['description'])) {
            $args['description'] = wp_kses_post($args['description']);
        }
        if ($input['dry_run'] ?? false) {
            return ['dry_run' => true, 'fields' => (object) $args];
        }
        $result = $id ? wp_update_term($id, $taxonomy->name, wp_slash($args)) : wp_insert_term(wp_slash($args['name']), $taxonomy->name, wp_slash($args));
        if (is_wp_error($result)) {
            return $result;
        }
        $term = get_term($result['term_id'], $taxonomy->name);
        return $term && !is_wp_error($term) ? ['id' => $term->term_id, 'expected_hash' => Registry::hash(self::category_state($term)), 'complete' => true] : Registry::unconfirmed();
    }
    public static function assign_categories(array $input)
    {
        $id = $input['id'];
        $taxonomy = get_taxonomy('tinypress_link_cat');
        if (!self::editable($id) || !$taxonomy || !current_user_can($taxonomy->cap->assign_terms)) {
            return Registry::denied();
        }
        if (is_wp_error($check = Registry::stale($input, self::state($id)))) {
            return $check;
        }
        foreach ($input['category_ids'] as $termId) {
            if (!term_exists($termId, $taxonomy->name) || !current_user_can('assign_term', $termId)) {
                return Registry::denied();
            }
        }
        if ($input['dry_run'] ?? false) {
            return ['dry_run' => true, 'category_ids' => $input['category_ids']];
        }
        $result = wp_set_object_terms($id, $input['category_ids'], $taxonomy->name);
        if (is_wp_error($result)) {
            return $result;
        }
        $actual = wp_get_object_terms($id, $taxonomy->name, ['fields' => 'ids']);
        $complete = !is_wp_error($actual) && !array_diff($input['category_ids'], $actual) && !array_diff($actual, $input['category_ids']);
        return self::read(['id' => $id]) + ['complete' => $complete];
    }

    public static function favorite(array $input)
    {
        if (!self::post($input['id'])) {
            return Registry::denied();
        }
        if (is_wp_error($check = Registry::stale($input, self::state($input['id'])))) {
            return $check;
        }
        if ($input['dry_run'] ?? false) {
            return ['dry_run' => true, 'favorite' => $input['favorite']];
        }
        return \TINYPRESS_Favorite_Links::set_favorite($input['id'], $input['favorite']) ? self::read(['id' => $input['id']]) : Registry::unconfirmed();
    }
    public static function bulk(array $input)
    {
        $seen = [];
        $previews = [];
        $slugs = [];
        foreach ($input['items'] as $row) {
            if (isset($row['settings']['tiny_slug'])) {
                $slug = $row['settings']['tiny_slug'];
                if (in_array($slug, $slugs, true)) {
                    return Registry::error(__('Bulk short paths must be unique.', 'tinypress'));
                }
                $slugs[] = $slug;
            }
            $related = [$row['id'], (int) get_post_meta($row['id'], 'source_post_id', true)];
            foreach (array_filter($related) as $id) {
                if (isset($seen[$id])) {
                    return Registry::error(__('Bulk targets and linked source records must not overlap.', 'tinypress'));
                }
                $seen[$id] = true;
            }
            $preview = self::save($row + ['dry_run' => true]);
            if (is_wp_error($preview)) {
                return $preview;
            }
            $previews[] = $preview;
        }
        if ($input['dry_run'] ?? false) {
            return ['dry_run' => true, 'items' => $previews];
        }
        $applied = [];
        foreach ($input['items'] as $row) {
            $result = self::save($row);
            if (is_wp_error($result) || empty($result['complete'])) {
                return ['complete' => false, 'applied_ids' => $applied, 'failed_id' => $row['id']];
            }
            $applied[] = $row['id'];
        }
        return ['complete' => true, 'applied_ids' => $applied];
    }

    private static function category_state(\WP_Term $term): array
    {
        return [(array) $term, get_objects_in_term($term->term_id, $term->taxonomy), get_terms(['taxonomy' => $term->taxonomy, 'parent' => $term->term_id, 'hide_empty' => false, 'number' => 1001])];
    }
    public static function category_delete(array $input)
    {
        $taxonomy = get_taxonomy('tinypress_link_cat');
        $id = $input['id'];
        $term = get_term($id, 'tinypress_link_cat');
        if (!$taxonomy || !$term || is_wp_error($term) || !current_user_can($taxonomy->cap->delete_terms) || !current_user_can('delete_term', $id)) {
            return Registry::denied();
        }
        $state = self::category_state($term);
        global $wpdb;
        if ($wpdb->last_error || is_wp_error($state[1]) || is_wp_error($state[2]) || count($state[1]) > 1000 || count($state[2]) > 1000) {
            return Registry::error(__('Use native administration for this category scope.', 'tinypress'), 413);
        }
        if (is_wp_error($check = Registry::stale($input, $state))) {
            return $check;
        }
        foreach ($state[1] as $postId) {
            if (!self::editable((int) $postId)) {
                return Registry::denied();
            }
        }
        foreach ($state[2] as $child) {
            if (!current_user_can('edit_term', $child->term_id)) {
                return Registry::denied();
            }
        }
        if ($input['dry_run'] ?? false) {
            return ['dry_run' => true, 'affected_links' => array_map('intval', $state[1])];
        }
        $result = wp_delete_term($id, $taxonomy->name);
        return !is_wp_error($result) && $result && !term_exists($id, $taxonomy->name) ? ['complete' => true, 'id' => $id] : Registry::unconfirmed();
    }

    public static function analytics(array $input)
    {
        if (!self::post($input['id']) || !current_user_can('tinypress_view_shortlink_analytics')) {
            return Registry::denied();
        }
        $rows = tinypress()->get_daily_analytics($input['id']);
        if (is_wp_error($rows)) {
            return $rows;
        }
        if (count($rows) > 1000) {
            return Registry::error(__('Use native analytics for more than 1,000 daily records.', 'tinypress'), 413);
        }
        $items = array_map(static function ($row) {
            return ['date' => $row['DateOnly'], 'clicks' => (int) $row['ClickCount']];
        }, $rows);
        $destinations = [];
        if (Registry::pro() && class_exists('Shortlinks_Pro_Dynamic_Redirects') && !self::is_revision($input['id'])) {
            $native = \Shortlinks_Pro_Dynamic_Redirects::get_instance()->add_analytics_data([], $input['id']);
            foreach ($native['destinationDataByDate'] ?? [] as $date => $routes) {
                foreach ($routes as $route) {
                    $destinations[] = ['date' => (string) $date, 'rule_id' => (string) $route['id'], 'label' => (string) $route['label'], 'clicks' => (int) $route['clicks']];
                }
            }
            if (count($destinations) > 1000) {
                return Registry::error(__('Use native analytics for this destination scope.', 'tinypress'), 413);
            }
        }
        return ['id' => $input['id'], 'items' => $items, 'destination_items' => $destinations, 'expected_hash' => Registry::hash([self::state($input['id']), $rows])];
    }
    public static function reset_analytics(array $input)
    {
        $current = self::analytics($input);
        if (is_wp_error($current)) {
            return $current;
        }
        if (!self::editable($input['id'])) {
            return Registry::denied();
        }
        if (!hash_equals($current['expected_hash'], $input['expected_hash'])) {
            return Registry::error(__('Analytics changed; read it again before resetting.', 'tinypress'), 409);
        }
        if ($input['dry_run'] ?? false) {
            return ['dry_run' => true, 'id' => $input['id']];
        }
        $result = tinypress()->reset_analytics($input['id']);
        $read = self::analytics($input);
        return $result !== false && !is_wp_error($read) && !$read['items'] ? $read + ['complete' => true] : Registry::unconfirmed();
    }
    public static function health(array $input)
    {
        if (!Registry::pro() || !class_exists('Shortlinks_Pro_Link_Checker') || !self::post($input['id']) || !current_user_can('tinypress_view_shortlink_analytics')) {
            return Registry::denied();
        }
        $result = \Shortlinks_Pro_Link_Checker::get_instance()->get_saved_result($input['id']);
        return ['id' => $input['id'], 'result' => (object) array_intersect_key($result, array_flip(['status', 'http_code', 'redirect_count', 'checked_at']))];
    }
    public static function check_health(array $input)
    {
        $read = self::health($input);
        if (is_wp_error($read)) {
            return $read;
        }
        if (!self::editable($input['id']) || !tinypress_is_shortlink_available_for_post_types($input['id'])) {
            return Registry::denied();
        }
        if (is_wp_error($check = Registry::stale($input, self::state($input['id'])))) {
            return $check;
        }
        $checker = \Shortlinks_Pro_Link_Checker::get_instance();
        if (!$checker->should_check_link_type($checker->get_link_type($input['id']))) {
            return Registry::error(__('This link type is disabled in native Link Health settings.', 'tinypress'), 409);
        }
        if ($input['dry_run'] ?? false) {
            return ['dry_run' => true, 'id' => $input['id']];
        }
        $checker->check_link($input['id']);
        return self::health($input);
    }

    public static function status(array $input)
    {
        $id = $input['id'];
        if (!self::editable($id) || get_post_status($id) === 'trash' || get_post_meta($id, 'source_post_id', true)) {
            return Registry::denied();
        }
        $type = get_post_type_object('tinypress_link');
        if (in_array($input['status'], ['publish', 'private'], true) && !current_user_can($type->cap->publish_posts)) {
            return Registry::denied();
        }
        if (is_wp_error($check = Registry::stale($input, self::state($id)))) {
            return $check;
        }
        if ($input['dry_run'] ?? false) {
            return ['dry_run' => true, 'status' => $input['status']];
        }
        $result = wp_update_post(['ID' => $id, 'post_status' => $input['status']], true);
        return !is_wp_error($result) && get_post_status($id) === $input['status'] ? self::read(['id' => $id]) : Registry::unconfirmed();
    }
    private static function post_mirrors(int $id)
    {
        global $wpdb;
        // phpcs:ignore WordPress.WP.PostsPerPage.posts_per_page_numberposts, WordPress.DB.SlowDBQuery.slow_db_query_meta_value -- Bounded sentinel scan before object filtering.
        $ids = get_posts(['post_type' => 'tinypress_link', 'post_status' => ['publish', 'draft', 'pending', 'private', 'future', 'trash'], 'fields' => 'ids', 'numberposts' => 1001, 'meta_key' => 'source_post_id', 'meta_value' => $id, 'orderby' => 'ID', 'order' => 'ASC']);
        return $wpdb->last_error || count($ids) > 1000 ? Registry::error(__('Native source mirrors could not be safely read.', 'tinypress'), 413) : array_map('intval', $ids);
    }
    public static function post_shortlink(array $input)
    {
        $id = $input['id'];
        $post = get_post($id);
        if (!$post || $post->post_type === 'tinypress_link' || !tinypress_is_post_type_enabled($post->post_type) || !current_user_can('read_post', $id) || !current_user_can('edit_post', $id)) {
            return Registry::denied();
        }
        $mirrors = self::post_mirrors($id);
        if (is_wp_error($mirrors)) {
            return $mirrors;
        }
        $state = [(array) $post, get_post_meta($id)];
        foreach ($mirrors as $mirror) {
            $state[] = self::state($mirror);
        }
        return ['id' => $id, 'tiny_slug' => (string) get_post_meta($id, 'tiny_slug', true), 'short_url' => self::short_url($id), 'expected_hash' => Registry::hash($state)];
    }
    public static function post_shortlink_save(array $input)
    {
        $id = $input['id'];
        $read = self::post_shortlink($input);
        if (is_wp_error($read)) {
            return $read;
        }
        if (!Registry::editable($id) || !current_user_can('tinypress_edit_shortlinks') || !current_user_can('edit_post_meta', $id, 'tiny_slug') || !current_user_can('edit_post_meta', $id, 'tinypress_meta_side_' . get_post_type($id))) {
            return Registry::denied();
        }
        if (!hash_equals($read['expected_hash'], $input['expected_hash'])) {
            return Registry::error(__('The source or a mirror changed; read it again.', 'tinypress'), 409);
        }
        $mirrors = self::post_mirrors($id);
        if (is_wp_error($mirrors)) {
            return $mirrors;
        }
        foreach ($mirrors as $mirror) {
            if (!self::editable($mirror) || !current_user_can('edit_post_meta', $mirror, 'tiny_slug')) {
                return Registry::denied();
            }
        }
        if (!preg_match('~^[a-zA-Z0-9_-]+(?:/[a-zA-Z0-9_-]+)*$~D', $input['tiny_slug'])) {
            return Registry::error(__('Use a nonempty short path.', 'tinypress'));
        }
        global $wpdb;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.NoCaching -- Fresh native alias collision check.
        $existing = $wpdb->get_col($wpdb->prepare("SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = 'tiny_slug' AND meta_value = %s LIMIT 1001", $input['tiny_slug']));
        if ($wpdb->last_error || array_diff(array_map('intval', $existing), array_merge([$id], $mirrors))) {
            return Registry::error(__('This short path is unavailable.', 'tinypress'), 409);
        }
        if ($input['dry_run'] ?? false) {
            return ['dry_run' => true, 'tiny_slug' => $input['tiny_slug']];
        }
        $complete = tinypress_set_native_post_slug($id, $input['tiny_slug']);
        foreach ($mirrors as $mirror) {
            $complete = get_post_meta($mirror, 'tiny_slug', true) === $input['tiny_slug'] && $complete;
        }
        return self::post_shortlink($input) + ['complete' => $complete];
    }

    public static function copy(array $input)
    {
        if (!self::editable($input['id']) || !current_user_can('tinypress_create_shortlinks')) {
            return Registry::denied();
        }
        if (is_wp_error($check = Registry::stale($input, self::state($input['id'])))) {
            return $check;
        }
        if (self::is_revision($input['id'])) {
            return Registry::error(__('Copy revision-linked records through the native revision workflow.', 'tinypress'), 403);
        }
        foreach (get_object_taxonomies('tinypress_link') as $name) {
            $taxonomy = get_taxonomy($name);
            $terms = wp_get_object_terms($input['id'], $name, ['fields' => 'ids']);
            if (!$taxonomy || is_wp_error($terms) || !current_user_can($taxonomy->cap->assign_terms)) {
                return Registry::denied();
            }
            foreach ($terms as $term) {
                if (!current_user_can('assign_term', $term)) {
                    return Registry::denied();
                }
            }
        }
        if ($input['dry_run'] ?? false) {
            return ['dry_run' => true, 'source_id' => $input['id'], 'status' => 'draft'];
        }
        $result = \TINYPRESS_Duplicate_Link::copy_record($input['id'], static function ($newId, $keys) use ($input) {
            if (!self::editable($input['id']) || is_wp_error(Registry::stale($input, self::state($input['id'])))) {
                return false;
            }
            foreach ($keys as $key) {
                if (!current_user_can('edit_post_meta', $newId, $key)) {
                    return false;
                }
            }
            return true;
        });
        if (is_wp_error($result)) {
            $data = $result->get_error_data();
            return is_array($data) && isset($data['id']) ? ['complete' => false, 'id' => $data['id']] : $result;
        }
        $read = self::read(['id' => (int) $result]);
        return is_wp_error($read) ? ['complete' => false, 'id' => (int) $result] : $read + ['complete' => get_post_status($result) === 'draft' && get_post_meta($result, 'tiny_slug', true) !== get_post_meta($input['id'], 'tiny_slug', true)];
    }

    public static function clear_autolink_cache(array $input)
    {
        if (!Registry::pro() || !class_exists('Shortlinks_Pro_Autolink') || !self::settings_access() || !current_user_can('manage_options')) {
            return Registry::denied();
        }
        if ($input['dry_run'] ?? false) {
            return ['dry_run' => true];
        }
        \Shortlinks_Pro_Autolink::get_instance()->invalidate_autolink_cache();
        return get_transient('tinypress_autolink_rules') === false && wp_cache_get('tinypress_autolink_rules', 'tinypress_autolink') === false ? ['complete' => true] : Registry::unconfirmed();
    }

    private static function is_revision(int $id): bool
    {
        if (get_post_meta($id, 'is_revision_link', true)) {
            return true;
        }
        $source = (int) get_post_meta($id, 'source_post_id', true);
        return $source && (wp_is_post_revision($source) || (function_exists('tinypress_is_pp_revision') && tinypress_is_pp_revision($source)));
    }
    private static function metadata_allowed(int $id, array $patch): bool
    {
        if (!self::view()) {
            return false;
        }
        foreach (array_keys($patch) as $key) {
            if ($key !== 'post_title' && !current_user_can('edit_post_meta', $id, $key)) {
                return false;
            }
        }
        $source = (int) get_post_meta($id, 'source_post_id', true);
        foreach (['tiny_slug', 'target_url'] as $key) {
            $stored = get_post_meta($id, $key, true);
            $sourceValue = $source ? get_post_meta($source, $key, true) : '';
            $willSync = array_key_exists($key, $patch) || (!empty($stored) && $stored !== $sourceValue);
            if ($source && $willSync && !current_user_can('edit_post_meta', $source, $key)) {
                return false;
            }
        }
        return true;
    }

    public static function report(array $input)
    {
        if (!Registry::pro() || !class_exists('Shortlinks_Pro_Reports') || !current_user_can('tinypress_view_shortlink_analytics')) {
            return Registry::denied();
        }
        foreach (['start_date', 'end_date'] as $key) {
            $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $input[$key], wp_timezone());
            if (!$date || $date->format('Y-m-d') !== $input[$key]) {
                return Registry::error(__('Use valid native report dates in YYYY-MM-DD format.', 'tinypress'));
            }
        }
        if ($input['start_date'] > $input['end_date']) {
            return Registry::error(__('The report end date must not precede its start.', 'tinypress'));
        }
        foreach ($input['ids'] as $id) {
            if (!self::post($id)) {
                return Registry::denied();
            }
        }
        $stats = \Shortlinks_Pro_Reports::get_instance()->get_stats($input['start_date'], $input['end_date'], $input['ids']);
        global $wpdb;
        return $wpdb->last_error ? Registry::error(__('Native report data could not be read.', 'tinypress'), 500) : ['ids' => $input['ids'], 'start_date' => $input['start_date'], 'end_date' => $input['end_date'], 'stats' => (object) $stats];
    }

    private static function settings_patch_allowed(array $patch): bool
    {
        if (!self::settings_access()) {
            return false;
        }
        foreach (array_keys($patch) as $key) {
            if (strpos($key, 'tinypress_role_') === 0 && !current_user_can('manage_options')) {
                return false;
            }
        }
        return true;
    }

    private static function short_url(int $id): string
    {
        $url = $id ? (string) tinypress_get_tinyurl($id, false) : '';
        return Registry::credentialUrl($url) ? '' : $url;
    }
}
