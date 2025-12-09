-- ============================================
-- IM-MVP 数据库表结构
-- ============================================

CREATE DATABASE IF NOT EXISTS `im_mvp` DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

USE `im_mvp`;

-- 1. 客服表
CREATE TABLE IF NOT EXISTS `agent` (
    `id` INT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
    `username` VARCHAR(50) NOT NULL UNIQUE COMMENT '登录用户名',
    `password` VARCHAR(255) NOT NULL COMMENT '密码(bcrypt)',
    `nickname` VARCHAR(50) DEFAULT '' COMMENT '昵称',
    `avatar` VARCHAR(255) DEFAULT '' COMMENT '头像',
    `max_sessions` INT UNSIGNED DEFAULT 10 COMMENT '最大同时处理会话数',
    `status` TINYINT UNSIGNED DEFAULT 1 COMMENT '账号状态: 1启用 0禁用',
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='客服表';

-- 2. 客户表
CREATE TABLE IF NOT EXISTS `customer` (
    `id` INT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
    `uuid` VARCHAR(64) NOT NULL UNIQUE COMMENT '客户唯一标识',
    `ip` VARCHAR(45) DEFAULT '' COMMENT 'IP地址',
    `user_agent` VARCHAR(500) DEFAULT '' COMMENT '浏览器UA',
    `nickname` VARCHAR(50) DEFAULT '' COMMENT '昵称',
    `last_active_at` TIMESTAMP NULL COMMENT '最后活跃时间',
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX `idx_uuid` (`uuid`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='客户表';

-- 3. 会话表
CREATE TABLE IF NOT EXISTS `conversation` (
    `id` INT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
    `customer_id` INT UNSIGNED NOT NULL COMMENT '客户ID',
    `agent_id` INT UNSIGNED DEFAULT NULL COMMENT '分配的客服ID',
    `status` TINYINT UNSIGNED DEFAULT 0 COMMENT '状态: 0待分配 1进行中 2已完成',
    `last_message_at` TIMESTAMP NULL COMMENT '最后消息时间',
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `closed_at` TIMESTAMP NULL COMMENT '关闭时间',
    INDEX `idx_customer` (`customer_id`),
    INDEX `idx_agent_status` (`agent_id`, `status`),
    INDEX `idx_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='会话表';

-- 4. 消息表
CREATE TABLE IF NOT EXISTS `message` (
    `id` BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
    `conversation_id` INT UNSIGNED NOT NULL COMMENT '会话ID',
    `sender_type` TINYINT UNSIGNED NOT NULL COMMENT '发送者类型: 1客户 2客服 3系统',
    `sender_id` INT UNSIGNED NOT NULL COMMENT '发送者ID',
    `content_type` TINYINT UNSIGNED DEFAULT 1 COMMENT '内容类型: 1文本 2图片',
    `content` TEXT COMMENT '消息内容',
    `is_read` TINYINT UNSIGNED DEFAULT 0 COMMENT '是否已读: 0未读 1已读',
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX `idx_conversation` (`conversation_id`),
    INDEX `idx_created` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='消息表';

-- 插入测试客服账号 (密码: 123456)
-- 密码hash: password_hash('123456', PASSWORD_DEFAULT)
INSERT INTO `agent` (`username`, `password`, `nickname`, `max_sessions`) VALUES
('admin', '$2y$10$HgzFySZqVKAluUGYBwUiQ.CNpQR44U2tJKN3gO1W.7Z1kWQ7opzCi', '管理员', 20),
('agent1', '$2y$10$HgzFySZqVKAluUGYBwUiQ.CNpQR44U2tJKN3gO1W.7Z1kWQ7opzCi', '客服小张', 10),
('agent2', '$2y$10$HgzFySZqVKAluUGYBwUiQ.CNpQR44U2tJKN3gO1W.7Z1kWQ7opzCi', '客服小李', 10);

