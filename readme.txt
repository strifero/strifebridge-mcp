=== StrifeBridge MCP ===
Contributors: strifero
Tags: mcp, claude, ai, automation, rest-api
Requires at least: 5.6
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 2.4.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Connect your WordPress site to Claude and other AI assistants through the Model Context Protocol. Manage posts, media, menus, and more in plain English.

== Description ==

StrifeBridge MCP turns your WordPress site into an MCP (Model Context Protocol) endpoint that AI assistants like Claude can connect to directly. Instead of describing a change to an AI and then applying it yourself, you ask and it happens.

Want to add a blog post? Ask Claude. Need to update a menu item? Ask Claude. Wondering which pages are missing SEO metadata? Ask Claude to check and fix them.

StrifeBridge MCP exposes a structured, authenticated REST API and MCP server inside your WordPress install. AI assistants that support MCP (ChatGPT, Claude.ai, Claude Desktop, Gemini, Cursor, Windsurf, and any MCP-capable agent framework) can connect to it and perform tasks across your site through natural language. Connections use OAuth 2.1 and act as a real WordPress account, so an assistant is held to that account's permissions.

= What you can do =

* Create, update, and delete posts, pages, and custom post types
* Upload files to the media library and manage attachments
* Read and write WordPress options, with a security blacklist for sensitive keys
* Manage navigation menus, taxonomy terms, and widgets
* List, activate, deactivate, and delete plugins
* List users and inspect their roles
* Get site info, flush rewrite rules

= See and control what the AI does =

Every tool call is recorded in an activity log — what ran, whether it worked, and where the request came from, including refused calls and failed sign-in attempts. The Settings screen shows recent activity at a glance.

Safe Mode adds guardrails on top of the per-tool-group toggles:

* **Never publish** — new posts stay drafts, and updates cannot publish them
* **Trash instead of delete** — deletions stay recoverable
* **Read-only mode** — the AI can look but not touch

= How it works =

1. Install and activate the plugin
2. Go to Settings and copy your Server URL
3. Paste it into your AI assistant as a custom MCP connector
4. The assistant sends you back to WordPress to sign in and approve the connection
5. Ask the assistant to do things on your site

No middleware, no third-party relay service. Every request goes from your AI assistant directly to your own WordPress site. You own the endpoint, you own the token, and you can revoke access at any time from Settings.

= Compatible with =

* ChatGPT &mdash; via OAuth
* Google Gemini &mdash; via OAuth
* Claude.ai (web and desktop), and Claude Code
* Cursor
* Windsurf
* Any MCP-capable agent framework

ChatGPT and Gemini require OAuth, which arrived in version 3.0.0. The others accept either OAuth or the legacy token.

= Security =

StrifeBridge MCP was designed with security as a first-class concern:

* OAuth 2.1 with mandatory PKCE (S256 only; the `plain` method is refused), single-use 60-second authorization codes, exact-match redirect URIs, and rotating refresh tokens
* Tokens, authorization codes, and client secrets are stored only as hashes and compared in constant time with `hash_equals()`
* Every connection is bound to a WordPress account and subject to that account's capabilities, and can be revoked individually from Settings
* WordPress secret keys, auth salts, and the plugin's own token are blacklisted from the options API
* Emergency Lockdown button in Settings disables the entire API with one click
* Every tool group (posts, media, menus, etc.) can be individually toggled off from Settings
* The plugin includes no outbound network calls, no analytics, and no tracking

= Compatibility with self-hosted WordPress =

StrifeBridge MCP works on any self-hosted WordPress installation running WordPress 5.6 or higher. WordPress.com and WordPress Multisite are not currently supported.

= Extending StrifeBridge MCP =

StrifeBridge MCP exposes extension hooks that let add-on plugins register additional MCP tools without modifying the core plugin. Developers can hook into `sbmcp_register_rest_routes`, `sbmcp_mcp_tools`, `sbmcp_mcp_tool_call`, `sbmcp_tool_groups`, and `sbmcp_admin_after_settings` to add routes, tools, admin UI, and tool groups. This is how separately distributed add-ons extend the plugin with features like file editing, database queries, and user management.

