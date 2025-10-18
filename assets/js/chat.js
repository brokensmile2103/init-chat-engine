document.addEventListener('DOMContentLoaded', function () {
    const root = document.getElementById('init-chatbox-root');
    if (!root || typeof InitChatEngineData === 'undefined') return;

    // DOM elements
    const messagesEl = document.getElementById('init-chatbox-messages');
    const messagesListEl = document.getElementById('init-chatbox-messages-list');
    const loadingEl = document.getElementById('init-chatbox-loading');
    const formEl = document.getElementById('init-chatbox-form');
    const inputMsg = document.getElementById('init-chatbox-message');
    const inputName = document.getElementById('init-chatbox-guest-name');
    const btnSetName = document.getElementById('init-chatbox-set-name');
    const currentNameBox = document.getElementById('init-chatbox-current-display');
    const btnChangeName = document.getElementById('init-chatbox-change-name');
    const formNameBlock = document.getElementById('init-chatbox-name-form');
    const activeBlock = document.getElementById('init-chatbox-active');
    const loadMoreBtn = document.getElementById('init-chatbox-load-more-btn');
    const loadMoreEl = document.getElementById('init-chatbox-load-more');
    const errorEl = document.getElementById('init-chatbox-error');
    const errorTextEl = document.getElementById('init-chatbox-error-text');
    const errorCloseEl = document.getElementById('init-chatbox-error-close');
    const connectionStatusEl = document.getElementById('init-chatbox-connection-status');
    const charCountEl = document.getElementById('init-chatbox-char-count');
    const charCountGuestEl = document.getElementById('init-chatbox-char-count-guest');
    const rateLimitEl = document.getElementById('init-chatbox-rate-limit');
    const rateLimitGuestEl = document.getElementById('init-chatbox-rate-limit-guest');

    // Configuration from localized data
    const config = {
        sendUrl: InitChatEngineData.send_url,
        fetchUrl: InitChatEngineData.rest_url,
        allowGuests: InitChatEngineData.allow_guests,
        currentUser: InitChatEngineData.current_user,
        showAvatars: InitChatEngineData.show_avatars,
        showTimestamps: InitChatEngineData.show_timestamps,
        enableNotifications: InitChatEngineData.enable_notifications,
        enableSounds: InitChatEngineData.enable_sounds,
        maxMessageLength: InitChatEngineData.max_message_length || 500,
        rateLimit: InitChatEngineData.rate_limit || 10,
        i18n: InitChatEngineData.i18n || {}
    };

    // State management
    let state = {
        guestName: localStorage.getItem('init_chatbox_guest_name') || '',
        lastMessageId: 0,
        firstMessageId: null,
        isLoadingHistory: false,
        hasMoreHistory: true,
        isInitialLoading: true,
        userScrolledUp: false,
        lastScrollTop: 0,
        scrollThreshold: 50,
        loadHistoryDelay: 200,
        isSending: false,
        lastSendTime: 0,
        sendCooldown: 500,
        connectionLost: false,
        loadMoreButtonVisible: false,
        lastScrollDirection: 'down',
        scrollStabilityTimer: null,
        // NEW: Request management
        pendingRequests: new Map(), // Track ongoing requests
        requestQueue: [], // Queue for requests when network is slow
        isProcessingQueue: false,
        retryCount: 0,
        maxRetries: 3,
        retryDelay: 1000,
        // NEW: Message cache for timestamp updates
        messageCache: new Map() // messageId -> message data
    };

    // Enhanced polling system with better error handling
    let polling = {
        interval: 2000,
        minInterval: 2000,
        maxInterval: 12000, // Increased max interval for poor connections
        timer: null,
        isWindowFocused: true,
        isInputFocused: false,
        consecutiveEmptyFetches: 0,
        consecutiveErrors: 0, // NEW: Track consecutive errors
        lastActivity: Date.now(),
        lastMessageTime: Date.now(),
        backoffMultiplier: 1.5, // NEW: Exponential backoff
        isOnline: navigator.onLine // NEW: Online status
    };

    // Title notification system
    let titleNotification = {
        originalTitle: document.title,
        blinkTimer: null,
        blinkState: false
    };

    // ===== REQUEST MANAGEMENT =====

    function generateRequestId() {
        return `req_${Date.now()}_${Math.random().toString(36).substr(2, 9)}`;
    }

    function abortPendingRequests(type = 'fetch') {
        for (const [id, request] of state.pendingRequests) {
            if (request.type === type) {
                if (request.controller) {
                    request.controller.abort();
                }
                state.pendingRequests.delete(id);
            }
        }
    }

    function createManagedRequest(url, options = {}, type = 'fetch') {
        const requestId = generateRequestId();
        const controller = new AbortController();
        
        const requestData = {
            id: requestId,
            type: type,
            controller: controller,
            timestamp: Date.now(),
            url: url
        };
        
        state.pendingRequests.set(requestId, requestData);
        
        // Add abort signal to options
        options.signal = controller.signal;
        
        const requestPromise = fetch(url, options)
            .then(response => {
                state.pendingRequests.delete(requestId);
                return response;
            })
            .catch(error => {
                state.pendingRequests.delete(requestId);
                if (error.name === 'AbortError') {
                    console.debug('Request aborted:', requestId);
                    return Promise.reject(new Error('Request aborted'));
                }
                throw error;
            });
            
        return { requestId, promise: requestPromise, controller };
    }

    // ===== UTILITY FUNCTIONS =====

    // ===== FX KEYWORD (PER-MESSAGE) =====
    function escapeRegExp(str) {
        return str.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
    }

    let FX_RULES_COMPILED = null;
    function getCompiledFXRules() {
        if (FX_RULES_COMPILED) return FX_RULES_COMPILED;
        if (typeof FX_KEYWORDS !== 'object') return (FX_RULES_COMPILED = { list: [], re: null });
        
        const list = [];
        Object.entries(FX_KEYWORDS).forEach(([effect, entries]) => {
            entries.forEach(({ keyword, emoji }) => {
                if (!keyword) return;
                list.push({
                    effect,
                    emoji: emoji || null,
                    keyword,
                    pattern: `\\b${escapeRegExp(keyword)}\\b`
                });
            });
        });
        if (!list.length) return (FX_RULES_COMPILED = { list: [], re: null });
        
        const parts = list.map((r, i) => `(?<K${i}>${r.pattern})`);
        const re = new RegExp(parts.join('|'), 'gi');
        return (FX_RULES_COMPILED = { list, re });
    }

    function applyFXInMessageContainer(container) {
        const { list, re } = getCompiledFXRules();
        if (!re || !container) return;
        
        const walker = document.createTreeWalker(
            container,
            NodeFilter.SHOW_TEXT,
            {
                acceptNode(node) {
                    const p = node.parentElement;
                    if (!p) return NodeFilter.FILTER_REJECT;
                    if (p.closest('script,style,a.fx-keyword')) return NodeFilter.FILTER_REJECT;
                    if (!node.nodeValue || !node.nodeValue.trim()) return NodeFilter.FILTER_REJECT;
                    return NodeFilter.FILTER_ACCEPT;
                }
            }
        );
        
        const textNodes = [];
        while (walker.nextNode()) textNodes.push(walker.currentNode);
        
        textNodes.forEach((textNode) => {
            const text = textNode.nodeValue;
            re.lastIndex = 0;
            if (!re.test(text)) return;
            re.lastIndex = 0;
            
            const frag = document.createDocumentFragment();
            let lastIndex = 0;
            let m;
            while ((m = re.exec(text)) !== null) {
                const start = m.index;
                const end = re.lastIndex;
                if (start > lastIndex) {
                    frag.appendChild(document.createTextNode(text.slice(lastIndex, start)));
                }
                let ruleIndex = -1;
                for (let i = 0; i < list.length; i++) {
                    if (m.groups && m.groups[`K${i}`]) { ruleIndex = i; break; }
                }
                const rule = list[ruleIndex];
                const a = document.createElement('a');
                a.href = '#';
                a.className = 'fx-keyword';
                a.dataset.effect = rule.effect;
                if (rule.emoji) a.dataset.emoji = rule.emoji;
                a.textContent = text.slice(start, end);
                a.addEventListener('click', (e) => {
                    e.preventDefault();
                    try { runEffect(rule.effect, rule.emoji); } catch {}
                });
                frag.appendChild(a);
                lastIndex = end;
            }
            if (lastIndex < text.length) {
                frag.appendChild(document.createTextNode(text.slice(lastIndex)));
            }
            textNode.parentNode.replaceChild(frag, textNode);
        });
    }

    function escapeHTML(str) {
        if (!str) return '';
        const div = document.createElement('div');
        div.textContent = str;
        return div.innerHTML;
    }

    // Enhanced show/hide functions with class support
    function hideElement(element) {
        if (!element) return;
        
        if (element.classList.contains('uk-hidden') || 
            element.classList.contains('hidden') || 
            element.classList.contains('ice-hidden')) {
            return;
        }
        
        const testDiv = document.createElement('div');
        document.body.appendChild(testDiv);
        
        testDiv.className = 'uk-hidden';
        const ukHiddenWorks = window.getComputedStyle(testDiv).display === 'none';
        
        testDiv.className = 'hidden';
        const hiddenWorks = window.getComputedStyle(testDiv).display === 'none';
        
        testDiv.className = 'ice-hidden';
        const iceHiddenWorks = window.getComputedStyle(testDiv).display === 'none';
        
        document.body.removeChild(testDiv);
        
        if (ukHiddenWorks) {
            element.classList.add('uk-hidden');
        } else if (hiddenWorks) {
            element.classList.add('hidden');
        } else if (iceHiddenWorks) {
            element.classList.add('ice-hidden');
        } else {
            element.style.display = 'none';
        }
    }
    
    function showElement(element) {
        if (!element) return;
        element.classList.remove('uk-hidden', 'hidden', 'ice-hidden');
        if (element.style.display === 'none') {
            element.style.display = '';
        }
    }

    function showError(message, autoDismiss = true) {
        if (!errorEl || !errorTextEl) return;
        errorTextEl.textContent = message;
        showElement(errorEl);
        
        if (autoDismiss) {
            setTimeout(() => hideError(), 5000); // Increased to 5s for better UX
        }
    }

    function hideError() {
        hideElement(errorEl);
    }

    function updateConnectionStatus(status, message) {
        if (!connectionStatusEl) return;
        
        connectionStatusEl.className = `init-chatbox-connection-status ${status}`;
        connectionStatusEl.querySelector('.init-chatbox-connection-text').textContent = message;
        
        if (status === 'connected') {
            showElement(connectionStatusEl);
            setTimeout(() => {
                hideElement(connectionStatusEl);
            }, 2000); // Slightly longer display
        } else {
            showElement(connectionStatusEl);
        }
    }

    function updateCharCount() {
        if (!inputMsg) return;
        
        const activeCharCountEl = charCountEl || charCountGuestEl;
        if (!activeCharCountEl) return;
        
        const current = inputMsg.value.length;
        const max = config.maxMessageLength;
        const currentEl = activeCharCountEl.querySelector('.init-chatbox-char-current');
        
        if (currentEl) {
            currentEl.textContent = current;
            
            activeCharCountEl.classList.remove('warning', 'danger');
            if (current > max * 0.9) {
                activeCharCountEl.classList.add('danger');
            } else if (current > max * 0.8) {
                activeCharCountEl.classList.add('warning');
            }
        }
    }

    function recordActivity() {
        polling.lastActivity = Date.now();
        updatePollingInterval();
    }

    // ===== AVATAR FUNCTIONS =====

    function getFallbackAvatar(displayName) {
        if (!displayName) return '';
        
        const firstLetter = displayName.charAt(0).toUpperCase();
        const colors = [
            '#FF6B6B', '#4ECDC4', '#45B7D1', '#FFA07A', 
            '#98D8C8', '#F7DC6F', '#BB8FCE', '#85C1E9',
            '#F8C471', '#82E0AA', '#F1948A', '#85C1E9'
        ];
        const colorIndex = displayName.charCodeAt(0) % colors.length;
        const bgColor = colors[colorIndex];
        
        return `
            <div class="init-chatbox-avatar-fallback" style="
                width: 32px; 
                height: 32px; 
                border-radius: 50%; 
                background: ${bgColor}; 
                color: white; 
                display: flex; 
                align-items: center; 
                justify-content: center; 
                font-weight: bold; 
                font-size: 14px;
                flex-shrink: 0;
                margin-right: 8px;
                border: 2px solid white;
                box-shadow: 0 2px 4px rgba(0,0,0,0.1);
            ">
                ${firstLetter}
            </div>
        `;
    }

    function createAvatarHTML(msg) {
        if (!config.showAvatars) return '';
        
        if (msg.avatar_url && msg.avatar_url.trim()) {
            return `
                <img class="init-chatbox-avatar" 
                     src="${escapeHTML(msg.avatar_url)}" 
                     alt="${escapeHTML(msg.display_name)}"
                     loading="lazy"
                />
            `;
        } else {
            return getFallbackAvatar(msg.display_name);
        }
    }

    // ===== MESSAGE FORMATTING =====

    function formatMessageText(text) {
        // ===== Emoji-enlarge marker from server: |z ... z|
        let emojiBig = false;
        if (typeof text === 'string' && text.startsWith('|z') && text.endsWith('z|')) {
            emojiBig = true;
            text = text.slice(2, -2);
        }

        // Lấy domain hiện tại (bỏ www.)
        const currentHost = (typeof location !== 'undefined' ? location.hostname : '')
            .replace(/^www\./i, '');

        const isSameSite = (url) => {
            try {
                const u = new URL(url);
                const host = u.hostname.replace(/^www\./i, '');
                // nếu muốn cho phép subdomain, đổi thành:
                // return host === currentHost || host.endsWith('.' + currentHost);
                return host === currentHost;
            } catch {
                return false;
            }
        };

        const rules = [
            { 
                // *text* - bold (1 trong 2 đầu có khoảng trắng, không có space sát dấu)
                regex: /((?<=^|\s)\*([^\s*][^*]*?[^\s*]|\S)\*)|(\*([^\s*][^*]*?[^\s*]|\S)\*(?=\s|$))/g, 
                tag: 'strong',
                captureGroup: [2, 4]
            },
            { 
                // `em` - italic
                regex: /((?<=^|\s)`([^\s`][^`]*?[^\s`]|\S)`)|(`([^\s`][^`]*?[^\s`]|\S)`(?=\s|$))/g, 
                tag: 'em',
                captureGroup: [2, 4]
            },
            { 
                // ~text~ - del
                regex: /((?<=^|\s)~([^\s~][^~]*?[^\s~]|\S)~)|(~([^\s~][^~]*?[^\s~]|\S)~(?=\s|$))/g, 
                tag: 'del',
                captureGroup: [2, 4]
            },
            { 
                // ^text^ - mark
                regex: /((?<=^|\s)\^([^\s^][^^]*?[^\s^]|\S)\^)|(\^([^\s^][^^]*?[^\s^]|\S)\^(?=\s|$))/g, 
                tag: 'mark',
                captureGroup: [2, 4]
            },
            { 
                // _text_ - custom highlight
                regex: /((?<=^|\s)_([^\s_][^_]*?[^\s_]|\S)_)|(_([^\s_][^_]*?[^\s_]|\S)_(?=\s|$))/g, 
                tag: 'span', 
                className: 'init-fx-highlight-text',
                captureGroup: [2, 4]
            },
            { 
                // URLs (chỉ link nếu cùng domain)
                regex: /(https?:\/\/[^\s]+)/g, 
                tag: 'a', 
                attr: 'href="$1" target="_blank" rel="noopener"',
                attrGroup: 1,
                domainRestrict: true
            }
        ];

        let result = '';
        let cursor = 0;

        while (cursor < text.length) {
            let earliest = null;
            let matchedRule = null;

            for (const rule of rules) {
                rule.regex.lastIndex = cursor;
                const match = rule.regex.exec(text);
                if (match && (!earliest || match.index < earliest.index)) {
                    earliest = match;
                    matchedRule = rule;
                }
            }

            if (!earliest) {
                result += escapeHTML(text.slice(cursor));
                break;
            }

            result += escapeHTML(text.slice(cursor, earliest.index));

            // Xử lý riêng cho URL: nếu khác domain, giữ nguyên text, không bọc <a>
            if (matchedRule.tag === 'a' && matchedRule.domainRestrict) {
                const urlStr = earliest[matchedRule.attrGroup || 0] || earliest[0];
                if (!isSameSite(urlStr)) {
                    // chỉ thêm text đã escape, không link
                    result += escapeHTML(earliest[0]);
                    cursor = earliest.index + earliest[0].length;
                    continue;
                }
            }

            let inner;
            if (matchedRule.captureGroup) {
                inner = escapeHTML(
                    earliest[matchedRule.captureGroup[0]] || earliest[matchedRule.captureGroup[1]]
                );
            } else {
                // nếu không chỉ định, dùng group 1 nếu có, ngược lại lấy toàn match
                inner = escapeHTML(earliest[1] || earliest[0]);
            }

            const tag = matchedRule.tag;
            const classAttr = matchedRule.className ? ` class="${matchedRule.className}"` : '';

            let otherAttr = '';
            if (matchedRule.attr) {
                const grp = matchedRule.attrGroup || 0; // mặc định 0 = toàn match
                const repl = escapeHTML(earliest[grp] || earliest[0]);
                otherAttr = ' ' + matchedRule.attr.replace('$1', repl);
            }

            result += `<${tag}${classAttr}${otherAttr}>${inner}</${tag}>`;
            cursor = earliest.index + earliest[0].length;
        }

        injectHighlightStyleIfNeeded?.();

        // ===== HOOK: cho sticker/custom formatting
        if (typeof window.initChatEngineFormatHook === 'function') {
            result = window.initChatEngineFormatHook(result, text);
        }

        // ===== Emoji enlarge on client (server marker ưu tiên)
        if (emojiBig && !/<img\b/i.test(result)) {
            result = `<span class="uk-emoji-xlarge">${result}</span>`;
        }

        return result;
    }

    function injectHighlightStyleIfNeeded() {
        if (!document.getElementById('fx-highlight-style')) {
            const style = document.createElement('style');
            style.id = 'fx-highlight-style';
            style.textContent = `
                .init-fx-highlight-text {
                    background-image: linear-gradient(120deg, rgba(156, 255, 0, 0.7) 0, rgba(156, 255, 0, 0.7) 100%);
                    background-repeat: no-repeat;
                    background-size: 100% 0.5em;
                    background-position: 0 100%;
                }
            `;
            document.head.appendChild(style);
        }
    }

    // ===== SCROLL MANAGEMENT =====

    function isAtBottom() {
        if (!messagesEl) return true;
        const threshold = state.scrollThreshold;
        return messagesEl.scrollTop + messagesEl.clientHeight >= messagesEl.scrollHeight - threshold;
    }

    function scrollToBottom(smooth = false) {
        if (!messagesEl) return;
        
        if (smooth) {
            messagesEl.scrollTo({
                top: messagesEl.scrollHeight,
                behavior: 'smooth'
            });
        } else {
            messagesEl.scrollTop = messagesEl.scrollHeight;
        }
        state.userScrolledUp = false;
    }

    function updateLoadMoreButton(shouldShow) {
        if (!loadMoreEl || state.loadMoreButtonVisible === shouldShow) {
            return;
        }
        
        state.loadMoreButtonVisible = shouldShow;
        
        if (shouldShow) {
            showElement(loadMoreEl);
        } else {
            hideElement(loadMoreEl);
        }
    }

    // ===== POLLING SYSTEM - OPTIMIZED WITH ERROR HANDLING =====

    function calculateOptimalInterval() {
        const now = Date.now();
        const timeSinceLastActivity = now - polling.lastActivity;
        const timeSinceLastMessage = now - polling.lastMessageTime;
        
        let newInterval = polling.minInterval;

        // Apply exponential backoff on consecutive errors
        if (polling.consecutiveErrors > 0) {
            const backoffFactor = Math.pow(polling.backoffMultiplier, Math.min(polling.consecutiveErrors, 5));
            newInterval = Math.min(polling.maxInterval, polling.minInterval * backoffFactor);
        }

        // Base logic: User engagement (only if no errors)
        if (polling.consecutiveErrors === 0) {
            if (polling.isInputFocused) {
                newInterval = polling.minInterval;
            } else if (polling.isWindowFocused && timeSinceLastActivity < 30000) {
                newInterval = polling.minInterval + 500;
            } else if (polling.isWindowFocused) {
                newInterval = polling.minInterval + 1000;
            } else {
                const backgroundTime = Math.min(timeSinceLastActivity, 300000);
                newInterval = polling.minInterval + (backgroundTime / 60000) * 1000;
            }

            // Adjust based on message activity
            if (timeSinceLastMessage < 60000) {
                newInterval = Math.min(newInterval, polling.minInterval + 500);
            } else if (timeSinceLastMessage > 300000) {
                newInterval += 1000;
            }

            // Adjust based on empty fetch results
            if (polling.consecutiveEmptyFetches > 0) {
                newInterval += polling.consecutiveEmptyFetches * 300;
            }
        }

        // Network status adjustment
        if (!polling.isOnline) {
            newInterval = polling.maxInterval;
        }

        return Math.max(polling.minInterval, Math.min(polling.maxInterval, Math.round(newInterval)));
    }

    function updatePollingInterval() {
        const newInterval = calculateOptimalInterval();
        
        if (newInterval !== polling.interval) {
            polling.interval = newInterval;
            
            if (polling.timer) {
                clearInterval(polling.timer);
            }
            startPolling();
        }
    }

    function startPolling() {
        if (polling.timer) clearInterval(polling.timer);
        polling.timer = setInterval(fetchNewMessages, polling.interval);
    }

    // ===== TITLE NOTIFICATIONS =====

    function startTitleBlink() {
        if (titleNotification.blinkTimer || polling.isWindowFocused) return;
        
        titleNotification.blinkTimer = setInterval(() => {
            titleNotification.blinkState = !titleNotification.blinkState;
            document.title = titleNotification.blinkState 
                ? (config.i18n.new_message_title || 'New message in the chat!') 
                : titleNotification.originalTitle;
        }, 2000);
    }
    
    function stopTitleBlink() {
        if (titleNotification.blinkTimer) {
            clearInterval(titleNotification.blinkTimer);
            titleNotification.blinkTimer = null;
            titleNotification.blinkState = false;
            document.title = titleNotification.originalTitle;
        }
    }

    // ===== MESSAGE FUNCTIONS WITH TIMESTAMP UPDATES =====

    function createMessageElement(msg, isCurrentUser = false) {
        const div = document.createElement('div');
        div.className = `init-chatbox-message ${isCurrentUser ? 'current-user' : ''} ${msg.user_type || 'guest'}-user`;
        div.dataset.messageId = msg.id;

        const avatarHTML = createAvatarHTML(msg);
        const displayName = `<span class="init-chatbox-author">${escapeHTML(msg.display_name)}</span>`;
        const timestamp = config.showTimestamps ? 
            `<span class="init-chatbox-meta-time" data-timestamp="${msg.created_at}">${escapeHTML(msg.created_at_human || msg.created_at)}</span>` : '';
        const messageText = `<div class="init-chatbox-text">${formatMessageText(msg.message)}</div>`;

        div.innerHTML = `
            <div class="init-chatbox-message-header">
                <div class="init-chatbox-meta">
                    ${avatarHTML}
                    <div class="init-chatbox-meta-content">
                        <div class="init-chatbox-meta-name">${displayName}</div>
                        ${timestamp}
                    </div>
                </div>
            </div>
            <div class="init-chatbox-message-body">
                ${messageText}
            </div>
        `;

        const textContainer = div.querySelector('.init-chatbox-text');
        if (textContainer) {
            applyFXInMessageContainer(textContainer);
        }

        // ===== THÊM HOOK TẠI ĐÂY =====
        // Hook để theme có thể xử lý message element sau khi tạo
        if (typeof window.initChatEngineMessageElementHook === 'function') {
            window.initChatEngineMessageElementHook(div, msg, isCurrentUser);
        }

        return div;
    }

    // NEW: Update existing message timestamps
    function updateMessageTimestamps(messages) {
        if (!config.showTimestamps || !messages || messages.length === 0) return;
        
        let updatedCount = 0;
        messages.forEach(msg => {
            // Update cache
            const cachedMsg = state.messageCache.get(msg.id);
            if (cachedMsg && cachedMsg.created_at_human !== msg.created_at_human) {
                state.messageCache.set(msg.id, msg);
                
                // Find existing message element and update it
                const existingElement = messagesListEl.querySelector(`[data-message-id="${msg.id}"]`);
                if (existingElement) {
                    const timestampEl = existingElement.querySelector('.init-chatbox-meta-time');
                    if (timestampEl && msg.created_at_human) {
                        timestampEl.textContent = msg.created_at_human;
                        timestampEl.setAttribute('data-timestamp', msg.created_at);
                        updatedCount++;
                    }
                }
            } else if (!cachedMsg) {
                // New message, add to cache
                state.messageCache.set(msg.id, msg);
            }
        });
        
        if (updatedCount > 0) {
            console.debug(`Updated ${updatedCount} message timestamps`);
        }
    }

    function appendMessage(msg, shouldScroll = true) {
        if (!messagesListEl) return;

        // Check if message already exists
        const existingMessage = messagesListEl.querySelector(`[data-message-id="${msg.id}"]`);
        if (existingMessage) {
            // Update existing message instead of duplicating
            updateMessageTimestamps([msg]);
            return;
        }

        const isCurrentUser = msg.is_current_user || 
            (config.currentUser && msg.display_name === config.currentUser) ||
            (!config.currentUser && msg.display_name === state.guestName);
            
        const messageEl = createMessageElement(msg, isCurrentUser);
        messagesListEl.appendChild(messageEl);

        // Update cache
        state.messageCache.set(msg.id, msg);

        const id = parseInt(msg.id);
        state.lastMessageId = Math.max(state.lastMessageId, id);
        if (state.firstMessageId === null || id < state.firstMessageId) {
            state.firstMessageId = id;
        }

        if (loadingEl) {
            hideElement(loadingEl);
        }

        if (shouldScroll && (!state.userScrolledUp || isAtBottom())) {
            scrollToBottom(true);
        }

        if (config.enableSounds && !isCurrentUser && !polling.isWindowFocused) {
            playNotificationSound();
        }
    }

    function prependMessage(msg) {
        if (!messagesListEl) return;

        // Check if message already exists
        const existingMessage = messagesListEl.querySelector(`[data-message-id="${msg.id}"]`);
        if (existingMessage) {
            return;
        }

        const isCurrentUser = msg.is_current_user || 
            (config.currentUser && msg.display_name === config.currentUser) ||
            (!config.currentUser && msg.display_name === state.guestName);
            
        const messageEl = createMessageElement(msg, isCurrentUser);
        messagesListEl.insertBefore(messageEl, messagesListEl.firstChild);

        // Update cache
        state.messageCache.set(msg.id, msg);

        const id = parseInt(msg.id);
        if (state.firstMessageId === null || id < state.firstMessageId) {
            state.firstMessageId = id;
        }
    }

    // ===== NOTIFICATION FUNCTIONS =====

    function playNotificationSound() {
        try {
            const audioContext = new (window.AudioContext || window.webkitAudioContext)();
            const oscillator = audioContext.createOscillator();
            const gainNode = audioContext.createGain();
            
            oscillator.connect(gainNode);
            gainNode.connect(audioContext.destination);
            
            oscillator.frequency.value = 800;
            oscillator.type = 'sine';
            
            gainNode.gain.setValueAtTime(0.1, audioContext.currentTime);
            gainNode.gain.exponentialRampToValueAtTime(0.01, audioContext.currentTime + 0.1);
            
            oscillator.start(audioContext.currentTime);
            oscillator.stop(audioContext.currentTime + 0.1);
        } catch (error) {
            console.debug('Could not play notification sound:', error);
        }
    }

    function showBrowserNotification(title, body, icon) {
        if (!config.enableNotifications || !('Notification' in window) || Notification.permission !== 'granted') {
            return;
        }

        try {
            const notification = new Notification(title, {
                body: body,
                icon: icon || InitChatEngineData.favicon,
                badge: InitChatEngineData.favicon,
                tag: 'init-chat-engine',
                renotify: true
            });

            notification.onclick = function() {
                window.focus();
                notification.close();
            };

            setTimeout(() => notification.close(), 5000);
        } catch (error) {
            console.debug('Could not show notification:', error);
        }
    }

    // ===== ENHANCED FETCH FUNCTIONS WITH REQUEST MANAGEMENT =====

    function fetchNewMessages() {
        // Prevent multiple concurrent fetch requests
        if (state.pendingRequests.size > 0) {
            console.debug('Skipping fetch - request already in progress');
            return;
        }

        const url = `${config.fetchUrl}?after_id=${state.lastMessageId}`;
        const { promise } = createManagedRequest(url, {}, 'fetch');
        
        promise
            .then(response => {
                if (!response.ok) {
                    throw new Error(`HTTP ${response.status}`);
                }
                return response.json();
            })
            .then(data => {
                // Reset error counters on success
                polling.consecutiveErrors = 0;
                state.retryCount = 0;

                // Handle connection restoration
                if (state.connectionLost) {
                    state.connectionLost = false;
                    updateConnectionStatus('connected', config.i18n.connected || 'Connected');
                }

                if (data.success && data.messages && data.messages.length > 0) {
                    polling.consecutiveEmptyFetches = 0;
                    polling.lastMessageTime = Date.now();
                    
                    const wasAtBottom = isAtBottom();
                    
                    // First, try to update timestamps for ALL visible messages
                    // This requires the API to return timestamp updates for existing messages
                    if (data.updated_messages && data.updated_messages.length > 0) {
                        updateMessageTimestamps(data.updated_messages);
                    }
                    
                    // Then append new messages
                    data.messages.forEach(msg => {
                        appendMessage(msg, false);
                    });
                    
                    if (wasAtBottom || !state.userScrolledUp) {
                        scrollToBottom(true);
                    }
                    
                    // Show notification if window is not focused
                    if (!polling.isWindowFocused) {
                        startTitleBlink();
                        
                        const lastMessage = data.messages[data.messages.length - 1];
                        showBrowserNotification(
                            config.i18n.new_message_title || 'New message',
                            `${lastMessage.display_name}: ${lastMessage.message.substring(0, 50)}${lastMessage.message.length > 50 ? '...' : ''}`,
                            lastMessage.avatar_url
                        );
                    }
                } else {
                    polling.consecutiveEmptyFetches++;
                    
                    // Even when no new messages, check for timestamp updates
                    if (data.success && data.updated_messages && data.updated_messages.length > 0) {
                        updateMessageTimestamps(data.updated_messages);
                    }
                }
                
                updatePollingInterval();
            })
            .catch(error => {
                console.error('Fetch error:', error);
                
                if (error.message !== 'Request aborted') {
                    polling.consecutiveErrors++;
                    polling.consecutiveEmptyFetches++;
                    
                    // Show connection error only after multiple failures
                    if (polling.consecutiveErrors >= 3 && !state.connectionLost) {
                        state.connectionLost = true;
                        updateConnectionStatus('reconnecting', config.i18n.connection_lost || 'Connection lost. Trying to reconnect...');
                    }
                    
                    // Implement retry with exponential backoff
                    if (state.retryCount < state.maxRetries) {
                        state.retryCount++;
                        const retryDelay = state.retryDelay * Math.pow(2, state.retryCount - 1);
                        setTimeout(() => {
                            if (polling.consecutiveErrors > 0) { // Only retry if still having errors
                                fetchNewMessages();
                            }
                        }, retryDelay);
                    }
                }
                
                updatePollingInterval();
            });
    }

    function fetchOlderMessages() {
        if (state.isLoadingHistory || !state.hasMoreHistory || state.firstMessageId === null) return;
        
        // Abort any existing load more requests
        abortPendingRequests('loadMore');
        
        state.isLoadingHistory = true;
        
        if (loadMoreBtn) {
            loadMoreBtn.disabled = true;
            loadMoreBtn.textContent = config.i18n.loading || 'Loading...';
        }

        const scrollHeightBefore = messagesEl.scrollHeight;
        const scrollTopBefore = messagesEl.scrollTop;

        const url = `${config.fetchUrl}?before_id=${state.firstMessageId}&limit=15`;
        const { promise } = createManagedRequest(url, {}, 'loadMore');

        promise
            .then(response => response.json())
            .then(data => {
                if (data.success && data.messages && data.messages.length > 0) {
                    const messagesInOrder = [...data.messages].reverse();
                    messagesInOrder.forEach(prependMessage);
                    
                    // Maintain scroll position after prepending
                    requestAnimationFrame(() => {
                        const scrollHeightAfter = messagesEl.scrollHeight;
                        const scrollDiff = scrollHeightAfter - scrollHeightBefore;
                        messagesEl.scrollTop = scrollTopBefore + scrollDiff;
                    });
                    
                    if (!data.has_more) {
                        state.hasMoreHistory = false;
                        updateLoadMoreButton(false);
                    } else {
                        updateLoadMoreButton(false);
                    }
                } else {
                    state.hasMoreHistory = false;
                    updateLoadMoreButton(false);
                }
            })
            .catch(error => {
                if (error.message !== 'Request aborted') {
                    console.error('Load more error:', error);
                    showError(config.i18n.network_error || 'Failed to load messages');
                }
            })
            .finally(() => {
                state.isLoadingHistory = false;
                if (loadMoreBtn) {
                    loadMoreBtn.disabled = false;
                    loadMoreBtn.textContent = config.i18n.load_more || 'Load older messages';
                }
            });
    }

    // ===== MESSAGE SENDING - ENHANCED WITH REQUEST MANAGEMENT =====

    function sendMessage(e) {
        e.preventDefault();

        // Abort any pending send requests to prevent duplicates
        abortPendingRequests('send');

        const now = Date.now();
        if (state.isSending) {
            showError(config.i18n.send_failed || 'Message already being sent');
            return;
        }
        
        if (now - state.lastSendTime < state.sendCooldown) {
            const remainingTime = Math.ceil((state.sendCooldown - (now - state.lastSendTime)) / 1000);
            showError(`${config.i18n.rate_limit_exceeded || 'Please slow down'} (${remainingTime}s)`);
            return;
        }

        const message = inputMsg.value.trim();
        const display_name = config.currentUser || state.guestName;

        if (!message) {
            showError(config.i18n.missing_name || 'Message cannot be empty');
            return;
        }

        if (message.length > config.maxMessageLength) {
            showError(config.i18n.message_too_long || 'Message is too long');
            return;
        }

        if (!display_name) {
            showError(config.i18n.missing_name || 'Display name is required');
            return;
        }

        // Set sending state
        state.isSending = true;
        state.lastSendTime = now;
        inputMsg.disabled = true;

        // Visual feedback
        const submitBtn = formEl.querySelector('button[type="submit"], input[type="submit"]');
        const submitTextEl = submitBtn ? submitBtn.querySelector('.init-chatbox-submit-text') : null;
        let originalText = '';
        
        if (submitBtn) {
            submitBtn.disabled = true;
            if (submitTextEl) {
                originalText = submitTextEl.textContent;
                submitTextEl.textContent = config.i18n.loading || 'Sending...';
            }
            
            setTimeout(() => {
                submitBtn.disabled = false;
                if (submitTextEl) {
                    submitTextEl.textContent = originalText;
                }
            }, state.sendCooldown);
        }

        const { promise } = createManagedRequest(config.sendUrl, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-WP-Nonce': InitChatEngineData.nonce
            },
            body: JSON.stringify({ message, display_name })
        }, 'send');

        promise
            .then(response => {
                if (!response.ok) {
                    throw new Error(`HTTP ${response.status}`);
                }
                return response.json();
            })
            .then(data => {
                if (data.success) {
                    inputMsg.value = '';
                    updateCharCount();

                    // Remove empty state if exists
                    const emptyDiv = messagesListEl.querySelector('.init-chatbox-empty');
                    if (emptyDiv) {
                        emptyDiv.remove();
                    }

                    // Reset polling to fastest speed after sending
                    polling.consecutiveEmptyFetches = 0;
                    polling.consecutiveErrors = 0;
                    polling.lastMessageTime = Date.now();
                    recordActivity();

                    // Immediate fetch new messages instead of waiting for polling
                    setTimeout(fetchNewMessages, 100);

                    // Auto-focus input after successful send
                    setTimeout(() => inputMsg.focus(), 50);
                } else {
                    throw new Error(data.message || 'Send failed');
                }
            })
            .catch(error => {
                if (error.message !== 'Request aborted') {
                    console.error('Send error:', error);
                    
                    let errorMessage = config.i18n.send_failed || 'Failed to send message';
                    if (error.message.includes('429')) {
                        errorMessage = config.i18n.rate_limit_exceeded || 'You are sending messages too quickly';
                    } else if (error.message.includes('400')) {
                        errorMessage = config.i18n.message_blocked || 'Message was blocked';
                    }
                    
                    showError(errorMessage);
                }
            })
            .finally(() => {
                state.isSending = false;
                inputMsg.disabled = false;
            });
    }

    // ===== NETWORK STATUS MONITORING =====

    function handleOnlineStatus() {
        polling.isOnline = navigator.onLine;
        
        if (polling.isOnline) {
            polling.consecutiveErrors = 0;
            if (state.connectionLost) {
                updateConnectionStatus('connected', config.i18n.connected || 'Back online');
                state.connectionLost = false;
                // Immediate fetch when back online
                setTimeout(fetchNewMessages, 500);
            }
        } else {
            state.connectionLost = true;
            updateConnectionStatus('offline', config.i18n.offline || 'No internet connection');
        }
        
        updatePollingInterval();
    }

    // ===== EVENT LISTENERS =====

    // Network status events
    window.addEventListener('online', handleOnlineStatus);
    window.addEventListener('offline', handleOnlineStatus);

    // Window focus/blur events
    window.addEventListener('focus', () => {
        polling.isWindowFocused = true;
        polling.consecutiveEmptyFetches = 0;
        polling.consecutiveErrors = Math.max(0, polling.consecutiveErrors - 1); // Reduce error count on focus
        stopTitleBlink();
        recordActivity();
    });

    window.addEventListener('blur', () => {
        polling.isWindowFocused = false;
        updatePollingInterval();
    });

    // Page visibility API
    if (document.hidden !== undefined) {
        document.addEventListener('visibilitychange', () => {
            polling.isWindowFocused = !document.hidden;
            if (polling.isWindowFocused) {
                polling.consecutiveEmptyFetches = 0;
                polling.consecutiveErrors = Math.max(0, polling.consecutiveErrors - 1);
                stopTitleBlink();
                recordActivity();
                // Quick fetch when tab becomes visible
                setTimeout(fetchNewMessages, 200);
            } else {
                updatePollingInterval();
            }
        });
    }

    // Input focus events
    if (inputMsg) {
        inputMsg.addEventListener('focus', () => {
            polling.isInputFocused = true;
            stopTitleBlink();
            recordActivity();
        });

        inputMsg.addEventListener('blur', () => {
            polling.isInputFocused = false;
            updatePollingInterval();
        });

        // Input events
        inputMsg.addEventListener('input', () => {
            updateCharCount();
            recordActivity();
        });

        // Prevent sending if over limit
        inputMsg.addEventListener('keypress', (e) => {
            if (inputMsg.value.length >= config.maxMessageLength && e.key !== 'Backspace' && e.key !== 'Delete') {
                e.preventDefault();
                showError(config.i18n.message_too_long || 'Message is too long');
            }
        });

        // Enter to send (with Shift+Enter for new line)
        inputMsg.addEventListener('keydown', (e) => {
            if (e.key === 'Enter' && !e.shiftKey) {
                e.preventDefault();
                if (formEl && !state.isSending) {
                    formEl.dispatchEvent(new Event('submit'));
                }
            }
        });
    }

    // Form submit
    if (formEl) {
        formEl.addEventListener('submit', sendMessage);
    }

    // Error close button
    if (errorCloseEl) {
        errorCloseEl.addEventListener('click', hideError);
    }

    // Load more button
    if (loadMoreBtn) {
        loadMoreBtn.addEventListener('click', fetchOlderMessages);
    }

    // Mouse/scroll activity
    document.addEventListener('mousemove', recordActivity, { passive: true });
    document.addEventListener('click', recordActivity);

    // Enhanced scroll handling
    if (messagesEl) {
        let loadHistoryTimeout;
        let scrollStabilityTimer;
        
        messagesEl.addEventListener('scroll', function () {
            recordActivity();
            
            if (state.isInitialLoading) {
                return;
            }

            // Clear previous stability timer
            if (scrollStabilityTimer) {
                clearTimeout(scrollStabilityTimer);
            }

            // Wait for scroll to stabilize before making decisions
            scrollStabilityTimer = setTimeout(() => {
                const scrollTop = messagesEl.scrollTop;
                const autoLoadZone = scrollTop < 20;
                const manualButtonZone = scrollTop < 400;
                const canScroll = messagesEl.scrollHeight > messagesEl.clientHeight;

                // Detect scroll direction with larger threshold
                if (Math.abs(scrollTop - state.lastScrollTop) > 8) {
                    if (scrollTop < state.lastScrollTop && !isAtBottom()) {
                        state.userScrolledUp = true;
                        state.lastScrollDirection = 'up';
                    } else if (isAtBottom()) {
                        state.userScrolledUp = false;
                        state.lastScrollDirection = 'down';
                    }
                }

                state.lastScrollTop = scrollTop;

                // Auto-load when very close to top
                if (autoLoadZone && canScroll && state.hasMoreHistory && 
                    !state.isLoadingHistory && state.lastScrollDirection === 'up') {
                    
                    clearTimeout(loadHistoryTimeout);
                    loadHistoryTimeout = setTimeout(() => {
                        fetchOlderMessages();
                    }, 150);
                }
                // Show manual load button
                else if (manualButtonZone && !autoLoadZone && canScroll && 
                         state.hasMoreHistory && !state.isLoadingHistory && 
                         !isAtBottom()) {
                    
                    if (!state.loadMoreButtonVisible) {
                        updateLoadMoreButton(true);
                    }
                }
                // Hide button
                else if (!manualButtonZone || isAtBottom() || !state.hasMoreHistory) {
                    if (state.loadMoreButtonVisible) {
                        updateLoadMoreButton(false);
                    }
                }
            }, 80);
            
        }, { passive: true });
    }

    // ===== GUEST NAME FLOW =====

    if (!config.currentUser && config.allowGuests) {
        // Restore guest name if exists
        if (state.guestName && activeBlock && formNameBlock) {
            if (currentNameBox) currentNameBox.textContent = state.guestName;
            showElement(activeBlock);
            hideElement(formNameBlock);
        }

        // Set guest name
        if (btnSetName) {
            btnSetName.addEventListener('click', function () {
                const name = inputName.value.trim();
                if (!name) {
                    showError(config.i18n.missing_name || 'Display name is required');
                    return;
                }
                
                if (name.length > 50) {
                    showError('Name is too long (max 50 characters)');
                    return;
                }
                
                state.guestName = name;
                localStorage.setItem('init_chatbox_guest_name', state.guestName);
                
                if (currentNameBox) currentNameBox.textContent = state.guestName;
                hideElement(formNameBlock);
                showElement(activeBlock);
                
                recordActivity();
                
                // Auto-focus message input after setting name
                setTimeout(() => {
                    if (inputMsg) inputMsg.focus();
                }, 50);
            });
        }

        // Change guest name
        if (btnChangeName) {
            btnChangeName.addEventListener('click', function () {
                state.guestName = '';
                localStorage.removeItem('init_chatbox_guest_name');
                
                showElement(formNameBlock);
                hideElement(activeBlock);
                
                recordActivity();
            });
        }

        // Enter to set name
        if (inputName) {
            inputName.addEventListener('keydown', (e) => {
                if (e.key === 'Enter') {
                    e.preventDefault();
                    if (btnSetName) btnSetName.click();
                }
            });
        }
    }

    // ===== NOTIFICATION PERMISSION =====

    if (config.enableNotifications && 'Notification' in window && Notification.permission === 'default') {
        const requestPermission = () => {
            Notification.requestPermission();
            document.removeEventListener('click', requestPermission);
        };
        
        document.addEventListener('click', requestPermission, { once: true });
    }

    // ===== SCROLL TO BOTTOM BUTTON =====

    function createScrollButton() {
        const scrollBtn = document.createElement('button');
        scrollBtn.id = 'init-chatbox-scroll-btn';
        scrollBtn.innerHTML = '↓ ' + (config.i18n.new_message || 'New messages');
        scrollBtn.className = 'init-chatbox-scroll-to-bottom';
        hideElement(scrollBtn);
        
        scrollBtn.addEventListener('click', () => {
            scrollToBottom(true);
            hideElement(scrollBtn);
            recordActivity();
        });
        
        if (root) {
            root.style.position = 'relative';
            root.appendChild(scrollBtn);
        }
        
        return scrollBtn;
    }

    const scrollBtn = createScrollButton();

    // Show/hide scroll button
    if (messagesEl) {
        messagesEl.addEventListener('scroll', function () {
            if (state.isInitialLoading) return;
            
            if (state.userScrolledUp && !isAtBottom()) {
                showElement(scrollBtn);
            } else {
                hideElement(scrollBtn);
            }
        }, { passive: true });
    }

    // ===== INITIALIZATION =====

    function initializeChat() {
        // Load initial messages
        if (loadingEl) showElement(loadingEl);
        
        const url = `${config.fetchUrl}?limit=15`;
        const { promise } = createManagedRequest(url, {}, 'init');
        
        promise
            .then(response => {
                if (!response.ok) {
                    throw new Error(`HTTP ${response.status}`);
                }
                return response.json();
            })
            .then(data => {
                if (messagesListEl) messagesListEl.innerHTML = '';
                
                if (data.success && data.messages && data.messages.length > 0) {
                    // API returns latest messages in DESC order
                    // Reverse to show oldest->newest
                    const messagesInOrder = [...data.messages].reverse();
                    messagesInOrder.forEach(msg => {
                        // Cache messages during initialization
                        state.messageCache.set(msg.id, msg);
                        appendMessage(msg, false);
                    });
                    
                    // Ensure proper scroll to bottom
                    const scrollToBottomInit = () => {
                        if (messagesEl) {
                            messagesEl.scrollTop = messagesEl.scrollHeight;
                        }
                    };
                    
                    scrollToBottomInit();
                    setTimeout(scrollToBottomInit, 10);
                    setTimeout(scrollToBottomInit, 50);
                    
                    setTimeout(() => {
                        scrollToBottomInit();
                        state.isInitialLoading = false;
                        if (loadingEl) hideElement(loadingEl);
                    }, 100);
                    
                    if (!data.has_more) {
                        state.hasMoreHistory = false;
                    }
                } else {
                    // No messages - show empty state
                    if (messagesListEl) {
                        const emptyDiv = document.createElement('div');
                        emptyDiv.className = 'init-chatbox-empty';
                        emptyDiv.textContent = config.i18n.empty_message || 'No messages yet. Be the first to chat!';
                        messagesListEl.appendChild(emptyDiv);
                    }
                    
                    state.isInitialLoading = false;
                    if (loadingEl) hideElement(loadingEl);
                }
            })
            .catch(error => {
                console.error('Initial load error:', error);
                
                if (messagesListEl) {
                    const errorDiv = document.createElement('div');
                    errorDiv.className = 'init-chatbox-empty';
                    errorDiv.textContent = config.i18n.network_error || 'Failed to load messages';
                    messagesListEl.appendChild(errorDiv);
                }
                
                state.isInitialLoading = false;
                if (loadingEl) hideElement(loadingEl);
                
                showError(config.i18n.network_error || 'Failed to load messages');
            });
    }

    // ===== START EVERYTHING =====

    // Initialize network status
    handleOnlineStatus();

    // Initialize character count
    updateCharCount();

    // Initialize chat
    initializeChat();

    // Start polling for new messages
    startPolling();

    // ===== CLEANUP =====

    window.addEventListener('beforeunload', () => {
        // Clear all timers
        if (polling.timer) {
            clearInterval(polling.timer);
        }
        if (state.scrollStabilityTimer) {
            clearTimeout(state.scrollStabilityTimer);
        }
        if (titleNotification.blinkTimer) {
            clearInterval(titleNotification.blinkTimer);
        }
        
        // Abort all pending requests
        for (const [id, request] of state.pendingRequests) {
            if (request.controller) {
                request.controller.abort();
            }
        }
        state.pendingRequests.clear();
    });

    // Performance monitoring (optional)
    if (window.performance && window.performance.mark) {
        window.performance.mark('chat-script-loaded');
    }
});
