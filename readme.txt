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

= 1.2.9 – February 13, 2026 =
- Overhauled **User Ban System** with timezone-aware logic and optimized detection flow
- Fixed **timezone inconsistency** across ban creation, validation, and display layers
  - `init_plugin_suite_chat_engine_ban_user()`: now uses `DateTime` with WordPress timezone for precise `expires_at` calculation
  - `init_plugin_suite_chat_engine_check_user_banned()`: replaced `NOW()` (GMT) with `current_time('mysql')` for accurate expiration checks
  - `init_plugin_suite_chat_engine_render_banned_message()`: switched to `date_i18n()` to prevent double timezone conversion
- Improved **ban detection priority** — user accounts are now checked first, IP fallback only when user is not banned
  - Prevents trivial IP-based bypass when logged-in users are banned
  - Guest users (no `user_id`) continue to rely on IP-only detection
- Eliminated redundant dual-condition SQL queries (`user_id OR ip_address`) in favor of sequential checks
- Ban expiration timestamps now remain consistent across creation → validation → display (e.g., 48-hour ban created at 8:57 AM expires exactly at 8:57 AM, not 3:56 PM)
- Enhanced WP_DEBUG logging with detailed timestamp comparisons for admin troubleshooting
- Fully backwards-compatible with existing ban records and database schema
- Internal security hardening only; no UI or frontend behavioral changes

= 1.2.8 – November 11, 2025 =
- Hotfix: fixed **Load More (history pagination)** message order becoming inconsistent
  - **API (`before_id`)**: changed `ORDER BY id` from **ASC → DESC**
  - **Frontend (Load More)**: removed `.reverse()`, now directly `prepend()` using API DESC order
  - **Frontend (Initial load)**: unchanged — reverse initial batch then `append()` (old → new)
  - **Frontend (Realtime / after_id)**: unchanged — API returns ASC, frontend `append()`
- Result: timeline in the DOM remains strictly **old → new**
- Minimal change — **no DB schema changes, no UI changes**

= 1.2.7 – November 10, 2025 =
- Fixed **Load More / Pagination** logic returning messages in reversed order
- API now consistently outputs messages in **chronological ASC** order across:
  - Initial load
  - History pagination (`before_id`)
  - Realtime polling (`after_id`)
- No frontend sorting required — FE only append/prepend based on mode
- Ensures smooth timeline continuity when fetching older batches
- Internal change only; does **not affect DB schema or UI behavior**

= 1.2.6 – October 20, 2025 =
- Overhauled **Word Filter Engine** with hardened validation lifecycle
- Default strategy is now **aggressive substring detection** (catches `https://`, domains, encoded text, spam links, etc.)
- Fully respects existing Security settings:
  - `Enable Word Filtering` toggle
  - `Blocked Words` textarea (one per line, supports Unicode)
  - `Word Filter Exceptions` (role-based whitelist: Administrators always bypass)
- Introduced new developer extension hooks (no new UI options):
  - `init_plugin_suite_chat_engine_word_filter_strategy` — switch filter logic (`substring`, `word`, `regex`)
  - `init_plugin_suite_chat_engine_blocked_words` — modify blocked word list programmatically
  - `init_plugin_suite_chat_engine_bypass_filter` — bypass filtering based on custom logic (VIP, IP ranges, etc.)
  - `init_plugin_suite_chat_engine_word_block_hit` — event fired when a blocked word triggers
- Improved unicode normalization and internal cleanup of blocked-word lists (removes empty lines and `#comments`)
- Fully backwards-compatible — no disruption to existing settings or workflows
- Strengthened message security layer; prevents all major spam patterns (URL, Discord invite, Telegram link, obfuscated characters)

= 1.2.5 – October 18, 2025 =  
- Added new **“Delete All Messages”** button under **Quick Actions** in the management panel  
- Feature permanently removes all chat messages from the database with a single click  
- Protected by full security stack: admin-only capability, nonce verification, and SQL transaction safety  
- Resets all chat statistics (`total_messages`, `messages_today`, `active_users_today`, etc.) post-deletion  
- Includes detailed WP_DEBUG logging for admin audit trail (`who`, `when`)  
- UX-consistent with existing “Run Cleanup Now” button — executes instantly without JS dependency  
- Designed as a “nuclear cleanup” option for administrators managing public chat environments  
- No other functional or visual changes; this update focuses solely on administrative maintenance tools  

= 1.2.4 – October 18, 2025 =  
- Rebuilt **FX Keyword Engine** for per-message precision and zero-DOM overhead  
- Replaced global `TreeWalker` scanning with on-demand inline FX application during message render  
- Introduced new internal helpers: `getCompiledFXRules()` and `applyFXInMessageContainer()`  
- Rules are now compiled once and reused, ensuring stable performance even with large message histories  
- Removed redundant functions `initChatboxReplaceFXKeywords()`, `safeReplaceFXKeywordsInDOM()`, and `runFXIfHasMessages()`  
- Eliminated repeated DOM traversals after message batch rendering (initial load, polling, or history fetch)  
- Maintained full compatibility with external `runEffect()` logic and `FX_KEYWORDS` data structure  
- Improved keyword detection accuracy using unified regex alternation with named groups  
- Achieved significant runtime gains — messages now apply FX instantly upon creation  
- Internal optimization only; no visual or behavioral changes for end users  

