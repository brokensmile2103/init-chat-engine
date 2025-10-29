=== Init Chat Engine – Real-Time, Community, Extensible ===
Contributors: brokensmile.2103
Tags: chat, community, realtime, shortcode, lightweight
Requires at least: 5.5
Tested up to: 6.9
Requires PHP: 7.4
Stable tag: 1.2.6
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
- Guest messaging support (optional)
- Smart polling system (adaptive 3.5–10s based on activity)
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

Example: `[init_chatbox height="500px" title="Community Chat" theme="modern"]`

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

= How do I backup chat data? =  
Chat messages are stored in your WordPress database in the `wp_init_chatbox_msgs` table. Use any WordPress backup plugin or database backup tool.

== Changelog ==

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
