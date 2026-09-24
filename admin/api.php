<?php
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../config/database.php';

// 接口会话校验：登录失效时返回 JSON 401（而非跳转登录页），
// 便于前端保留当前操作状态并允许用户重新登录后重试
if (empty($_SESSION['admin_id'])) {
    jsonResponse(401, '登录状态已失效，请重新登录');
}

header('Content-Type: application/json; charset=utf-8');

$action = $_GET['action'] ?? $_POST['action'] ?? '';
$db = getDB();

/**
 * 解析批量操作的ID列表（支持数组或逗号分隔字符串），去重并过滤非法值
 */
function parseIds($raw) {
    if (!is_array($raw)) $raw = explode(',', (string)$raw);
    $ids = array_map('intval', $raw);
    $ids = array_filter($ids, function($id) { return $id > 0; });
    return array_values(array_unique($ids));
}

switch ($action) {
    case 'detail':
        $id = intval($_GET['id'] ?? 0);
        $stmt = $db->prepare("SELECT * FROM messages WHERE id = ?");
        $stmt->execute([$id]);
        $msg = $stmt->fetch();
        if (!$msg) jsonResponse(1, '留言不存在');
        $msg['type_label'] = getTypeLabel($msg['type']);
        $msg['status_label'] = getStatusLabel($msg['status']);
        $msg['content'] = nl2br(cleanInput($msg['content']));
        $msg['title'] = cleanInput($msg['title']);
        $msg['nickname'] = cleanInput($msg['nickname']);
        $msg['audit_note'] = !empty($msg['audit_note']) ? nl2br(cleanInput($msg['audit_note'])) : '';
        jsonResponse(0, 'ok', $msg);
        break;

    case 'audit':
        $id = intval($_POST['id'] ?? 0);
        $status = intval($_POST['status'] ?? 0);
        if (!in_array($status, [1, 2])) jsonResponse(1, '无效状态');
        $stmt = $db->prepare("UPDATE messages SET status = ? WHERE id = ?");
        $stmt->execute([$status, $id]);
        jsonResponse(0, '操作成功');
        break;

    case 'batch_preview':
        // 批量审核前预览：返回每个选中项的当前状态，标记哪些会被实际处理
        $ids = parseIds($_POST['ids'] ?? []);
        if (empty($ids)) jsonResponse(1, '请先选择要审核的留言');

        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $stmt = $db->prepare("SELECT id, title, nickname, status FROM messages WHERE id IN ($placeholders)");
        $stmt->execute($ids);
        $found = array_column($stmt->fetchAll(), null, 'id');

        $items = [];
        $actionableCount = 0;
        foreach ($ids as $id) {
            if (!isset($found[$id])) {
                $items[] = [
                    'id' => $id,
                    'exists' => false,
                    'actionable' => false,
                    'title' => '',
                    'status_label' => '留言不存在',
                ];
                continue;
            }
            $m = $found[$id];
            $actionable = intval($m['status']) === 0;
            if ($actionable) $actionableCount++;
            $items[] = [
                'id' => intval($m['id']),
                'exists' => true,
                'actionable' => $actionable,
                'title' => cleanInput($m['title']),
                'nickname' => cleanInput($m['nickname']),
                'status' => intval($m['status']),
                'status_label' => getStatusLabel($m['status']),
            ];
        }
        jsonResponse(0, 'ok', [
            'items' => $items,
            'total' => count($items),
            'actionable_count' => $actionableCount,
        ]);
        break;

    case 'batch_audit':
        // 批量审核：逐条独立事务处理，单条失败不影响其他条目，成功项不回滚
        $ids = parseIds($_POST['ids'] ?? []);
        $statusRaw = $_POST['status'] ?? '';
        $status = is_scalar($statusRaw) ? intval($statusRaw) : 0;
        $noteRaw = $_POST['note'] ?? '';
        $note = is_string($noteRaw) ? trim($noteRaw) : '';

        if (empty($ids)) jsonResponse(1, '请先选择要审核的留言');
        if (!in_array($status, [1, 2])) jsonResponse(1, '无效状态');
        if (mb_strlen($note) > 500) jsonResponse(1, '处理意见不能超过500字');

        $results = [];
        $successCount = 0;
        $failCount = 0;

        foreach ($ids as $id) {
            try {
                $db->beginTransaction();

                $stmt = $db->prepare("SELECT id, status FROM messages WHERE id = ? FOR UPDATE");
                $stmt->execute([$id]);
                $msg = $stmt->fetch();

                if (!$msg) {
                    $db->rollBack();
                    $results[] = ['id' => $id, 'success' => false, 'msg' => '留言不存在或已被删除'];
                    $failCount++;
                    continue;
                }

                if (intval($msg['status']) !== 0) {
                    $db->rollBack();
                    $results[] = [
                        'id' => $id,
                        'success' => false,
                        'msg' => '状态已变更（当前：' . getStatusLabel($msg['status']) . '），已跳过',
                        'current_status' => intval($msg['status']),
                    ];
                    $failCount++;
                    continue;
                }

                $stmt = $db->prepare("UPDATE messages SET status = ?, audit_note = ?, audited_at = NOW() WHERE id = ? AND status = 0");
                $stmt->execute([$status, $note !== '' ? $note : null, $id]);

                if ($stmt->rowCount() === 0) {
                    // 并发兜底：行锁内状态仍为0但更新未生效时按失败返回
                    $db->rollBack();
                    $results[] = ['id' => $id, 'success' => false, 'msg' => '状态已变更，已跳过'];
                    $failCount++;
                    continue;
                }

                $db->commit();
                $results[] = ['id' => $id, 'success' => true, 'msg' => $status === 1 ? '已通过' : '已拒绝'];
                $successCount++;
            } catch (Exception $e) {
                if ($db->inTransaction()) $db->rollBack();
                $results[] = ['id' => $id, 'success' => false, 'msg' => '处理失败，请重试'];
                $failCount++;
            }
        }

        jsonResponse(0, "处理完成：成功 {$successCount} 条，失败 {$failCount} 条", [
            'results' => $results,
            'success_count' => $successCount,
            'fail_count' => $failCount,
        ]);
        break;

    case 'delete':
        $id = intval($_POST['id'] ?? 0);
        // 删除关联图片
        $stmt = $db->prepare("SELECT image FROM messages WHERE id = ?");
        $stmt->execute([$id]);
        $msg = $stmt->fetch();
        if ($msg && $msg['image']) {
            $imgFile = __DIR__ . '/../' . $msg['image'];
            if (file_exists($imgFile)) unlink($imgFile);
        }
        $db->prepare("DELETE FROM messages WHERE id = ?")->execute([$id]);
        jsonResponse(0, '删除成功');
        break;

    case 'report_detail':
        $id = intval($_GET['id'] ?? 0);
        $stmt = $db->prepare("SELECT r.*, m.title as message_title, m.nickname as message_nickname, m.type as message_type, m.content as message_content, m.image as message_image, a.username as admin_name FROM reports r LEFT JOIN messages m ON r.message_id = m.id LEFT JOIN admins a ON r.processed_by = a.id WHERE r.id = ?");
        $stmt->execute([$id]);
        $report = $stmt->fetch();
        if (!$report) jsonResponse(1, '举报不存在');

        $report['report_type_label'] = getReportTypeLabel($report['report_type']);
        $report['status_label'] = getReportStatusLabel($report['status']);
        $report['status_class'] = getReportStatusClass($report['status']);
        $report['message_exists'] = !empty($report['message_title']);
        $report['message_type_label'] = $report['message_type'] ? getTypeLabel($report['message_type']) : '';
        $report['message_title'] = $report['message_title'] ? cleanInput($report['message_title']) : '';
        $report['message_nickname'] = $report['message_nickname'] ? cleanInput($report['message_nickname']) : '';
        $report['message_content'] = $report['message_content'] ? nl2br(cleanInput($report['message_content'])) : '';
        $report['description'] = $report['description'] ? nl2br(cleanInput($report['description'])) : '';
        $report['process_note'] = $report['process_note'] ? nl2br(cleanInput($report['process_note'])) : '';
        $report['admin_name'] = $report['admin_name'] ? cleanInput($report['admin_name']) : '';

        jsonResponse(0, 'ok', $report);
        break;

    case 'process_report':
        $id = intval($_POST['id'] ?? 0);
        $status = intval($_POST['status'] ?? 0);
        $note = cleanInput($_POST['note'] ?? '');

        if (!in_array($status, [1, 2, 3])) jsonResponse(1, '无效状态');

        $db->beginTransaction();
        try {
            $stmt = $db->prepare("SELECT * FROM reports WHERE id = ? AND status = 0 FOR UPDATE");
            $stmt->execute([$id]);
            $report = $stmt->fetch();
            if (!$report) jsonResponse(1, '举报不存在或已处理');

            if ($status === 1) {
                $stmt = $db->prepare("SELECT image FROM messages WHERE id = ?");
                $stmt->execute([$report['message_id']]);
                $msg = $stmt->fetch();
                if ($msg && $msg['image']) {
                    $imgFile = __DIR__ . '/../' . $msg['image'];
                    if (file_exists($imgFile)) unlink($imgFile);
                }
                $db->prepare("DELETE FROM messages WHERE id = ?")->execute([$report['message_id']]);
            }

            $stmt = $db->prepare("UPDATE reports SET status = ?, processed_by = ?, processed_at = NOW(), process_note = ? WHERE id = ?");
            $stmt->execute([$status, $_SESSION['admin_id'], $note, $id]);

            $db->commit();

            $statusMsg = [1 => '已删除留言', 2 => '已忽略举报', 3 => '已驳回举报'];
            jsonResponse(0, $statusMsg[$status] . '成功');
        } catch (Exception $e) {
            $db->rollBack();
            jsonResponse(1, '操作失败: ' . $e->getMessage());
        }
        break;

    default:
        jsonResponse(1, '未知操作');
}
