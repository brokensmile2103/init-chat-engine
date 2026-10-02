document.addEventListener('DOMContentLoaded', function () {
    if (typeof InitChatEngineData === 'undefined') return;

    // Container mặc định có id "init-chatbox-root", nhưng shortcode cho phép đổi id
    // (vd: [init_chatbox id="my-chat"]). Trước 1.3.8 JS chỉ tìm đúng id mặc định nên
    // chat không khởi động khi dùng id tùy chỉnh.
    const customRootId = InitChatEngineData.shortcode_atts && InitChatEngineData.shortcode_atts.id;
    const root = document.getElementById('init-chatbox-root') ||
        (customRootId ? document.getElementById(customRootId) : null);
    if (!root) return;

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
        // Phòng chat (1.3.9): '' = phòng mặc định. room_token là chữ ký do server
        // in sẵn vào trang, bắt buộc phải gửi kèm khi dùng phòng riêng.
        room: InitChatEngineData.room || '',
        roomToken: InitChatEngineData.room_token || '',
        // Request treo quá lâu (máy sleep, đổi mạng...) sẽ bị hủy để không chặn
        // các lần poll sau (fetchNewMessages bỏ qua khi còn request đang chạy).
        requestTimeout: 20000,
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
        interval: 3500,
        minInterval: 3500, // 3.5s - đủ nhanh cho cảm giác realtime, giảm ~40% request so với 2s
        maxInterval: 10000, // 10s khi tab background / không hoạt động
        // NEW: Chatbox không nằm trong viewport (tab vẫn active, nhưng user cuộn qua
        // chỗ khác của trang) => giãn polling xa hơn nữa so với maxInterval thông thường
        viewportMaxInterval: 20000, // 20s khi chatbox ngoài viewport
        timer: null,
        isWindowFocused: true,
        isInputFocused: false,
        isInViewport: true, // NEW: cập nhật bởi IntersectionObserver bên dưới
        consecutiveEmptyFetches: 0,
        consecutiveErrors: 0, // NEW: Track consecutive errors
        lastActivity: Date.now(),
        lastMessageTime: Date.now(),
        backoffMultiplier: 1.5, // NEW: Exponential backoff
        isOnline: navigator.onLine, // NEW: Online status
        // NEW: Tạm dừng hẳn polling (không chỉ giãn interval) khi tab ẩn quá lâu
        isPaused: false,
        pauseAfter: 10 * 60 * 1000 // 10 phút không hoạt động + tab ẩn => dừng poll
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
        let timedOut = false;
        
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

        // Luôn lấy dữ liệu mới từ server, không dùng HTTP cache của trình duyệt
        // (tránh kẹt ở 1 response rỗng cũ của cùng URL ?after_id=X).
        if (!options.cache) {
            options.cache = 'no-store';
        }

        // Timeout: request treo (vd: sau khi máy sleep / đổi wifi) sẽ bị hủy và
        // được tính là lỗi mạng -> cơ chế retry / backoff xử lý tiếp như bình thường.
        const timeoutId = setTimeout(() => {
            timedOut = true;
            controller.abort();
        }, config.requestTimeout);
        
        const requestPromise = fetch(url, options)
            .then(response => {
                clearTimeout(timeoutId);
                state.pendingRequests.delete(requestId);
                return response;
            })
            .catch(error => {
                clearTimeout(timeoutId);
                state.pendingRequests.delete(requestId);
                if (error.name === 'AbortError') {
                    if (timedOut) {
                        return Promise.reject(new Error('Request timeout'));
                    }
                    console.debug('Request aborted:', requestId);
                    return Promise.reject(new Error('Request aborted'));
                }
                throw error;
            });
            
        return { requestId, promise: requestPromise, controller };
    }

    // ===== UTILITY FUNCTIONS =====

    // Ghép query string an toàn cho cả 2 dạng REST URL: /wp-json/... (pretty
    // permalink) và index.php?rest_route=... (permalink "Plain"). Trước 1.3.8 luôn
    // nối bằng "?" nên ở dạng Plain URL bị hỏng (2 dấu "?") -> REST trả 404 và chat
    // không tải được tin nhắn.
    function withQuery(baseUrl, query) {
        return baseUrl + (baseUrl.indexOf('?') === -1 ? '?' : '&') + query;
    }

    // Thêm tham số phòng (room + room_token) vào URL REST - phòng mặc định thì bỏ qua
    // để URL giữ nguyên như các bản trước.
    function withRoom(url) {
        if (!config.room) return url;
        return withQuery(url, `room=${encodeURIComponent(config.room)}&room_token=${encodeURIComponent(config.roomToken)}`);
    }

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

    // Escape cho cả nội dung text LẪN giá trị thuộc tính HTML. Bản cũ dùng
    // textContent -> innerHTML, cách này KHÔNG escape dấu nháy (" và '), nên chuỗi
    // do người dùng nhập (tên hiển thị, URL trong tin nhắn) có thể thoát khỏi
    // thuộc tính src/alt/href/data-* khi ghép vào template HTML.
    const HTML_ESCAPE_MAP = {
        '&': '&amp;',
        '<': '&lt;',
        '>': '&gt;',
        '"': '&quot;',
        "'": '&#039;'
    };

    function escapeHTML(str) {
        if (str === null || str === undefined || str === '') return '';
        return String(str).replace(/[&<>"']/g, (ch) => HTML_ESCAPE_MAP[ch]);
    }

    // Class dùng để ẩn phần tử: chỉ cần dò 1 lần cho cả trang. Bản cũ tạo div test
    // + getComputedStyle() 3 lần (ép trình duyệt tính lại layout) MỖI lần gọi
    // hideElement().
    let detectedHideClass;

    function getHideClass() {
        if (detectedHideClass !== undefined) return detectedHideClass;

        const testDiv = document.createElement('div');
        document.body.appendChild(testDiv);

        detectedHideClass = null;
        for (const cls of ['uk-hidden', 'hidden', 'ice-hidden']) {
            testDiv.className = cls;
            if (window.getComputedStyle(testDiv).display === 'none') {
                detectedHideClass = cls;
                break;
            }
        }

        document.body.removeChild(testDiv);
        return detectedHideClass;
    }

    // Enhanced show/hide functions with class support
    function hideElement(element) {
        if (!element) return;
        
        if (element.classList.contains('uk-hidden') || 
            element.classList.contains('hidden') || 
            element.classList.contains('ice-hidden')) {
            return;
        }
        
        const hideClass = getHideClass();
        
        if (hideClass) {
            element.classList.add(hideClass);
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

    // mousemove bắn ra hàng chục event/giây - chỉ cần ghi nhận hoạt động tối đa
    // 1 lần/giây là đủ chính xác cho việc điều chỉnh tần suất polling.
    let lastPassiveActivityAt = 0;

    function recordActivityThrottled() {
        const now = Date.now();
        if (now - lastPassiveActivityAt < 1000) return;
        lastPassiveActivityAt = now;
        recordActivity();
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
                ${escapeHTML(firstLetter)}
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

            // NEW: Chatbox ngoài viewport (trang vẫn active, không phải tab ẩn) => user
            // gần như chắc chắn không đang nhìn chatbox, giãn xa hơn cả maxInterval thường,
            // trừ khi đang gõ (isInputFocused đã return sớm ở nhánh trên nên không bị ảnh hưởng)
            if (!polling.isInViewport) {
                newInterval = Math.max(newInterval, polling.viewportMaxInterval);
            }
        }

        // Network status adjustment
        if (!polling.isOnline) {
            newInterval = polling.maxInterval;
        }

        // Trần interval động: cao hơn khi chatbox ngoài viewport, ngược lại giữ nguyên maxInterval
        const effectiveMaxInterval = (!polling.isInViewport && polling.consecutiveErrors === 0)
            ? Math.max(polling.maxInterval, polling.viewportMaxInterval)
            : polling.maxInterval;

        return Math.max(polling.minInterval, Math.min(effectiveMaxInterval, Math.round(newInterval)));
    }

    function updatePollingInterval() {
        const idleTime = Date.now() - polling.lastActivity;
        const shouldPause = !polling.isWindowFocused && !polling.isInputFocused && idleTime > polling.pauseAfter;

        if (shouldPause) {
            if (!polling.isPaused) {
                polling.isPaused = true;
                if (polling.timer) {
                    clearInterval(polling.timer);
                    polling.timer = null;
                }
                console.debug('Polling paused after', Math.round(idleTime / 1000), 's inactivity');
            }
            return;
        }

        const wasPaused = polling.isPaused;
        polling.isPaused = false;

        const newInterval = calculateOptimalInterval();

        if (wasPaused || newInterval !== polling.interval || !polling.timer) {
            polling.interval = newInterval;
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
        const initialTimeText = msg.created_at_iso
            ? formatRelativeTime(msg.created_at_iso)
            : (msg.created_at_human || msg.created_at);
        const timestamp = config.showTimestamps ? 
            `<span class="init-chatbox-meta-time" data-timestamp="${escapeHTML(msg.created_at_iso || msg.created_at)}">${escapeHTML(initialTimeText)}</span>` : '';
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

    // ===== CLIENT-SIDE RELATIVE TIME =====
    // Trước đây phần "x phút trước" được refresh bằng cách server query lại 50 tin
    // nhắn gần nhất + tính human_time_diff() MỖI LẦN poll (mỗi 3.5-10s/client), dù
    // tuyệt đại đa số các lần đó không có gì thay đổi để hiển thị. Giờ tính thẳng ở
    // client dựa vào data-timestamp (ISO) đã có sẵn trên DOM, không tốn thêm request.
    // NOTE (1.3.6): Bỏ hậu tố "ago"/"trước" để hiển thị gọn hơn trên giao diện
    // (giống Facebook: "2 giờ" thay vì "2 giờ trước"). Đồng thời bổ sung thêm
    // các mốc tuần / tháng / năm cho tin nhắn cũ, trước đây chỉ tính tới "ngày".
    const RELATIVE_TIME_THRESHOLDS = [
        { limit: 3600, divisor: 60, unit: 'unit_minutes', fallback: 'minutes' },
        { limit: 86400, divisor: 3600, unit: 'unit_hours', fallback: 'hours' },
        { limit: 604800, divisor: 86400, unit: 'unit_days', fallback: 'days' },       // < 7 ngày
        { limit: 2592000, divisor: 604800, unit: 'unit_weeks', fallback: 'weeks' },   // < 30 ngày
        { limit: 31536000, divisor: 2592000, unit: 'unit_months', fallback: 'months' }, // < 365 ngày
    ];

    function formatRelativeTime(isoString) {
        const then = new Date(isoString).getTime();
        if (isNaN(then)) return '';

        const diffSec = Math.max(0, Math.floor((Date.now() - then) / 1000));

        if (diffSec < 60) {
            return config.i18n.now || 'now';
        }

        for (const step of RELATIVE_TIME_THRESHOLDS) {
            if (diffSec < step.limit) {
                const value = Math.floor(diffSec / step.divisor);
                return `${value} ${config.i18n[step.unit] || step.fallback}`;
            }
        }

        // >= 1 năm
        const years = Math.floor(diffSec / 31536000);
        return `${years} ${config.i18n.unit_years || 'years'}`;
    }

    function refreshVisibleTimestamps() {
        if (!config.showTimestamps || !messagesListEl) return;

        messagesListEl.querySelectorAll('.init-chatbox-meta-time[data-timestamp]').forEach(el => {
            const iso = el.getAttribute('data-timestamp');
            if (!iso) return;
            const text = formatRelativeTime(iso);
            if (text) el.textContent = text;
        });
    }

    // Cập nhật mỗi 60s là đủ mượt cho hiển thị dạng "x phút trước", không cần dày hơn
    setInterval(refreshVisibleTimestamps, 60000);

    function appendMessage(msg, shouldScroll = true) {
        if (!messagesListEl) return;

        // Check if message already exists (tránh duplicate khi poll trả trùng)
        const existingMessage = messagesListEl.querySelector(`[data-message-id="${msg.id}"]`);
        if (existingMessage) {
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

        const url = withRoom(withQuery(config.fetchUrl, `after_id=${state.lastMessageId}`));
        const { promise } = createManagedRequest(url, {}, 'fetch');
        
        promise
            .then(response => {
                if (!response.ok) {
                    throw new Error(`HTTP ${response.status}`);
                }
                return response.json();
            })
            .then(data => {
                handlePinnedFromResponse(data);
                if (window._initChatPinHandleResponse) window._initChatPinHandleResponse(data);
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

                    // Append new messages (timestamp "x phút trước" được refresh
                    // định kỳ ở client bởi refreshVisibleTimestamps(), không cần server trả kèm)
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

        const url = withRoom(withQuery(config.fetchUrl, `before_id=${state.firstMessageId}&limit=15`));
        const { promise } = createManagedRequest(url, {}, 'loadMore');

        promise
            .then(response => response.json())
            .then(data => {
                if (data.success && data.messages && data.messages.length > 0) {
                    data.messages.forEach(prependMessage);
                    
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
            body: JSON.stringify({ message, display_name, room: config.room, room_token: config.roomToken })
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

        // Nếu polling đang bị pause hẳn (do tab ẩn quá lâu), fetch ngay và resume interval
        if (polling.isPaused) {
            setTimeout(fetchNewMessages, 200);
        }
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

    // NEW: Viewport visibility (IntersectionObserver) - chatbox có thể nằm ở cuối trang
    // dài, tab vẫn active/focus nhưng user đang đọc chỗ khác => vẫn nên giãn polling xa
    // hơn thay vì chỉ dựa vào document.hidden (vốn chỉ biết tab ẩn/hiện, không biết vị
    // trí cuộn trang)
    if ('IntersectionObserver' in window && root) {
        const chatboxViewportObserver = new IntersectionObserver((entries) => {
            entries.forEach((entry) => {
                polling.isInViewport = entry.isIntersecting;
            });

            if (polling.isInViewport) {
                recordActivity();
                // Fetch ngay khi chatbox vừa xuất hiện lại trong viewport, tránh cảm giác lag
                setTimeout(fetchNewMessages, 200);
            } else {
                updatePollingInterval();
            }
        }, { threshold: 0 });

        chatboxViewportObserver.observe(root);
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
    document.addEventListener('mousemove', recordActivityThrottled, { passive: true });
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
                    showError(config.i18n.name_too_long || 'Name is too long (max 50 characters)');
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
        
        const url = withRoom(withQuery(config.fetchUrl, 'limit=15'));
        const { promise } = createManagedRequest(url, {}, 'init');
        
        promise
            .then(response => {
                if (!response.ok) {
                    throw new Error(`HTTP ${response.status}`);
                }
                return response.json();
            })
            .then(data => {
                handlePinnedFromResponse(data);
                if (window._initChatPinHandleResponse) window._initChatPinHandleResponse(data);
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

    // ============================================================
    // PINNED MESSAGE FEATURE
    // ============================================================

    // ---- Helpers i18n ------------------------------------------

    /**
     * Lấy chuỗi i18n từ config, fallback sang giá trị mặc định
     * để tránh hiển thị undefined nếu key bị thiếu.
     */
    function pinT( key, fallback ) {
        return ( config.i18n && config.i18n[ key ] ) ? config.i18n[ key ] : ( fallback || key );
    }

    // ---- Config ------------------------------------------------

    // Tính base namespace URL từ fetchUrl đã có trong config
    // config.fetchUrl = .../namespace/messages  → cắt /messages
    const PIN_NAMESPACE_URL = config.fetchUrl
        ? config.fetchUrl.replace( /\/messages\/?(\?.*)?$/, '' )
        : '';

    const isAdmin = !! InitChatEngineData.is_admin;

    // ---- State -------------------------------------------------

    let pinnedState = {
        currentPinnedId : null,
        data            : null,
        isPinning       : false,
    };

    // ---- DOM: Banner -------------------------------------------

    const pinnedBanner = ( function createPinnedBanner() {
        const el = document.createElement( 'div' );
        el.id        = 'init-chatbox-pinned-banner';
        el.className = 'init-chatbox-pinned-banner';
        el.setAttribute( 'aria-label', pinT( 'pinned_label', 'Pinned message' ) );
        el.setAttribute( 'role', 'region' );
        el.style.display = 'none';

        el.innerHTML = `
            <div class="init-chatbox-pinned-inner">
                <span class="init-chatbox-pinned-icon" aria-hidden="true">📌</span>
                <div class="init-chatbox-pinned-clickable" role="button" tabindex="0">
                    <span class="init-chatbox-pinned-label"></span>
                    <span class="init-chatbox-pinned-text"></span>
                </div>
            <button type="button" class="init-chatbox-pinned-expand" aria-label="Expand">
                ⤢
            </button>
                <button
                    type="button"
                    class="init-chatbox-pinned-unpin ${isAdmin ? '' : 'ice-hidden'}"
                ></button>
            </div>
        `;

        // Gắn text & aria sau khi i18n sẵn sàng
        const labelEl   = el.querySelector( '.init-chatbox-pinned-label' );
        const unpinBtn  = el.querySelector( '.init-chatbox-pinned-unpin' );

        if ( labelEl ) {
            labelEl.textContent = pinT( 'pinned_label', 'Pinned message' );
        }
        if ( unpinBtn ) {
            unpinBtn.textContent = pinT( 'pinned_unpin', 'Unpin' );
            unpinBtn.title       = pinT( 'unpin_this_message', 'Unpin this message' );
            unpinBtn.setAttribute( 'aria-label', pinT( 'unpin_this_message', 'Unpin this message' ) );
        }

        // Gắn vào DOM: ngay trước khung tin nhắn
        if ( messagesEl && messagesEl.parentNode ) {
            messagesEl.parentNode.insertBefore( el, messagesEl );
        } else if ( root ) {
            root.insertBefore( el, root.firstChild );
        }

        return el;


    } )();

    // ---- Render Banner -----------------------------------------

    function renderPinnedBanner( pinData ) {
        if ( ! pinnedBanner ) return;

        if ( ! pinData || ! pinData.id ) {
            pinnedBanner.style.display = 'none';
            pinnedBanner.classList.remove('expanded');
            pinnedState.currentPinnedId = null;
            pinnedState.data = null;

            messagesListEl && messagesListEl
                .querySelectorAll( '.pinned-highlight' )
                .forEach( function ( el ) { el.classList.remove( 'pinned-highlight' ); } );

            updateAllPinButtons( null );
            return;
        }

        pinnedState.currentPinnedId = pinData.id;
        pinnedState.data = pinData;

        const rawText = ( pinData.message || '' )
            .replace( /<[^>]+>/g, '' )
            .replace( /\s+/g, ' ' )
            .trim();
        const preview = rawText.length > 100
            ? rawText.substring( 0, 100 ) + '\u2026'
            : rawText;

        const textEl = pinnedBanner.querySelector( '.init-chatbox-pinned-text' );
        if ( textEl ) {
            textEl.textContent = preview;
            textEl.dataset.full = rawText;
        }

        pinnedBanner.style.display = '';
        updateAllPinButtons( pinData.id );
    }

    // ---- Pin button per message --------------------------------

    function addPinButtonToMessage( msgEl, messageId ) {
        if ( ! isAdmin || ! msgEl ) return;
        if ( msgEl.querySelector( '.init-chatbox-pin-btn' ) ) return;

        const isCurrentlyPinned = pinnedState.currentPinnedId !== null
            && String( pinnedState.currentPinnedId ) === String( messageId );

        const btn = document.createElement( 'button' );
        btn.type      = 'button';
        btn.className = 'init-chatbox-pin-btn' + ( isCurrentlyPinned ? ' pinned-active' : '' );
        btn.dataset.msgId = messageId;
        btn.textContent = pinT( isCurrentlyPinned ? 'unpin_action' : 'pin_action', isCurrentlyPinned ? 'Unpin' : 'Pin' );
        btn.title       = pinT( isCurrentlyPinned ? 'unpin_this_message' : 'pin_this_message', isCurrentlyPinned ? 'Unpin this message' : 'Pin this message' );
        btn.setAttribute( 'aria-label', btn.title );

        btn.addEventListener( 'click', function ( e ) {
            e.stopPropagation();
            const alreadyPinned = pinnedState.currentPinnedId !== null
                && String( pinnedState.currentPinnedId ) === String( this.dataset.msgId );
            if ( alreadyPinned ) {
                doUnpin();
            } else {
                doPin( parseInt( this.dataset.msgId, 10 ) );
            }
        } );

        msgEl.appendChild( btn );
    }

    function updateAllPinButtons( pinnedId ) {
        if ( ! messagesListEl ) return;

        messagesListEl.querySelectorAll( '.init-chatbox-pin-btn' ).forEach( function ( btn ) {
            const isThisPinned = pinnedId !== null && String( pinnedId ) === String( btn.dataset.msgId );
            btn.textContent    = pinT( isThisPinned ? 'unpin_action' : 'pin_action', isThisPinned ? 'Unpin' : 'Pin' );
            btn.title          = pinT( isThisPinned ? 'unpin_this_message' : 'pin_this_message', isThisPinned ? 'Unpin this message' : 'Pin this message' );
            btn.setAttribute( 'aria-label', btn.title );
            btn.classList.toggle( 'pinned-active', !! isThisPinned );
        } );
    }

    // ---- API calls --------------------------------------------

    function doPin( messageId ) {
        if ( pinnedState.isPinning ) return;
        pinnedState.isPinning = true;

        messagesListEl && messagesListEl
            .querySelectorAll( '.init-chatbox-pin-btn' )
            .forEach( function ( b ) { b.disabled = true; } );

        fetch( PIN_NAMESPACE_URL + '/pin', {
            method  : 'POST',
            headers : {
                'Content-Type' : 'application/json',
                'X-WP-Nonce'   : InitChatEngineData.nonce,
            },
            body: JSON.stringify( { message_id: messageId, room: config.room, room_token: config.roomToken } ),
        } )
        .then( function ( r ) {
            if ( ! r.ok ) throw new Error( 'HTTP ' + r.status );
            return r.json();
        } )
        .then( function ( data ) {
            if ( data.success ) {
                renderPinnedBanner( data.pinned_message );
            } else {
                showError( data.message || pinT( 'pin_failed', 'Could not pin message.' ) );
            }
        } )
        .catch( function () {
            showError( pinT( 'pin_connect_error', 'Connection error while pinning.' ) );
        } )
        .finally( function () {
            pinnedState.isPinning = false;
            messagesListEl && messagesListEl
                .querySelectorAll( '.init-chatbox-pin-btn' )
                .forEach( function ( b ) { b.disabled = false; } );
        } );
    }

    function doUnpin() {
        if ( pinnedState.isPinning ) return;
        pinnedState.isPinning = true;

        const unpinBtn = pinnedBanner.querySelector( '.init-chatbox-pinned-unpin' );
        if ( unpinBtn ) unpinBtn.disabled = true;

        fetch( withRoom( PIN_NAMESPACE_URL + '/pin' ), {
            method  : 'DELETE',
            headers : { 'X-WP-Nonce': InitChatEngineData.nonce },
        } )
        .then( function ( r ) {
            if ( ! r.ok ) throw new Error( 'HTTP ' + r.status );
            return r.json();
        } )
        .then( function ( data ) {
            if ( data.success ) {
                renderPinnedBanner( null );
            } else {
                showError( data.message || pinT( 'unpin_failed', 'Could not unpin message.' ) );
            }
        } )
        .catch( function () {
            showError( pinT( 'unpin_connect_error', 'Connection error while unpinning.' ) );
        } )
        .finally( function () {
            pinnedState.isPinning = false;
            if ( unpinBtn ) unpinBtn.disabled = false;
        } );
    }

    // ---- Banner buttons ----------------------------------------

    // Click vào vùng nội dung banner → jump đến tin nhắn
    const pinnedClickable = pinnedBanner.querySelector( '.init-chatbox-pinned-clickable' );
    if ( pinnedClickable ) {
        const jumpToPinned = function () {
            if ( ! pinnedState.currentPinnedId || ! messagesListEl ) return;

            const target = messagesListEl.querySelector(
                '[data-message-id="' + pinnedState.currentPinnedId + '"]'
            );

            if ( target ) {
                target.scrollIntoView( { behavior: 'smooth', block: 'center' } );
                target.classList.remove( 'pinned-highlight' );
                requestAnimationFrame( function () {
                    target.classList.add( 'pinned-highlight' );
                    setTimeout( function () {
                        target.classList.remove( 'pinned-highlight' );
                    }, 1800 );
                } );
            } else {
                showError( pinT( 'pin_not_loaded', 'Pinned message is not loaded yet. Scroll up to load history.' ), true );
            }
        };

        pinnedClickable.addEventListener( 'click', jumpToPinned );

        // Keyboard: Enter / Space
        pinnedClickable.addEventListener( 'keydown', function ( e ) {
            if ( e.key === 'Enter' || e.key === ' ' ) {
                e.preventDefault();
                jumpToPinned();
            }
        } );
    }

    const unpinBannerBtn = pinnedBanner.querySelector( '.init-chatbox-pinned-unpin' );
    if ( unpinBannerBtn && isAdmin ) {
        unpinBannerBtn.addEventListener( 'click', doUnpin );
    }

    const expandBtn = pinnedBanner.querySelector('.init-chatbox-pinned-expand');

    if (expandBtn) {
        let expanded = false;

        expandBtn.addEventListener('click', function (e) {
            e.stopPropagation();

            const textEl = pinnedBanner.querySelector('.init-chatbox-pinned-text');
            if (!textEl) return;

            const full = textEl.dataset.full || textEl.textContent;

            if (!expanded) {
                textEl.textContent = full;
                textEl.classList.add('expanded');
                expandBtn.textContent = '⤡';
                expanded = true;
            } else {
                const preview = full.length > 100
                    ? full.substring(0, 100) + '\u2026'
                    : full;

                textEl.textContent = preview;
                textEl.classList.remove('expanded');
                expandBtn.textContent = '⤢';
                expanded = false;
            }
        });
    }

    // ---- MutationObserver: thêm pin button vào message mới ----

    if ( isAdmin && messagesListEl ) {
        const pinObserver = new MutationObserver( function ( mutations ) {
            mutations.forEach( function ( mutation ) {
                mutation.addedNodes.forEach( function ( node ) {
                    if ( node.nodeType !== Node.ELEMENT_NODE ) return;

                    if ( node.classList && node.classList.contains( 'init-chatbox-message' ) ) {
                        addPinButtonToMessage( node, node.dataset.messageId );
                        return;
                    }

                    if ( node.querySelectorAll ) {
                        node.querySelectorAll( '.init-chatbox-message[data-message-id]' )
                            .forEach( function ( el ) {
                                addPinButtonToMessage( el, el.dataset.messageId );
                            } );
                    }
                } );
            } );
        } );

        pinObserver.observe( messagesListEl, { childList: true, subtree: false } );
    }

    // ---- handlePinnedFromResponse ------------------------------

    function handlePinnedFromResponse( data ) {
        if ( ! data || ! data.success ) return;

        if ( Object.prototype.hasOwnProperty.call( data, 'pinned_message' ) ) {
            const incoming   = data.pinned_message;
            const incomingId = incoming ? incoming.id : null;

            // Chỉ re-render khi ID thay đổi (tránh flicker khi polling)
            if ( incomingId !== pinnedState.currentPinnedId ) {
                renderPinnedBanner( incoming || null );
            }
        }
    }

    window._initChatPinHandleResponse = handlePinnedFromResponse;

    // ---- Load trạng thái pin ban đầu --------------------------
    // Không cần request riêng (?limit=1) như trước: response của lần tải tin nhắn
    // đầu tiên (initializeChat) đã kèm sẵn pinned_message và được xử lý qua
    // handlePinnedFromResponse() - tiết kiệm 1 request REST cho mỗi lượt tải trang.

    // ============================================================
    // END PINNED MESSAGE FEATURE
    // ============================================================
});