== Privacy ==

StrifeBridge MCP keeps an activity log in your own WordPress database, in a table named `{prefix}sbmcp_audit_log`. Each entry records the time of the request, the name of the tool that ran, a short sanitized summary of what it acted on, whether the call succeeded, failed, or was refused, and — unless you turn it off — the IP address the request came from.

* **Nothing leaves your site.** The log is local to your database. The plugin makes no outbound network calls and sends no analytics or telemetry anywhere.
* **No content or secrets are stored.** Argument summaries are allowlisted per tool, so option values, post content, widget settings, uploaded file data, and API tokens are never written to the log. A tool the plugin does not recognize records no arguments at all.
* **IP logging is optional.** "Log IP addresses" under Settings &rarr; StrifeBridge MCP &rarr; Safety is on by default and can be switched off, after which new entries store no address. Entries already recorded keep the addresses they were written with; deleting those is a matter of waiting for them to be pruned, or clearing the table.
* **Entries are pruned automatically.** A daily scheduled task keeps the free version to a rolling window of 30 days or 1000 entries, whichever comes first. Older entries are deleted permanently.
* **Everything is removed on uninstall.** Deleting the plugin through the WordPress admin drops the log table and removes every option the plugin created. Deactivating alone leaves the log in place, so it survives a temporary deactivation.

If your site is subject to GDPR or similar regulation, note that IP addresses are personal data. Either switch IP logging off, or account for this log in your own privacy policy and retention practices.

== Installation ==

1. Upload the plugin files to the `/wp-content/plugins/strifebridge-mcp` directory, or install the plugin through the WordPress Plugins screen directly
2. Activate the plugin through the Plugins screen in WordPress
3. Go to Settings &rarr; StrifeBridge MCP to get your Connector URL
4. Paste the Server URL into ChatGPT, Claude.ai, Gemini, or your preferred MCP-capable AI tool, then approve the connection when it sends you back to WordPress
5. Ask the AI to manage your site

== Frequently Asked Questions ==

= What is MCP? =

MCP stands for Model Context Protocol. It is an open standard developed by Anthropic that lets AI assistants connect to external tools and data sources. An MCP server exposes a set of named tools that an AI can call directly, instead of the AI having to construct raw HTTP requests.

= Do I need a Claude.ai paid plan? =

No. Claude.ai free users can add one custom connector, and StrifeBridge MCP works as a custom connector. Pro and Team plans can add multiple connectors.

= Does this work with ChatGPT or Gemini? =

Yes, as of version 3.0.0. Their connector interfaces require OAuth rather than a pasted token, which is why earlier versions could not be used with them at all. Paste the server URL from Settings &rarr; StrifeBridge MCP into the assistant, sign in to WordPress when it sends you back, and approve the connection.

Claude (web, desktop, and Claude Code), Cursor, and Windsurf also work, and can use either OAuth or the legacy token. Any client that can connect to a remote MCP server over Streamable HTTP should work; those named here are the ones actually tested.

= Does this work on WordPress.com? =

No. Only self-hosted WordPress installs (WordPress.org) are supported.

= Does this work on WordPress Multisite? =

Not yet. Multisite support is on the roadmap.

= Is my site safe if I install this? =

Every request to the plugin requires a bearer token. The token is generated when you activate the plugin and can be rotated from Settings at any time. There is also an Emergency Lockdown button in Settings that immediately disables the entire API. The plugin makes no outbound network calls.

= What happens if the AI makes a mistake? =

Always keep backups of your site, and test any significant changes on a staging environment first. The plugin includes safety measures (WordPress secret key blacklisting, one-click lockdown, individual tool group toggles) but it cannot prevent an AI from making an unintended edit to a post or option if you asked it to.

= Why would I use an MCP plugin instead of just asking the AI for code? =

Speed and accuracy. Instead of asking the AI for code, switching tabs, copying it into SFTP or the admin panel, and hoping it works, you describe what you want and the AI does it directly. For small repetitive edits, the time savings compound fast.

