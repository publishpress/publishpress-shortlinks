---
name: wp-security-scanner
description: Security vulnerability scanner for the PublishPress Shortlinks WordPress plugin. Use when the user asks for a security scan, security audit, vulnerability check, or to find/report security issues. Designed for frequent incremental runs that only surface new findings.
---

# PublishPress Shortlinks — Security Scanner

Scans for exploitable security vulnerabilities and outputs:
1. Local advisory files in `/security-audit/`
2. GitHub Security Advisory draft payloads ready to submit via `gh` CLI

**Designed for frequent runs.** Before reporting a finding, check `/security-audit/` for an existing file matching the same vulnerability to avoid duplicates.

---

## Directories to Exclude

Never analyze: `/vendor/`, `/lib/vendor/`, `/dist/`, `/.git/`, `/dev-workspace/`, `/node_modules/`, `/tests/`

---

## Scan Checklist

Run Grep searches in parallel for each category. Only report findings that are **reachable by users** and **not already mitigated** upstream in the call stack.

### SQL Injection
- `$wpdb->get_results\(.*\$` without `prepare()`
- `$wpdb->query\(.*\$` without `prepare()`
- String interpolation directly inside SQL: `"SELECT.*\$`

### XSS
- `echo \$_(POST|GET|REQUEST|COOKIE)` without escaping
- `echo.*\$_(POST|GET|REQUEST)` in templates
- Verify: is the variable escaped with `esc_html/esc_attr/esc_url/wp_kses` before echo?

### CSRF
- `wp_ajax_` and `wp_ajax_nopriv_` handlers without `wp_verify_nonce` or `check_ajax_referer`
- POST handlers in admin pages without `check_admin_referer` or `wp_verify_nonce`

### Authentication & Authorization
- `wp_ajax_nopriv_` handlers that perform writes without a nonce
- REST API `register_rest_route` without `permission_callback` or with `__return_true`
- Missing `current_user_can()` before `wp_insert_post`, `update_post_meta`, `$wpdb->insert`

### Open Redirect (Shortlinks-Specific — HIGH PRIORITY)
- `wp_redirect\(` or `header\('Location` with a variable
- Confirm: is `$url` from DB/meta (safe) or from `$_GET`/`$_POST`/`$_REQUEST` (dangerous)?
- Check `class-redirection.php`: parameter forwarding appending `$_GET` to target URL unsanitized

### URL Validation (Shortlinks-Specific)
- `update_post_meta.*target_url` without `esc_url_raw()` or `filter_var(FILTER_VALIDATE_URL)`
- Stored URLs output without `esc_url()` in links or redirects

### Click Tracking (Shortlinks-Specific)
- User-controlled headers (`HTTP_CLIENT_IP`, `HTTP_X_FORWARDED_FOR`) stored without `filter_var(FILTER_VALIDATE_IP)`
- `$wpdb->insert` with `user_ip` using unsanitized value

### Password Protection (Shortlinks-Specific)
- Link passwords embedded in HTML/JS output (`$link_password` in `echo` or `?>`)

### Dangerous Functions (plugin code only)
- `eval(`, `unserialize(` with user input, `base64_decode(` in dynamic context

---

## Incremental Run Strategy

Before creating any output file:

1. List files in `/security-audit/`
2. For each vulnerability found, check if a file already exists that covers the same location and vulnerability type
3. Only create new files for findings not yet documented
4. If an existing advisory's status is now resolved (the vulnerable code was fixed), note it in the report but do not delete the file

---

## Output 1 — Local Advisory File

**Naming:** `tinypress-[NNN]-[SEVERITY]-[short-kebab-description].md`  
Sequential numbering from the highest existing number + 1.

```markdown
## Security Advisory

### Summary
[One-line description]

### Severity
[Critical / High / Medium / Low]

### CVSS Score
[X.X (Label)] — CVSS:3.1/[vector string]

### CWE
[CWE-NNN: Name]

### Affected Versions
[e.g., All versions ≤ 1.4.0]

### Vulnerability Details

**Type:** [type]  
**Location:** `path/to/file.php:line`  
**Attack Vector:** [Network/Local]  
**Privileges Required:** [None/Low/High]  
**User Interaction:** [None/Required]

### Description
[2–4 sentences: what the code does, why it's vulnerable, what an attacker gains]

### Proof of Concept
[Minimal reproduction steps or curl/HTTP payload]

### Vulnerable Code
\```php
// path/to/file.php:line
[exact code snippet]
\```

### Remediation
[Specific fix with corrected code snippet]

\```php
[fixed code]
\```

### References
- [OWASP / CWE / WP docs links]
```

