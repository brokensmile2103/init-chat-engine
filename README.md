# Init Chat Engine – Real-Time, Community, Extensible
> A lightweight, real-time chat system for WordPress — built with REST API and Vanilla JS.  
> No jQuery, no reloads, full admin control.

**Not just a chatbox. A true Chat Engine for WordPress.**

[![Version](https://img.shields.io/badge/stable-v1.2.4-blue.svg)](https://wordpress.org/plugins/init-chat-engine/)
[![License](https://img.shields.io/badge/license-GPLv2-blue.svg)](https://www.gnu.org/licenses/gpl-2.0.html)
![Made with ❤️ in HCMC](https://img.shields.io/badge/Made%20with-%E2%9D%A4%EF%B8%8F%20in%20HCMC-blue)

## Overview

Init Chat Engine is a clean and minimal **frontend chatbox plugin** for WordPress,  
designed for community interaction with a complete set of administrative tools.  

Built on REST API + Vanilla JS, it works on any hosting without WebSockets,  
delivering a lightweight yet powerful chat experience.

Perfect for communities, forums, fanpages, SaaS dashboards, support widgets,  
or any WordPress site that needs real-time conversation.

## Features

- **Frontend**
  - Built with REST API and Vanilla JS (no jQuery, no bloat)
  - Guest messaging support (optional)
  - Smart polling system (adaptive 3.5–10s)
  - Browser notifications for new messages
  - Scroll-up to load history, scroll-down to auto-scroll
  - Optimistic sending & “new message” jump button
  - Clean UI with customizable themes
  - Shortcode `[init_chatbox]` for easy embedding
  - Template override supported (`chatbox.php`)

- **Admin & Moderation**
  - Full settings panel (Basic, Security, Advanced)
  - Message management with search & pagination
  - Ban/unban users by IP or user ID (with expiration)
  - Rate limiting (messages per minute)
  - Word filter system with custom blocked words
  - Statistics dashboard with activity charts
  - Cleanup tools for old messages
  - Custom CSS support

- **Security**
  - IP/user-based bans with expiration
  - Configurable spam protection (rate limiting)
  - Word filtering & moderation queue
  - Automatic cleanup of old messages & expired bans

- **Multilingual Ready**
  - Translation-ready with `.pot` file included
  - Vietnamese translation bundled
  - Fully compatible with WordPress i18n

## Installation

1. Upload to `/wp-content/plugins/init-chat-engine`
2. Activate in WordPress admin
3. Go to **Settings → Chat Engine** and configure
4. Insert `[init_chatbox]` shortcode anywhere to display the chatbox
5. Manage messages and users under **Chat Engine → Management**

## License

GPLv2 or later — open source, extensible, developer-first.

## Part of Init Plugin Suite

Init Chat Engine is part of the [Init Plugin Suite](https://en.inithtml.com/init-plugin-suite-minimalist-powerful-and-free-wordpress-plugins/) —  
a collection of blazing-fast, no-bloat plugins made for WordPress developers who care about quality and speed.
