-- ============================================
-- 添加客户邮箱和时区字段
-- 执行时间: 2025-12-06
-- ============================================

USE `im_mvp`;

-- 添加邮箱字段
ALTER TABLE `customer` 
ADD COLUMN `email` VARCHAR(255) DEFAULT '' COMMENT '邮箱' AFTER `nickname`;

-- 添加时区字段
ALTER TABLE `customer` 
ADD COLUMN `timezone` VARCHAR(50) DEFAULT '' COMMENT '客户时区' AFTER `email`;

-- 添加邮箱索引（方便后续按邮箱查询客户）
ALTER TABLE `customer` 
ADD INDEX `idx_email` (`email`);

