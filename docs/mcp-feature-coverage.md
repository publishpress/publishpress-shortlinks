# Shortlinks MCP feature coverage

| Native area | MCP operations | Checks and boundaries |
| --- | --- | --- |
| Global settings | Get/update plugin settings | Native definitions and sanitizers; strict types, revision, settings authority; secrets excluded. |
| Links and local configuration | List/get/save links; bulk updates | Native creation and field sanitizers, object/meta permissions, stale tokens, source permissions and locks. |
| Native post editor | Get/set post Short String | Enabled post types, native metabox storage, source/mirror authorization and revisions. |
| Copy and publication | Copy link; set status; transition link | Native Copy and WordPress lifecycle; create/edit/delete/publish, metadata and category checks. |
| Categories | List/save/delete; assign to links | Native term APIs; assignment and cascading child/member checks. |
| Favorites | Read on get-link; set favorite | Connected user's native favorite storage only. |
| Per-link analytics | Read daily/per-rule counts; reset chart data | Native chart/reset service, readable link and analytics authority; no raw visitor records. |
| Pro reports | Summary for explicit link IDs and dates | Native aggregate counts, per-link read and analytics authority; retained logs, no visitor identifiers. |
| Pro link health | Saved result; explicit check; settings | Active Pro checker, native HTTP safety, link-type settings and edit authority. |
| Pro autolinking | Settings, per-link controls and native cache invalidation | Native limits, block/element/shortcode exclusions and inheritance modes. |
| Pro scheduling/routing | Discovered per-link and global controls | Native activation/expiration, click limit, notices and country/device/referrer rule sanitizer. |
| Roles and dependencies | Native settings discovery and updates | Active edition locks, registered role choices; role configuration requires manage_options. |
| Revisions and custom statuses | Native status discovery and source checks | Dependency behavior preserved; authenticated revision destinations are not exported. |
| QR codes and editor insertion | Shortlink URL and native frontend/editor rendering | No duplicated QR renderer or general post-content editor. |

Credentials, recipient addresses, raw visitor logs, license management, CSV transfers, third-party migration and active scan-run administration are native-only boundaries. MCP does not replace third-party plugin workflows or general WordPress tools.
