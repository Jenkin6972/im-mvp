-- 系统配置表 - 用于存储多语言文案等配置
-- 执行命令: mysql -u root -p im_mvp < migrations/create_system_config.sql

CREATE TABLE IF NOT EXISTS `system_config` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `key` VARCHAR(100) NOT NULL COMMENT '配置键',
    `value` TEXT COMMENT '配置值（JSON格式）',
    `group` VARCHAR(50) DEFAULT 'general' COMMENT '配置分组: general, sdk_texts, system_messages',
    `description` VARCHAR(255) COMMENT '配置说明',
    `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY `uk_key` (`key`),
    KEY `idx_group` (`group`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='系统配置表';

-- 插入默认的多语言文案配置
-- SDK 前端文案
INSERT INTO `system_config` (`key`, `value`, `group`, `description`) VALUES
('sdk_language', '"en"', 'general', '客户端默认语言: zh=中文, en=英文'),
('welcome_message', '{"zh": "您好，有什么可以帮您？", "en": "Hello, how can I help you?"}', 'sdk_texts', '欢迎语'),
('input_placeholder', '{"zh": "请输入消息...", "en": "Type a message..."}', 'sdk_texts', '输入框占位符'),
('send_button', '{"zh": "发送", "en": "Send"}', 'sdk_texts', '发送按钮'),
('status_connected', '{"zh": "已连接", "en": "Connected"}', 'sdk_texts', '连接状态-已连接'),
('status_disconnected', '{"zh": "未连接", "en": "Disconnected"}', 'sdk_texts', '连接状态-未连接'),
('status_error', '{"zh": "连接错误", "en": "Connection error"}', 'sdk_texts', '连接状态-错误'),
('agent_typing', '{"zh": "客服正在输入...", "en": "Agent is typing..."}', 'sdk_texts', '客服正在输入提示'),
('conversation_closed', '{"zh": "会话已结束", "en": "Conversation ended"}', 'sdk_texts', '会话结束提示'),
('queue_waiting', '{"zh": "正在排队等待客服...", "en": "Waiting in queue..."}', 'sdk_texts', '排队等待提示(默认)'),
('agent_assigned', '{"zh": "客服已接入", "en": "Agent connected"}', 'sdk_texts', '客服接入提示(默认)'),
('offline_messages_tip', '{"zh": "您有 {count} 条离线消息", "en": "You have {count} offline message(s)"}', 'sdk_texts', '离线消息提示'),

-- 后端推送的系统消息
('msg_agent_assigned', '{"zh": "客服已接入，正在为您服务。", "en": "Agent connected. We are here to help."}', 'system_messages', '客服接入通知'),
('msg_queue_waiting', '{"zh": "当前暂无客服在线，您的消息已收到，客服上线后会尽快回复您。", "en": "No agents available. Your message has been received and will be answered soon."}', 'system_messages', '无客服在线通知');