= 1.2.3 – October 14, 2025 =
- Added safe integration hook for cross-plugin Init FX Engine keyword replacement  
- Chat engine now auto-invokes external DOM keyword highlighter (`replaceFXKeywordsInDOM`) **only when new messages are loaded**  
- Added conditional wrapper with `typeof` check to prevent errors if the external plugin is not active  
- Introduced new internal helper: `safeRunFX()` for async idle execution (avoids blocking UI on message bursts)  
- Implemented new scoped function `initChatboxReplaceFXKeywords()` — optimized DOM scanning limited to `.init-chatbox-text` only  
- Rewrote FX keyword parser with `TreeWalker` for deep text traversal and regex stability  
- Eliminated duplicate link generation and ensured idempotent behavior (no double replacements)  
- Performance improved significantly when many messages are rendered or refreshed in batch  
- Internal enhancement only — no UI changes; improves plugin compatibility and runtime stability

= 1.2.2 – October 7, 2025 =
- **Hotfix Release:** removed redundant ban check inside `[init_chatbox]` shortcode  
- Eliminated secondary `init_plugin_suite_chat_engine_check_user_banned()` call (already handled by shortcode controller)  
- Prevented duplicate banned-message rendering and minor timezone mismatches  
- Simplified shortcode logic for better maintainability and consistency with ban middleware  
- No user-facing behavior change — internal backend cleanup only  

= 1.2.1 – October 7, 2025 =
- Added role-based word filter exceptions in Security settings  
- New UI option: **“Word Filter Exceptions”** allows selecting user roles that can bypass blocked-word restrictions  
- Default exempt role: **Administrator** (others can be toggled via checkboxes)  
- Enhanced backend sanitization with strict role validation against existing WordPress roles  
- Updated message validation logic: users in exempt roles can send blocked words without triggering filter  
- Preserves security for guests and non-exempt roles (still subject to normal word filtering)  
- Improved localization: added Vietnamese translations for all new settings strings  

= 1.2.0 – October 2, 2025 =
- Introduced new filter `init_plugin_suite_chat_engine_enrich_message_row` for extending message rows with custom user metadata  
- Enables themes/plugins to attach extra flags (roles, VIP status, moderation rights, etc.) without touching core logic  
- Improves flexibility and forward-compatibility of the chat engine API, allowing richer integrations and UI features downstream  

= 1.1.9 – October 1, 2025 =
- Added optional support for user profile links in chat messages
- Introduced `profile_url` field in API responses for registered users
- Provided frontend hook (`initChatEngineMessageElementHook`) to linkify display names if desired
- Feature is opt-in only; by default, names remain plain text for backward compatibility

= 1.1.8 – September 13, 2025 =
- Hardened `/send` security for logged-in users: strictly validate `X-WP-Nonce` and block cross-site POST attempts
- Sanitized inputs on the server before storage: apply `wp_strip_all_tags()` to both `message` and `display_name` (logged-in and guest paths)
- Rejected empty or non-visible messages: now fails fast on whitespace-/zero-width-/control-character–only content
- Enforced length limits server-side (pre-insert) to prevent oversized payloads; behavior matches UI constraints
- Kept output defense-in-depth: responses continue to use `wp_kses_post()` for message rendering
- Tightened server-side anti-abuse: permission callback rate-limit check remains authoritative (in addition to any client throttling)

= 1.1.7 – September 13, 2025 =
- Updated URL auto-linking logic to only apply when the link matches the current site domain
- Prevented external or mismatched-domain links from being auto-converted into `<a>` tags
- Reduced risk of spammy or malicious links being injected into formatted content
- Maintained full support for existing markdown-style text formatting features
- Improved overall content safety and formatting reliability

= 1.1.6 – September 1, 2025 =
- Updated codebase to fully comply with WordPress Coding Standards (WPCS)
- Refactored inline documentation and formatting for better readability and maintainability
- Improved code consistency to align with official WordPress best practices
- Minor internal cleanups to enhance long-term stability

= 1.1.5 – August 4, 2025 =
- Enhanced text formatting logic with smarter boundary detection for markdown-style syntax
- Improved formatting rules to require whitespace boundaries OR string start/end positions
- Fixed formatting conflicts in code identifiers (e.g., `init_live_search` no longer formats "live")
- Resolved mathematical expression formatting issues (e.g., `1*2*3 = 6` no longer bolds "2")
- Updated regex patterns to use OR logic: format when either start OR end has whitespace boundary
- Enhanced support for edge cases like `*start* and *end*` now properly formats both words
- Maintained strict content validation: no spaces immediately after opening or before closing markers
- Added comprehensive capture group handling for multiple regex patterns
- Improved formatting accuracy while preserving backward compatibility
- Enhanced user experience with more intuitive and predictable text formatting behavior

