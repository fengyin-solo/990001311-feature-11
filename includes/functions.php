<?php
session_start();

/**
 * 返回JSON响应
 */
function jsonResponse($code, $msg, $data = null) {
    header('Content-Type: application/json; charset=utf-8');
    $res = ['code' => $code, 'msg' => $msg];
    if ($data !== null) $res['data'] = $data;
    echo json_encode($res, JSON_UNESCAPED_UNICODE);
    exit;
}

/**
 * 获取留言类型文字
 */
function getTypeLabel($type) {
    $map = ['help' => '居民求助', 'suggest' => '意见建议', 'lost' => '失物招领'];
    return $map[$type] ?? '其他';
}

/**
 * 获取类型图标
 */
function getTypeIcon($type) {
    $map = ['help' => '🆘', 'suggest' => '💡', 'lost' => '🔍'];
    return $map[$type] ?? '📌';
}

/**
 * 获取状态文字
 */
function getStatusLabel($status) {
    $map = [0 => '待审核', 1 => '已通过', 2 => '已拒绝'];
    return $map[$status] ?? '未知';
}

/**
 * 获取状态样式类
 */
function getStatusClass($status) {
    $map = [0 => 'pending', 1 => 'approved', 2 => 'rejected'];
    return $map[$status] ?? '';
}

/**
 * 时间格式化
 */
function timeAgo($datetime) {
    $now = new DateTime();
    $ago = new DateTime($datetime);
    $diff = $now->diff($ago);

    if ($diff->y > 0) return $diff->y . '年前';
    if ($diff->m > 0) return $diff->m . '个月前';
    if ($diff->d > 0) return $diff->d . '天前';
    if ($diff->h > 0) return $diff->h . '小时前';
    if ($diff->i > 0) return $diff->i . '分钟前';
    return '刚刚';
}

/**
 * 检查管理员登录
 *
 * 普通访问（页面）未登录时跳转登录页；
 * AJAX 请求（批量审核等）未登录/会话失效时返回 401 JSON，
 * 以便前端识别“权限已失效”，保留当前选择并允许重试。
 */
function requireAdmin() {
    if (empty($_SESSION['admin_id'])) {
        $isAjax = !empty($_SERVER['HTTP_X_REQUESTED_WITH'])
            && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';
        if ($isAjax) {
            header('Content-Type: application/json; charset=utf-8');
            http_response_code(401);
            echo json_encode(['code' => 401, 'msg' => '登录已过期，请重新登录后重试'], JSON_UNESCAPED_UNICODE);
            exit;
        }
        header('Location: login.php');
        exit;
    }
}

/**
 * 解析批量操作提交的留言ID
 * 支持 ids[]=1&ids[]=2（FormData/数组）或 ids=1,2（逗号分隔字符串）
 *
 * @return int[] 去重、去零后的正整数ID数组，最多 100 个
 */
function parseBatchIds($raw) {
    $ids = [];
    if (is_array($raw)) {
        $ids = $raw;
    } elseif (is_string($raw) && $raw !== '') {
        $ids = explode(',', $raw);
    }
    $result = [];
    foreach ($ids as $v) {
        $id = intval($v);
        if ($id > 0) $result[$id] = $id;
        if (count($result) >= 100) break;
    }
    return array_values($result);
}

/**
 * 获取待审核留言数量
 */
function getPendingMessageCount() {
    $db = getDB();
    return (int) $db->query("SELECT COUNT(*) FROM messages WHERE status = 0")->fetchColumn();
}

/**
 * 组装留言列表项的前端展示数据（批量预览/结果用）
 */
function formatMessageRow($msg) {
    return [
        'id' => (int) $msg['id'],
        'type' => $msg['type'],
        'type_label' => getTypeLabel($msg['type']),
        'type_class' => $msg['type'],
        'title' => cleanInput($msg['title']),
        'nickname' => cleanInput($msg['nickname']),
        'status' => (int) $msg['status'],
        'status_label' => getStatusLabel($msg['status']),
        'status_class' => getStatusClass($msg['status']),
    ];
}

/**
 * 批量审核预览
 * 返回提交ID对应的留言当前状态；提交后状态已变化/已删除的条目标记出来，供前端逐条提示。
 *
 * @param int[] $ids
 * @return array ['items' => 每条留言信息(含 exists/pending 标记), 'pending_count' => 当前待审总数]
 */
