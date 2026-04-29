=== StrifeBridge MCP ===
Contributors: strifero
Tags: mcp, claude, ai, automation, rest-api
Requires at least: 5.6
Tested up to: 6.9
Requires PHP: 7.4
Stable tag: 2.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Connect your WordPress site to Claude and other AI assistants through the Model Context Protocol. Manage posts, media, menus, and more in plain English.

== Description ==

StrifeBridge MCP turns your WordPress site into an MCP (Model Context Protocol) endpoint that AI assistants like Claude can connect to directly. Instead of describing a change to an AI and then applying it yourself, you ask and it happens.

Want to add a blog post? Ask Claude. Need to update a menu item? Ask Claude. Wondering which pages are missing SEO metadata? Ask Claude to check and fix them.

StrifeBridge MCP exposes a structured, token-authenticated REST API and MCP server inside your WordPress install. AI assistants that support MCP (Claude.ai, Claude Desktop, Cursor, Windsurf, and any MCP-capable agent framework) can connect to it and perform tasks across your site through natural language.

= What you can do =

* Create, update, and delete posts, pages, and custom post types
* Upload files to the media library and manage attachments
* Read and write WordPress options, with a security blacklist for sensitive keys
* Manage navigation menus, taxonomy terms, and widgets
* List, activate, deactivate, and delete plugins
* List users and inspect their roles
* Get site info, flush rewrite rules

= How it works =

1. Install and activate the plugin
2. Go to Settings and copy your Connector URL (the bearer token is embedded in the path)
3. Paste the URL into your AI assistant as a custom MCP connector
4. Ask the assistant to do things on your site

No middleware, no third-party relay service. Every request goes from your AI assistant directly to your own WordPress site. You own the endpoint, you own the token, and you can revoke access at any time from Settings.

= Compatible with =

* Claude.ai (web and desktop) &mdash; primary integration
* Claude Desktop app
* Cursor
* Windsurf
* Any MCP-capable agent framework

= Security =

StrifeBridge MCP was designed with security as a first-class concern:

* Bearer token authentication on every request, validated with constant-time `hash_equals()`
* WordPress secret keys, auth salts, and the plugin's own token are blacklisted from the options API
* Emergency Lockdown button in Settings disables the entire API with one click
* Every tool group (posts, media, menus, etc.) can be individually toggled off from Settings
* The plugin includes no outbound network calls, no analytics, and no tracking

= Compatibility with self-hosted WordPress =

StrifeBridge MCP works on any self-hosted WordPress installation running WordPress 5.6 or higher. WordPress.com and WordPress Multisite are not currently supported.

= Extending StrifeBridge MCP =

StrifeBridge MCP exposes extension hooks that let add-on plugins register additional MCP tools without modifying the core plugin. Developers can hook into `sbmcp_register_rest_routes`, `sbmcp_mcp_tools`, `sbmcp_mcp_tool_call`, `sbmcp_tool_groups`, and `sbmcp_admin_after_settings` to add routes, tools, admin UI, and tool groups. This is how separately distributed add-ons extend the plugin with features like file editing, database queries, and user management.

== Installation ==

1. Upload the plugin files to the `/wp-content/plugins/strifebridge-mcp` directory, or install the plugin through the WordPress Plugins screen directly
2. Activate the plugin through the Plugins screen in WordPress
3. Go to Settings &rarr; StrifeBridge MCP to get your Connector URL
4. Paste the Connector URL into Claude.ai (Settings &rarr; Integrations &rarr; Add custom connector) or your preferred MCP-capable AI tool
5. Ask the AI to manage your site

== Frequently Asked Questions ==

= What is MCP? =

MCP stands for Model Context Protocol. It is an open standard developed by Anthropic that lets AI assistants connect to external tools and data sources. An MCP server exposes a set of named tools that an AI can call directly, instead of the AI having to construct raw HTTP requests.

= Do I need a Claude.ai paid plan? =

No. Claude.ai free users can add one custom connector, and StrifeBridge MCP works as a custom connector. Pro and Team plans can add multiple connectors.

= Does this work with ChatGPT or Gemini? =

No. ChatGPT and Gemini use different protocols and do not currently support MCP.

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

= Can I disable individual tool groups? =

Yes. Settings &rarr; StrifeBridge MCP has toggles for every tool group (posts, media, options, menus, users, plugins, widgets, taxonomies, system). Turn off anything you do not want the AI to access.

== Screenshots ==

1. Settings page showing the Connector URL, tool group toggles, and Emergency Lockdown button
2. Claude.ai using StrifeBridge MCP to create a new blog post
3. Claude.ai inspecting a WordPress menu and adding a new menu item
4. Tool group toggles and community sidebar in the admin settings

== Changelog ==

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

= 2.0.0 =
Initial release. Install to connect your WordPress site to Claude and other MCP-capable AI assistants.
