=== Init Chat Engine – Real-Time, Community, Extensible ===
Contributors: brokensmile.2103
Tags: chat, community, realtime, shortcode, lightweight
Requires at least: 5.5
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 1.3.9
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

A lightweight, real-time community chat system built with REST API and Vanilla JS. No jQuery, no reload. Full admin panel with moderation tools.

== Description ==

Init Chat Engine is a clean and minimal frontend chatbox plugin, designed for homepage or site-wide communication with comprehensive administrative controls.

This plugin is the core user system behind the [Init Plugin Suite](https://en.inithtml.com/init-plugin-suite-minimalist-powerful-and-free-wordpress-plugins/) – optimized for frontend-first interaction, extensibility, and real-time gamification.

GitHub repository: [https://github.com/brokensmile2103/init-chat-engine](https://github.com/brokensmile2103/init-chat-engine)

**Key Features:**

**Frontend Experience:**
- Built with 100% REST API and Vanilla JS
- No jQuery, no bloat – blazing fast
- Fully embeddable via `[init_chatbox]` shortcode
- Multiple chat rooms via `[init_chatbox room="..."]` – each room has its own messages, pinned message and limits
- Guest messaging support (optional)
- Smart polling system (adaptive 3.5–10s based on activity, up to 20s when the chatbox is scrolled out of view)
- Browser notifications when new messages arrive
- Scroll-up to load history, scroll-down to auto-scroll
- Optimistic sending & "new message" jump button
- Clean UI with customizable themes
- Template override supported (`chatbox.php`)

**Administrative Control:**
- **Complete Settings Panel** - Basic, Security, and Advanced configurations
- **Message Management** - Search, view, delete messages with pagination
- **User Moderation** - Ban/unban users by IP or user ID with expiration
- **Rate Limiting** - Prevent spam with configurable message limits
- **Word Filtering** - Block messages containing prohibited words
- **Statistics Dashboard** - View chat activity, user engagement, and trends
- **Cleanup Tools** - Automatic and manual cleanup of old messages
- **Custom CSS Support** - Full styling customization options

**Security & Moderation:**
- IP-based and user-based banning system
- Configurable rate limiting (messages per minute)
- Word filtering with custom blocked word lists
- Message moderation queue (optional)
- Automatic cleanup of old messages and expired bans
- Admin override capabilities

**Multilingual Ready:**
- Translation-ready with full `.pot` file included
- Vietnamese translation included
- Easy to translate to any language

Perfect for community-based sites, forums, fanpages, manga readers, SaaS dashboards, customer support, or any interactive chat widget.

== Installation ==

1. Upload the plugin to the `/wp-content/plugins/` directory.
2. Activate the plugin through the 'Plugins' menu in WordPress.
3. Configure settings under `Settings → Chat Engine`.
4. Add the `[init_chatbox]` shortcode anywhere you want the chatbox to appear.
5. Optional: Visit `Chat Engine → Management` to moderate messages and users.

== Shortcode Attributes ==

Shortcode `[init_chatbox]` supports the following attributes:

- `height` - Set chat height (e.g., `height="400px"`)
- `width` - Set chat width (e.g., `width="100%"`)
- `theme` - Apply custom theme (e.g., `theme="dark"`)
- `show_avatars` - Override avatar setting (`true`/`false`)
- `show_timestamps` - Override timestamp setting (`true`/`false`)
- `title` - Add custom chat title
- `class` - Add custom CSS classes
- `id` - Set custom container ID
- `room` - Chat room name (letters, numbers, `-` and `_`). Leave empty for the default room (the main chat, which keeps all existing messages). Each room has its own messages, pinned message and message limit (e.g. `room="vip"`)
- `allow_guests` - Per-room override of the "Allow guests" setting (`yes`/`no`)
- `max_messages` - Per-room override of the "Maximum Messages" setting (10–10,000)

Example: `[init_chatbox height="500px" title="Community Chat" theme="modern"]`

Room example: `[init_chatbox room="members" title="Members Lounge" allow_guests="no" max_messages="500"]`

Only one chatbox per page is supported. Room overrides (`allow_guests`, `max_messages`) are saved when the shortcode is displayed on published content (not in drafts or previews), so use the same attributes everywhere a room is embedded.

Shortcode `[init_chat_stats]` also accepts `room` to show statistics for a single room (e.g. `[init_chat_stats room="vip"]`); without it, all rooms are counted.

== Filters for Developers ==

This plugin provides filters and actions to allow developers to extend word filtering, message processing, and chat behavior without modifying core files.

**`init_plugin_suite_chat_engine_word_filter_strategy`**  
Modify word filtering strategy (`substring`, `word`, `regex`).  
**Applies to:** Message validation  
**Params:** `string $strategy`, `array $settings`, `string $message`

**`init_plugin_suite_chat_engine_blocked_words`**  
Modify the blocked-words list before validation.  
**Applies to:** Message validation  
**Params:** `array $blocked_words`, `array $settings`, `string $message`

**`init_plugin_suite_chat_engine_bypass_filter`**  
Bypass filtering under custom conditions (VIP, internal users, etc.).  
**Applies to:** Message validation  
**Params:** `bool $bypass`, `string $message`, `WP_User|null $user`, `array $settings`

**`init_plugin_suite_chat_engine_word_block_hit`** *(action)*  
Triggered when a word filter rule blocks a message.  
**Applies to:** Message validation  
**Params:** `string $blocked_word`, `string $message`, `string $strategy`

**`init_plugin_suite_chat_engine_enrich_message_row`**  
Extend chat message data (add flags, metadata, user info, etc.).  
**Applies to:** Backend DB → JSON output  
**Params:** `array $message_row`, `WP_User|null $user`

**`init_plugin_suite_chat_engine_message_saved`** *(action)*  
Fired after a message is stored.  
**Applies to:** POST /send  
**Params:** `int $message_id`, `string $message`, `int|null $user_id`, `string $display_name`, `string $room` (added in 1.3.9; `''` = default room)

**`init_plugin_suite_chat_engine_ip_headers`**  
Choose which `$_SERVER` keys are trusted (in priority order) when detecting the visitor IP used for bans and rate limiting. Default keeps the existing list (Cloudflare / proxy headers first, then `REMOTE_ADDR`). Sites not behind a proxy or CDN can return `array( 'REMOTE_ADDR' )` to prevent spoofed `X-Forwarded-For` headers from bypassing IP bans.  
**Applies to:** Ban check, rate limiting, message logging  
**Params:** `array $ip_keys`

== Screenshots ==

1. Admin settings panel - Basic configuration
2. Admin settings panel - Security and moderation
3. Admin settings panel - Advanced options and maintenance
4. Frontend chatbox interface with guest and user messages

== Frequently Asked Questions ==

= Can guests send messages? =  
Yes, if enabled in the plugin settings under Basic Settings. Guests will be asked to enter a display name before sending messages.

= How do I moderate messages and users? =  
Go to `Chat Engine → Management` to view recent messages, ban/unban users, and see chat statistics. You can search messages, delete inappropriate content, and ban users by IP or user account.

= Does it support real-time messaging? =  
This plugin uses REST API with smart polling (3.5-10 second intervals) for broad compatibility. No WebSocket setup required, works on any hosting.

= How many messages are stored? =  
Configurable in settings (default: 1000 messages). Old messages are automatically deleted when the limit is reached. You can also set up automatic cleanup based on age.

= Can I customize the chat appearance? =  
Yes, multiple ways:
- Use the Custom CSS field in Advanced Settings
- Override the template by placing `chatbox.php` in your theme's `init-chat-engine/` folder
- Use shortcode attributes for basic styling
- Disable plugin CSS entirely and use your own

= How does the ban system work? =  
Administrators can ban users by IP address or user account from the Management panel. Bans can be temporary (with expiration date) or permanent. Banned users see a clear message explaining their restriction.

= Can I limit message frequency? =  
Yes, use the Rate Limiting setting to control how many messages users can send per minute (1-100 messages). This helps prevent spam and abuse.

= Is it translation-ready? =  
Yes, the plugin is fully translation-ready with Vietnamese translation included. All text strings use proper WordPress internationalization functions.

= Can I have several chat rooms? =  
Yes (since 1.3.9). Add the `room` attribute: `[init_chatbox room="vip"]`. Rooms are created automatically from the shortcode and every room is isolated: messages, pinned message and message limit. The room name is signed by the server, so visitors cannot post into rooms that don't exist on your site. Bans, rate limiting, word filtering and account age rules are shared by all rooms. Manage rooms under `Chat Engine → Management` (room filter on Recent Messages and Statistics, plus a Chat Rooms overview).

= How do I backup chat data? =  
Chat messages are stored in your WordPress database in the `wp_init_chatbox_msgs` table. Use any WordPress backup plugin or database backup tool.

== Changelog ==

= 1.3.9 – October 2, 2026 =
- Fix: open chat tabs could get stuck and stop receiving new messages until the page was reloaded (polling kept requesting the same `after_id`), on sites with a persistent object cache such as Redis or Memcached. A slow poll request that read the database just before a new message was sent could write its outdated "latest message ID" into the shared cache *after* the new message had cleared it, so every client already at that ID was told there was nothing new. Frontend cache entries are now tagged with a generation token that changes on every message change, so a late write can never hide newer messages. Reproduced and verified fixed against a real Redis object cache under concurrent requests
- Fix: `GET /messages` and `GET /user-status` now send `no-cache` headers (plus `X-LiteSpeed-Cache-Control: no-cache`), and the chat script fetches with `cache: 'no-store'`, so page caches / CDNs (LiteSpeed Cache "Cache REST API", Cloudflare, Varnish, Nginx FastCGI cache) and the browser can no longer serve an old empty polling response for the same URL
- Fix: a request that hung (e.g. after the computer woke from sleep or switched networks) blocked all later polls forever because polling skips while a request is still pending. Requests now time out after 20 seconds and polling resumes automatically
- Performance: "no new messages" polls are cached safely again (1.3.7 had to disable this to avoid the race above): idle polls on a site with a persistent object cache no longer touch the database at all
- New: multiple chat rooms. Add `room="..."` to `[init_chatbox]` to create an isolated room with its own messages, pinned message and message limit. Existing messages stay in the default room, so current chatboxes are unchanged (the `id` attribute is still only the HTML container ID)
- New: room names are signed (HMAC) by the server and printed into the page, so the REST API only accepts rooms that come from a shortcode on your site — nobody can create or spam hidden rooms. Works with page caching
- New: per-room shortcode overrides `allow_guests="yes|no"` and `max_messages="..."` (only saved from published content, so contributors cannot change a room's rules through drafts or previews)
- New: Management → Recent Messages has a room filter and a Room column; Management → Statistics can be filtered by room and lists all rooms (message count, last activity, guest access, message limit) with a "Delete Room Messages" action
- New: `[init_chat_stats room="..."]` shows statistics for a single room
- New: the "Maximum Messages" limit and the daily cleanup now apply per room
- Developer: `init_plugin_suite_chat_engine_message_saved` now receives the room as a 5th argument; `GET /messages` and `POST /send` responses include `room`
- Database: adds a `room` column and a `(room, is_deleted, id)` index to the messages table. The upgrade runs automatically on the first request after updating (also on the frontend, not only in wp-admin) and does not touch existing data
- New translatable strings for rooms (Vietnamese translation updated)

= 1.3.8 – October 1, 2026 =
- Fix: the **Rate Limiting** setting was ignored — the limit was read from a legacy option the Settings page no longer writes to, so every site was silently limited to the default 10 messages/minute regardless of what the admin configured. The saved value is now honored (sites that never saved Security settings keep the previous default of 10)
- Fix: the daily cleanup cron (and the "Run Cleanup Now" button) had the same problem with **Maximum Messages** and **Automatic Cleanup** days — it always used the defaults (1000 / 30 days). It now uses the configured values
- Fix: maximum message length was counted in bytes instead of characters, so Vietnamese / accented / emoji messages were rejected well before the configured limit even though the on-screen counter still showed room left. Length is now counted in characters, matching the counter and the setting's description
- Fix: using a custom container ID (`[init_chatbox id="..."]`) stopped the chat from loading at all — the script only looked for the default `init-chatbox-root` ID
- Fix: on sites using the **Plain** permalink structure (REST URLs of the form `index.php?rest_route=...`) the chat never loaded any messages — the script appended its parameters with a second `?`, producing an invalid URL (HTTP 404). Query parameters are now appended correctly for both permalink styles
- Fix: Custom CSS containing `>` child selectors or quotes (e.g. `content: "..."`, `font-family: "..."`) was broken by HTML-escaping on output. CSS is now output intact while still stripping HTML tags and neutralizing `</` so it cannot break out of the `<style>` tag
- Fix: after **Delete All Messages** (or deleting the pinned message from the Management page / moderation endpoint), the pinned banner kept showing the deleted message because it renders from a stored snapshot. Admin deletions now unpin it too (automatic trimming of old messages by the Maximum Messages limit does not unpin)
- Fix: unbanning by user ID / IP (without a ban ID) did not clear the ban cache, so the user could stay blocked until the cache expired; cached temporary bans also never outlive their expiry time now
- Fix: validation error for **Minimum Account Age** (over 3650 days) was never displayed because it was added after the settings error notice had already been registered
- Fix: the "has messages" flag used to size the empty chatbox was cached for a day and never invalidated, so a freshly used chat could keep its "empty" layout
- Fix: the admin message list could show stale data for up to 5 minutes (new messages missing, wrong page size after changing Screen Options) on hosts with a persistent object cache. The list cache is now versioned and invalidated on every message change
- Fix: uninstalling the plugin left the `init_chatbox_stats` and `init_chatbox_banned` tables, settings options, the cleanup cron event and per-user Screen Options behind. Uninstall now removes all plugin data
- Security: hardened HTML escaping in the chat script — the previous helper did not escape quotes, so a crafted display name or same-site link inside a message could break out of an HTML attribute (stored XSS). Quotes are now escaped everywhere user content is placed into the chat markup
- Security: the `theme` shortcode attribute is now restricted to letters, numbers, `-` and `_` before being used in template / stylesheet paths, preventing path traversal (e.g. `theme="../../.."`)
- Security: `GET /user-status` no longer exposes the full ban record (banning admin's user ID, stored IP address); `ban_info` now contains only `reason`, `banned_at` and `expires_at`
- Security: added the `init_plugin_suite_chat_engine_ip_headers` filter so sites not behind a proxy/CDN can trust only `REMOTE_ADDR`, preventing spoofed forwarding headers from bypassing IP bans and rate limits. Default behavior is unchanged
- Performance: the "not banned" result was never actually served from cache (the negative cache stored `false`, which is indistinguishable from a cache miss), so every poll from every client queried the ban table 1–2 times. It is now cached correctly — on hosts with a persistent object cache most polls no longer touch the ban table at all
- Performance: sending a message now updates the `total_messages` / `messages_today` counters with a single atomic query each instead of a read + write pair, which also fixes lost increments when several messages are sent at the same moment
- Performance: removed a duplicate REST request on every page load (a separate `?limit=1` call only used to read the pinned message, which the initial message load already returns)
- Performance: the Management message list no longer runs ban-check and user lookups per row (previously up to 2 queries + 1 user lookup for each of the 20–200 rows); bans and users are preloaded once per page. Bulk delete now runs a single query instead of one per selected message
- Performance: `[init_chat_stats]` results are cached for 1 minute and only the numbers actually displayed are queried (previously up to 6 `COUNT(*)` queries on every page view)
- Performance: the chat script no longer forces a style/layout recalculation every time it hides an element, and mouse-move activity tracking is throttled to once per second
- Performance: the visitor IP is resolved once per request instead of on every ban / rate-limit / logging call
- New translatable string: "Name is too long (max 50 characters)." (Vietnamese translation updated)
- No changes to database schema, REST routes, or the message response shape used by third-party integrations

= 1.3.7 – August 21, 2026 =
- Fix: intermittent missing messages on the frontend chat under concurrent traffic — a race condition in the `GET /messages` polling cache could cause a newly sent message to be silently hidden from all clients until a *later* message triggered a cache clear (symptom: 2nd message never appears, then sending a 3rd makes both appear together; refreshing the page also "fixes" it since page load uses a separate, unaffected cache path). Only reproduces on hosts with a persistent object cache (Redis/Memcached/etc.) under concurrent request timing; does not affect message storage — only what polling clients see, and only temporarily
- Fix: account age requirement check (`min_account_age_days`) compared the site's local time against WordPress's `user_registered` field, which core always stores in UTC — could make the check off by the site's UTC offset. Now compares against a true UTC timestamp
- Performance: `get_all_settings()` could be called up to 3 times within a single POST /send request (directly, plus internally by message validation and the account-age check) — now cached per-request so settings are read and merged only once
- Internal: full WordPress Coding Standards (WPCS) compliance pass — no functional changes intended; see notes below if you maintain a fork or patches against this plugin
  - Verified with a byte-for-byte token comparison against the previous release to confirm no logic changes beyond the two fixes above
  - `in_array()` calls now use strict comparison (`true` as third argument) to avoid loose-comparison type coercion edge cases
  - All conditionals now use Yoda style, all `?:` short ternaries expanded, all inline comments end in proper punctuation, all functions have complete docblocks

= 1.3.6 – August 1, 2026 =
- Enhancement: relative timestamps ("x minutes...") now also cover weeks, months, and years for older messages — previously capped at "x days"
- Change: dropped the "ago" suffix from relative timestamps to keep the UI compact (e.g. "2 hours" instead of "2 hours ago"), matching the convention used by most modern social apps. Translators: the `minutes_ago` / `hours_ago` / `days_ago` JS i18n strings were renamed to `unit_minutes` / `unit_hours` / `unit_days` / `unit_weeks` / `unit_months` / `unit_years` — please update custom translations accordingly
- Performance: polling now also accounts for whether the chatbox is actually scrolled into view (via `IntersectionObserver`), not just whether the browser tab itself is focused/visible. If the chatbox is off-screen elsewhere on a long page, polling backs off further (up to 20s) and resumes instantly once it's back in view
- No changes to database schema or REST API response shape

= 1.3.5 – August 1, 2026 =
- Fix: timestamps ("x minutes ago") could get stuck on "now" indefinitely (or show hours-old immediately after posting) on any site whose timezone setting differs from UTC. Caused by `created_at_iso` being computed from a local-time string without converting to true GMT first — now uses `get_gmt_from_date()` for a correct absolute timestamp. Only affects the new client-side timestamp feature below; does not affect message content, delivery, or ordering
- Performance: removed a redundant DB query that ran on every single poll request to refresh message timestamps (previously fetched + recomputed 50 rows every 3.5–10s per connected client)
- Performance: relative timestamps ("x minutes ago") are now computed client-side and refreshed locally every 60s, no extra network round-trip
- Performance: throttled the `last_activity` stat write so it's persisted at most once per minute instead of on every poll request, reducing DB write load on busy sites
- Performance: polling now pauses entirely (instead of just backing off) after 10 minutes of inactivity on a hidden/unfocused tab, and resumes instantly on focus
- Performance: replaced the single-column `idx_is_deleted` index with a composite `(is_deleted, id)` index on the messages table — the composite index already covers every query the old one did, so keeping both only added write overhead. Existing sites are migrated automatically (old index dropped, new one added); new installs get the new index directly
- Performance: added object caching (`wp_cache_*`) to `GET /messages` — polling requests with no new messages now skip the database entirely, and initial page loads share a single cached query across all visitors instead of querying per visitor. Effectiveness depends on your host providing a persistent object cache (see FAQ)
- Fix: `TRUNCATE`-based "delete all messages" and the automatic daily cleanup cron did not invalidate any cache before this release, so admin-side stats/message list and (now) the frontend cache could show stale data after those actions. Both now invalidate all related caches
- Fix: the REST `/admin/moderate` delete action did not invalidate any cache before this release
- Tuned adaptive polling range to 3.5–10s (previously 2–12s) to match documented behavior and reduce request volume
- No changes to REST API response shape used by third-party integrations, except the internal `updated_messages` field (undocumented, poll-only) has been removed

= 1.3.4 – April 7, 2026 =
- Fixed bulk delete and single message delete not working due to stale object cache
- Added `init_plugin_suite_chat_engine_clear_message_cache()` to properly invalidate message list, total count, and stats cache after every delete action
- Fixed nonce variable conflict between single action handler and cleanup handler that could cause cleanup verification to fail silently
- Added Screen Options support for configurable messages per page (default: 20, range: 5–200)
- Per-page preference is saved per user via user meta — does not affect other admins
- Replaced WordPress screen option API with direct POST handler for reliable save behavior
- No changes to database schema, REST API, or existing chat flow

= 1.3.3 – March 30, 2026 =
- Added expand/collapse support for pinned message content in banner
- Allows viewing full pinned message without jumping to original message
- Stores full message content via `dataset` to avoid additional queries or requests
- Updated pinned banner to use absolute positioning to eliminate layout shift (CLS)
- Ensures stable layout when banner appears or updates
- Refined animation and interaction for smoother realtime UX
- No changes to API, database, or existing chat flow

= 1.3.2 – March 30, 2026 =
- Introduced Pin Message feature for chat moderation (admin only)
- Allows pinning a single message to the top of the chat box (auto-replaces previous pin)
- Added hover-based Pin/Unpin button on messages for admins with dynamic state handling
- Implemented pinned message banner with jump-to-message and highlight animation
- Added REST endpoints: POST /pin and DELETE /pin (nonce-verified, admin only)
- `GET /messages` now includes `pinned_message` for real-time sync across clients
- Pin state cached (5 minutes) with immediate invalidation on update
- Uses existing database (`init_chatbox_stats`) — no schema changes required
- Fully translation-ready via `InitChatEngineData.i18n`
- Fully backwards-compatible and does not affect existing chat flow

= 1.3.1 – March 26, 2026 =
- Performance optimization: added object caching for frequently called checks
- `init_plugin_suite_chat_engine_has_messages()` now cached (24 hours) to reduce redundant DB queries during render
- `init_plugin_suite_chat_engine_check_user_banned()` now uses 10-minute cache with multi-key strategy (user ID + IP)
- Implemented proper cache invalidation on ban/unban actions to ensure real-time accuracy
- Added negative caching to eliminate repeated queries for non-banned users
- Internal optimization only — no UI or database changes
- Improves scalability and reduces database load under high traffic chat environments

= 1.3.0 – March 2, 2026 =
- Introduced new **Minimum Account Age Requirement** (Security setting)
- Administrators can now require users to have an account older than **X days** before participating in chat
- Rule only applies when **guest chatting is disabled** (login required mode)
- Fully integrated across backend and frontend:
  - REST `/send` endpoint now enforces account age validation via centralized security check
  - Shared reusable function `init_plugin_suite_chat_engine_check_account_age_requirement()` ensures single source of truth
- Frontend UI enhancement:
  - Chat input is automatically disabled (`pointer-events: none; opacity: 0.6`) when account is too new
  - Context-aware warning message displayed directly under the input area
- No impact on guest mode — public chats remain unaffected when guests are allowed
- Backwards-compatible with existing settings and database schema
- Security-focused enhancement to mitigate spam, clone accounts, and coordinated war activity

View full changelog (all versions): [Init Chat Engine – Changelog](https://en.inithtml.com/plugin/init-chat-engine/)

== License ==

This plugin is licensed under the GPLv2 or later.  
You are free to use, modify, and distribute it under the same license.
