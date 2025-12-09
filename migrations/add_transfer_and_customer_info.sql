-- ============================================
-- IM-MVP 会话转移和客户信息扩展
-- ============================================

USE `im_mvp`;

-- 1. 客服表添加管理员标识
ALTER TABLE `agent` 
ADD COLUMN `is_admin` TINYINT UNSIGNED DEFAULT 0 COMMENT '是否管理员: 0否 1是' AFTER `status`;

-- 更新 admin 账号为管理员
UPDATE `agent` SET `is_admin` = 1 WHERE `username` = 'admin';

-- 2. 客户表添加来源信息
ALTER TABLE `customer` 
ADD COLUMN `source_url` VARCHAR(500) DEFAULT '' COMMENT '来源页面URL' AFTER `user_agent`,
ADD COLUMN `referrer` VARCHAR(500) DEFAULT '' COMMENT '来源referrer' AFTER `source_url`,
ADD COLUMN `device_type` VARCHAR(20) DEFAULT '' COMMENT '设备类型: pc/mobile/tablet' AFTER `referrer`,
ADD COLUMN `browser` VARCHAR(50) DEFAULT '' COMMENT '浏览器' AFTER `device_type`,
ADD COLUMN `os` VARCHAR(50) DEFAULT '' COMMENT '操作系统' AFTER `browser`,
ADD COLUMN `city` VARCHAR(50) DEFAULT '' COMMENT '城市(根据IP解析)' AFTER `os`;

-- 3. 会话转移记录表
CREATE TABLE IF NOT EXISTS `conversation_transfer` (
    `id` INT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
    `conversation_id` INT UNSIGNED NOT NULL COMMENT '会话ID',
    `from_agent_id` INT UNSIGNED NOT NULL COMMENT '原客服ID',
    `to_agent_id` INT UNSIGNED NOT NULL COMMENT '目标客服ID',
    `transfer_type` TINYINT UNSIGNED DEFAULT 1 COMMENT '转移类型: 1手动 2超时自动',
    `operator_id` INT UNSIGNED DEFAULT NULL COMMENT '操作人ID(手动转移时)',
    `reason` VARCHAR(255) DEFAULT '' COMMENT '转移原因',
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX `idx_conversation` (`conversation_id`),
    INDEX `idx_from_agent` (`from_agent_id`),
    INDEX `idx_to_agent` (`to_agent_id`),
    INDEX `idx_created` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='会话转移记录表';

-- 4. 会话表添加最后客服回复时间（用于超时判断）
ALTER TABLE `conversation` 
ADD COLUMN `last_agent_reply_at` TIMESTAMP NULL COMMENT '客服最后回复时间' AFTER `last_message_at`,
ADD COLUMN `last_customer_msg_at` TIMESTAMP NULL COMMENT '客户最后消息时间' AFTER `last_agent_reply_at`;