= 1.1.4 – August 3, 2025 =
- Added extensible hook system for enhanced message formatting and customization
- Introduced `window.initChatEngineFormatHook` for custom text formatting (supports sticker display and theme extensions)
- Added `window.initChatEngineMessageElementHook` for post-processing message elements after creation
- Enhanced message rendering pipeline to support external plugins and theme customizations
- Improved integration capabilities with Init Manga sticker system and other theme features
- Maintained backward compatibility while providing flexible extension points for developers
- Optimized hook execution with proper error handling to prevent chat interruptions

= 1.1.3 – August 01, 2025 =
- Advanced request management system with AbortController to prevent duplicate API calls
- Real-time timestamp updates: message timestamps now refresh automatically (e.g., "5 minutes" → "6 minutes")
- Enhanced network error handling with exponential backoff and smart retry mechanism
- Improved connection stability for slow/unstable networks with intelligent polling intervals
- Request deduplication system prevents message loading conflicts and UI inconsistencies
- Network status monitoring with automatic reconnection when connection is restored
- Better error recovery with consecutive error tracking and adaptive polling frequency
- Performance optimizations: reduced unnecessary API calls and improved memory management
- Enhanced user experience with clearer loading states and connection status indicators
- Robust offline/online detection with proper fallback handling for network interruptions

= 1.1.2 – July 30, 2025 =
- Complete dark mode system overhaul with comprehensive theme support
- Enhanced CSS variables system with dedicated light/dark theme variable sets
- Full component coverage: dark mode now applies to all elements (messages, inputs, buttons, scrollbars, modals)
- Smart theme detection: auto-detect system dark mode preference with `@media (prefers-color-scheme: dark)`
- Improved color contrast and accessibility with proper contrast ratios for dark theme
- Smooth theme transitions with 0.3s transition animations when switching between themes

= 1.1.1 – July 29, 2025 =
- Resolved infinite scroll loop bug causing chat interface crashes
- Fixed load more button toggle conflicts in middle scroll positions (50-200px from top)
- Implemented debounced scroll handling with 100ms stabilization timer
- Added scroll direction tracking to prevent unnecessary auto-load triggers
- Improved scroll zone boundaries: auto-load (<30px), manual button (30-200px), hide (>300px)
- Enhanced state management to prevent redundant UI updates and layout thrashing
- Added proper timer cleanup on page unload to prevent memory leaks
- Optimized scroll performance with passive event listeners and reduced DOM queries
- Fixed scroll button visibility logic to prevent flickering during rapid scrolling
- Strengthened error handling for edge cases in scroll position calculations
- Added support for utility classes (uk-hidden, hidden, .ice-hidden)
- Automatic CSS framework detection and appropriate hide/show class usage
- Fixed character counter not working for guest users due to duplicate ID names
- Improved "Load more" button display logic to be more generous but still safe (expanded zone to 400px)
- Faster UI response time: reduced debounce to 80ms, auto-load delay to 150ms

= 1.1.0 – July 29, 2025 =
- Major admin panel upgrade with tabbed interface (Basic, Security, Advanced)
- Added full message management system with search and pagination
- Introduced user ban/unban system with support for IP and user restrictions
- Built statistics dashboard with activity charts and engagement metrics
- Implemented rate limiting control (messages per minute) to prevent spam
- Added word filter system with custom blocked word list
- Included auto/manual cleanup tools for old messages
- Redesigned admin UI with professional styling and responsive support
- Added connection status indicators and improved error handling
- Introduced REST API endpoints for admin moderation actions
- Implemented caching and nonce verification for all admin operations
- Added full settings validation and sanitization
- Full i18n support with .pot file and Vietnamese translation included

= 1.0.3 – July 20, 2025 =
- Added support for inline message formatting: `*bold*`, `_highlight_`, `~strike~`, `^mark^`, and `italic`
- Reused highlight style `.init-fx-highlight-text` from Init FX Engine (no duplicated CSS)
- Improved message rendering with safe HTML output
- Removed redundant `escapeHTML()` call to enable formatting
- Minor refactor of formatting logic

= 1.0.2 – July 19, 2025 =
- Added user avatar rendering with fallback support
- Introduced blinking document title when new messages arrive
- Added anti-spam cooldown and click-lock on send button
- Refined guest name workflow and improved avatar integration
- Enhanced message HTML structure and cleaned up code

= 1.0.1 – July 19, 2025 =
- Implemented smart polling system with adaptive intervals
- Added browser notification API support for new messages
- Improved scroll behavior and scroll-to-bottom logic
- Enhanced typing state detection and guest name handling
- Fixed message prepending offset issue and made UI tweaks

= 1.0.0 – July 18, 2025 =
- Initial release
- Core chat functionality using REST API (no WebSocket)
- Guest messaging support with basic identity system
- Admin settings for message limits and guest permissions
- Shortcode support with template override
- Scrollable history with smooth auto-scroll
- Optimistic message sending with fallback retry

== License ==

This plugin is licensed under the GPLv2 or later.  
You are free to use, modify, and distribute it under the same license.