= Can I add my own MCP tools? =

Yes. The plugin exposes extension hooks (`sbmcp_register_rest_routes`, `sbmcp_mcp_tools`, `sbmcp_mcp_tool_call`, `sbmcp_tool_groups`) that let you register additional routes and tools from your own code.

= Can I see what the AI has actually done? =

Yes. Settings &rarr; StrifeBridge MCP has a Recent Activity panel showing the last 10 tool calls: the time, which tool ran, a short summary of what it was pointed at, the result, and the requesting IP. Refused calls and failed authentication attempts are recorded too, so a wrong token shows up in the log.

The log never records option values, post content, widget settings, uploaded file data, or your token. Only an allowlisted summary per tool (for example `id=42` or `key=blogname`) is kept, so the log is safe to export or hand to a client. The free version keeps a rolling window of 30 days or 1000 entries, whichever comes first.

= How do I stop the AI from publishing or deleting things? =

Settings &rarr; StrifeBridge MCP has a Safety section with three independent toggles. "Never publish" makes every new post a draft and refuses any update that would publish or schedule one — editing a post that is already live still works, so turning this on does not take your site down. "Trash instead of delete" keeps deletions recoverable. "Read-only mode" refuses every tool that would change anything, which is a good way to watch what an assistant wants to do before letting it do so.

All three default to off on existing sites, so upgrading never changes how your setup behaves. New installs start with "Trash instead of delete" on.

= Why did my `<script>` or `<iframe>` disappear from a post? =

WordPress strips unsafe HTML from post content unless the user saving it has the `unfiltered_html` capability. Requests authenticated with the StrifeBridge token have no logged-in WordPress user, so that capability is not present and WordPress applies its normal filtering. Standard block markup, formatting, links, and images are unaffected; `<script>`, `<iframe>`, and similar tags are removed. This is WordPress behaving as designed, not a plugin bug.

= Who is shown as the author of posts the AI creates? =

By default the oldest administrator account. You can pick a different user under Settings &rarr; StrifeBridge MCP &rarr; Safety. Before version 2.4.0 these posts had no author at all.

= Can I disable individual tool groups? =

Yes. Settings &rarr; StrifeBridge MCP has toggles for every tool group (posts, media, options, menus, users, plugins, widgets, taxonomies, system). Turn off anything you do not want the AI to access.

= The assistant cannot connect, and /.well-known/oauth-authorization-server returns 404 (nginx) =

Almost always nginx answering the request before WordPress ever sees it. Most nginx setups carry a catch-all block for ACME certificate validation, shipped by default with Certbot and by several control panels:

    location ^~ /.well-known/ {
        allow all;
    }

The `^~` modifier tells nginx that once this prefix matches it should stop looking for a better match, so *every* request under `/.well-known/` is served straight from the filesystem. The two OAuth discovery documents live at `/.well-known/oauth-authorization-server` and `/.well-known/oauth-protected-resource` and do not exist on disk — the plugin generates them — so nginx returns 404, and the assistant gives up at discovery before it ever reaches PHP. Nothing in WordPress can override this; the request never arrives.

Narrow the ACME block to the path it actually needs, and route the rest of `/.well-known/` to WordPress:

    location ^~ /.well-known/acme-challenge/ { allow all; }
    location ^~ /.well-known/ {
        allow all;
        try_files $uri $uri/ /index.php?$args;
    }

Then `nginx -t` and reload, and check that both URLs return JSON.

Both parts matter. `allow all` overrides any inherited `deny` on the path. The `^~` on the second block is doing real work and is not decorative: the very common hardening rule

    location ~ /\. { deny all; }

is a *regular expression* location, and nginx tries regex locations before falling back to an ordinary prefix match. A plain `location /.well-known/` therefore loses to it and the documents stay blocked. `^~` stops regex matching from being attempted at all, so the block wins. This is also why the ACME line keeps its own `^~` — it is a longer prefix, so it continues to win for certificate validation, and renewal is unaffected. ACME only ever uses `/.well-known/acme-challenge/`.

