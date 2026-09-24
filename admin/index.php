<?php
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../config/database.php';
requireAdmin();

$pageTitle = '后台管理 - 社区便民留言板';
$currentPage = 'admin';
$cssPath = '../assets/css/style.css';
$jsPath = '../assets/js/main.js';

$db = getDB();

// 筛选参数
$status = $_GET['status'] ?? '';
$type = $_GET['type'] ?? '';
$keyword = trim($_GET['keyword'] ?? '');
$page = max(1, intval($_GET['page'] ?? 1));
$pageSize = 15;
$offset = ($page - 1) * $pageSize;

$where = "WHERE 1=1";
$params = [];

if ($status !== '' && in_array($status, ['0', '1', '2'])) {
    $where .= " AND status = ?";
    $params[] = intval($status);
}
if ($type && in_array($type, ['help', 'suggest', 'lost'])) {
    $where .= " AND type = ?";
    $params[] = $type;
}
if ($keyword) {
    $where .= " AND (title LIKE ? OR content LIKE ? OR nickname LIKE ?)";
    $kw = "%$keyword%";
    $params[] = $kw;
    $params[] = $kw;
    $params[] = $kw;
}

$countStmt = $db->prepare("SELECT COUNT(*) FROM messages $where");
$countStmt->execute($params);
$total = $countStmt->fetchColumn();
$totalPages = ceil($total / $pageSize);

$sql = "SELECT * FROM messages $where ORDER BY created_at DESC LIMIT $pageSize OFFSET $offset";
$stmt = $db->prepare($sql);
$stmt->execute($params);
$messages = $stmt->fetchAll();

// 统计
$pendingCount = $db->query("SELECT COUNT(*) FROM messages WHERE status = 0")->fetchColumn();

include __DIR__ . '/header.php';
?>