---

## Output 2 — GitHub Advisory Draft

After the local file, generate a `gh` CLI command to create a draft advisory in the repository. Use `gh api` with the GitHub Security Advisories REST endpoint.

**Template:**

```bash
gh api \
  --method POST \
  -H "Accept: application/vnd.github+json" \
  /repos/publishpress/publishpress-shortlinks/security-advisories \
  --field summary="[One-line summary]" \
  --field description="[Full markdown description — same as Description + PoC + Remediation from the local file]" \
  --field severity="[critical|high|medium|low]" \
  --field "cwes[][cwe_id]=[CWE-NNN]" \
  --field "cwes[][name]=[CWE name]" \
  --field "vulnerabilities[][package][ecosystem]=other" \
  --field "vulnerabilities[][package][name]=publishpress/publishpress-shortlinks" \
  --field "vulnerabilities[][vulnerable_version_range]=<= [affected_version]" \
  --field "cvss_vector_string=CVSS:3.1/[vector]"
```

**Rules:**
- `severity` must be one of: `critical`, `high`, `medium`, `low`
- `cvss_vector_string` must be a valid CVSS 3.1 vector (e.g., `CVSS:3.1/AV:N/AC:L/PR:L/UI:R/S:U/C:N/I:L/A:N`)
- `vulnerable_version_range` format: `<= 1.4.0` (use the current plugin version from `tinypress.php` header)
- `description` field supports GitHub Flavored Markdown; include the full write-up
- The command creates a **draft** — it will NOT be published automatically
- Include a `--dry-run` note comment in the output so the user can review before executing

Always present the command inside a shell code block with the comment `# Review before running — this creates a DRAFT advisory (not published)` on the first line.

---

## Severity & CVSS Quick Reference

| Finding Type | Typical Severity | Example Vector |
|---|---|---|
| SQL injection (auth not required) | Critical | AV:N/AC:L/PR:N/UI:N/S:U/C:H/I:H/A:H |
| SQL injection (auth required) | High | AV:N/AC:L/PR:L/UI:N/S:U/C:H/I:H/A:H |
| XSS stored (admin) | Medium | AV:N/AC:L/PR:L/UI:R/S:C/C:L/I:L/A:N |
| CSRF — write action | Medium | AV:N/AC:L/PR:L/UI:R/S:U/C:N/I:L/A:N |
| Open redirect (unauthenticated) | Medium | AV:N/AC:L/PR:N/UI:R/S:C/C:L/I:L/A:N |
| Password/secret in source | High | AV:N/AC:L/PR:N/UI:N/S:U/C:L/I:L/A:N |
| IP spoofing / analytics tamper | Low | AV:N/AC:H/PR:N/UI:N/S:U/C:N/I:L/A:N |
| Unvalidated URL storage | Medium | AV:N/AC:L/PR:L/UI:N/S:U/C:N/I:L/A:N |

---

## False Positive Avoidance

- Verify capability checks aren't already applied in a parent function before flagging authorization issues
- Confirm nonces aren't verified earlier in the call stack before flagging CSRF
- Check output escaping before assuming XSS — trace the variable from source to echo
- For SQL: confirm the string-interpolated value is actually user-controlled, not a hardcoded constant
- For redirects: `esc_url_raw()` on a DB-retrieved URL is NOT a false positive — but it is if the URL comes from a filter hook that a user controls

---

## Execution Workflow

1. List `/security-audit/` to load existing advisory numbers and slugs
2. Run all Grep searches from the Scan Checklist (parallel where possible)
3. For each hit, trace the data flow to confirm exploitability
4. Deduplicate against existing advisories
5. For each new confirmed vulnerability:
   a. Write local advisory file
   b. Generate the `gh api` draft command
6. If no new findings: respond with "No new security findings since last scan."

For the current plugin version, read the `Version:` header from `tinypress.php`.