If the URLs still 404 after reloading, re-save Settings &rarr; Permalinks once to regenerate WordPress's rewrite rules.

This is an nginx-only problem. Apache serves the documents through the plugin's rewrite rules with no configuration change, and managed WordPress hosts are almost all unaffected.

= Does OAuth work if WordPress is installed in a subdirectory? =

Yes, but the discovery documents live under that subdirectory — `example.com/blog/.well-known/oauth-authorization-server` — and the OAuth issuer is `example.com/blog` to match, which is self-consistent and works with any connector that follows the documents. A client that assumes the domain root will not find them; that needs a redirect in your webserver from the root `/.well-known/` paths to the subdirectory.

== Screenshots ==

1. Settings page showing the Connector URL, tool group toggles, and Emergency Lockdown button
2. Claude.ai using StrifeBridge MCP to create a new blog post
3. Claude.ai inspecting a WordPress menu and adding a new menu item
4. Tool group toggles and community sidebar in the admin settings

== Changelog ==

= 3.0.0 =
* New: **OAuth 2.1 support.** ChatGPT, Gemini, and any other assistant whose connector expects OAuth can now connect. Previously the plugin offered only a bearer token, which those connector interfaces do not accept, so they could not be used at all. Paste the server URL into the assistant, sign in to WordPress, approve the connection, and you are done — there is no token to copy by hand.
* New: **Connections act as a real WordPress account.** An OAuth connection is bound to the user who approved it, and every tool call runs as that account rather than as an anonymous token holder. WordPress capability checks apply to tool calls for the first time, posts are attributed to a real author, and each connection is revocable on its own without disturbing the others. Approving a connection requires an administrator in this release, so connections currently act with administrator authority; binding a connection to a lower-privileged account is coming in 3.1, and the per-tool capability enforcement it needs is already in place and active.
* New: **Connected Applications** in Settings. Every approved assistant is listed with the account it acts as, what it was granted, and when it last made a request, each with a Revoke button. Revoking takes effect on the application's next request.
* New: Authorization screen in wp-admin naming the application, the account it will act as, and the access it is asking for, with the return address it will be sent back to.
* New: Dynamic Client Registration, which ChatGPT requires in order to connect at all, and both OAuth discovery documents at `/.well-known/oauth-authorization-server` and `/.well-known/oauth-protected-resource`.
* New: Authorizations, refusals, token issuance, and revocations are all recorded in the activity log. A call refused because the bound account lacks the capability is recorded as **denied**, alongside the existing Safe Mode and guard refusals.
* Security: PKCE is mandatory and only the S256 method is accepted; the `plain` method is rejected outright. Authorization codes are single-use, expire in 60 seconds, and are bound to the application and its PKCE challenge. Redirect URIs are matched exactly against what the application registered, with no wildcard or prefix matching. Refresh tokens rotate on every use. Access tokens last one hour, refresh tokens thirty days. Tokens, authorization codes, and client secrets are stored only as hashes and compared in constant time. The token and registration endpoints are rate limited.
* **Existing bearer token connections keep working, unchanged.** Nothing needs to be reconnected or reconfigured on upgrade. The bearer token is now marked as the legacy path in Settings, since it carries full administrator authority and cannot be scoped down; OAuth is recommended for new connections.
* Developers: `sbmcp_tool_capabilities` declares the capability each tool requires, `sbmcp_oauth_scopes` extends the scope list, `sbmcp_audit_loggable_args` is now filterable so add-ons can declare their own loggable arguments, and the two discovery documents are filterable through `sbmcp_oauth_authorization_server_metadata` and `sbmcp_oauth_protected_resource_metadata`.

