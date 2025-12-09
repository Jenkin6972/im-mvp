/**
 * 客服工作台 JS
 */
(function() {
    // const API_BASE = 'http://127.0.0.1:9501';
    const API_BASE = 'http://54.151.35.185';
    // const WS_URL = 'ws://127.0.0.1:9502';
    const WS_URL = 'ws://54.151.35.185:80';

    const state = {
        token: localStorage.getItem('agent_token') || '',
        agent: null,
        conversations: [],
        currentConvId: null,
        messages: {},
        ws: null,
        heartbeatTimer: null,        // 心跳定时器
        notificationAudio: null,
        audioUnlocked: false,
        typingTimer: null,           // 打字状态发送节流
        customerTyping: {},          // 客户打字状态 { convId: bool }
        customerTypingTimer: {},     // 客户打字状态超时定时器
        quickReplies: [],            // 快捷回复列表
        godViewMode: false,          // 超管上帝视角模式
        allAgents: [],               // 所有客服列表（上帝视角用）
        currentView: 'chat',         // 当前视图：chat/stats
        currentCustomer: null,       // 当前选中会话的客户信息
        customerPanelVisible: true   // 客户信息面板是否显示
    };

    const $ = (sel) => document.querySelector(sel);

    // 初始化并解锁音频（需要在用户点击事件中调用）
    function unlockAudio() {
        if (state.audioUnlocked) return;

        if (!state.notificationAudio) {
            state.notificationAudio = new Audio('/dingding.mp3');
            state.notificationAudio.volume = 0.5;
        }

        // 静音播放一次来解锁
        state.notificationAudio.muted = true;
        state.notificationAudio.play().then(() => {
            state.notificationAudio.pause();
            state.notificationAudio.muted = false;
            state.notificationAudio.currentTime = 0;
            state.audioUnlocked = true;
            console.log('Audio unlocked');
        }).catch(e => {
            console.log('Audio unlock failed:', e);
        });
    }

    // 播放提示音
    function playNotificationSound() {
        if (!state.notificationAudio || !state.audioUnlocked) {
            console.log('Audio not ready');
            return;
        }
        state.notificationAudio.currentTime = 0;
        state.notificationAudio.play().catch(e => {
            console.log('Audio play failed:', e);
        });
    }

    // Toast 提示
    function showToast(message, duration = 3000) {
        // 移除旧的 toast
        const oldToast = document.querySelector('.toast-message');
        if (oldToast) oldToast.remove();

        const toast = document.createElement('div');
        toast.className = 'toast-message';
        toast.textContent = message;
        document.body.appendChild(toast);

        setTimeout(() => {
            toast.classList.add('fade-out');
            setTimeout(() => toast.remove(), 300);
        }, duration);
    }

    // 初始化
    function init() {
        if (state.token) {
            checkAuth();
        }
        bindEvents();
    }

    function bindEvents() {
        $('#loginBtn').onclick = login;
        $('#password').onkeypress = (e) => { if (e.key === 'Enter') login(); };
        $('#logoutBtn').onclick = logout;
        $('#statusSelect').onchange = updateStatus;

        // 监听页面任意点击，解锁音频（针对已登录用户刷新页面的情况）
        document.addEventListener('click', function onFirstClick() {
            unlockAudio();
            document.removeEventListener('click', onFirstClick);
        }, { once: true });

        // 上帝视角切换
        $('#godViewCheckbox').onchange = toggleGodViewMode;

        // 筛选器事件
        $('#statusFilter').onchange = applyFilters;
        $('#agentFilter').onchange = applyFilters;

        // 管理中心下拉菜单
        $('#adminDropdownBtn').onclick = toggleAdminDropdown;
        $('#statsMenuItem').onclick = () => { closeAdminDropdown(); showStatsPage(); };
        $('#agentMgmtMenuItem').onclick = () => { closeAdminDropdown(); showAgentMgmtPage(); };
        $('#textConfigMenuItem').onclick = () => { closeAdminDropdown(); showTextConfigModal(); };
        // 点击其他区域关闭下拉菜单
        document.addEventListener('click', (e) => {
            if (!e.target.closest('.admin-dropdown')) {
                closeAdminDropdown();
            }
        });
    }

    async function login() {
        // 用户点击登录，解锁音频
        unlockAudio();

        const username = $('#username').value.trim();
        const password = $('#password').value.trim();
        if (!username || !password) {
            $('#loginError').textContent = '请输入用户名和密码';
            return;
        }

        try {
            const res = await fetch(`${API_BASE}/auth/login`, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ username, password })
            });
            const data = await res.json();
            if (data.code === 0) {
                state.token = data.data.token;
                state.agent = data.data.agent;
                localStorage.setItem('agent_token', state.token);
                showWorkspace();
            } else {
                $('#loginError').textContent = data.message;
            }
        } catch (e) {
            $('#loginError').textContent = '网络错误';
        }
    }

    async function checkAuth() {
        try {
            const res = await fetch(`${API_BASE}/agent/info`, {
                headers: { 'Authorization': `Bearer ${state.token}` }
            });
            const data = await res.json();
            if (data.code === 0) {
                state.agent = data.data;
                showWorkspace();
            } else {
                logout();
            }
        } catch (e) {
            logout();
        }
    }

    function logout() {
        state.token = '';
        state.agent = null;
        localStorage.removeItem('agent_token');
        $('#loginPage').style.display = 'flex';
        $('#workspace').style.display = 'none';

        // 清理心跳定时器
        if (state.heartbeatTimer) {
            clearInterval(state.heartbeatTimer);
            state.heartbeatTimer = null;
        }

        // 关闭 WebSocket 连接（先置空 onclose 防止重连）
        if (state.ws) {
            state.ws.onclose = null;
            state.ws.close();
            state.ws = null;
        }
    }

    function showWorkspace() {
        $('#loginPage').style.display = 'none';
        $('#workspace').style.display = 'block';
        $('#agentName').textContent = state.agent.nickname || state.agent.username;
        $('#statusSelect').value = state.agent.status || 1;
        console.log('state.agent.is_admin', state.agent.is_admin);

        // 状态筛选栏对所有客服显示
        $('#filterBar').style.display = 'flex';

        // 超管专属功能显示
        if (state.agent.is_admin === 1) {
            $('#adminModeToggle').style.display = 'flex';
            $('#adminDropdown').style.display = 'block';
        }

        loadConversations();
        loadQuickReplies();
        connectWS();
    }

    // 切换上帝视角模式
    function toggleGodViewMode() {
        state.godViewMode = $('#godViewCheckbox').checked;
        state.currentConvId = null;

        if (state.godViewMode) {
            $('#sidebarTitle').textContent = '所有会话（只读）';
            $('#agentFilter').style.display = 'block';
            loadAllConversations();
        } else {
            $('#sidebarTitle').textContent = '会话列表';
            $('#agentFilter').style.display = 'none';
            $('#statusFilter').value = '';
            $('#agentFilter').value = '';
            loadConversations();
        }

        $('#chatArea').innerHTML = '<div class="empty-chat">请选择一个会话</div>';
        // 隐藏客户信息面板
        state.currentCustomer = null;
        $('#customerPanel').style.display = 'none';
    }

    // 应用筛选条件
    function applyFilters() {
        if (state.godViewMode) {
            loadAllConversations();
        } else {
            loadConversations();
        }
    }

    // 加载所有会话（上帝视角）
    async function loadAllConversations() {
        try {
            const status = $('#statusFilter').value;
            const agentId = $('#agentFilter').value;
            let url = `${API_BASE}/conversation/all?`;
            if (status !== '') url += `status=${status}&`;
            if (agentId !== '') url += `agent_id=${agentId}&`;

            const res = await fetch(url, {
                headers: { 'Authorization': `Bearer ${state.token}` }
            });
            const data = await res.json();
            if (data.code === 0) {
                state.conversations = data.data.list;
                state.allAgents = data.data.agents || [];
                updateAgentFilter();
                renderConversations();
            }
        } catch (e) {
            console.error('加载所有会话失败', e);
        }
    }

    // 更新客服筛选下拉框
    function updateAgentFilter() {
        const select = $('#agentFilter');
        const currentValue = select.value;
        select.innerHTML = '<option value="">全部客服</option>' +
            state.allAgents.map(a => `<option value="${a.id}">${a.nickname || a.username}</option>`).join('');
        select.value = currentValue;
    }

    async function loadConversations() {
        try {
            const status = $('#statusFilter').value;
            let url = `${API_BASE}/conversation/list`;
            if (status !== '') {
                url += `?status=${status}`;
            }
            const res = await fetch(url, {
                headers: { 'Authorization': `Bearer ${state.token}` }
            });
            const data = await res.json();
            if (data.code === 0) {
                state.conversations = data.data.list;
                renderConversations();
            }
        } catch (e) {}
    }

    function renderConversations() {
        const list = $('#convList');
        list.innerHTML = state.conversations.map(c => {
            const lastMsg = c.last_message;
            let preview = '暂无消息';
            if (lastMsg) {
                // 根据发送者类型显示前缀
                const prefix = lastMsg.sender_type === 2 ? '我: ' : '';
                preview = prefix + (lastMsg.content || '');
                // 截断过长的内容
                if (preview.length > 20) {
                    preview = preview.substring(0, 20) + '...';
                }
            }
            const time = lastMsg?.created_at ? formatTime(lastMsg.created_at) : '';
            const unreadCount = c.unread_count || 0;
            const unreadBadge = unreadCount > 0 ? `<span class="conv-unread">${unreadCount > 99 ? '99+' : unreadCount}</span>` : '';

            // 显示会话状态标签
            let statusLabel = '';
            let agentLabel = '';
            const statusMap = { 0: '待分配', 1: '进行中', 2: '已关闭' };
            const statusColors = { 0: '#faad14', 1: '#52c41a', 2: '#999' };
            // 非进行中的会话都显示状态标签
            if (c.status !== 1) {
                statusLabel = `<span style="font-size:11px;color:${statusColors[c.status]};margin-left:4px;">[${statusMap[c.status]}]</span>`;
            }
            // 上帝视角模式下显示客服信息
            if (state.godViewMode) {
                // 上帝视角下始终显示状态
                statusLabel = `<span style="font-size:11px;color:${statusColors[c.status]};margin-left:4px;">[${statusMap[c.status]}]</span>`;
                if (c.agent) {
                    agentLabel = `<div style="font-size:11px;color:#1890ff;">客服: ${c.agent.nickname || c.agent.username}</div>`;
                }
            }

            return `
                <div class="conversation-item ${c.id === state.currentConvId ? 'active' : ''}" data-id="${c.id}">
                    <div class="conv-avatar">${(c.customer?.uuid || '?').charAt(0).toUpperCase()}</div>
                    <div class="conv-info">
                        <div class="conv-name">客户 ${c.customer?.id || c.id}${statusLabel}${unreadBadge}</div>
                        ${agentLabel}
                        <div class="conv-preview">${preview}</div>
                    </div>
                    <div class="conv-time">${time}</div>
                </div>
            `;
        }).join('');

        list.querySelectorAll('.conversation-item').forEach(el => {
            el.onclick = () => selectConversation(parseInt(el.dataset.id));
        });
    }

    // 格式化时间显示
    function formatTime(dateStr) {
        const date = new Date(dateStr);
        const now = new Date();
        const today = new Date(now.getFullYear(), now.getMonth(), now.getDate());
        const msgDate = new Date(date.getFullYear(), date.getMonth(), date.getDate());

        if (msgDate.getTime() === today.getTime()) {
            // 今天，只显示时间
            return date.toLocaleTimeString('zh-CN', { hour: '2-digit', minute: '2-digit' });
        } else if (msgDate.getTime() === today.getTime() - 86400000) {
            // 昨天
            return '昨天 ' + date.toLocaleTimeString('zh-CN', { hour: '2-digit', minute: '2-digit' });
        } else {
            // 其他日期
            return date.toLocaleDateString('zh-CN', { month: '2-digit', day: '2-digit' });
        }
    }

    async function selectConversation(convId) {
        state.currentConvId = convId;
        renderConversations();
        await loadMessages(convId);
        renderChatArea();

        // 加载并显示客户信息面板
        await loadCustomerInfo(convId);
        renderCustomerPanel();

        // 非上帝视角模式下标记消息为已读
        if (!state.godViewMode) {
            await markMessagesAsRead(convId);
        }
    }

    // 加载客户信息
    async function loadCustomerInfo(convId) {
        try {
            const res = await fetch(`${API_BASE}/conversation/customer/${convId}`, {
                headers: { 'Authorization': `Bearer ${state.token}` }
            });
            const data = await res.json();
            if (data.code === 0) {
                state.currentCustomer = data.data;
            } else {
                state.currentCustomer = null;
            }
        } catch (e) {
            console.log('Load customer info failed:', e);
            state.currentCustomer = null;
        }
    }

    // 渲染客户信息面板
    function renderCustomerPanel() {
        const panel = $('#customerPanel');
        const body = $('#customerPanelBody');

        if (!state.currentConvId || !state.currentCustomer) {
            panel.style.display = 'none';
            return;
        }

        if (!state.customerPanelVisible) {
            panel.style.display = 'none';
            return;
        }

        const c = state.currentCustomer;
        const emailValue = c.email || '';
        const emailDisplay = emailValue || '未填写';

        body.innerHTML = `
            <div class="panel-info-row">
                <div class="panel-info-label">客户ID</div>
                <div class="panel-info-value">${c.id}</div>
            </div>
            <div class="panel-info-row">
                <div class="panel-info-label">UUID</div>
                <div class="panel-info-value" style="font-size:12px;">${c.uuid}</div>
            </div>
            <div class="panel-info-row">
                <div class="panel-info-label">邮箱</div>
                <div class="panel-info-value">
                    <div class="email-display" id="emailDisplay">
                        <span class="email-text">${escapeHtml(emailDisplay)}</span>
                        <button class="email-edit-btn" onclick="showEmailEdit()">✏️ 编辑</button>
                    </div>
                    <div class="email-edit-form" id="emailEditForm">
                        <input type="email" class="email-edit-input" id="emailEditInput" value="${escapeHtml(emailValue)}" placeholder="请输入邮箱">
                        <button class="email-save-btn" onclick="saveCustomerEmail()">保存</button>
                        <button class="email-cancel-btn" onclick="cancelEmailEdit()">取消</button>
                    </div>
                </div>
            </div>
            <div class="panel-info-row">
                <div class="panel-info-label">时区</div>
                <div class="panel-info-value">${c.timezone || '-'}</div>
            </div>
            <div class="panel-info-row">
                <div class="panel-info-label">IP地址</div>
                <div class="panel-info-value">${c.ip || '-'}</div>
            </div>
            <div class="panel-info-row">
                <div class="panel-info-label">城市</div>
                <div class="panel-info-value">${c.city || '-'}</div>
            </div>
            <div class="panel-info-row">
                <div class="panel-info-label">设备</div>
                <div class="panel-info-value">${c.device_type || '-'}</div>
            </div>
            <div class="panel-info-row">
                <div class="panel-info-label">操作系统</div>
                <div class="panel-info-value">${c.os || '-'}</div>
            </div>
            <div class="panel-info-row">
                <div class="panel-info-label">浏览器</div>
                <div class="panel-info-value">${c.browser || '-'}</div>
            </div>
            <div class="panel-info-row">
                <div class="panel-info-label">来源页面</div>
                <div class="panel-info-value" style="font-size:12px;">${c.source_url ? `<a href="${escapeHtml(c.source_url)}" target="_blank">${escapeHtml(c.source_url)}</a>` : '-'}</div>
            </div>
            <div class="panel-section-title">统计信息</div>
            <div class="panel-stats-row">
                <span class="panel-stats-label">历史会话</span>
                <span class="panel-stats-value">${c.history_conversations} 次</span>
            </div>
            <div class="panel-stats-row">
                <span class="panel-stats-label">总消息数</span>
                <span class="panel-stats-value">${c.total_messages} 条</span>
            </div>
            <div class="panel-info-row">
                <div class="panel-info-label">首次访问</div>
                <div class="panel-info-value">${c.created_at || '-'}</div>
            </div>
            <div class="panel-info-row">
                <div class="panel-info-label">最后活跃</div>
                <div class="panel-info-value">${c.last_active_at || '-'}</div>
            </div>
            ${c.history_conversations > 1 ? `
            <div class="panel-action-row">
                <button class="panel-action-btn" onclick="showCustomerHistoryModal(${c.id})">
                    📋 查看历史会话
                </button>
            </div>
            ` : ''}
        `;

        panel.style.display = 'flex';
    }

    // 切换客户信息面板显示/隐藏
    window.toggleCustomerPanel = function() {
        state.customerPanelVisible = !state.customerPanelVisible;
        renderCustomerPanel();
        // 重新渲染聊天区域以更新按钮文字
        renderChatArea();
    };

    // 显示邮箱编辑表单
    window.showEmailEdit = function() {
        $('#emailDisplay').style.display = 'none';
        $('#emailEditForm').classList.add('show');
        $('#emailEditInput').focus();
    };

    // 取消邮箱编辑
    window.cancelEmailEdit = function() {
        $('#emailDisplay').style.display = 'flex';
        $('#emailEditForm').classList.remove('show');
        // 恢复原值
        $('#emailEditInput').value = state.currentCustomer?.email || '';
    };

    // 保存客户邮箱
    window.saveCustomerEmail = async function() {
        const email = $('#emailEditInput').value.trim();
        const customerId = state.currentCustomer?.id;

        if (!customerId) {
            showToast('客户信息无效');
            return;
        }

        try {
            const res = await fetch(`${API_BASE}/customer/${customerId}`, {
                method: 'PUT',
                headers: {
                    'Authorization': `Bearer ${state.token}`,
                    'Content-Type': 'application/json'
                },
                body: JSON.stringify({ email: email })
            });
            const data = await res.json();
            if (data.code === 0) {
                state.currentCustomer.email = email;
                renderCustomerPanel();
                showToast('邮箱更新成功');
            } else {
                showToast(data.message || '更新失败');
            }
        } catch (e) {
            console.log('Save email failed:', e);
            showToast('更新失败');
        }
    };

    // 标记会话消息为已读
    async function markMessagesAsRead(convId) {
        if (state.godViewMode) return; // 上帝视角不标记已读
        try {
            await fetch(`${API_BASE}/conversation/read/${convId}`, {
                method: 'POST',
                headers: { 'Authorization': `Bearer ${state.token}` }
            });

            // 更新本地未读数
            const conv = state.conversations.find(c => c.id === convId);
            if (conv && conv.unread_count > 0) {
                conv.unread_count = 0;
                renderConversations();
            }
        } catch (e) {
            console.log('Mark read failed:', e);
        }
    }

    async function loadMessages(convId) {
        try {
            // 上帝视角模式下使用只读参数
            const readonlyParam = state.godViewMode ? '?readonly=1' : '';
            const res = await fetch(`${API_BASE}/message/history/${convId}${readonlyParam}`, {
                headers: { 'Authorization': `Bearer ${state.token}` }
            });
            const data = await res.json();
            if (data.code === 0) {
                state.messages[convId] = data.data.list;
            }
        } catch (e) {}
    }

    function renderChatArea() {
        const conv = state.conversations.find(c => c.id === state.currentConvId);
        if (!conv) return;

        const msgs = state.messages[state.currentConvId] || [];
        const isTyping = state.customerTyping[state.currentConvId];
        const isReadonly = state.godViewMode;

        // 上帝视角模式下显示客服信息
        let agentInfo = '';
        if (isReadonly && conv.agent) {
            agentInfo = `<span style="margin-left:12px;color:#1890ff;font-size:13px;">客服: ${conv.agent.nickname || conv.agent.username}</span>`;
        }

        // 上帝视角只读提示
        const readonlyNotice = isReadonly ?
            '<div class="readonly-notice">🔒 上帝视角模式 - 仅查看，不可回复消息</div>' : '';

        // 操作按钮（上帝视角模式下隐藏）
        const panelBtnText = state.customerPanelVisible ? '隐藏信息' : '客户信息';
        const actionButtons = isReadonly ? '' : `
            <div class="header-actions">
                <button class="info-btn" onclick="toggleCustomerPanel()">${panelBtnText}</button>
                <button class="transfer-btn" onclick="showTransferModal(${conv.id})">转移</button>
                <button class="close-conv-btn" onclick="closeConversation(${conv.id})">结束会话</button>
            </div>`;

        // 输入区域（上帝视角模式下隐藏）
        const inputArea = isReadonly ? '' : `
            <div class="chat-input-area">
                <div class="quick-reply-bar" id="quickReplyBar">
                    <button class="quick-reply-toggle" id="quickReplyToggle" title="快捷回复">⚡</button>
                    <div class="quick-reply-dropdown" id="quickReplyDropdown" style="display:none;">
                        ${state.quickReplies.map(qr => `<div class="quick-reply-item" data-content="${escapeHtml(qr.content)}">${escapeHtml(qr.title)}</div>`).join('')}
                        ${state.quickReplies.length === 0 ? '<div class="quick-reply-empty">暂无快捷回复</div>' : ''}
                    </div>
                </div>
                <textarea class="chat-input" id="chatInput" placeholder="输入消息..." rows="1"></textarea>
                <button class="send-btn" id="sendBtn">发送</button>
            </div>`;

        $('#chatArea').innerHTML = `
            ${readonlyNotice}
            <div class="chat-header">
                <span class="chat-title">客户 ${conv.customer?.id || conv.id}${agentInfo}</span>
                <span class="typing-indicator" id="typingIndicator" style="display:${isTyping ? 'inline' : 'none'}; margin-left:10px; color:#999; font-size:12px;">对方正在输入...</span>
                ${actionButtons}
            </div>
            <div class="chat-messages" id="chatMessages">
                ${msgs.map(m => renderMessageHTML(m)).join('')}
            </div>
            ${inputArea}
        `;
        $('#chatMessages').scrollTop = $('#chatMessages').scrollHeight;

        // 非只读模式下绑定事件
        if (!isReadonly) {
            $('#sendBtn').onclick = sendMessage;
            const chatInput = $('#chatInput');
            chatInput.onkeypress = (e) => { if (e.key === 'Enter' && !e.shiftKey) { e.preventDefault(); sendMessage(); } };
            chatInput.oninput = () => sendTypingStatus(true);
            chatInput.onblur = () => sendTypingStatus(false);

            // 快捷回复事件
            $('#quickReplyToggle').onclick = toggleQuickReply;
            document.querySelectorAll('.quick-reply-item').forEach(item => {
                item.onclick = () => {
                    chatInput.value = item.dataset.content;
                    $('#quickReplyDropdown').style.display = 'none';
                    chatInput.focus();
                };
            });
        }
    }

    // HTML转义
    function escapeHtml(str) {
        const div = document.createElement('div');
        div.textContent = str;
        return div.innerHTML;
    }

    // 切换快捷回复下拉框
    function toggleQuickReply() {
        const dropdown = $('#quickReplyDropdown');
        dropdown.style.display = dropdown.style.display === 'none' ? 'block' : 'none';
    }

    // 加载快捷回复
    async function loadQuickReplies() {
        try {
            const res = await fetch('/quick-reply/list', {
                headers: { 'Authorization': `Bearer ${state.token}` }
            });
            const data = await res.json();
            if (data.code === 0) {
                state.quickReplies = data.data.list || [];
            }
        } catch (e) {
            console.error('加载快捷回复失败', e);
        }
    }

    // 渲染单条消息（支持已读状态显示、时间、头像）
    function renderMessageHTML(m) {
        if (m.sender_type === 3) return `<div class="msg-system">${escapeHtml(m.content)}</div>`;

        const isAgent = m.sender_type === 2;
        const cls = isAgent ? 'msg-right' : 'msg-left';
        const wrapperCls = isAgent ? 'msg-wrapper-right' : 'msg-wrapper-left';
        const avatarCls = isAgent ? 'msg-avatar-agent' : 'msg-avatar-customer';
        const avatarText = isAgent ? '客服' : '客户';
        const readStatus = isAgent ? getReadStatusHTML(m) : '';

        // 格式化时间：只显示 HH:MM
        let timeStr = '';
        if (m.created_at) {
            const timePart = m.created_at.split(' ')[1];
            if (timePart) {
                timeStr = timePart.substring(0, 5); // HH:MM
            }
        }

        return `
            <div class="msg-wrapper ${wrapperCls}">
                <div class="msg-avatar ${avatarCls}">${avatarText}</div>
                <div class="msg-bubble">
                    <div class="msg ${cls}">${escapeHtml(m.content)}${readStatus}</div>
                    <div class="msg-time">${timeStr}</div>
                </div>
            </div>`;
    }

    // 获取消息已读状态HTML
    function getReadStatusHTML(m) {
        // 客服发送的消息显示已读状态
        if (m.is_read) {
            return '<span class="msg-status read">✓✓</span>';
        } else if (m.id) {
            return '<span class="msg-status sent">✓</span>';
        }
        return '<span class="msg-status sending">...</span>';
    }

    // 发送打字状态
    function sendTypingStatus(isTyping) {
        if (!state.ws || state.ws.readyState !== WebSocket.OPEN || !state.currentConvId) return;

        // 节流：正在打字时每2秒发送一次
        if (isTyping) {
            if (state.typingTimer) return;
            state.ws.send(JSON.stringify({
                type: 'typing',
                data: { conversation_id: state.currentConvId, is_typing: true }
            }));
            state.typingTimer = setTimeout(() => {
                state.typingTimer = null;
            }, 2000);
        } else {
            // 停止打字时立即发送
            if (state.typingTimer) {
                clearTimeout(state.typingTimer);
                state.typingTimer = null;
            }
            state.ws.send(JSON.stringify({
                type: 'typing',
                data: { conversation_id: state.currentConvId, is_typing: false }
            }));
        }
    }

    function sendMessage() {
        const input = $('#chatInput');
        const content = input.value.trim();
        if (!content || !state.ws || state.ws.readyState !== WebSocket.OPEN) return;

        state.ws.send(JSON.stringify({
            type: 'message',
            data: { conversation_id: state.currentConvId, content }
        }));
        input.value = '';
    }

    window.closeConversation = async function(convId) {
        if (!confirm('确定要结束此会话吗？')) return;
        try {
            const res = await fetch(`${API_BASE}/conversation/close/${convId}`, {
                method: 'POST',
                headers: { 'Authorization': `Bearer ${state.token}` }
            });
            const data = await res.json();
            if (data.code === 0) {
                loadConversations();
                if (state.currentConvId === convId) {
                    state.currentConvId = null;
                    state.currentCustomer = null;
                    $('#chatArea').innerHTML = '<div class="empty-chat">请选择一个会话</div>';
                    $('#customerPanel').style.display = 'none';
                }
            }
        } catch (e) {}
    };

    // 显示客户信息弹窗（保留作为备用）
    window.showCustomerInfo = async function(convId) {
        try {
            const res = await fetch(`${API_BASE}/conversation/customer/${convId}`, {
                headers: { 'Authorization': `Bearer ${state.token}` }
            });
            const data = await res.json();
            if (data.code === 0) {
                const c = data.data;
                $('#customerInfo').innerHTML = `
                    <div class="info-row"><span class="info-label">客户ID</span><span class="info-value">${c.id}</span></div>
                    <div class="info-row"><span class="info-label">UUID</span><span class="info-value">${c.uuid}</span></div>
                    <div class="info-row"><span class="info-label">IP地址</span><span class="info-value">${c.ip || '-'}</span></div>
                    <div class="info-row"><span class="info-label">城市</span><span class="info-value">${c.city || '-'}</span></div>
                    <div class="info-row"><span class="info-label">设备</span><span class="info-value">${c.device_type || '-'}</span></div>
                    <div class="info-row"><span class="info-label">操作系统</span><span class="info-value">${c.os || '-'}</span></div>
                    <div class="info-row"><span class="info-label">浏览器</span><span class="info-value">${c.browser || '-'}</span></div>
                    <div class="info-row"><span class="info-label">来源页面</span><span class="info-value">${c.source_url || '-'}</span></div>
                    <div class="info-row"><span class="info-label">来源引荐</span><span class="info-value">${c.referrer || '-'}</span></div>
                    <div class="info-row"><span class="info-label">首次访问</span><span class="info-value">${c.created_at || '-'}</span></div>
                    <div class="info-row"><span class="info-label">最后活跃</span><span class="info-value">${c.last_active_at || '-'}</span></div>
                    <div class="info-row"><span class="info-label">历史会话</span><span class="info-value">${c.history_conversations} 次</span></div>
                    <div class="info-row"><span class="info-label">总消息数</span><span class="info-value">${c.total_messages} 条</span></div>
                `;
                $('#customerModal').classList.add('show');
            } else {
                alert(data.message || '获取客户信息失败');
            }
        } catch (e) {
            console.log('Get customer info failed:', e);
        }
    };

    // 显示转移会话弹窗
    window.showTransferModal = async function(convId) {
        state.transferConvId = convId;
        try {
            const res = await fetch(`${API_BASE}/conversation/agents/${convId}`, {
                headers: { 'Authorization': `Bearer ${state.token}` }
            });
            const data = await res.json();
            if (data.code === 0) {
                const select = $('#transferAgentSelect');
                select.innerHTML = '<option value="">请选择客服</option>' +
                    data.data.list.map(a => `<option value="${a.id}">${a.nickname || a.username}</option>`).join('');
                $('#transferReason').value = '';
                $('#transferModal').classList.add('show');
            } else {
                alert(data.message || '获取客服列表失败');
            }
        } catch (e) {
            console.log('Get agents failed:', e);
        }
    };

    // 提交转移
    window.submitTransfer = async function() {
        const toAgentId = $('#transferAgentSelect').value;
        const reason = $('#transferReason').value.trim();

        if (!toAgentId) {
            alert('请选择目标客服');
            return;
        }

        try {
            const res = await fetch(`${API_BASE}/conversation/transfer/${state.transferConvId}`, {
                method: 'POST',
                headers: {
                    'Authorization': `Bearer ${state.token}`,
                    'Content-Type': 'application/json'
                },
                body: JSON.stringify({ to_agent_id: parseInt(toAgentId), reason })
            });
            const data = await res.json();
            if (data.code === 0) {
                alert('转移成功');
                closeModal('transferModal');
                loadConversations();
                if (state.currentConvId === state.transferConvId) {
                    state.currentConvId = null;
                    state.currentCustomer = null;
                    $('#chatArea').innerHTML = '<div class="empty-chat">请选择一个会话</div>';
                    $('#customerPanel').style.display = 'none';
                }
            } else {
                alert(data.message || '转移失败');
            }
        } catch (e) {
            console.log('Transfer failed:', e);
        }
    };

    // 关闭弹窗
    window.closeModal = function(modalId) {
        $('#' + modalId).classList.remove('show');
    };

    // ========== 客户历史会话功能 ==========

    // 历史会话状态
    const historyState = {
        customerId: null,
        page: 1,
        pageSize: 10,
        total: 0,
        list: []
    };

    // 显示客户历史会话弹窗
    window.showCustomerHistoryModal = async function(customerId) {
        historyState.customerId = customerId;
        historyState.page = 1;
        await loadCustomerHistory();
        $('#customerHistoryModal').classList.add('show');
    };

    // 加载客户历史会话
    async function loadCustomerHistory() {
        const container = $('#customerHistoryList');
        container.innerHTML = '<div style="text-align:center; padding:40px; color:#999;">加载中...</div>';

        try {
            const res = await fetch(`${API_BASE}/customer/${historyState.customerId}/conversations?page=${historyState.page}&page_size=${historyState.pageSize}`, {
                headers: { 'Authorization': `Bearer ${state.token}` }
            });
            const data = await res.json();

            if (data.code === 0) {
                historyState.list = data.data.list;
                historyState.total = data.data.total;
                renderCustomerHistory();
            } else {
                container.innerHTML = `<div class="history-empty">${data.message || '加载失败'}</div>`;
            }
        } catch (e) {
            container.innerHTML = '<div class="history-empty">加载失败，请重试</div>';
        }
    }

    // 渲染历史会话列表
    function renderCustomerHistory() {
        const container = $('#customerHistoryList');

        if (historyState.list.length === 0) {
            container.innerHTML = '<div class="history-empty">暂无历史会话</div>';
            $('#customerHistoryPagination').innerHTML = '';
            return;
        }

        container.innerHTML = historyState.list.map(conv => {
            const statusClass = `status-${conv.status}`;
            const agentName = conv.agent ? conv.agent.nickname : '未分配';
            const lastMsg = conv.last_message ? conv.last_message.content : '无消息';
            const createdAt = conv.created_at ? conv.created_at.split(' ')[0] : '';

            return `
                <div class="history-conv-item" onclick="showHistoryMessages(${conv.id}, '${escapeHtml(createdAt)}')">
                    <div class="history-conv-info">
                        <div class="history-conv-header">
                            <span class="history-conv-id">会话 #${conv.id}</span>
                            <span class="history-conv-status ${statusClass}">${conv.status_text}</span>
                        </div>
                        <div class="history-conv-meta">
                            客服：${escapeHtml(agentName)} | ${conv.message_count} 条消息 | ${createdAt}
                        </div>
                        <div class="history-conv-preview">${escapeHtml(lastMsg)}</div>
                    </div>
                    <div class="history-conv-arrow">›</div>
                </div>
            `;
        }).join('');

        // 渲染分页
        renderHistoryPagination();
    }

    // 渲染分页
    function renderHistoryPagination() {
        const totalPages = Math.ceil(historyState.total / historyState.pageSize);
        if (totalPages <= 1) {
            $('#customerHistoryPagination').innerHTML = '';
            return;
        }

        $('#customerHistoryPagination').innerHTML = `
            <div class="history-pagination">
                <button ${historyState.page <= 1 ? 'disabled' : ''} onclick="historyPageChange(${historyState.page - 1})">上一页</button>
                <span style="padding: 6px 12px;">${historyState.page} / ${totalPages}</span>
                <button ${historyState.page >= totalPages ? 'disabled' : ''} onclick="historyPageChange(${historyState.page + 1})">下一页</button>
            </div>
        `;
    }

    // 翻页
    window.historyPageChange = async function(page) {
        historyState.page = page;
        await loadCustomerHistory();
    };

    // 显示历史会话的消息详情
    window.showHistoryMessages = async function(conversationId, dateStr) {
        $('#historyMessagesTitle').textContent = `会话 #${conversationId} - ${dateStr}`;
        const container = $('#historyMessagesList');
        container.innerHTML = '<div style="text-align:center; padding:40px; color:#999;">加载中...</div>';
        $('#historyMessagesModal').classList.add('show');

        try {
            const res = await fetch(`${API_BASE}/message/history/${conversationId}?limit=100&readonly=1`, {
                headers: { 'Authorization': `Bearer ${state.token}` }
            });
            const data = await res.json();

            if (data.code === 0) {
                renderHistoryMessages(data.data.list);
            } else {
                container.innerHTML = `<div class="history-empty">${data.message || '加载失败'}</div>`;
            }
        } catch (e) {
            container.innerHTML = '<div class="history-empty">加载失败，请重试</div>';
        }
    };

    // 渲染历史消息
    function renderHistoryMessages(messages) {
        const container = $('#historyMessagesList');

        if (messages.length === 0) {
            container.innerHTML = '<div class="history-empty">暂无消息记录</div>';
            return;
        }

        container.innerHTML = messages.map(msg => {
            const time = msg.created_at ? msg.created_at.split(' ')[1] : '';

            if (msg.sender_type === 3) {
                // 系统消息
                return `<div class="history-msg-system">${escapeHtml(msg.content)}</div>`;
            }

            const isAgent = msg.sender_type === 2;
            const className = isAgent ? 'history-msg-right' : 'history-msg-left';

            return `
                <div class="history-msg-item ${className}">
                    ${escapeHtml(msg.content)}
                    <div class="history-msg-time">${time}</div>
                </div>
            `;
        }).join('');

        // 滚动到底部
        container.scrollTop = container.scrollHeight;
    }

    // ========== 历史会话功能结束 ==========

    async function updateStatus() {
        const status = $('#statusSelect').value;
        try {
            await fetch(`${API_BASE}/agent/status`, {
                method: 'PUT',
                headers: {
                    'Authorization': `Bearer ${state.token}`,
                    'Content-Type': 'application/json'
                },
                body: JSON.stringify({ status: parseInt(status) })
            });
        } catch (e) {}
    }

    function connectWS() {
        // 如果没有 token，不建立连接
        if (!state.token) {
            console.log('No token, skip WS connection');
            return;
        }

        // 关闭已有连接
        if (state.ws) {
            state.ws.onclose = null;  // 防止触发重连
            state.ws.close();
        }

        state.ws = new WebSocket(`${WS_URL}?type=agent&token=${state.token}`);

        state.ws.onopen = () => console.log('WS Connected');

        state.ws.onmessage = (e) => {
            try {
                const data = JSON.parse(e.data);
                handleWSMessage(data);
            } catch (err) {}
        };

        state.ws.onclose = () => {
            console.log('WS Disconnected');
            // 只有在有 token 的情况下才重连
            if (state.token) {
                setTimeout(connectWS, 3000);
            }
        };

        // 心跳（只创建一次）
        if (!state.heartbeatTimer) {
            state.heartbeatTimer = setInterval(() => {
                if (state.ws && state.ws.readyState === WebSocket.OPEN) {
                    state.ws.send(JSON.stringify({ type: 'ping' }));
                }
            }, 30000);
        }
    }

    function handleWSMessage(data) {
        switch (data.type) {
            case 'connected':
                // WebSocket连接成功，更新状态显示
                if (data.data && data.data.status !== undefined) {
                    state.agent.status = data.data.status;
                    $('#statusSelect').value = data.data.status;
                }
                console.log('WS Connected with status:', data.data?.status);
                break;
            case 'status_changed':
                // 状态变更通知
                if (data.data && data.data.status !== undefined) {
                    state.agent.status = data.data.status;
                    $('#statusSelect').value = data.data.status;
                }
                break;
            case 'new_message':
                console.log('来消息啦')
                console.log(data.data?.sender_type)
                // 客户发来的消息，播放提示音
                if (data.data?.sender_type === 1) {
                    playNotificationSound();
                }
                addMessage(data.data);
                break;
            case 'message_sent':
                addMessage(data.data);
                break;
            case 'conversation_assigned':
                // 新会话分配或转入，播放提示音
                playNotificationSound();
                if (data.data?.is_transfer) {
                    // 会话转入：缓存消息并显示未读数
                    const convData = data.data.conversation;
                    if (convData && data.data.messages) {
                        state.messages[convData.id] = data.data.messages;
                    }
                }
                loadConversations();
                break;
            case 'conversation_closed':
                loadConversations();
                break;
            case 'conversation_transferred_out':
                // 【功能1】会话已转出给其他客服，从列表中移除
                const transferredConvId = data.data?.conversation_id;
                // 从本地状态移除
                if (transferredConvId) {
                    delete state.messages[transferredConvId];
                    delete state.unread[transferredConvId];
                }
                // 如果当前正在查看该会话，清空聊天区域
                if (state.currentConvId === transferredConvId) {
                    state.currentConvId = null;
                    state.currentCustomer = null;
                    $('#chatArea').innerHTML = '<div class="empty-chat">请选择一个会话</div>';
                    $('#customerPanel').style.display = 'none';
                }
                // 刷新会话列表
                loadConversations();
                // 提示用户
                showToast(`会话已转移给 ${data.data?.to_agent_name || '其他客服'}`);
                break;
            case 'typing':
                // 客户打字状态
                handleCustomerTyping(data.data);
                break;
            case 'messages_read':
                // 客户已读消息
                handleMessagesRead(data.data);
                break;
            case 'kicked':
                // 被踢下线
                handleKicked(data.message);
                break;
        }
    }

    // 处理被踢下线
    function handleKicked(message) {
        // 关闭WebSocket连接
        if (state.ws) {
            state.ws.close();
            state.ws = null;
        }
        // 显示提示
        alert(message || '您的账号在其他设备登录，当前连接已断开');
        // 退出登录
        logout();
    }

    // 处理客户打字状态
    function handleCustomerTyping(data) {
        const convId = data?.conversation_id;
        if (!convId) return;

        const isTyping = data?.is_typing;
        state.customerTyping[convId] = isTyping;

        // 清除之前的超时定时器
        if (state.customerTypingTimer[convId]) {
            clearTimeout(state.customerTypingTimer[convId]);
        }

        // 设置超时自动清除打字状态
        if (isTyping) {
            state.customerTypingTimer[convId] = setTimeout(() => {
                state.customerTyping[convId] = false;
                updateTypingIndicator(convId);
            }, 3000);
        }

        updateTypingIndicator(convId);
    }

    // 更新打字指示器
    function updateTypingIndicator(convId) {
        if (convId !== state.currentConvId) return;
        const indicator = $('#typingIndicator');
        if (indicator) {
            indicator.style.display = state.customerTyping[convId] ? 'inline' : 'none';
        }
    }

    // 处理消息已读状态
    function handleMessagesRead(data) {
        const convId = data?.conversation_id;
        if (!convId || !state.messages[convId]) return;

        // 标记所有客服发送的消息为已读
        if (data?.reader === 'customer') {
            state.messages[convId].forEach(m => {
                if (m.sender_type === 2) {
                    m.is_read = true;
                }
            });
            if (convId === state.currentConvId) {
                renderChatArea();
            }
        }
    }

    function addMessage(msg) {
        const convId = msg.conversation_id;
        if (!state.messages[convId]) state.messages[convId] = [];

        const exists = state.messages[convId].find(m => m.id === msg.id);
        if (!exists) {
            state.messages[convId].push(msg);
            if (convId === state.currentConvId) {
                renderChatArea();
                // 当前会话收到消息，标记为已读
                if (msg.sender_type === 1) {
                    markMessagesAsRead(convId);
                }
            }

            // 更新会话列表中的最后一条消息和未读数
            updateConversationInList(msg);
        }
    }

    // 更新会话列表中的最后一条消息
    function updateConversationInList(msg) {
        const conv = state.conversations.find(c => c.id === msg.conversation_id);
        if (conv) {
            // 更新最后一条消息
            conv.last_message = {
                id: msg.id,
                content: msg.content,
                sender_type: msg.sender_type,
                created_at: msg.created_at
            };

            // 如果是客户消息且不是当前会话，增加未读数
            if (msg.sender_type === 1 && msg.conversation_id !== state.currentConvId) {
                conv.unread_count = (conv.unread_count || 0) + 1;
            }

            // 重新渲染会话列表
            renderConversations();
        }
    }

    // ==================== 管理中心下拉菜单 ====================

    function toggleAdminDropdown() {
        $('#adminDropdown').classList.toggle('open');
    }

    function closeAdminDropdown() {
        $('#adminDropdown').classList.remove('open');
    }

    // ==================== 统计页面功能 ====================

    // 显示统计页面
    async function showStatsPage() {
        state.currentView = 'stats';
        $('#mainContent').style.display = 'none';
        $('#statsContainer').style.display = 'block';
        $('#agentMgmtContainer').style.display = 'none';

        // 默认日期范围：最近7天
        const endDate = new Date().toISOString().split('T')[0];
        const startDate = new Date(Date.now() - 7 * 24 * 60 * 60 * 1000).toISOString().split('T')[0];

        $('#statsContainer').innerHTML = `
            <div class="stats-header">
                <h2>📊 客服KPI统计</h2>
                <button class="back-btn" id="backToChat">返回工作台</button>
            </div>
            <div class="stats-filter">
                <label>开始日期: <input type="date" id="statsStartDate" value="${startDate}"></label>
                <label>结束日期: <input type="date" id="statsEndDate" value="${endDate}"></label>
                <button class="send-btn" id="refreshStats">刷新</button>
            </div>
            <div id="statsContent">加载中...</div>
        `;

        $('#backToChat').onclick = hideStatsPage;
        $('#refreshStats').onclick = loadStats;
        $('#statsStartDate').onchange = loadStats;
        $('#statsEndDate').onchange = loadStats;

        await loadStats();
    }

    // 返回工作台（通用）
    function backToWorkspace() {
        state.currentView = 'chat';
        $('#mainContent').style.display = 'flex';
        $('#statsContainer').style.display = 'none';
        $('#agentMgmtContainer').style.display = 'none';
    }

    // 隐藏统计页面（兼容旧代码）
    function hideStatsPage() {
        backToWorkspace();
    }

    // 加载统计数据
    async function loadStats() {
        const startDate = $('#statsStartDate').value;
        const endDate = $('#statsEndDate').value;

        try {
            const res = await fetch(`${API_BASE}/statistics/global?start_date=${startDate}&end_date=${endDate}`, {
                headers: { 'Authorization': `Bearer ${state.token}` }
            });
            const data = await res.json();
            if (data.code === 0) {
                renderStats(data.data);
            } else {
                $('#statsContent').innerHTML = `<div style="color:red;">加载失败: ${data.message}</div>`;
            }
        } catch (e) {
            $('#statsContent').innerHTML = `<div style="color:red;">加载失败: ${e.message}</div>`;
        }
    }

    // 渲染统计数据
    function renderStats(stats) {
        const agentStats = stats.agent_detail_stats || [];

        $('#statsContent').innerHTML = `
            <div class="stats-cards">
                <div class="stats-card">
                    <div class="stats-card-title">总会话数</div>
                    <div class="stats-card-value">${stats.total_conversations}</div>
                </div>
                <div class="stats-card">
                    <div class="stats-card-title">已完成会话</div>
                    <div class="stats-card-value" style="color:#52c41a;">${stats.closed_conversations}</div>
                </div>
                <div class="stats-card">
                    <div class="stats-card-title">等待中会话</div>
                    <div class="stats-card-value" style="color:#faad14;">${stats.waiting_conversations}</div>
                </div>
                <div class="stats-card">
                    <div class="stats-card-title">进行中会话</div>
                    <div class="stats-card-value" style="color:#1890ff;">${stats.active_conversations}</div>
                </div>
                <div class="stats-card">
                    <div class="stats-card-title">总消息数</div>
                    <div class="stats-card-value">${stats.total_messages}</div>
                </div>
            </div>

            <div class="stats-table-container">
                <div class="stats-table-title">客服KPI明细</div>
                <table class="stats-table">
                    <thead>
                        <tr>
                            <th>客服</th>
                            <th>接待会话</th>
                            <th>已完成</th>
                            <th>当前活跃</th>
                            <th>发送消息</th>
                            <th>接收消息</th>
                            <th>平均响应时间</th>
                        </tr>
                    </thead>
                    <tbody>
                        ${agentStats.map(a => `
                            <tr>
                                <td>${a.nickname || a.username}${a.is_admin ? ' <span style="color:#722ed1;font-size:11px;">[管理员]</span>' : ''}</td>
                                <td>${a.total_conversations}</td>
                                <td>${a.closed_conversations}</td>
                                <td><span style="color:#1890ff;font-weight:bold;">${a.current_active_conversations}</span></td>
                                <td>${a.sent_messages}</td>
                                <td>${a.received_messages}</td>
                                <td>${a.avg_response_time_formatted || '-'}</td>
                            </tr>
                        `).join('')}
                        ${agentStats.length === 0 ? '<tr><td colspan="7" style="text-align:center;color:#999;">暂无数据</td></tr>' : ''}
                    </tbody>
                </table>
            </div>
        `;
    }

    // ==================== 客服管理页面功能 ====================

    // 显示客服管理页面
    async function showAgentMgmtPage() {
        state.currentView = 'agentMgmt';
        $('#mainContent').style.display = 'none';
        $('#statsContainer').style.display = 'none';
        $('#agentMgmtContainer').style.display = 'block';

        $('#agentMgmtContainer').innerHTML = `
            <div class="agent-mgmt-header">
                <h2>👥 客服管理</h2>
                <div>
                    <button class="add-agent-btn" id="addAgentBtn">+ 新增客服</button>
                    <button class="back-btn" id="backFromAgentMgmt">返回工作台</button>
                </div>
            </div>
            <div class="agent-table-container">
                <table class="agent-table">
                    <thead>
                        <tr>
                            <th>ID</th>
                            <th>用户名</th>
                            <th>昵称</th>
                            <th>角色</th>
                            <th>状态</th>
                            <th>最大接待数</th>
                            <th>当前会话数</th>
                            <th>创建时间</th>
                            <th>操作</th>
                        </tr>
                    </thead>
                    <tbody id="agentTableBody">
                        <tr><td colspan="9" style="text-align:center;">加载中...</td></tr>
                    </tbody>
                </table>
            </div>
        `;

        $('#backFromAgentMgmt').onclick = backToWorkspace;
        $('#addAgentBtn').onclick = () => openAgentEditModal();

        await loadAgentList();
    }

    // 加载客服列表
    async function loadAgentList() {
        try {
            const res = await fetch(`${API_BASE}/agent/list`, {
                headers: { 'Authorization': `Bearer ${state.token}` }
            });
            const data = await res.json();
            if (data.code === 0) {
                renderAgentList(data.data.list || []);
            } else {
                $('#agentTableBody').innerHTML = `<tr><td colspan="9" style="text-align:center;color:red;">加载失败: ${data.message}</td></tr>`;
            }
        } catch (e) {
            $('#agentTableBody').innerHTML = `<tr><td colspan="9" style="text-align:center;color:red;">加载失败: ${e.message}</td></tr>`;
        }
    }

    // 渲染客服列表
    function renderAgentList(agents) {
        if (agents.length === 0) {
            $('#agentTableBody').innerHTML = '<tr><td colspan="9" style="text-align:center;color:#999;">暂无客服</td></tr>';
            return;
        }

        const statusMap = { 1: { text: '在线', class: 'online' }, 2: { text: '离线', class: 'offline' }, 3: { text: '忙碌', class: 'busy' } };

        $('#agentTableBody').innerHTML = agents.map(a => {
            const status = statusMap[a.status] || { text: '未知', class: 'offline' };
            const roleClass = a.is_admin === 1 ? 'admin' : 'normal';
            const roleText = a.is_admin === 1 ? '管理员' : '普通客服';
            const isSelf = a.id === state.agent.id;
            return `
                <tr>
                    <td>${a.id}</td>
                    <td>${a.username}</td>
                    <td>${a.nickname || '-'}</td>
                    <td><span class="agent-role ${roleClass}">${roleText}</span></td>
                    <td><span class="agent-status ${status.class}">${status.text}</span></td>
                    <td>${a.max_sessions}</td>
                    <td>${a.current_sessions || 0}</td>
                    <td>${a.created_at ? a.created_at.substring(0, 10) : '-'}</td>
                    <td>
                        <button class="action-btn edit" onclick="openAgentEditModal(${a.id})">编辑</button>
                        ${!isSelf ? `<button class="action-btn delete" onclick="confirmDeleteAgent(${a.id}, '${a.username}')">删除</button>` : ''}
                    </td>
                </tr>
            `;
        }).join('');
    }

    // 打开客服编辑弹窗
    async function openAgentEditModal(agentId = null) {
        const isEdit = agentId !== null;
        $('#agentEditTitle').textContent = isEdit ? '编辑客服' : '新增客服';
        $('#passwordHint').textContent = isEdit ? '(留空则不修改)' : '*';
        $('#editAgentId').value = agentId || '';
        $('#editAgentUsername').value = '';
        $('#editAgentPassword').value = '';
        $('#editAgentNickname').value = '';
        $('#editAgentMaxSessions').value = '10';
        $('#editAgentRole').value = '0';

        if (isEdit) {
            // 获取客服详情
            try {
                const res = await fetch(`${API_BASE}/agent/detail/${agentId}`, {
                    headers: { 'Authorization': `Bearer ${state.token}` }
                });
                const data = await res.json();
                if (data.code === 0) {
                    const agent = data.data;
                    $('#editAgentUsername').value = agent.username || '';
                    $('#editAgentNickname').value = agent.nickname || '';
                    $('#editAgentMaxSessions').value = agent.max_sessions || 10;
                    $('#editAgentRole').value = agent.is_admin || 0;
                }
            } catch (e) {
                showToast('获取客服信息失败');
            }
        }

        $('#agentEditModal').classList.add('show');
    }

    // 提交客服编辑
    async function submitAgentEdit() {
        const agentId = $('#editAgentId').value;
        const isEdit = agentId !== '';
        const username = $('#editAgentUsername').value.trim();
        const password = $('#editAgentPassword').value;
        const nickname = $('#editAgentNickname').value.trim();
        const maxSessions = parseInt($('#editAgentMaxSessions').value) || 10;
        const isAdmin = parseInt($('#editAgentRole').value) || 0;

        if (!username) {
            showToast('用户名不能为空');
            return;
        }
        if (!isEdit && !password) {
            showToast('密码不能为空');
            return;
        }

        const body = { username, nickname, max_sessions: maxSessions, is_admin: isAdmin };
        if (password) {
            body.password = password;
        }

        try {
            const url = isEdit ? `${API_BASE}/agent/update/${agentId}` : `${API_BASE}/agent/create`;
            const res = await fetch(url, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'Authorization': `Bearer ${state.token}`
                },
                body: JSON.stringify(body)
            });
            const data = await res.json();
            if (data.code === 0) {
                showToast(isEdit ? '更新成功' : '创建成功');
                closeModal('agentEditModal');
                await loadAgentList();
            } else {
                showToast(data.message || '操作失败');
            }
        } catch (e) {
            showToast('操作失败: ' + e.message);
        }
    }

    // 确认删除客服
    function confirmDeleteAgent(agentId, username) {
        if (confirm(`确定要删除客服 "${username}" 吗？\n\n注意：删除后该客服的所有会话将被清理。`)) {
            deleteAgent(agentId);
        }
    }

    // 删除客服
    async function deleteAgent(agentId) {
        try {
            const res = await fetch(`${API_BASE}/agent/delete/${agentId}`, {
                method: 'POST',
                headers: { 'Authorization': `Bearer ${state.token}` }
            });
            const data = await res.json();
            if (data.code === 0) {
                showToast('删除成功');
                await loadAgentList();
            } else {
                showToast(data.message || '删除失败');
            }
        } catch (e) {
            showToast('删除失败: ' + e.message);
        }
    }

    // ==================== 文案配置管理 ====================

    // 打开文案配置弹窗
    async function showTextConfigModal() {
        try {
            const res = await fetch(`${API_BASE}/admin/config`, {
                headers: { 'Authorization': `Bearer ${state.token}` }
            });
            const data = await res.json();
            if (data.code === 0) {
                renderTextConfigList(data.data);
                // 设置当前语言
                const lang = data.data.current_language || 'en';
                $('#sdkLanguageSelect').value = typeof lang === 'string' ? lang.replace(/"/g, '') : lang;
                $('#textConfigModal').classList.add('show');
            } else {
                showToast(data.message || '获取配置失败');
            }
        } catch (e) {
            showToast('获取配置失败: ' + e.message);
        }
    }

    // 渲染配置列表
    function renderTextConfigList(data) {
        const grouped = data.grouped || {};
        const container = $('#textConfigList');

        const groupNames = {
            'sdk_texts': '📱 客户端文案',
            'system_messages': '💬 系统消息'
        };

        let html = '';
        for (const [group, items] of Object.entries(grouped)) {
            if (group === 'general') continue; // 跳过general，语言设置单独处理

            html += `<div class="config-group">`;
            html += `<div class="config-group-title">${groupNames[group] || group}</div>`;

            for (const item of items) {
                const value = item.value;
                const zhValue = (typeof value === 'object' && value.zh) ? value.zh : '';
                const enValue = (typeof value === 'object' && value.en) ? value.en : '';

                html += `
                <div class="config-item" data-key="${item.key}">
                    <div class="config-item-header">
                        <div>
                            <span class="config-item-key">${item.key}</span>
                            <span class="config-item-desc">${item.description || ''}</span>
                        </div>
                        <button class="config-save-btn" onclick="saveConfigItem('${item.key}')">保存</button>
                    </div>
                    <div class="config-item-values">
                        <div class="config-lang-field">
                            <label class="config-lang-label">中文 (zh)</label>
                            <input type="text" class="config-lang-input" id="config_zh_${item.key}" value="${escapeHtml(zhValue)}">
                        </div>
                        <div class="config-lang-field">
                            <label class="config-lang-label">English (en)</label>
                            <input type="text" class="config-lang-input" id="config_en_${item.key}" value="${escapeHtml(enValue)}">
                        </div>
                    </div>
                </div>`;
            }
            html += `</div>`;
        }

        container.innerHTML = html;
    }

    // 保存单个配置项
    window.saveConfigItem = async function(key) {
        const zhInput = $(`#config_zh_${key}`);
        const enInput = $(`#config_en_${key}`);

        if (!zhInput || !enInput) {
            showToast('找不到输入框');
            return;
        }

        const value = {
            zh: zhInput.value,
            en: enInput.value
        };

        try {
            const res = await fetch(`${API_BASE}/admin/config/${key}`, {
                method: 'PUT',
                headers: {
                    'Authorization': `Bearer ${state.token}`,
                    'Content-Type': 'application/json'
                },
                body: JSON.stringify({ value: value })
            });
            const data = await res.json();
            if (data.code === 0) {
                showToast('保存成功');
            } else {
                showToast(data.message || '保存失败');
            }
        } catch (e) {
            showToast('保存失败: ' + e.message);
        }
    };

    // 保存语言设置
    window.saveLanguageSetting = async function() {
        const lang = $('#sdkLanguageSelect').value;
        try {
            const res = await fetch(`${API_BASE}/admin/config/language`, {
                method: 'PUT',
                headers: {
                    'Authorization': `Bearer ${state.token}`,
                    'Content-Type': 'application/json'
                },
                body: JSON.stringify({ language: lang })
            });
            const data = await res.json();
            if (data.code === 0) {
                showToast('语言设置已保存');
            } else {
                showToast(data.message || '保存失败');
            }
        } catch (e) {
            showToast('保存失败: ' + e.message);
        }
    };

    // 暴露函数到全局
    window.showStatsPage = showStatsPage;
    window.showAgentMgmtPage = showAgentMgmtPage;
    window.openAgentEditModal = openAgentEditModal;
    window.submitAgentEdit = submitAgentEdit;
    window.confirmDeleteAgent = confirmDeleteAgent;
    window.backToWorkspace = backToWorkspace;
    window.showTextConfigModal = showTextConfigModal;

    init();
})();