<div class="admin-container">
    <aside class="admin-sidebar">
        <div class="sidebar-header">
            <h3>📋 管理后台</h3>
        </div>
        <nav class="sidebar-nav">
            <a href="index.php" class="sidebar-link active">📝 留言管理</a>
            <a href="index.php?status=0" class="sidebar-link">⏳ 待审核 <?= $pendingCount > 0 ? "($pendingCount)" : '' ?></a>
            <a href="reports.php" class="sidebar-link">🚩 举报管理</a>
            <?php $pendingReportCount = getPendingReportCount(); ?>
            <a href="reports.php?status=0" class="sidebar-link">⏳ 待处理举报 <?= $pendingReportCount > 0 ? "($pendingReportCount)" : '' ?></a>
            <a href="../index.php" class="sidebar-link" target="_blank">🌐 查看前台</a>
            <a href="logout.php" class="sidebar-link">🚪 退出登录</a>
        </nav>
    </aside>

    <div class="admin-main">
        <div class="admin-header">
            <h2>留言管理</h2>
            <span class="admin-user">👤 <?= cleanInput($_SESSION['admin_name']) ?></span>
        </div>

        <!-- 筛选栏 -->
        <div class="admin-filter">
            <form method="GET" class="filter-form">
                <select name="status">
                    <option value="">全部状态</option>
                    <option value="0" <?= $status === '0' ? 'selected' : '' ?>>待审核</option>
                    <option value="1" <?= $status === '1' ? 'selected' : '' ?>>已通过</option>
                    <option value="2" <?= $status === '2' ? 'selected' : '' ?>>已拒绝</option>
                </select>
                <select name="type">
                    <option value="">全部类型</option>
                    <option value="help" <?= $type === 'help' ? 'selected' : '' ?>>居民求助</option>
                    <option value="suggest" <?= $type === 'suggest' ? 'selected' : '' ?>>意见建议</option>
                    <option value="lost" <?= $type === 'lost' ? 'selected' : '' ?>>失物招领</option>
                </select>
                <input type="text" name="keyword" placeholder="搜索关键词..." value="<?= cleanInput($keyword) ?>">
                <button type="submit" class="btn btn-primary btn-sm">筛选</button>
                <a href="index.php" class="btn btn-secondary btn-sm">重置</a>
            </form>
        </div>

        <!-- 批量操作工具栏 -->
        <div class="batch-toolbar">
            <span class="batch-selected-info">已选 <strong id="selectedCount">0</strong> 条待审核留言</span>
            <div class="batch-actions">
                <button class="btn btn-sm btn-success" onclick="openBatchModal(1)">✓ 批量通过</button>
                <button class="btn btn-sm btn-warning" onclick="openBatchModal(2)">✗ 批量拒绝</button>
                <button class="btn btn-sm btn-secondary" onclick="clearSelection()">清空选择</button>
            </div>
        </div>

        <!-- 留言表格 -->
        <div class="admin-table-wrapper">
            <table class="admin-table">
                <thead>
                    <tr>
                        <th class="th-check"><input type="checkbox" id="selectAll" title="全选本页待审核" onchange="toggleSelectAll(this)"></th>
                        <th>ID</th>
                        <th>类型</th>
                        <th>标题</th>
                        <th>昵称</th>
                        <th>状态</th>
                        <th>浏览</th>
                        <th>时间</th>
                        <th>操作</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($messages)): ?>
                    <tr><td colspan="9" class="text-center">暂无数据</td></tr>
                    <?php else: ?>
                    <?php foreach ($messages as $msg): ?>
                    <tr>
                        <td class="td-check">
                            <?php if ($msg['status'] == 0): ?>
                            <input type="checkbox" class="row-check" value="<?= $msg['id'] ?>">
                            <?php endif; ?>
                        </td>
                        <td><?= $msg['id'] ?></td>
                        <td><span class="badge badge-<?= $msg['type'] ?>"><?= getTypeLabel($msg['type']) ?></span></td>
                        <td class="td-title" title="<?= cleanInput($msg['title']) ?>"><?= cleanInput(mb_substr($msg['title'], 0, 20)) ?></td>
                        <td><?= cleanInput($msg['nickname']) ?></td>
                        <td><span class="status-badge status-<?= getStatusClass($msg['status']) ?>"><?= getStatusLabel($msg['status']) ?></span></td>
                        <td><?= $msg['views'] ?></td>
                        <td class="td-time"><?= date('m-d H:i', strtotime($msg['created_at'])) ?></td>
                        <td class="td-actions">
                            <button class="btn btn-xs btn-info" onclick="viewMessage(<?= $msg['id'] ?>)">查看</button>
                            <?php if ($msg['status'] != 1): ?>
                            <button class="btn btn-xs btn-success" onclick="auditMessage(<?= $msg['id'] ?>, 1)">通过</button>
                            <?php endif; ?>
                            <?php if ($msg['status'] != 2): ?>
                            <button class="btn btn-xs btn-warning" onclick="auditMessage(<?= $msg['id'] ?>, 2)">拒绝</button>
                            <?php endif; ?>
                            <button class="btn btn-xs btn-danger" onclick="deleteMessage(<?= $msg['id'] ?>)">删除</button>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>

        <!-- 分页 -->
        <?php if ($totalPages > 1): ?>
        <div class="pagination">
            <?php if ($page > 1): ?>
            <a href="index.php?page=<?= $page - 1 ?>&status=<?= $status ?>&type=<?= $type ?>&keyword=<?= urlencode($keyword) ?>" class="page-btn">上一页</a>
            <?php endif; ?>
            <?php for ($i = max(1, $page - 2); $i <= min($totalPages, $page + 2); $i++): ?>
            <a href="index.php?page=<?= $i ?>&status=<?= $status ?>&type=<?= $type ?>&keyword=<?= urlencode($keyword) ?>" class="page-btn <?= $i === $page ? 'active' : '' ?>"><?= $i ?></a>
            <?php endfor; ?>
            <?php if ($page < $totalPages): ?>
            <a href="index.php?page=<?= $page + 1 ?>&status=<?= $status ?>&type=<?= $type ?>&keyword=<?= urlencode($keyword) ?>" class="page-btn">下一页</a>
            <?php endif; ?>
            <span class="page-info">共 <?= $total ?> 条</span>
        </div>
        <?php endif; ?>
    </div>