= 2.4.0 =
* New: Activity log. Every tool call is recorded — the tool, a short sanitized summary of what it acted on, the result, the time, and the requesting IP. Calls refused by a tool group toggle, by Safe Mode, or by a security guard are recorded as denied, and failed authentication attempts are logged too.
* New: Recent Activity panel in Settings showing the last 10 entries. The free version keeps a rolling window of 30 days or 1000 entries, whichever comes first, pruned daily.
* New: Safe Mode. Three independent toggles in Settings — never publish, trash instead of delete, and a global read-only mode that refuses every tool that would change anything. "Never publish" forces new posts to draft and refuses an update that would publish or schedule a draft, while still allowing edits to posts that are already published. All default to off on existing sites, so upgrading does not change how your setup behaves. New installs start with "trash instead of delete" on.
* New: IP logging can be switched off. Activity log entries record the requesting IP address by default; the "Log IP addresses" setting turns that off without affecting the rest of the log. See the new Privacy section.
* New: Configurable author for AI-created posts, defaulting to the oldest administrator. Previously these posts were stored with no author, and showed as authorless in the admin list, feeds, and author archives.
* Fix: `create_post` now validates the post type and post status against what is actually registered on the site. An unrecognized value previously produced content that was invisible everywhere in the admin. It also refuses `attachment` and `nav_menu_item`, matching the other posts tools.
* Fix: a call refused by a validation or security guard is now recorded as **denied** rather than as an error. Previously only tool-group and Safe Mode refusals were classified that way, and everything a handler rejected — a blocked option key, an upload whose contents did not match its extension, a missing required parameter — was filed alongside genuine failures. Denied now answers "what did my guards stop?" and error answers "what broke?".
* Privacy: the activity log never stores option values, post content, widget settings, uploaded file data, or token values. Argument summaries are allowlisted per tool, and an unrecognized tool records no arguments at all.
* Developers: add-ons can extend the log through the `sbmcp_audit_log_retention_days` and `sbmcp_audit_log_retention_rows` filters, the `sbmcp_audit_log_query()` accessor, the `sbmcp_audit_should_log` filter, and the `sbmcp_audit_denial_codes` filter for classifying their own error codes as denials.

= 2.3.3 =
* Fix (data loss): `update_option` stored JSON as a literal string. The `value` parameter was documented as "pass objects/arrays as JSON-encoded string", which read as a promise to decode, but the text was written to `wp_options` verbatim. Any option WordPress stores as a serialized array received a string instead, and the plugin that owned the option silently fell back to its defaults. This took a live site's Rank Math configuration offline. `update_option` now takes an explicit `json` parameter with three distinct states: `json: true` decodes the value and stores it as an array, so WordPress serializes it natively; `json: false` stores the value as a literal string, unchanged, which is how a genuine JSON string gets written; and omitting `json` entirely causes a JSON-looking value to be **rejected** with a message naming both flags, rather than being guessed at or silently stored. Values that are not JSON are stored exactly as before, whether or not the flag is present.
* Fix (data loss): `delete_post` treated `force=false` as true and permanently deleted a post the caller asked to trash. The value was cast with `(bool)`, and PHP evaluates the string `"false"` as true, so any query-string or form caller hit this; JSON callers sent a real boolean and were unaffected, which is why it went unnoticed. All boolean parameters now route through a shared helper that treats `"false"`, `"0"`, `""` and `0` as false.
* Fix: `upload_media` accepted a `filename` alongside a source `url` and then discarded it, naming the attachment after the URL instead. `filename` is now honoured on the URL path. It must carry an image extension, matching the images-only contract the URL path always had.
* Fix: `update_post` returned a PHP 8 fatal error when the request had no JSON body.
* Fix: `update_post` no longer errors on a request body that sets `thumbnail_id` to null.
* Fix: `upload_media` now returns a proper error when the attachment record cannot be created, instead of reporting a successful upload with `id: 0` and leaving the uploaded file orphaned.
* Fix: removed a PHP 8.1+ deprecation notice when listing or fetching media items that have no attached file.
* Fix: `create_menu_item` read the menu ID from a parameter named `id`, while the tool schema declares it as `menu_id` and marks it required. A caller following the published schema supplied `menu_id`, and the handler looked for something else. The handler now reads `menu_id`, and returns a clear error instead of failing obscurely when it is absent. The REST route `/menu/<id>/items` is unchanged.
* Change: validation errors now name the parameter that is missing, in the form `Missing required parameter: slug`, using the actual schema key. Messages such as "Provide a plugin slug." described the value without naming the key to send.
* Docs: the `update_option` description now states that it is the recommended, cache-safe way to write options, and that direct SQL writes to the options table bypass the object cache.

