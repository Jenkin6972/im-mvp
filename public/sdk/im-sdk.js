/**
 * IM-MVP SDK
 * 可嵌入任意网页的客服聊天组件
 */
(function(window, document) {
    'use strict';

    const VERSION = '1.0.0';

    // 默认配置
    const defaultConfig = {
        server: '',              // WebSocket 地址，如: ws://example.com/ws
        apiServer: '',           // HTTP API 地址，如: http://example.com（可选，不填则从 server 推导）
        position: 'right',       // 'right' | 'left'
        theme: '#1890ff',
        title: 'Customer Service',
        zIndex: 2147483647
    };

    // 默认文案配置（fallback，当无法获取服务器配置时使用）
    const defaultTexts = {
        welcome_message: 'Hello, how can I help you?',
        input_placeholder: 'Type a message...',
        send_button: 'Send',
        status_connected: 'Connected',
        status_disconnected: 'Disconnected',
        status_error: 'Connection error',
        agent_typing: 'Agent is typing...',
        conversation_closed: 'Conversation ended',
        queue_waiting: 'Waiting in queue...',
        agent_assigned: 'Agent connected',
        offline_messages_tip: 'You have {count} offline message(s)'
    };

    // SDK主对象
    const ImSDK = {
        version: VERSION,
        config: { ...defaultConfig },
        texts: { ...defaultTexts },
        state: {
            isOpen: false,
            isConnected: false,
            customerUuid: null,
            conversationId: null,
            unreadCount: 0,
            messages: [],
            agentTyping: false,        // 客服正在输入
            typingTimer: null,         // 打字状态发送节流
            agentTypingTimer: null     // 客服打字状态超时
        },
        ws: null,
        elements: {},
        reconnectTimer: null,
        heartbeatTimer: null,

        /**
         * 初始化SDK
         */
        async init(options = {}) {
            this.config = { ...defaultConfig, ...options };

            if (!this.config.server) {
                console.error('[IM-SDK] server 配置不能为空');
                return;
            }

            // 先加载文案配置
            await this.loadTextsConfig();

            this.loadCustomerUuid();
            this.loadMessages();
            this.injectStyles();
            this.render();
            this.bindEvents();

            console.log('[IM-SDK] Initialized', this.config);
        },

        /**
         * 获取 HTTP API 服务器地址
         */
        getApiServer() {
            // 如果配置了 apiServer，直接使用
            if (this.config.apiServer) {
                return this.config.apiServer;
            }
            // 否则从 WebSocket 地址推导：ws://host/ws -> http://host
            return this.config.server
                .replace('ws://', 'http://')
                .replace('wss://', 'https://')
                .replace(/\/ws\/?$/, '');  // 移除末尾的 /ws
        },

        /**
         * 从服务器加载文案配置
         */
        async loadTextsConfig() {
            try {
                const httpServer = this.getApiServer();
                const res = await fetch(`${httpServer}/config/sdk-texts`);
                const data = await res.json();
                if (data.code === 0 && data.data) {
                    this.texts = { ...defaultTexts, ...data.data };
                    console.log('[IM-SDK] Texts loaded:', this.texts.language);
                }
            } catch (e) {
                console.log('[IM-SDK] Load texts config failed, using defaults', e);
            }
        },

        /**
         * 生成/获取客户UUID
         */
        loadCustomerUuid() {
            let uuid = localStorage.getItem('im_customer_uuid');
            if (!uuid) {
                uuid = 'cust_' + Date.now() + '_' + Math.random().toString(36).substr(2, 9);
                localStorage.setItem('im_customer_uuid', uuid);
            }
            this.state.customerUuid = uuid;
        },

        /**
         * 从本地存储加载消息
         */
        loadMessages() {
            try {
                const saved = localStorage.getItem('im_messages');
                if (saved) {
                    this.state.messages = JSON.parse(saved);
                }
            } catch (e) {
                this.state.messages = [];
            }
        },

        /**
         * 保存消息到本地存储
         */
        saveMessages() {
            try {
                // 只保留最近100条
                const toSave = this.state.messages.slice(-100);
                localStorage.setItem('im_messages', JSON.stringify(toSave));
            } catch (e) {
                console.error('[IM-SDK] Save messages failed', e);
            }
        },

        /**
         * 注入样式
         */
        injectStyles() {
            if (document.getElementById('im-sdk-styles')) return;

            const css = this.getStyles();
            const style = document.createElement('style');
            style.id = 'im-sdk-styles';
            style.textContent = css;
            document.head.appendChild(style);
        },

        /**
         * 获取CSS样式
         */
        getStyles() {
            const theme = this.config.theme;
            const position = this.config.position;
            const positionStyle = position === 'left' ? 'left: 20px;' : 'right: 20px;';

            return `
                .im-sdk-widget {
                    position: fixed !important;
                    bottom: 20px !important;
                    ${positionStyle}
                    z-index: ${this.config.zIndex} !important;
                    font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif !important;
                    font-size: 14px !important;
                    line-height: 1.5 !important;
                }
                .im-sdk-bubble {
                    width: 60px;
                    height: 60px;
                    border-radius: 50%;
                    background: ${theme};
                    box-shadow: 0 4px 12px rgba(0,0,0,0.15);
                    cursor: pointer;
                    display: flex;
                    align-items: center;
                    justify-content: center;
                    transition: transform 0.2s, box-shadow 0.2s;
                }
                .im-sdk-bubble:hover {
                    transform: scale(1.05);
                    box-shadow: 0 6px 16px rgba(0,0,0,0.2);
                }
                .im-sdk-bubble svg {
                    width: 28px;
                    height: 28px;
                    fill: white;
                }
                .im-sdk-badge {
                    position: absolute;
                    top: -5px;
                    right: -5px;
                    min-width: 20px;
                    height: 20px;
                    border-radius: 10px;
                    background: #ff4d4f;
                    color: white;
                    font-size: 12px;
                    display: none;
                    align-items: center;
                    justify-content: center;
                    padding: 0 6px;
                }
                .im-sdk-badge.show { display: flex; }
                .im-sdk-window {
                    position: absolute;
                    bottom: 0;
                    ${position === 'left' ? 'left: 0;' : 'right: 0;'}
                    width: 360px;
                    height: 500px;
                    background: white;
                    border-radius: 12px;
                    box-shadow: 0 8px 32px rgba(0,0,0,0.15);
                    display: flex;
                    flex-direction: column;
                    overflow: hidden;
                }
                .im-sdk-header {
                    background: ${theme};
                    color: white;
                    padding: 16px;
                    display: flex;
                    align-items: center;
                }
                .im-sdk-title {
                    font-weight: 600;
                    font-size: 16px;
                    flex: 1;
                }
                .im-sdk-status {
                    font-size: 12px;
                    opacity: 0.8;
                    margin-right: 12px;
                }
                .im-sdk-close {
                    background: none;
                    border: none;
                    color: white;
                    font-size: 24px;
                    cursor: pointer;
                    padding: 0;
                    line-height: 1;
                    opacity: 0.8;
                }
                .im-sdk-close:hover { opacity: 1; }
                .im-sdk-messages {
                    flex: 1;
                    overflow-y: auto;
                    padding: 16px;
                    background: #f5f5f5;
                }
                .im-sdk-msg-wrapper {
                    display: flex;
                    align-items: flex-start;
                    gap: 8px;
                    margin-bottom: 12px;
                }
                .im-sdk-msg-wrapper-left { flex-direction: row; }
                .im-sdk-msg-wrapper-right { flex-direction: row-reverse; }
                .im-sdk-msg-avatar {
                    width: 32px;
                    height: 32px;
                    border-radius: 50%;
                    flex-shrink: 0;
                    display: flex;
                    align-items: center;
                    justify-content: center;
                    font-size: 12px;
                    color: white;
                }
                .im-sdk-avatar-customer { background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); }
                .im-sdk-avatar-agent { background: linear-gradient(135deg, #11998e 0%, #38ef7d 100%); }
                .im-sdk-msg-bubble { max-width: 75%; }
                .im-sdk-msg {
                    padding: 10px 14px;
                    border-radius: 12px;
                    word-break: break-word;
                }
                .im-sdk-msg-left {
                    background: white;
                    border-bottom-left-radius: 4px;
                }
                .im-sdk-msg-right {
                    background: ${theme};
                    color: white;
                    border-bottom-right-radius: 4px;
                }
                .im-sdk-msg-time {
                    font-size: 10px;
                    color: #999;
                    margin-top: 4px;
                }
                .im-sdk-msg-wrapper-right .im-sdk-msg-time { text-align: right; }
                .im-sdk-msg-system {
                    text-align: center;
                    color: #999;
                    font-size: 12px;
                    margin: 10px 0;
                }
                .im-sdk-typing {
                    color: #999;
                    font-size: 12px;
                    padding: 8px 16px;
                    display: none;
                }
                .im-sdk-typing.show { display: block; }
                .im-sdk-msg-status {
                    font-size: 11px;
                    margin-left: 6px;
                    opacity: 0.7;
                }
                .im-sdk-msg-status.read { color: #52c41a; }
                .im-sdk-footer {
                    padding: 12px;
                    background: white;
                    border-top: 1px solid #eee;
                    display: flex;
                    gap: 8px;
                }
                .im-sdk-input {
                    flex: 1;
                    border: 1px solid #ddd;
                    border-radius: 20px;
                    padding: 10px 16px;
                    outline: none;
                    font-size: 14px;
                }
                .im-sdk-input:focus { border-color: ${theme}; }
                .im-sdk-send {
                    background: ${theme};
                    color: white;
                    border: none;
                    border-radius: 20px;
                    padding: 10px 20px;
                    cursor: pointer;
                    font-size: 14px;
                }
                .im-sdk-send:hover { opacity: 0.9; }
            `;
        },

        /**
         * 渲染组件
         */
        render() {
            const widget = document.createElement('div');
            widget.className = 'im-sdk-widget';
            widget.innerHTML = this.getBubbleHTML() + this.getWindowHTML();
            document.body.appendChild(widget);

            this.elements.widget = widget;
            this.elements.bubble = widget.querySelector('.im-sdk-bubble');
            this.elements.badge = widget.querySelector('.im-sdk-badge');
            this.elements.window = widget.querySelector('.im-sdk-window');
            this.elements.messages = widget.querySelector('.im-sdk-messages');
            this.elements.input = widget.querySelector('.im-sdk-input');
            this.elements.sendBtn = widget.querySelector('.im-sdk-send');
            this.elements.closeBtn = widget.querySelector('.im-sdk-close');
            this.elements.status = widget.querySelector('.im-sdk-status');
            this.elements.typing = widget.querySelector('.im-sdk-typing');
        },

        getBubbleHTML() {
            return `
                <div class="im-sdk-bubble">
                    <svg viewBox="0 0 24 24"><path d="M20 2H4c-1.1 0-2 .9-2 2v18l4-4h14c1.1 0 2-.9 2-2V4c0-1.1-.9-2-2-2z"/></svg>
                    <span class="im-sdk-badge">0</span>
                </div>
            `;
        },

        getWindowHTML() {
            return `
                <div class="im-sdk-window" style="display:none;">
                    <div class="im-sdk-header">
                        <span class="im-sdk-title">${this.config.title}</span>
                        <span class="im-sdk-status">${this.texts.status_disconnected}</span>
                        <button class="im-sdk-close">&times;</button>
                    </div>
                    <div class="im-sdk-messages"></div>
                    <div class="im-sdk-typing">${this.texts.agent_typing}</div>
                    <div class="im-sdk-footer">
                        <input type="text" class="im-sdk-input" placeholder="${this.texts.input_placeholder}">
                        <button class="im-sdk-send">${this.texts.send_button}</button>
                    </div>
                </div>
            `;
        },

        bindEvents() {
            this.elements.bubble.addEventListener('click', () => this.toggle());
            this.elements.closeBtn.addEventListener('click', () => this.close());
            this.elements.sendBtn.addEventListener('click', () => this.sendMessage());
            this.elements.input.addEventListener('keypress', (e) => {
                if (e.key === 'Enter') this.sendMessage();
            });
            // 打字状态
            this.elements.input.addEventListener('input', () => this.sendTypingStatus(true));
            this.elements.input.addEventListener('blur', () => this.sendTypingStatus(false));
        },

        toggle() {
            this.state.isOpen ? this.close() : this.open();
        },

        open() {
            this.state.isOpen = true;
            this.elements.window.style.display = 'flex';
            this.elements.bubble.style.display = 'none';
            this.state.unreadCount = 0;
            this.updateBadge();
            this.renderMessages();
            this.connect();
            this.elements.input.focus();

            // 每次打开窗口时，如果已连接则发送已读状态
            if (this.ws && this.ws.readyState === WebSocket.OPEN && this.state.conversationId) {
                this.sendReadStatus();
            }

            // 每次打开窗口时调用 history 接口同步已读状态
            this.fetchHistory();

            // 显示欢迎语（每次打开窗口都显示，但只显示一次）
            this.showWelcomeMessage();
        },

        /**
         * 显示欢迎语
         * 每次打开聊天窗口时显示系统欢迎消息
         */
        showWelcomeMessage() {
            // 优先使用服务器配置的欢迎语，否则使用本地配置
            const welcomeText = this.texts.welcome_message || this.config.welcomeMessage;
            if (!welcomeText) return;

            // 创建欢迎消息（不保存到本地存储，每次打开都显示）
            const welcomeMsg = {
                id: 'welcome_' + Date.now(),
                content: welcomeText,
                sender_type: 3, // 系统消息
                created_at: new Date().toISOString(),
                isWelcome: true // 标记为欢迎消息
            };

            // 直接渲染到消息区域（追加到末尾）
            this.renderWelcomeMessage(welcomeMsg);
        },

        /**
         * 渲染欢迎消息到消息区域
         */
        renderWelcomeMessage(msg) {
            const container = this.elements.messages;
            if (!container) return;

            // 检查是否已经有欢迎消息（避免重复）
            if (container.querySelector('.im-sdk-welcome-msg')) return;

            const div = document.createElement('div');
            div.className = 'im-sdk-msg im-sdk-msg-system im-sdk-welcome-msg';
            const span = document.createElement('span');
            span.textContent = msg.content;
            div.appendChild(span);

            // 插入到消息列表末尾，用户每次打开都能看到
            container.appendChild(div);
            container.scrollTop = container.scrollHeight;
        },

        close() {
            this.state.isOpen = false;
            this.elements.window.style.display = 'none';
            this.elements.bubble.style.display = 'flex';
        },

        connect() {
            if (this.ws && this.ws.readyState === WebSocket.OPEN) return;

            // 先初始化客户信息（发送来源页面等）
            this.initCustomer();

            const wsUrl = `${this.config.server}?type=customer&uuid=${this.state.customerUuid}`;
            this.ws = new WebSocket(wsUrl);

            this.ws.onopen = () => {
                this.state.isConnected = true;
                this.updateStatus(this.texts.status_connected);
                this.startHeartbeat();
                this.fetchHistory();
            };

            this.ws.onmessage = (e) => {
                try {
                    const data = JSON.parse(e.data);
                    this.handleMessage(data);
                } catch (err) {}
            };

            this.ws.onclose = () => {
                this.state.isConnected = false;
                this.updateStatus(this.texts.status_disconnected);
                this.stopHeartbeat();
                if (this.state.isOpen) {
                    this.reconnectTimer = setTimeout(() => this.connect(), 3000);
                }
            };

            this.ws.onerror = () => {
                this.updateStatus(this.texts.status_error);
            };
        },

        /**
         * 初始化客户信息（发送来源页面、设备信息等）
         */
        initCustomer() {
            const httpServer = this.getApiServer();

            // 自动获取客户时区
            let timezone = '';
            try {
                timezone = Intl.DateTimeFormat().resolvedOptions().timeZone || '';
            } catch (e) {
                // 浏览器不支持时区API，使用UTC偏移量
                const offset = new Date().getTimezoneOffset();
                const hours = Math.abs(Math.floor(offset / 60));
                const sign = offset <= 0 ? '+' : '-';
                timezone = `UTC${sign}${hours}`;
            }

            const params = new URLSearchParams({
                uuid: this.state.customerUuid,
                source_url: window.location.href,
                referrer: document.referrer || '',
                timezone: timezone
            });

            fetch(`${httpServer}/customer/init`, {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: params.toString()
            }).catch(e => console.log('[IM-SDK] Init customer failed', e));
        },

        handleMessage(data) {
            switch (data.type) {
                case 'connected':
                    // 连接后发送已读状态
                    if (this.state.conversationId) {
                        this.sendReadStatus();
                    }
                    break;
                case 'new_message':
                case 'message_sent':
                    // 确保 conversationId 已设置
                    if (data.data?.conversation_id && !this.state.conversationId) {
                        this.state.conversationId = data.data.conversation_id;
                    }
                    this.addMessage(data.data);
                    // 消息送达后发送已读确认
                    if (data.data?.sender_type === 2 && this.state.isOpen) {
                        this.sendReadStatus();
                    }
                    break;
                case 'conversation_closed':
                    this.addSystemMessage(this.texts.conversation_closed);
                    break;
                case 'queue_notice':
                    // 排队等待通知
                    this.addSystemMessage(data.data?.message || this.texts.queue_waiting);
                    break;
                case 'agent_assigned':
                    // 客服已接入
                    this.addSystemMessage(data.data?.message || this.texts.agent_assigned);
                    this.state.conversationId = data.data?.conversation_id;
                    break;
                case 'offline_messages':
                    // 离线消息
                    this.handleOfflineMessages(data.data);
                    break;
                case 'typing':
                    this.handleTyping(data.data);
                    break;
                case 'messages_read':
                    this.handleMessagesRead(data.data);
                    break;
                case 'pong':
                    break;
            }
        },

        // 处理离线消息
        handleOfflineMessages(data) {
            const messages = data?.messages || [];
            if (messages.length === 0) return;

            this.state.conversationId = data?.conversation_id;

            // 添加离线消息提示
            const offlineTip = this.texts.offline_messages_tip.replace('{count}', messages.length);
            this.addSystemMessage(offlineTip);

            // 添加每条消息
            messages.forEach(m => this.addMessage(m));

            // 发送已读确认
            this.sendReadStatus();
        },

        // 处理客服打字状态
        handleTyping(data) {
            const isTyping = data?.is_typing;
            this.state.agentTyping = isTyping;

            // 清除之前的超时定时器
            if (this.state.agentTypingTimer) {
                clearTimeout(this.state.agentTypingTimer);
            }

            // 设置超时自动清除打字状态
            if (isTyping) {
                this.state.agentTypingTimer = setTimeout(() => {
                    this.state.agentTyping = false;
                    this.updateTypingIndicator();
                }, 3000);
            }

            this.updateTypingIndicator();
        },

        // 更新打字指示器
        updateTypingIndicator() {
            if (this.elements.typing) {
                this.elements.typing.classList.toggle('show', this.state.agentTyping);
            }
        },

        // 发送打字状态
        sendTypingStatus(isTyping) {
            if (!this.ws || this.ws.readyState !== WebSocket.OPEN) return;

            // 节流
            if (isTyping) {
                if (this.state.typingTimer) return;
                this.ws.send(JSON.stringify({
                    type: 'typing',
                    data: { is_typing: true }
                }));
                this.state.typingTimer = setTimeout(() => {
                    this.state.typingTimer = null;
                }, 2000);
            } else {
                if (this.state.typingTimer) {
                    clearTimeout(this.state.typingTimer);
                    this.state.typingTimer = null;
                }
                this.ws.send(JSON.stringify({
                    type: 'typing',
                    data: { is_typing: false }
                }));
            }
        },

        // 发送已读状态
        sendReadStatus() {
            if (!this.ws || this.ws.readyState !== WebSocket.OPEN) return;
            this.ws.send(JSON.stringify({
                type: 'read',
                data: { conversation_id: this.state.conversationId }
            }));
        },

        // 处理消息已读通知
        handleMessagesRead(data) {
            if (data?.reader === 'agent') {
                // 标记客户发送的消息为已读
                this.state.messages.forEach(m => {
                    if (m.sender_type === 1) {
                        m.is_read = true;
                    }
                });
                this.renderMessages();
            }
        },

        sendMessage() {
            const content = this.elements.input.value.trim();
            if (!content || !this.state.isConnected) return;

            this.ws.send(JSON.stringify({
                type: 'message',
                data: { content }
            }));
            this.elements.input.value = '';
        },

        addMessage(msg) {
            const exists = this.state.messages.find(m => m.id === msg.id);
            if (exists) return;

            this.state.messages.push(msg);
            this.saveMessages();
            this.renderMessages();

            if (!this.state.isOpen && msg.sender_type === 2) {
                this.state.unreadCount++;
                this.updateBadge();
            }
        },

        addSystemMessage(text) {
            this.addMessage({
                id: Date.now(),
                sender_type: 3,
                content: text,
                created_at: new Date().toISOString()
            });
        },

        renderMessages() {
            const container = this.elements.messages;
            container.innerHTML = this.state.messages.map(m => this.getMessageHTML(m)).join('');
            container.scrollTop = container.scrollHeight;
        },

        getMessageHTML(msg) {
            const isCustomer = msg.sender_type === 1;
            const isSystem = msg.sender_type === 3;
            const escapedContent = this.escapeHtml(msg.content);

            if (isSystem) {
                return `<div class="im-sdk-msg-system">${escapedContent}</div>`;
            }

            // 客户发送的消息显示已读状态
            let statusHTML = '';
            if (isCustomer) {
                if (msg.is_read) {
                    statusHTML = '<span class="im-sdk-msg-status read">✓✓</span>';
                } else if (msg.id) {
                    statusHTML = '<span class="im-sdk-msg-status">✓</span>';
                }
            }

            // 消息方向
            const wrapperCls = isCustomer ? 'im-sdk-msg-wrapper-right' : 'im-sdk-msg-wrapper-left';
            const msgCls = isCustomer ? 'im-sdk-msg-right' : 'im-sdk-msg-left';
            const avatarCls = isCustomer ? 'im-sdk-avatar-customer' : 'im-sdk-avatar-agent';
            const avatarText = isCustomer ? 'My' : 'CS';

            // 格式化时间
            let timeStr = '';
            if (msg.created_at) {
                const timePart = msg.created_at.split(' ')[1];
                if (timePart) {
                    timeStr = timePart.substring(0, 5); // HH:MM
                }
            }

            return `
                <div class="im-sdk-msg-wrapper ${wrapperCls}">
                    <div class="im-sdk-msg-avatar ${avatarCls}">${avatarText}</div>
                    <div class="im-sdk-msg-bubble">
                        <div class="im-sdk-msg ${msgCls}">${escapedContent}${statusHTML}</div>
                        <div class="im-sdk-msg-time">${timeStr}</div>
                    </div>
                </div>`;
        },

        // HTML转义，防止XSS攻击
        escapeHtml(str) {
            if (!str) return '';
            const div = document.createElement('div');
            div.textContent = str;
            return div.innerHTML;
        },

        updateStatus(text) {
            this.elements.status.textContent = text;
        },

        updateBadge() {
            const badge = this.elements.badge;
            if (this.state.unreadCount > 0) {
                badge.textContent = this.state.unreadCount > 99 ? '99+' : this.state.unreadCount;
                badge.classList.add('show');
            } else {
                badge.classList.remove('show');
            }
        },

        startHeartbeat() {
            this.heartbeatTimer = setInterval(() => {
                if (this.ws && this.ws.readyState === WebSocket.OPEN) {
                    this.ws.send(JSON.stringify({ type: 'ping' }));
                }
            }, 30000);
        },

        stopHeartbeat() {
            if (this.heartbeatTimer) {
                clearInterval(this.heartbeatTimer);
                this.heartbeatTimer = null;
            }
        },

        fetchHistory() {
            const httpServer = this.getApiServer();
            fetch(`${httpServer}/customer/history?uuid=${this.state.customerUuid}`)
                .then(r => r.json())
                .then(res => {
                    if (res.code === 0 && res.data.list) {
                        // 从历史消息中获取会话ID
                        if (res.data.conversation_id) {
                            this.state.conversationId = res.data.conversation_id;
                        }
                        res.data.list.forEach(m => this.addMessage(m));
                    }
                })
                .catch(() => {});
        }
    };

    window.ImSDK = ImSDK;

})(window, document);

