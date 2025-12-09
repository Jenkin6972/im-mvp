-- ============================================
-- IM-MVP 数据库表结构
-- ============================================

CREATE DATABASE IF NOT EXISTS `im_mvp` DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

USE `im_mvp`;

-- 1. 客服表
CREATE TABLE `agent` (
                         `id` int unsigned NOT NULL AUTO_INCREMENT,
                         `username` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL COMMENT '登录用户名',
                         `password` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL COMMENT '密码(bcrypt)',
                         `nickname` varchar(50) COLLATE utf8mb4_unicode_ci DEFAULT '' COMMENT '昵称',
                         `avatar` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT '' COMMENT '头像',
                         `max_sessions` int unsigned DEFAULT '10' COMMENT '最大同时处理会话数',
                         `status` tinyint unsigned DEFAULT '1' COMMENT '账号状态: 1启用 0禁用',
                         `is_admin` tinyint unsigned DEFAULT '0' COMMENT '是否管理员: 0否 1是',
                         `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
                         `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                         PRIMARY KEY (`id`),
                         UNIQUE KEY `username` (`username`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='客服表';

CREATE TABLE `conversation` (
                                `id` int unsigned NOT NULL AUTO_INCREMENT,
                                `customer_id` int unsigned NOT NULL COMMENT '客户ID',
                                `agent_id` int unsigned DEFAULT NULL COMMENT '分配的客服ID',
                                `status` tinyint unsigned DEFAULT '0' COMMENT '状态: 0待分配 1进行中 2已完成',
                                `last_message_at` timestamp NULL DEFAULT NULL COMMENT '最后消息时间',
                                `last_agent_reply_at` timestamp NULL DEFAULT NULL COMMENT '客服最后回复时间',
                                `last_customer_msg_at` timestamp NULL DEFAULT NULL COMMENT '客户最后消息时间',
                                `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
                                `closed_at` timestamp NULL DEFAULT NULL COMMENT '关闭时间',
                                PRIMARY KEY (`id`),
                                KEY `idx_customer` (`customer_id`),
                                KEY `idx_agent_status` (`agent_id`,`status`),
                                KEY `idx_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='会话表';

CREATE TABLE `conversation_transfer` (
                                         `id` int unsigned NOT NULL AUTO_INCREMENT,
                                         `conversation_id` int unsigned NOT NULL COMMENT '会话ID',
                                         `from_agent_id` int unsigned NOT NULL COMMENT '原客服ID',
                                         `to_agent_id` int unsigned NOT NULL COMMENT '目标客服ID',
                                         `transfer_type` tinyint unsigned DEFAULT '1' COMMENT '转移类型: 1手动 2超时自动',
                                         `operator_id` int unsigned DEFAULT NULL COMMENT '操作人ID(手动转移时)',
                                         `reason` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT '' COMMENT '转移原因',
                                         `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
                                         PRIMARY KEY (`id`),
                                         KEY `idx_conversation` (`conversation_id`),
                                         KEY `idx_from_agent` (`from_agent_id`),
                                         KEY `idx_to_agent` (`to_agent_id`),
                                         KEY `idx_created` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='会话转移记录表';

CREATE TABLE `customer` (
                            `id` int unsigned NOT NULL AUTO_INCREMENT,
                            `uuid` varchar(64) COLLATE utf8mb4_unicode_ci NOT NULL COMMENT '客户唯一标识',
                            `ip` varchar(45) COLLATE utf8mb4_unicode_ci DEFAULT '' COMMENT 'IP地址',
                            `user_agent` varchar(500) COLLATE utf8mb4_unicode_ci DEFAULT '' COMMENT '浏览器UA',
                            `source_url` varchar(500) COLLATE utf8mb4_unicode_ci DEFAULT '' COMMENT '来源页面URL',
                            `referrer` varchar(500) COLLATE utf8mb4_unicode_ci DEFAULT '' COMMENT '来源referrer',
                            `device_type` varchar(20) COLLATE utf8mb4_unicode_ci DEFAULT '' COMMENT '设备类型: pc/mobile/tablet',
                            `browser` varchar(50) COLLATE utf8mb4_unicode_ci DEFAULT '' COMMENT '浏览器',
                            `os` varchar(50) COLLATE utf8mb4_unicode_ci DEFAULT '' COMMENT '操作系统',
                            `city` varchar(50) COLLATE utf8mb4_unicode_ci DEFAULT '' COMMENT '城市(根据IP解析)',
                            `nickname` varchar(50) COLLATE utf8mb4_unicode_ci DEFAULT '' COMMENT '昵称',
                            `email` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT '' COMMENT '邮箱',
                            `timezone` varchar(50) COLLATE utf8mb4_unicode_ci DEFAULT '' COMMENT '时区',
                            `last_active_at` timestamp NULL DEFAULT NULL COMMENT '最后活跃时间',
                            `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
                            PRIMARY KEY (`id`),
                            UNIQUE KEY `uuid` (`uuid`),
                            KEY `idx_uuid` (`uuid`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='客户表';

CREATE TABLE `message` (
                           `id` bigint unsigned NOT NULL AUTO_INCREMENT,
                           `conversation_id` int unsigned NOT NULL COMMENT '会话ID',
                           `sender_type` tinyint unsigned NOT NULL COMMENT '发送者类型: 1客户 2客服 3系统',
                           `sender_id` int unsigned NOT NULL COMMENT '发送者ID',
                           `content_type` tinyint unsigned DEFAULT '1' COMMENT '内容类型: 1文本 2图片',
                           `content` text COLLATE utf8mb4_unicode_ci COMMENT '消息内容',
                           `is_read` tinyint unsigned DEFAULT '0' COMMENT '是否已读: 0未读 1已读',
                           `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
                           PRIMARY KEY (`id`),
                           KEY `idx_conversation` (`conversation_id`),
                           KEY `idx_created` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='消息表';

CREATE TABLE `quick_reply` (
                               `id` bigint unsigned NOT NULL AUTO_INCREMENT,
                               `title` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL COMMENT '标题',
                               `content` text COLLATE utf8mb4_unicode_ci NOT NULL COMMENT '内容',
                               `sort_order` int NOT NULL DEFAULT '0' COMMENT '排序',
                               `is_active` tinyint NOT NULL DEFAULT '1' COMMENT '是否启用',
                               `created_at` timestamp NULL DEFAULT NULL,
                               `updated_at` timestamp NULL DEFAULT NULL,
                               PRIMARY KEY (`id`),
                               KEY `quick_reply_is_active_index` (`is_active`),
                               KEY `quick_reply_sort_order_index` (`sort_order`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `system_config` (
                                 `id` int unsigned NOT NULL AUTO_INCREMENT,
                                 `key` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL COMMENT '配置键',
                                 `value` text COLLATE utf8mb4_unicode_ci COMMENT '配置值（JSON格式）',
                                 `group` varchar(50) COLLATE utf8mb4_unicode_ci DEFAULT 'general' COMMENT '配置分组: general, sdk_texts, system_messages',
                                 `description` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT '配置说明',
                                 `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
                                 `updated_at` datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                                 PRIMARY KEY (`id`),
                                 UNIQUE KEY `uk_key` (`key`),
                                 KEY `idx_group` (`group`)
) ENGINE=InnoDB AUTO_INCREMENT=15 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='系统配置表';

-- 插入测试客服账号 (密码: 123456)
-- 密码hash: password_hash('123456', PASSWORD_DEFAULT)
INSERT INTO `agent` (`username`, `password`, `nickname`, `max_sessions`) VALUES
('admin', '$2y$10$HgzFySZqVKAluUGYBwUiQ.CNpQR44U2tJKN3gO1W.7Z1kWQ7opzCi', '管理员', 1),
('agent1', '$2y$10$HgzFySZqVKAluUGYBwUiQ.CNpQR44U2tJKN3gO1W.7Z1kWQ7opzCi', '客服小张', 10),
('agent2', '$2y$10$HgzFySZqVKAluUGYBwUiQ.CNpQR44U2tJKN3gO1W.7Z1kWQ7opzCi', '客服小李', 10);

INSERT INTO `im_mvp`.`system_config` (`id`, `key`, `value`, `group`, `description`, `created_at`, `updated_at`) VALUES (1, 'sdk_language', '\"en\"', 'general', '客户端默认语言: zh=中文, en=英文', '2025-12-06 22:36:47', '2025-12-06 22:36:47');
INSERT INTO `im_mvp`.`system_config` (`id`, `key`, `value`, `group`, `description`, `created_at`, `updated_at`) VALUES (2, 'welcome_message', '{\"zh\": \"您好，有什么可以帮您？\", \"en\": \"Hello, how can I help you?\"}', 'sdk_texts', '欢迎语', '2025-12-06 22:36:47', '2025-12-06 22:36:47');
INSERT INTO `im_mvp`.`system_config` (`id`, `key`, `value`, `group`, `description`, `created_at`, `updated_at`) VALUES (3, 'input_placeholder', '{\"zh\": \"请输入消息...\", \"en\": \"Type a message...\"}', 'sdk_texts', '输入框占位符', '2025-12-06 22:36:47', '2025-12-06 22:36:47');
INSERT INTO `im_mvp`.`system_config` (`id`, `key`, `value`, `group`, `description`, `created_at`, `updated_at`) VALUES (4, 'send_button', '{\"zh\": \"发送\", \"en\": \"Send\"}', 'sdk_texts', '发送按钮', '2025-12-06 22:36:47', '2025-12-06 22:36:47');
INSERT INTO `im_mvp`.`system_config` (`id`, `key`, `value`, `group`, `description`, `created_at`, `updated_at`) VALUES (5, 'status_connected', '{\"zh\": \"已连接\", \"en\": \"Connected\"}', 'sdk_texts', '连接状态-已连接', '2025-12-06 22:36:47', '2025-12-06 22:36:47');
INSERT INTO `im_mvp`.`system_config` (`id`, `key`, `value`, `group`, `description`, `created_at`, `updated_at`) VALUES (6, 'status_disconnected', '{\"zh\": \"未连接\", \"en\": \"Disconnected\"}', 'sdk_texts', '连接状态-未连接', '2025-12-06 22:36:47', '2025-12-06 22:36:47');
INSERT INTO `im_mvp`.`system_config` (`id`, `key`, `value`, `group`, `description`, `created_at`, `updated_at`) VALUES (7, 'status_error', '{\"zh\": \"连接错误\", \"en\": \"Connection error\"}', 'sdk_texts', '连接状态-错误', '2025-12-06 22:36:47', '2025-12-06 22:36:47');
INSERT INTO `im_mvp`.`system_config` (`id`, `key`, `value`, `group`, `description`, `created_at`, `updated_at`) VALUES (8, 'agent_typing', '{\"zh\": \"客服正在输入...\", \"en\": \"Agent is typing...\"}', 'sdk_texts', '客服正在输入提示', '2025-12-06 22:36:47', '2025-12-06 22:36:47');
INSERT INTO `im_mvp`.`system_config` (`id`, `key`, `value`, `group`, `description`, `created_at`, `updated_at`) VALUES (9, 'conversation_closed', '{\"zh\": \"会话已结束\", \"en\": \"Conversation ended\"}', 'sdk_texts', '会话结束提示', '2025-12-06 22:36:47', '2025-12-06 22:36:47');
INSERT INTO `im_mvp`.`system_config` (`id`, `key`, `value`, `group`, `description`, `created_at`, `updated_at`) VALUES (10, 'queue_waiting', '{\"zh\": \"正在排队等待客服...\", \"en\": \"Waiting in queue...\"}', 'sdk_texts', '排队等待提示(默认)', '2025-12-06 22:36:47', '2025-12-06 22:36:47');
INSERT INTO `im_mvp`.`system_config` (`id`, `key`, `value`, `group`, `description`, `created_at`, `updated_at`) VALUES (11, 'agent_assigned', '{\"zh\": \"客服已接入\", \"en\": \"Agent connected\"}', 'sdk_texts', '客服接入提示(默认)', '2025-12-06 22:36:47', '2025-12-06 22:36:47');
INSERT INTO `im_mvp`.`system_config` (`id`, `key`, `value`, `group`, `description`, `created_at`, `updated_at`) VALUES (12, 'offline_messages_tip', '{\"zh\": \"您有 {count} 条离线消息\", \"en\": \"You have {count} offline message(s)\"}', 'sdk_texts', '离线消息提示', '2025-12-06 22:36:47', '2025-12-06 22:36:47');
INSERT INTO `im_mvp`.`system_config` (`id`, `key`, `value`, `group`, `description`, `created_at`, `updated_at`) VALUES (13, 'msg_agent_assigned', '{\"zh\": \"客服已接入，正在为您服务。\", \"en\": \"Agent connected. We are here to help.\"}', 'system_messages', '客服接入通知', '2025-12-06 22:36:47', '2025-12-06 22:36:47');
INSERT INTO `im_mvp`.`system_config` (`id`, `key`, `value`, `group`, `description`, `created_at`, `updated_at`) VALUES (14, 'msg_queue_waiting', '{\"zh\": \"当前暂无客服在线，您的消息已收到，客服上线后会尽快回复您。\", \"en\": \"No agents available. Your message has been received and will be answered soon.\"}', 'system_messages', '无客服在线通知', '2025-12-06 22:36:47', '2025-12-06 22:36:47');


