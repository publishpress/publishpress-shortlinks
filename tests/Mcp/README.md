# Shortlinks MCP tests

Run `smoke.php` only in a disposable `shortlinks_mcp_*` WordPress database with the current Free source or Pro distribution activated. Pass the WordPress loader and released MCP Adapter directory:

```sh
SHORTLINKS_MCP_MODE=free php tests/Mcp/smoke.php /isolated/wordpress/wp-load.php /isolated/mcp-adapter
```

Use `SHORTLINKS_MCP_MODE=pro` for Pro. Fixtures use the released Adapter's `McpTool`, native settings/metabox definitions, WordPress persistence and synthetic records. Intercept outbound HTTP and mail. These tests do not establish an external AI-client HTTP session.

Optional `SHORTLINKS_MCP_CAPABILITIES_FILE` points to Capabilities' native application-password restriction component. Multisite must use network activation and a non-Super-Admin account for application-password restriction tests. Assembled Pro source fixtures do not substitute for release ZIP packaging tests.
