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
            <a href="index.php?status=0" class="sidebar-link">⏳ 待审核 <span class="sidebar-pending-count" data-empty=""><?= $pendingCount > 0 ? "($pendingCount)" : '' ?></span></a>
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

        <!-- 统计卡片（待审数量与侧边栏、批量审核结果同步更新） -->
        <div class="stats-grid" style="grid-template-columns: repeat(2, 1fr); margin-bottom: 20px;">
            <div class="stat-card">
                <div class="stat-number"><?= $db->query("SELECT COUNT(*) FROM messages")->fetchColumn() ?></div>
                <div class="stat-label">留言总数</div>
            </div>
            <div class="stat-card stat-help">
                <div class="stat-number sidebar-pending-count"><?= $pendingCount ?></div>
                <div class="stat-label">待审核</div>
            </div>
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

        <!-- 留言表格 -->
        <!-- 批量操作条：选中待审留言后可用 -->
        <div class="batch-bar" id="batchBar" style="display:none;">
            <span class="batch-info">已选择 <strong id="batchSelectedCount">0</strong> 条待审核留言</span>
            <div class="batch-actions">
                <button type="button" class="btn btn-sm btn-success" id="batchApproveBtn">批量通过</button>
                <button type="button" class="btn btn-sm btn-warning" id="batchRejectBtn">批量拒绝</button>
                <button type="button" class="btn btn-sm btn-secondary" id="batchClearBtn">清除选择</button>
            </div>
        </div>

        <div class="admin-table-wrapper">
            <table class="admin-table">
                <thead>
                    <tr>
                        <th class="col-check"><input type="checkbox" id="checkAll" title="全选本页待审核" disabled></th>
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
                    <tr class="message-row" data-id="<?= $msg['id'] ?>" data-status="<?= $msg['status'] ?>">
                        <td class="col-check">
                            <?php if ($msg['status'] == 0): ?>
                            <input type="checkbox" class="row-check" value="<?= $msg['id'] ?>">
                            <?php endif; ?>
                        </td>
                        <td><?= $msg['id'] ?></td>
                        <td><span class="badge badge-<?= $msg['type'] ?>"><?= getTypeLabel($msg['type']) ?></span></td>
                        <td class="td-title" title="<?= cleanInput($msg['title']) ?>"><?= cleanInput(mb_substr($msg['title'], 0, 20)) ?></td>
                        <td><?= cleanInput($msg['nickname']) ?></td>
                        <td class="cell-status"><span class="status-badge status-<?= getStatusClass($msg['status']) ?>"><?= getStatusLabel($msg['status']) ?></span></td>
                        <td><?= $msg['views'] ?></td>
                        <td class="td-time"><?= date('m-d H:i', strtotime($msg['created_at'])) ?></td>
                        <td class="td-actions cell-actions">
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
    <div class="modal-content modal-content-lg">
        <div class="modal-header">
            <h3 id="batchModalTitle">批量通过</h3>
            <button class="modal-close" id="batchModalClose">&times;</button>
        </div>
        <div class="modal-body">
            <!-- 阶段一：统一处理意见 + 提交前预览 -->
            <div id="batchStepPreview">
                <div class="form-group">
                    <label for="batchNote">统一处理意见（可选，将写入本次全部条目，最多500字）</label>
                    <textarea id="batchNote" rows="2" maxlength="500" placeholder="请输入统一处理意见..."></textarea>
                    <div class="form-hint"><span id="batchNoteCount">0</span>/500</div>
                </div>
                <div class="batch-preview-header">
                    <strong>将影响以下 <span id="batchPreviewCount">0</span> 条留言：</strong>
                    <span class="batch-preview-tip" id="batchPreviewTip"></span>
                </div>
                <div class="batch-preview-list" id="batchPreviewList">加载中...</div>
                <div class="form-actions batch-form-actions">
                    <button type="button" class="btn btn-secondary" id="batchCancelBtn">取消</button>
                    <button type="button" class="btn btn-primary" id="batchSubmitBtn">确认提交</button>
                </div>
            </div>

            <!-- 阶段二：逐条反馈结果 -->
            <div id="batchStepResult" style="display:none;">
                <div class="batch-summary" id="batchSummary"></div>
                <div class="batch-result-list" id="batchResultList"></div>
                <div class="batch-error" id="batchError" style="display:none;"></div>
                <div class="form-actions batch-form-actions">
                    <button type="button" class="btn btn-secondary" id="batchRetryBtn" style="display:none;">重试失败项</button>
                    <button type="button" class="btn btn-primary" id="batchDoneBtn">完成</button>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