= 2.3.2 =
* Security: The options tool guards are now case- and whitespace-normalized. `wp_options.option_name` is matched case-insensitively by MySQL and WordPress trims option keys before querying, so a key such as `SITEURL` or ` siteurl` previously slipped past the case-sensitive blacklist and still resolved to the real row. Every guard now compares the same normalized key the database does, on the read, write, and list paths.
* Security: Transients can no longer be written through the options tool. A forged `_site_transient_update_plugins` record could point WordPress at an attacker-supplied package URL and have the next auto-update install it.
* Security: `upload_path` and `upload_url_path` are now blacklisted, so the upload destination cannot be redirected through the options tool.
* Security: Option keys ending in `_salt` are now treated as sensitive and cannot be read or written.
* Security: Base64 media uploads are now validated by file content, not just by filename. The written file is sniffed with `wp_check_filetype_and_ext()` and removed if its real contents do not match the declared extension.
* Security: The posts tools (`list_posts`, `get_post`, `get_post_details`, `update_post`, `delete_post`) now refuse to operate on attachments and navigation menu items. Because those are posts underneath, `delete_post` could previously delete media with the Media tool group switched off, and menu items with Menus off. Use the dedicated media and menu tools instead.
* Security: The self-protection check in the plugin tools is now case-insensitive, so on a case-insensitive filesystem StrifeBridge MCP can no longer be tricked into deactivating or deleting itself.
* Fix: `list_media` and `list_terms` now clamp `per_page` to a minimum of 1. A negative value meant "no limit" to WordPress and returned the entire media library or term set in a single response.
* The GPLv2 license text is now included in the distribution.

= 2.3.1 =
* Security: The options tool no longer lets a token holder overwrite options it refuses to read. The write path now applies the same sensitive-key check (token/secret/key/password/roles/capabilities) as the read and list paths, closing a read/write asymmetry.
* Security: The role and capability map guard is now prefix-aware. Previously only the default-prefix `wp_user_roles` was blocked; on installs with a custom database prefix the real `{prefix}user_roles` and `{prefix}user_settings` options, plus any key ending in `_user_roles` or `_capabilities`, are now blocked on both read and write. This prevents a token holder from rewriting the role-to-capability map to escalate privileges.
* Security: Base64 media uploads now reject payloads larger than 10 MB before decoding, and the file type is validated from the filename before anything is written to disk.
* Fix: `update_option` now distinguishes a genuine no-op ("unchanged") from a failed write, which previously both reported "unchanged".

= 2.3.0 =
* Security: Emergency Lockdown now also disables the WordPress Abilities surface, not just the MCP and REST endpoints that use the bearer token. Previously, clicking Disable API left every tool callable through the Abilities API and MCP Adapter path.
* Security: Plugin internal options (the sbmcp_ prefixed options that store the API token, lockdown state, tool group toggles, and the abilities switch) are now blocked from the options tool entirely. Previously a token holder with the Options group enabled could switch tool groups back on that an administrator had disabled.
* Cleanup: uninstall now also removes the sbmcp_abilities_disabled option.

= 2.2.0 =
* New: StrifeBridge tools are now registered as native WordPress Abilities on WordPress 7.0 and later, so they appear in the core ability registry and can be called through the MCP Adapter by any compatible AI client. The existing StrifeBridge MCP endpoint is unchanged and continues to work on all supported versions.
* The abilities surface runs under the authenticated WordPress user and gates every tool with a real capability check (for example edit_posts, upload_files, manage_options), independent of the StrifeBridge bearer token.
* Per tool group toggles in Settings also apply to the abilities surface: a disabled group is not exposed as abilities.
* The abilities bridge can be turned off entirely with the sbmcp_enable_abilities filter or the sbmcp_abilities_disabled option.
* No effect on WordPress versions below 7.0, where the Abilities API does not exist.