function batchPreviewMessages(array $ids) {
    $db = getDB();
    $items = [];
    if ($ids) {
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $stmt = $db->prepare("SELECT id, nickname, type, title, status FROM messages WHERE id IN ($placeholders)");
        $stmt->execute($ids);
        $rows = $stmt->fetchAll();
        $byId = [];
        foreach ($rows as $row) $byId[(int) $row['id']] = $row;

        foreach ($ids as $id) {
            if (isset($byId[$id])) {
                $row = formatMessageRow($byId[$id]);
                $row['exists'] = true;
                $row['pending'] = ($row['status'] === 0);
                $items[] = $row;
            } else {
                $items[] = ['id' => $id, 'exists' => false, 'pending' => false,
                    'status' => -1, 'status_label' => '不存在', 'status_class' => '',
                    'type' => '', 'type_class' => '', 'type_label' => '-', 'title' => '留言不存在或已删除', 'nickname' => '-'];
            }
        }
    }
    return ['items' => $items, 'pending_count' => getPendingMessageCount()];
}

/**
 * 批量审核
 *
 * 逐条独立处理：单条失败不回滚已成功项；
 * 仅当留言仍为“待审核(0)”时才更新（状态已被他人改动则返回 changed，删除则 not_found）。
 *
 * @param int[] $ids
 * @param int   $status 目标状态 1=通过 2=拒绝
 * @param string $note 统一审核意见
 * @return array ['results' => 逐条结果, 'success_count', 'fail_count', 'changed_count', 'not_found_count', 'pending_count']
 */
function batchAuditMessages(array $ids, $status, $note) {
    $db = getDB();
    // 与单条审核相同的条件更新：只影响仍处于待审核状态的留言，成功项逐条提交、互不回滚
    $updateStmt = $db->prepare("UPDATE messages SET status = ?, audit_note = ? WHERE id = ? AND status = 0");
    $selectStmt = $db->prepare("SELECT id, nickname, type, title, status FROM messages WHERE id = ?");

    $results = [];
    $successCount = $failCount = $changedCount = $notFoundCount = 0;

    foreach ($ids as $id) {
        try {
            $updateStmt->execute([$status, $note, $id]);
            if ($updateStmt->rowCount() > 0) {
                // 更新成功：回查最新数据用于同步列表显示
                $selectStmt->execute([$id]);
                $msg = $selectStmt->fetch();
                $results[] = ['id' => $id, 'result' => 'success', 'msg' => '成功']
                    + ($msg ? formatMessageRow($msg) : ['title' => '#'.$id]);
                $successCount++;
                continue;
            }

            // 未命中待审行：查一下当前状态，区分“状态已变化”与“留言已删除”
            $selectStmt->execute([$id]);
            $msg = $selectStmt->fetch();
            if ($msg) {
                $results[] = ['id' => $id, 'result' => 'changed', 'msg' => '状态已变化，未处理']
                    + formatMessageRow($msg);
                $changedCount++;
            } else {
                $results[] = ['id' => $id, 'result' => 'not_found', 'msg' => '留言不存在或已删除',
                    'exists' => false, 'status' => -1, 'status_label' => '不存在', 'status_class' => '',
                    'type' => '', 'type_class' => '', 'type_label' => '-', 'title' => '留言不存在或已删除', 'nickname' => '-'];
                $notFoundCount++;
            }
        } catch (Exception $e) {
            // 单条异常不影响其它条目，调用方可带着该选择重试
            $results[] = ['id' => $id, 'result' => 'error', 'msg' => '处理失败，可重试'];
            $failCount++;
        }
    }

    return [
        'results' => $results,
        'success_count' => $successCount,
        'fail_count' => $failCount,
        'changed_count' => $changedCount,
        'not_found_count' => $notFoundCount,
        'pending_count' => getPendingMessageCount(),
    ];
}

/**
 * 过滤输入
 */
function cleanInput($str) {
    return htmlspecialchars(trim($str), ENT_QUOTES, 'UTF-8');
}

/**
 * 获取访客唯一标识
 * 基于session和cookie实现匿名用户标识
 */
function getVisitorId() {
    if (empty($_SESSION['visitor_id'])) {
        if (!empty($_COOKIE['visitor_id'])) {
            $_SESSION['visitor_id'] = $_COOKIE['visitor_id'];
        } else {
            $visitorId = md5(uniqid('visitor_', true) . $_SERVER['REMOTE_ADDR'] . $_SERVER['HTTP_USER_AGENT']);
            $_SESSION['visitor_id'] = $visitorId;
            setcookie('visitor_id', $visitorId, time() + 86400 * 365, '/');
        }
    }
    return $_SESSION['visitor_id'];
}

