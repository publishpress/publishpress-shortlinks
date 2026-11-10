# Shortlinks MCP integration

Shortlinks registers private `publishpress-shortlinks/` abilities through the WordPress Abilities API. Select actions in PublishPress Hub and connect with the WordPress MCP Adapter. The integration is optional and does not change native redirect rendering or require an AI provider.

## Discovery and writes

Start with `get-plugin-settings`, `list-links`, `get-link` and `list-categories`. Omit `id` from `get-link` to discover native per-link controls. Discovery returns native labels, schemas, defaults, date/time formats and native-only field identifiers. Active Pro modules contribute their native fields; Free does not expose disabled Pro controls.

Use JSON booleans for toggles, integers for IDs and numeric controls, declared strings for select choices, arrays for lists, and objects for keyed messages. Native string toggles and empty list storage are normalized to those API types. Auto-list post types and named exclusion lists accept each identifier once. Numeric-string IDs, string booleans, nulls, unknown keys and invalid choices are rejected. Dates and times use the returned `native_format`; an empty string clears a date or time. Repeater lists replace their rows. Keyed status-message patches preserve omitted entries.

Credential-bearing URLs and WordPress preview/nonces in URL controls are omitted from values and defaults. `redacted_fields` identifies omitted paths, and field discovery marks affected controls as `redacted`. Read the native settings before replacing a rule list that contains redacted fields. Labels, notes and templates are ordinary configured text and may contain information supplied by administrators.

Read `expected_hash` immediately before a write. A conflict requires a fresh read; do not reuse old revisions. `dry_run: true` returns sanitized changes without saving. Omitted settings, unrelated metadata and native credential fields remain unchanged.

## Link operations

`save-link` creates a published Shortlink or patches the supplied existing ID. New links require `target_url`; use `post_title` for the label and optionally `tiny_slug` for an explicit unused path. The native URL validator accepts its supported schemes. Creation reuses the native creation service. Redirect methods, link status, notes, autolink keywords, inheritance modes, expiration and available Pro routing controls use the discovered settings contract. Internal destinations offer the native Post Title label choice; external destinations use Shortlink Label or Custom Text. Discovery marks a native fallback when an older stored Post Title mode applies to an external destination.

`bulk-update-links` validates up to 20 explicit existing links before writes. Duplicate IDs, overlapping source records and repeated requested paths are rejected. Each row supplies its original revision. Native persistence is not transactional: inspect `complete`, `applied_ids` and `failed_id`, and read affected objects after an incomplete result.

`set-link-status` changes native publication status. `transition-link` supports trash, restore and permanent deletion; permanent deletion requires prior trash. Source-linked records retain their native source lifecycle. `copy-link` reuses native Copy to create an independent draft with a new path. Metadata and taxonomy permissions apply; an incomplete copy identifies the created draft for inspection.

`set-link-favorite` sets the connected user's own favorite state. `save-category`, `delete-category` and `set-link-categories` use native taxonomy controls. Category deletion preflights affected links and children without deleting link posts.

`get-post-shortlink` reads the enabled native post editor's Short String without generating a missing slug. `set-post-shortlink` updates that control and its native metabox representation. Auto-listed source and mirror records must be authorized; unrelated post fields remain unchanged.

## Analytics and Pro

`get-link-analytics` returns native daily chart counts for an authorized link. With Pro dynamic routing active, `destination_items` adds native per-rule/date counts and labels without exposing destination request logs. `reset-link-analytics` uses the native clearing flag and retains audit logs. Raw visitor IPs, account identifiers, user agents and referrer logs are not exported.

`get-link-report` returns native Pro totals, distinct visitor counts, active-link counts and averages for explicit readable link IDs and an inclusive `YYYY-MM-DD` date range. These totals include retained native logs after chart resets; visitor identifiers and individual requests are not returned.

With Pro active, `get-link-health` reads the saved health status and response code. `check-link-health` runs the native checker and stores its result, subject to native link-type settings and object permissions. Its preview makes no HTTP requests. Link Health can contact the configured shortlink and native destinations; normal native HTTP URL-safety rules apply.

`clear-autolink-cache` invalidates the native Pro rule cache. Its preview preserves both cache layers; execution requires native `manage_options` and Shortlinks settings authority.

Pro autolink limits/exclusions, activation, click expiration, expired-link notices, dynamic country/device/referrer rules and Link Health configuration use the shared native field definitions. Third-party dependencies and enabled features determine available controls. Revision destinations can contain authenticated preview nonces, so those destinations are omitted from reads and retain native revision routing.

## Access and boundaries

Native Shortlinks viewing, creation, editing, deletion, analytics and settings capabilities are checked independently. Post/source permissions, registered metadata authorization, taxonomy assignments and editor locks also apply. Role-access configuration additionally requires `manage_options`. Administrator role membership does not override denied capabilities or application-password restrictions.

Connected AI clients receive authorized labels, destinations, notes and configuration. Do not place secrets in fields you choose to expose. Link passwords, credentials, license data, digest recipient addresses and raw visitor logs remain in native administration. Native CSV transfers/migrations, bulk log deletion, active scan-run controls and licensing remain native admin operations. QR codes and editor hyperlink insertion use native rendering over the Shortlink URL; tools do not duplicate those rendering engines.

Lists and cascading operations are bounded to 1,000 records. Large operations and unsupported third-party controls remain in native administration. This integration does not implement arbitrary option/meta access, generic method dispatch, SQL, PHP execution or an unrestricted HTTP client.