</div>

<!-- 查看弹窗 -->
<div class="modal" id="viewModal" style="display:none;">
    <div class="modal-content">
        <div class="modal-header">
            <h3>留言详情</h3>
            <button class="modal-close" onclick="closeModal()">&times;</button>
        </div>
        <div class="modal-body" id="modalBody">加载中...</div>
    </div>
</div>

<!-- 批量审核弹窗 -->
<div class="modal" id="batchModal" style="display:none;">
    <div class="modal-content">
        <div class="modal-header">
            <h3 id="batchModalTitle">批量审核</h3>
            <button class="modal-close" onclick="closeBatchModal()">&times;</button>
        </div>
        <div class="modal-body" id="batchModalBody">加载中...</div>
    </div>
</div>

<script>
function auditMessage(id, status) {
    const action = status === 1 ? '通过' : '拒绝';
    if (!confirm('确定要' + action + '这条留言吗？')) return;
    fetch('api.php', {
        method: 'POST',
        headers: {'Content-Type': 'application/x-www-form-urlencoded'},
        body: 'action=audit&id=' + id + '&status=' + status
    })
    .then(r => r.json())
    .then(data => {
        if (data.code === 0) {
            alert('操作成功');
            location.reload();
        } else {
            alert(data.msg);
        }
    });
}

function deleteMessage(id) {
    if (!confirm('确定要删除这条留言吗？此操作不可恢复！')) return;
    fetch('api.php', {
        method: 'POST',
        headers: {'Content-Type': 'application/x-www-form-urlencoded'},
        body: 'action=delete&id=' + id
    })
    .then(r => r.json())
    .then(data => {
        if (data.code === 0) {
            alert('删除成功');
            location.reload();
        } else {
            alert(data.msg);
        }
    });
}

function viewMessage(id) {
    document.getElementById('viewModal').style.display = 'flex';
    document.getElementById('modalBody').innerHTML = '加载中...';
    fetch('api.php?action=detail&id=' + id)
    .then(r => r.json())
    .then(data => {
        if (data.code === 0) {
            const d = data.data;
            let html = '<div class="detail-view">';
            html += '<p><strong>类型：</strong>' + d.type_label + '</p>';
            html += '<p><strong>标题：</strong>' + d.title + '</p>';
            html += '<p><strong>昵称：</strong>' + d.nickname + '</p>';
            html += '<p><strong>电话：</strong>' + (d.phone || '未填写') + '</p>';
            html += '<p><strong>内容：</strong></p><div class="detail-text">' + d.content + '</div>';
            if (d.image) html += '<p><strong>图片：</strong><br><img src="../' + d.image + '" style="max-width:100%;margin-top:8px;"></p>';
            html += '<p><strong>状态：</strong>' + d.status_label + '</p>';
            if (d.audit_note) html += '<p><strong>处理意见：</strong></p><div class="detail-text">' + d.audit_note + '</div>';
            if (d.audited_at) html += '<p><strong>审核时间：</strong>' + d.audited_at + '</p>';
            html += '<p><strong>浏览量：</strong>' + d.views + '</p>';
            html += '<p><strong>时间：</strong>' + d.created_at + '</p>';
            html += '</div>';
            document.getElementById('modalBody').innerHTML = html;
        } else {
            document.getElementById('modalBody').innerHTML = data.msg;
        }
    });
}

function closeModal() {
    document.getElementById('viewModal').style.display = 'none';
}

document.getElementById('viewModal').addEventListener('click', function(e) {
    if (e.target === this) closeModal();
});