/**
 * 检查留言是否已收藏
 */
function isFavorited($messageId) {
    $visitorId = getVisitorId();
    $db = getDB();
    $stmt = $db->prepare("SELECT id FROM favorites WHERE visitor_id = ? AND message_id = ?");
    $stmt->execute([$visitorId, $messageId]);
    return $stmt->fetch() !== false;
}

/**
 * 获取当前访客收藏的所有留言ID
 */
function getFavoritedMessageIds() {
    $visitorId = getVisitorId();
    $db = getDB();
    $stmt = $db->prepare("SELECT message_id FROM favorites WHERE visitor_id = ?");
    $stmt->execute([$visitorId]);
    return array_column($stmt->fetchAll(), 'message_id');
}

/**
 * 切换收藏状态
 * 返回: ['favorited' => bool, 'action' => 'add'|'remove']
 */
function toggleFavorite($messageId) {
    $visitorId = getVisitorId();
    $db = getDB();

    $db->beginTransaction();
    try {
        $stmt = $db->prepare("SELECT id FROM favorites WHERE visitor_id = ? AND message_id = ? FOR UPDATE");
        $stmt->execute([$visitorId, $messageId]);
        $exists = $stmt->fetch();

        if ($exists) {
            $db->prepare("DELETE FROM favorites WHERE visitor_id = ? AND message_id = ?")
                ->execute([$visitorId, $messageId]);
            $result = ['favorited' => false, 'action' => 'remove'];
        } else {
            $db->prepare("INSERT INTO favorites (visitor_id, message_id) VALUES (?, ?)")
                ->execute([$visitorId, $messageId]);
            $result = ['favorited' => true, 'action' => 'add'];
        }

        $db->commit();
        return $result;
    } catch (Exception $e) {
        $db->rollBack();
        throw $e;
    }
}

/**
 * 获取举报类型文字
 */
function getReportTypeLabel($type) {
    $map = [
        'spam' => '垃圾信息',
        'abuse' => '辱骂攻击',
        'illegal' => '违法违规',
        'porn' => '色情低俗',
        'other' => '其他'
    ];
    return $map[$type] ?? '未知';
}

/**
 * 获取举报状态文字
 */
function getReportStatusLabel($status) {
    $map = [
        0 => '待处理',
        1 => '已处理-已删除',
        2 => '已处理-已忽略',
        3 => '已驳回'
    ];
    return $map[$status] ?? '未知';
}

/**
 * 获取举报状态样式类
 */
function getReportStatusClass($status) {
    $map = [
        0 => 'pending',
        1 => 'resolved-deleted',
        2 => 'resolved-ignored',
        3 => 'rejected'
    ];
    return $map[$status] ?? '';
}

/**
 * 检查当前访客是否已举报过某条留言
 */
function hasReported($messageId) {
    $visitorId = getVisitorId();
    $db = getDB();
    $stmt = $db->prepare("SELECT id FROM reports WHERE visitor_id = ? AND message_id = ?");
    $stmt->execute([$visitorId, $messageId]);
    return $stmt->fetch() !== false;
}

/**
 * 提交举报
 */
function submitReport($messageId, $reportType, $description = '') {
    $visitorId = getVisitorId();
    $db = getDB();

    $validTypes = ['spam', 'abuse', 'illegal', 'porn', 'other'];
    if (!in_array($reportType, $validTypes)) {
        throw new Exception('无效的举报类型');
    }

    $stmt = $db->prepare("SELECT id FROM messages WHERE id = ? AND status = 1");
    $stmt->execute([$messageId]);
    if (!$stmt->fetch()) {
        throw new Exception('留言不存在或未通过审核');
    }

    if (hasReported($messageId)) {
        throw new Exception('您已经举报过这条留言了');
    }

    $stmt = $db->prepare("INSERT INTO reports (message_id, visitor_id, report_type, description) VALUES (?, ?, ?, ?)");
    $stmt->execute([$messageId, $visitorId, $reportType, $description]);

    return $db->lastInsertId();
}

/**
 * 获取待处理举报数量
 */
function getPendingReportCount() {
    $db = getDB();
    return $db->query("SELECT COUNT(*) FROM reports WHERE status = 0")->fetchColumn();
}