/* ========== 原有单条操作（逻辑保持不变） ========== */
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

let currentViewId = null;

function viewMessage(id) {
    currentViewId = id;
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
            if (d.audit_note) html += '<p><strong>审核意见：</strong></p><div class="detail-text">' + d.audit_note + '</div>';
            if (d.image) html += '<p><strong>图片：</strong><br><img src="../' + d.image + '" style="max-width:100%;margin-top:8px;"></p>';
            html += '<p><strong>状态：</strong>' + d.status_label + '</p>';
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
const STATUS_LABELS = {0: '待审核', 1: '已通过', 2: '已拒绝'};
const STATUS_CLASSES = {0: 'pending', 1: 'approved', 2: 'rejected'};

// 当前选择的待审留言ID（跨页保留；无选中项/报错时不清空，保证可重试）
const selectedIds = new Set();
let batchTargetStatus = 1;        // 1=批量通过 2=批量拒绝
let batchSubmitting = false;     // 提交中防重复点击

const $ = id => document.getElementById(id);
const $$ = sel => Array.from(document.querySelectorAll(sel));

/* 后台页未加载 main.js，这里提供一个独立的轻提示 */
function batchToast(message, type) {
    type = type || 'info';
    document.querySelectorAll('.batch-toast').forEach(t => t.remove());
    const toast = document.createElement('div');
    toast.className = 'batch-toast batch-toast-' + type;
    toast.textContent = message;
    document.body.appendChild(toast);
    setTimeout(() => { toast.classList.add('show'); }, 10);
    setTimeout(() => {
        toast.classList.remove('show');
        setTimeout(() => toast.remove(), 300);
    }, 2500);
}

/* 同步勾选框/全选/操作条的显示状态 */
function syncSelectionUI() {
    $$('.row-check').forEach(cb => { cb.checked = selectedIds.has(parseInt(cb.value, 10)); });

    const pageChecks = $$('.row-check');
    const checkAll = $('checkAll');
    if (pageChecks.length === 0) {
        checkAll.disabled = true;
        checkAll.checked = false;
        checkAll.indeterminate = false;
    } else {
        checkAll.disabled = false;
        const checkedOnPage = pageChecks.filter(cb => cb.checked).length;
        checkAll.checked = checkedOnPage === pageChecks.length;
        checkAll.indeterminate = checkedOnPage > 0 && checkedOnPage < pageChecks.length;
    }

    const n = selectedIds.size;
    $('batchBar').style.display = n > 0 ? 'flex' : 'none';
    $('batchSelectedCount').textContent = n;
}

function updatePendingCount(n) {
    // 侧边栏显示 (n)，统计卡片显示原始数字，两处保持一致
    document.querySelectorAll('.sidebar-pending-count').forEach((el, idx) => {
        const inStatCard = el.closest('.stat-card') !== null;
        el.textContent = inStatCard ? n : (n > 0 ? '(' + n + ')' : '');
    });
}

/* 列表行状态就地更新（保证待审数量、列表显示一致） */
function syncRowStatus(item) {
    const row = document.querySelector('.message-row[data-id="' + item.id + '"]');
    if (!row) return;
    row.dataset.status = item.status;

    const statusCell = row.querySelector('.cell-status');
    if (statusCell) {
        statusCell.innerHTML = '<span class="status-badge status-' + STATUS_CLASSES[item.status] + '">'
            + STATUS_LABELS[item.status] + '</span>';
    }

    // 非待审核后不再允许勾选
    const checkCell = row.querySelector('.col-check');
    if (checkCell && item.status !== 0) checkCell.innerHTML = '';

    // 操作按钮与原页面渲染规则保持一致：已通过只显示“拒绝”，已拒绝只显示“通过”
    const actionsCell = row.querySelector('.cell-actions');
    if (actionsCell) {
        actionsCell.innerHTML =
            '<button class="btn btn-xs btn-info" onclick="viewMessage(' + item.id + ')">查看</button>' +
            (item.status !== 1 ? '<button class="btn btn-xs btn-success" onclick="auditMessage(' + item.id + ', 1)">通过</button>' : '') +
            (item.status !== 2 ? '<button class="btn btn-xs btn-warning" onclick="auditMessage(' + item.id + ', 2)">拒绝</button>' : '') +
            '<button class="btn btn-xs btn-danger" onclick="deleteMessage(' + item.id + ')">删除</button>';
    }
}

/* 勾选事件 */
document.addEventListener('change', function(e) {
    if (e.target.classList && e.target.classList.contains('row-check')) {
        const id = parseInt(e.target.value, 10);
        if (e.target.checked) selectedIds.add(id);
        else selectedIds.delete(id);
        syncSelectionUI();
    }
});

$('checkAll').addEventListener('change', function() {
    $$('.row-check').forEach(cb => {
        cb.checked = this.checked;
        const id = parseInt(cb.value, 10);
        if (this.checked) selectedIds.add(id);
        else selectedIds.delete(id);
    });
    syncSelectionUI();
});

$('batchClearBtn').addEventListener('click', function() {
    selectedIds.clear();
    syncSelectionUI();
});

/* 打开批量审核弹窗（先校验选择，再拉取提交前预览） */
function openBatch(status) {
    if (selectedIds.size === 0) {
        batchToast('请先勾选待审核留言', 'warning');
        return;
    }
    batchTargetStatus = status;
    $('batchModalTitle').textContent = status === 1 ? '批量通过' : '批量拒绝';
    $('batchSubmitBtn').textContent = status === 1 ? '确认批量通过' : '确认批量拒绝';
    $('batchModal').style.display = 'flex';
    $('batchStepPreview').style.display = '';
    $('batchStepResult').style.display = 'none';
    $('batchError').style.display = 'none';
    loadBatchPreview();
}

$('batchApproveBtn').addEventListener('click', () => openBatch(1));
$('batchRejectBtn').addEventListener('click', () => openBatch(2));

function closeBatchModal() {
    if (batchSubmitting) return; // 提交中不允许关闭，避免状态错乱
    $('batchModal').style.display = 'none';
    syncSelectionUI();
}
$('batchCancelBtn').addEventListener('click', closeBatchModal);
$('batchModalClose').addEventListener('click', closeBatchModal);
$('batchModal').addEventListener('click', function(e) {
    if (e.target === this) closeBatchModal();
});

$('batchNote').addEventListener('input', function() {
    $('batchNoteCount').textContent = this.value.length;
});

/* 提交前预览：展示会被影响的条目；他人已改动状态的条目逐条标注 */
function loadBatchPreview() {
    const ids = Array.from(selectedIds);
    $('batchPreviewCount').textContent = ids.length;
    $('batchPreviewList').innerHTML = '<div class="batch-list-loading">加载预览中...</div>';
    $('batchPreviewTip').textContent = '';
    $('batchSubmitBtn').disabled = true;

    const formData = new FormData();
    formData.append('action', 'batch_preview');
    ids.forEach(id => formData.append('ids[]', id));

    fetch('api.php', {
        method: 'POST',
        headers: {'X-Requested-With': 'XMLHttpRequest'},
        body: formData
    })
    .then(r => {
        if (r.status === 401) return r.json().then(j => Promise.reject({auth: true, msg: j.msg}));
        return r.json();
    })
    .then(data => {
        if (data.code !== 0) return Promise.reject({msg: data.msg});
        renderBatchPreview(data.data);
    })
    .catch(err => {
        renderPreviewError(err || {});
    });
}

function renderPreviewError(err) {
    const tip = $('batchPreviewTip');
    const isAuth = err && err.auth;
    const msg = isAuth
        ? (err.msg || '登录已过期，请重新登录后重试')
        : (err && err.msg ? err.msg : '网络中断，预览加载失败');
    tip.innerHTML = '<span class="batch-err-text">' + msg + '</span>'
        + ' <button type="button" class="btn btn-xs btn-secondary" id="batchPreviewRetry">重试</button>'
        + (isAuth ? ' <a href="login.php" target="_blank" class="btn btn-xs btn-info">重新登录</a>' : '');
    $('batchPreviewList').innerHTML = '<div class="batch-list-loading">预览不可用（当前选择已保留）</div>';
    $('batchSubmitBtn').disabled = true;
    const retry = $('batchPreviewRetry');
    if (retry) retry.addEventListener('click', loadBatchPreview);
}

function renderBatchPreview(data) {
    const list = $('batchPreviewList');
    list.innerHTML = '';
    let changed = 0;

    data.items.forEach(item => {
        const row = document.createElement('div');
        row.className = 'batch-item' + (item.pending ? '' : ' batch-item-skip');
        let flag = '';
        if (!item.exists) {
            flag = '<span class="batch-tag tag-missing">已删除，将跳过</span>';
            changed++;
        } else if (!item.pending) {
            flag = '<span class="batch-tag tag-changed">状态已变为“' + item.status_label + '”，将跳过</span>';
            changed++;
        }
        row.innerHTML =
            '<span class="batch-item-id">#' + item.id + '</span>'
            + '<span class="badge badge-' + (item.type_class || '') + '">' + item.type_label + '</span>'
            + '<span class="batch-item-title" title="' + item.title + '">' + item.title + '</span>'
            + '<span class="batch-item-meta">' + item.nickname + '</span>'
            + '<span class="status-badge status-' + item.status_class + '">' + item.status_label + '</span>'
            + flag;
        list.appendChild(row);
    });

    $('batchPreviewTip').innerHTML = changed > 0
        ? '<span class="batch-err-text">有 ' + changed + ' 条状态已变化或不存在，提交时将自动跳过，其余条目正常处理。</span>'
        : '<span class="batch-ok-text">全部条目均为待审核状态。</span>';
    // 仍有可处理条目才允许提交；全被跳过时也允许提交（服务端逐条返回跳过结果，反馈完整）
    $('batchSubmitBtn').disabled = false;
}

/* 提交整组批量审核 */
$('batchSubmitBtn').addEventListener('click', submitBatch);

function submitBatch() {
    if (batchSubmitting || selectedIds.size === 0) return;
    batchSubmitting = true;
    $('batchSubmitBtn').disabled = true;
    $('batchSubmitBtn').textContent = '提交中...';

    const formData = new FormData();
    formData.append('action', 'batch_audit');
    formData.append('status', batchTargetStatus);
    formData.append('note', $('batchNote').value);
    Array.from(selectedIds).forEach(id => formData.append('ids[]', id));

    fetch('api.php', {
        method: 'POST',
        headers: {'X-Requested-With': 'XMLHttpRequest'},
        body: formData
    })
    .then(r => {
        if (r.status === 401) return r.json().then(j => Promise.reject({auth: true, msg: j.msg}));
        return r.json();
    })
    .then(data => {
        if (data.code !== 0) return Promise.reject({msg: data.msg});
        renderBatchResult(data.data);
    })
    .catch(err => {
        // 权限失效/网络中断：保留当前选择与处理意见，留在当前界面允许重试
        const isAuth = err && err.auth;
        const msg = isAuth
            ? (err.msg || '登录已过期，请重新登录后重试')
            : (err && err.msg ? err.msg : '网络中断，提交未完成');
        $('batchPreviewTip').innerHTML = '<span class="batch-err-text">' + msg + '，当前选择与处理意见已保留</span>'
            + (isAuth ? ' <a href="login.php" target="_blank" class="btn btn-xs btn-info">重新登录</a>'
                      : ' <span class="batch-retry-hint">可再次点击“' + (batchTargetStatus === 1 ? '确认批量通过' : '确认批量拒绝') + '”重试</span>');
        batchToast(msg + '，选择已保留，可重试', 'error');
    })
    .finally(() => {
        batchSubmitting = false;
        $('batchSubmitBtn').disabled = false;
        $('batchSubmitBtn').textContent = batchTargetStatus === 1 ? '确认批量通过' : '确认批量拒绝';
    });
}

function renderBatchResult(data) {
    $('batchStepPreview').style.display = 'none';
    $('batchStepResult').style.display = '';

    const actionText = batchTargetStatus === 1 ? '通过' : '拒绝';
    const summary = $('batchSummary');
    const parts = [
        '<span class="sum-success">成功 ' + data.success_count + ' 条</span>',
        '<span class="sum-changed">状态已变化跳过 ' + data.changed_count + ' 条</span>',
        '<span class="sum-missing">不存在 ' + data.not_found_count + ' 条</span>'
    ];
    if (data.fail_count > 0) parts.push('<span class="sum-fail">失败 ' + data.fail_count + ' 条（可重试）</span>');
    summary.innerHTML = '批量' + actionText + '完成：' + parts.join('，');

    const resultList = $('batchResultList');
    resultList.innerHTML = '';

    const tagMap = {
        success: '<span class="batch-tag tag-success">成功</span>',
        changed: '<span class="batch-tag tag-changed">状态已变化，未处理</span>',
        not_found: '<span class="batch-tag tag-missing">不存在，未处理</span>',
        error: '<span class="batch-tag tag-error">失败，可重试</span>'
    };

    const successIds = [];
    data.results.forEach(item => {
        const row = document.createElement('div');
        row.className = 'batch-item batch-result-' + item.result;
        row.innerHTML =
            '<span class="batch-item-id">#' + item.id + '</span>'
            + '<span class="batch-item-title" title="' + (item.title || '') + '">' + (item.title || ('留言 #' + item.id)) + '</span>'
            + (item.status_label ? '<span class="status-badge status-' + (item.status_class || '') + '">' + item.status_label + '</span>' : '')
            + (tagMap[item.result] || '');
        resultList.appendChild(row);

        if (item.result === 'success') {
            successIds.push(item.id);
            selectedIds.delete(item.id);
            syncRowStatus(item);
        } else if (item.result === 'changed') {
            // 服务端回查的当前状态同步到列表，避免页面显示与实际不一致
            selectedIds.delete(item.id);
            syncRowStatus(item);
        } else if (item.result === 'not_found') {
            selectedIds.delete(item.id);
        }
        // item.result === 'error'：保留在 selectedIds 中，勾选状态不变，供重试
    });

    updatePendingCount(data.pending_count);
    syncSelectionUI();

    // 若详情弹窗正打开且对应留言本次审核成功，刷新详情使状态一致
    if (currentViewId !== null && successIds.indexOf(currentViewId) !== -1 && $('viewModal').style.display === 'flex') {
        viewMessage(currentViewId);
    }

    // 仍有失败（网络/服务端瞬时错误）项时展示重试按钮；状态变化/不存在不属于可重试失败
    $('batchRetryBtn').style.display = data.fail_count > 0 ? '' : 'none';
}

$('batchRetryBtn').addEventListener('click', function() {
    if (selectedIds.size === 0) {
        batchToast('没有可重试的条目', 'warning');
        return;
    }
    $('batchStepResult').style.display = 'none';
    $('batchStepPreview').style.display = '';
    submitBatch();
});

$('batchDoneBtn').addEventListener('click', function() {
    $('batchModal').style.display = 'none';
    batchSubmitting = false;
    syncSelectionUI();
});

syncSelectionUI();
</script>