/* ========== 批量审核 ========== */
// 批量操作状态：选择集保存在DOM复选框中，失败/异常时不清除，便于重试
const batchState = {
    status: 1,      // 1=通过 2=拒绝
    ids: [],        // 本次提交的ID快照
    dirty: false    // 是否已产生实际变更（关闭弹窗后需刷新页面保证数据一致）
};

function getRowCheckboxes() {
    return Array.from(document.querySelectorAll('.row-check'));
}

function getSelectedIds() {
    return getRowCheckboxes().filter(cb => cb.checked).map(cb => parseInt(cb.value, 10));
}

function updateSelectedCount() {
    const boxes = getRowCheckboxes();
    const checkedCount = boxes.filter(cb => cb.checked).length;
    document.getElementById('selectedCount').textContent = checkedCount;
    const selectAll = document.getElementById('selectAll');
    if (selectAll) {
        selectAll.checked = boxes.length > 0 && checkedCount === boxes.length;
        selectAll.indeterminate = checkedCount > 0 && checkedCount < boxes.length;
    }
}

function toggleSelectAll(master) {
    getRowCheckboxes().forEach(cb => { cb.checked = master.checked; });
    updateSelectedCount();
}

function clearSelection() {
    getRowCheckboxes().forEach(cb => { cb.checked = false; });
    updateSelectedCount();
}

// 行复选框变化（事件委托，无需逐行绑定）
document.querySelector('.admin-table').addEventListener('change', function(e) {
    if (e.target.classList.contains('row-check')) updateSelectedCount();
});

function openBatchModal(status) {
    const ids = getSelectedIds();
    if (ids.length === 0) {
        alert('请先勾选需要批量审核的留言');
        return;
    }
    batchState.status = status;
    batchState.ids = ids;
    document.getElementById('batchModal').style.display = 'flex';
    loadBatchPreview();
}

function loadBatchPreview() {
    const body = document.getElementById('batchModalBody');
    body.innerHTML = '加载中...';

    const formData = new FormData();
    formData.append('action', 'batch_preview');
    batchState.ids.forEach(id => formData.append('ids[]', id));

    fetch('api.php', { method: 'POST', body: formData })
    .then(r => r.json())
    .then(data => {
        if (data.code === 0) {
            renderBatchPreview(data.data);
        } else if (data.code === 401) {
            renderBatchError('登录状态已失效，请重新登录后再试。', true);
        } else {
            renderBatchError(data.msg || '预览失败，请重试。');
        }
    })
    .catch(() => renderBatchError('网络异常，请检查网络连接后重试。'));
}

function renderBatchPreview(preview) {
    const actionText = batchState.status === 1 ? '通过' : '拒绝';
    document.getElementById('batchModalTitle').textContent = '批量' + actionText + ' - 提交前预览';
    const body = document.getElementById('batchModalBody');
    const skipCount = preview.total - preview.actionable_count;

    let html = '<div class="batch-summary">共选择 <strong>' + preview.total + '</strong> 条，将' + actionText
        + ' <strong class="batch-item-ok">' + preview.actionable_count + '</strong> 条';
    if (skipCount > 0) html += '，<span class="batch-item-skip">跳过 ' + skipCount + ' 条（状态已变化）</span>';
    html += '</div>';

    html += '<div class="batch-list">';
    preview.items.forEach(item => {
        html += '<div class="batch-item">';
        html += '<span class="batch-item-title">#' + item.id + ' ' + (item.title || '') + '</span>';
        if (item.actionable) {
            html += '<span class="batch-item-status batch-item-ok">将' + actionText + '</span>';
        } else {
            html += '<span class="batch-item-status batch-item-skip">'
                + (item.exists ? '当前' + item.status_label + '，跳过' : '留言不存在，跳过') + '</span>';
        }
        html += '</div>';
    });
    html += '</div>';

    html += '<div class="form-group">'
        + '<label for="batchNote">统一处理意见（可选，将应用到本次所有被处理条目）</label>'
        + '<textarea id="batchNote" rows="3" maxlength="500" placeholder="请输入处理意见..."></textarea>'
        + '</div>';

    html += '<div class="form-actions">'
        + '<button type="button" class="btn btn-secondary" onclick="closeBatchModal()">取消</button>';
    if (preview.actionable_count > 0) {
        html += '<button type="button" class="btn btn-primary" id="batchConfirmBtn" onclick="submitBatchAudit()">确认'
            + actionText + ' ' + preview.actionable_count + ' 条</button>';
    } else {
        html += '<button type="button" class="btn btn-primary" disabled>没有可处理的条目</button>';
    }
    html += '</div>';

    body.innerHTML = html;
}

