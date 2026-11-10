---
name: publishpress-mcp
description: Implement or audit Shortlinks Free and Pro MCP settings, link operations and native workflow integration.
---

# PublishPress Shortlinks MCP integration

Hub owns transport and exposure. Shortlinks registers private abilities in `includes/Mcp/bootstrap.php`, independently of Hub and guarded by API availability. Pro loads shared Free code through its Composer dependency. Do not edit vendor copies or lockfiles.

Follow native Shortlinks PHP conventions and the lowest supported PHP version in the shared code. Existing callbacks use snake_case; do not add strict-types declarations or introduce a new application architecture.

Native sources:

- `TINYPRESS_Settings`, WPDK registered sections and `pb_settings_tinypress_settings_save` define settings/defaults and sanitization. `Fields` derives typed contracts from those controls. Native callback controls need explicit shapes; do not infer writable fields from arbitrary saved options.
- `tinypress_create_shorten_url` owns creation. `tinypress_set_native_post_slug` shares native metabox storage. AutoList metadata hooks synchronize source and mirror records; authorize and hash both sides.
- Native internal/external label choices come from `TINYPRESS_Meta_boxes::get_autolink_alt_text_options`. Discovery uses the object destination; registration accepts the union and execution validates the effective destination.
- Native metabox definitions and Pro save filters define local configuration, dynamic rules and inheritance modes. Reject invalid JSON before running sanitizers; preserve omitted and unrelated fields.
- `TINYPRESS_Duplicate_Link::copy_record`, `TINYPRESS_Favorite_Links::set_favorite`, and native analytics helpers are shared with admin workflows. Reuse those services rather than simulating forms or minting nonces.
- Destination analytics use the native version-3 reports schema and Pro aggregation; preserve existing report rows during migration. Keep request URLs and visitor identities outside MCP analytics results.
- Pro Link Health uses its native checker and saved-result reader. Do not expose raw HTTP responses, request logs, visitor identifiers, passwords or preview nonces.

Check actual capabilities, object/meta permissions, taxonomy rights, editor locks, edition gates and application-password restrictions. Native role helpers are not substitutes for `current_user_can`. Revision shortlinks can contain authenticated preview URLs; keep destinations private and preserve native revision routing.

Test through the released MCP Adapter with actual native WordPress persistence. Exercise malformed types, omitted-field preservation, previews, stale hashes, native source synchronization and partial failures. Distinguish assembled Pro fixtures from release ZIP and external HTTP-client tests. Keep usage docs and coverage current; do not leave audit-report artifacts.
