=== Init Chat Engine – Real-Time, Community, Extensible ===
Contributors: brokensmile.2103  
Tags: chat, community, realtime, shortcode, lightweight
Requires at least: 5.5  
Tested up to: 6.8  
Requires PHP: 7.4  
Stable tag: 1.1.6
License: GPLv2 or later  
License URI: https://www.gnu.org/licenses/gpl-2.0.html  

A lightweight, real-time community chat system built with REST API and Vanilla JS. No jQuery, no reload. Full admin panel with moderation tools.

== Description ==

Init Chat Engine is a clean and minimal frontend chatbox plugin, designed for homepage or site-wide communication with comprehensive administrative controls.

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

`[init_chatbox]` supports the following attributes:

- `height` - Set chat height (e.g., `height="400px"`)
- `width` - Set chat width (e.g., `width="100%"`)
- `theme` - Apply custom theme (e.g., `theme="dark"`)
- `show_avatars` - Override avatar setting (`true`/`false`)
- `show_timestamps` - Override timestamp setting (`true`/`false`)
- `title` - Add custom chat title
- `class` - Add custom CSS classes
- `id` - Set custom container ID

Example: `[init_chatbox height="500px" title="Community Chat" theme="modern"]`

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