function submitBatchAudit() {
    const note = document.getElementById('batchNote').value;
    const btn = document.getElementById('batchConfirmBtn');
    if (btn) { btn.disabled = true; btn.textContent = '提交中...'; }

    const formData = new FormData();
    formData.append('action', 'batch_audit');
    formData.append('status', batchState.status);
    formData.append('note', note);
    batchState.ids.forEach(id => formData.append('ids[]', id));

    fetch('api.php', { method: 'POST', body: formData })
    .then(r => r.json())
    .then(data => {
        if (data.code === 0) {
            batchState.dirty = true;
            renderBatchResult(data.data);
        } else if (data.code === 401) {
            renderBatchError('登录状态已失效，请重新登录后再试。', true);
        } else {
            renderBatchError(data.msg || '操作失败，请重试。');
        }
    })
    .catch(() => renderBatchError('网络异常，请检查网络连接后重试。'));
}

function renderBatchResult(result) {
    document.getElementById('batchModalTitle').textContent = '批量审核结果';
    const body = document.getElementById('batchModalBody');

    let html = '<div class="batch-summary">处理完成：成功 <strong class="batch-item-ok">'
        + result.success_count + '</strong> 条';
    if (result.fail_count > 0) html += '，失败 <strong class="batch-item-fail">' + result.fail_count + '</strong> 条';
    html += '</div>';

    html += '<div class="batch-list">';
    result.results.forEach(item => {
        html += '<div class="batch-item">';
        html += '<span class="batch-item-title">#' + item.id + '</span>';
        if (item.success) {
            html += '<span class="batch-item-status batch-item-ok">✓ ' + item.msg + '</span>';
        } else {
            html += '<span class="batch-item-status batch-item-fail">✗ ' + item.msg + '</span>';
        }
        html += '</div>';
    });
    html += '</div>';

    html += '<div class="form-actions">'
        + '<button type="button" class="btn btn-primary" onclick="closeBatchModal()">完成</button>'
        + '</div>';

    body.innerHTML = html;
}

// 异常提示：保留当前选择，允许重试（重试回到预览，重新确认最新状态）
function renderBatchError(msg, needLogin) {
    const body = document.getElementById('batchModalBody');
    let html = '<div class="batch-error-box"><p>' + msg + '<br>当前选择已保留，可直接重试。</p>'
        + '<div class="form-actions">';
    if (needLogin) html += '<a href="login.php" target="_blank" class="btn btn-secondary">去登录</a>';
    html += '<button type="button" class="btn btn-secondary" onclick="closeBatchModal()">关闭</button>'
        + '<button type="button" class="btn btn-primary" onclick="loadBatchPreview()">重试</button>'
        + '</div></div>';
    body.innerHTML = html;
}

function closeBatchModal() {
    document.getElementById('batchModal').style.display = 'none';
    if (batchState.dirty) {
        // 已有条目被处理：刷新页面，保证待审数量、列表与详情状态一致
        location.reload();
    }
}

document.getElementById('batchModal').addEventListener('click', function(e) {
    if (e.target === this) closeBatchModal();
});
</script>
