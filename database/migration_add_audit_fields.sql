-- 留言审核字段迁移脚本
-- 执行此 SQL 为留言表添加审核处理意见与审核时间字段，用于批量审核功能

USE `community_board`;

-- 留言表增加审核字段
ALTER TABLE `messages`
    ADD COLUMN `audit_note` VARCHAR(500) DEFAULT NULL COMMENT '审核处理意见' AFTER `status`,
    ADD COLUMN `audited_at` DATETIME DEFAULT NULL COMMENT '审核时间' AFTER `audit_note`;

-- 执行完成后，可以通过以下命令验证：
-- DESCRIBE messages;
