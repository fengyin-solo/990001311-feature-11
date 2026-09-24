-- 留言批量审核迁移脚本
-- 执行此 SQL 来为留言表增加统一审核意见字段

USE `community_board`;

-- 审核意见：单条审核时保持 NULL（不影响原有单条审核逻辑），批量审核时写入统一处理意见
ALTER TABLE `messages`
    ADD COLUMN `audit_note` VARCHAR(500) DEFAULT NULL COMMENT '审核意见/处理备注' AFTER `status`;

-- 执行完成后，可以通过以下命令验证：
-- DESCRIBE messages;