= 2.1.0 =
* Verified compatible with WordPress 7.0.
* Fix: `update_post` now honors `featured_media` (or `thumbnail_id`) to set or remove a post's featured image. Previously the parameter was silently ignored.
* Fix: `create_post` now accepts `featured_media` (or `thumbnail_id`) to set a featured image at creation time.
* Improvement: `list_options` adds an explicit `keys` parameter so callers can fetch a specific allowlist of options instead of dumping the whole options table.
* Improvement: `list_options` now truncates oversized option values (configurable via `max_value_bytes`, default 4096 bytes). Prevents giant serialized transients from overwhelming response sizes. Truncated rows are marked with `_truncated: true` and `_original_bytes`.

= 2.0.0 =
* Initial release as StrifeBridge MCP for WordPress
* MCP server with token authentication at `/wp-json/strifebridge/v1/`
* Posts and pages: create, read, update, delete
* Media library: list, upload, delete
* Options: read, write (with security blacklist)
* Users: list
* Plugins: list, activate, deactivate, delete
* Menus: full management of menus and menu items
* Taxonomies: full management of terms
* Widgets: list sidebars, read widgets, update widget configuration
* System: site info, flush rewrite rules
* Emergency Lockdown button
* Per-tool-group toggles
* Extension hooks for add-on plugins

== Upgrade Notice ==

= 3.0.0 =
Adds OAuth 2.1, so ChatGPT and Gemini can connect for the first time. Connections are approved from inside WordPress, act as a real account so capability checks apply, and can each be revoked from Settings. Approving requires an administrator in this release. Existing bearer token connections keep working exactly as they are and do not need to be reconnected — the bearer token is simply marked as the legacy path, and OAuth is recommended for anything new.

= 2.4.0 =
Adds an activity log so you can see exactly what your AI assistant has done, and Safe Mode guardrails (force draft, trash instead of delete, read-only). Safe Mode defaults to off on existing sites, so nothing about your current setup changes on upgrade. Also fixes AI-created posts having no author.

= 2.3.3 =
Bug-fix release, recommended for all users. Fixes two data-loss bugs: `update_option` wrote JSON as a literal string, corrupting any option WordPress stores as an array, and `delete_post` permanently deleted posts when passed `force=false` over a query string. `update_option` takes a new `json` parameter for storing arrays; a JSON-looking value passed without it is now rejected rather than stored incorrectly, so any existing call that relied on writing literal JSON text into an array option will now return a clear error instead of silently corrupting the option. If the literal string really was intended, pass `json: false` to store it unchanged.

= 2.3.2 =
Security release. Closes a blacklist bypass where a token holder could read or overwrite protected options (including `siteurl` and `active_plugins`) by varying the letter case of the key, blocks transient and upload-path forgery, validates media uploads by content, and stops the posts tools from reaching media and menu items that their own tool group had disabled. Recommended for all installs. No breaking changes; existing connector URLs continue to work.

= 2.3.1 =
Security release. Closes a privilege-escalation path where a token holder could overwrite sensitive options (including the role/capability map on custom-prefix installs) that the API refuses to read, and adds a size cap on base64 media uploads. Recommended for all installs. No breaking changes; existing connector URLs continue to work.

= 2.3.0 =
Security release. Emergency Lockdown now covers the Abilities surface, and plugin internal options can no longer be read or changed through the options tool. No breaking changes; existing connector URLs continue to work.

= 2.2.0 =
Adds native WordPress 7.0 Abilities API support: StrifeBridge tools are now discoverable and callable through the core MCP Adapter, gated by WordPress capabilities. The existing MCP endpoint is unchanged.

= 2.1.0 =
Bug fixes for `update_post` (now honors `featured_media`) and `list_options` (new `keys` parameter, large-value truncation). WordPress 7.0 compatibility verified.

= 2.0.0 =
Initial release. Install to connect your WordPress site to Claude and other MCP-capable AI assistants.
