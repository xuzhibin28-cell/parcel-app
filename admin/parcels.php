<?php
$page_title = '包裹管理';
$allowed_roles = ['admin'];
require_once '../includes/auth.php';
require_once '../includes/header.php';
$pdo = Database::getInstance();

$search = $_GET['search'] ?? '';
$status_filter = $_GET['status'] ?? '';

$sql = "SELECT p.*, u.name as rider_name 
        FROM parcels p 
        LEFT JOIN users u ON p.assigned_rider = u.id 
        WHERE 1=1";
$params = [];
if ($search) {
    $sql .= " AND (p.tracking_number LIKE ? OR p.recipient_name LIKE ?)";
    $params[] = "%$search%";
    $params[] = "%$search%";
}
if ($status_filter) {
    $sql .= " AND p.status = ?";
    $params[] = $status_filter;
}
$sql .= " ORDER BY p.created_at DESC";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$parcels = $stmt->fetchAll();
?>
<h1>包裹列表</h1>
<form class="search-bar">
    <input type="text" name="search" placeholder="搜索追踪号或收件人" value="<?= sanitize($search) ?>">
    <select name="status">
        <option value="">所有状态</option>
        <option value="pending" <?= $status_filter=='pending'?'selected':'' ?>>待处理</option>
        <option value="out_for_delivery" <?= $status_filter=='out_for_delivery'?'selected':'' ?>>配送中</option>
        <option value="delivered" <?= $status_filter=='delivered'?'selected':'' ?>>已送达</option>
        <option value="failed" <?= $status_filter=='failed'?'selected':'' ?>>配送失败</option>
        <option value="cancelled" <?= $status_filter=='cancelled'?'selected':'' ?>>已取消</option>
    </select>
    <button type="submit" class="btn btn-primary">搜索</button>
    <a href="parcel_form.php" class="btn btn-success">+ 新增包裹</a>
</form>
<table class="data-table">
    <thead>
        <tr>
            <th>追踪号</th>
            <th>收件人</th>
            <th>地址</th>
            <th>状态</th>
            <th>骑手</th>
            <th>操作</th>
        </tr>
    </thead>
    <tbody>
    <?php foreach($parcels as $p): ?>
        <tr>
            <td><?= $p['tracking_number'] ?></td>
            <td><?= sanitize($p['recipient_name']) ?></td>
            <td><?= sanitize($p['recipient_address']) ?></td>
            <td><span class="status-badge status-<?= $p['status'] ?>"><?= $p['status'] ?></span></td>
            <td><?= $p['rider_name'] ?? '未分配' ?></td>
            <td class="actions">
                <a href="parcel_form.php?id=<?= $p['id'] ?>" class="btn btn-sm">编辑</a>
                <?php if(!$p['assigned_rider']): ?>
                    <button onclick="openAssignModal(<?= $p['id'] ?>)" class="btn btn-sm btn-primary">分配骑手</button>
                    <button onclick="autoAssign(<?= $p['id'] ?>)" class="btn btn-sm btn-success">智能分配</button>
                <?php endif; ?>
                <a href="view_parcel_map.php?parcel_id=<?= $p['id'] ?>" class="btn btn-sm" target="_blank">🗺️ 地图</a>
                <a href="#" onclick="viewParcel(<?= $p['id'] ?>)" class="btn btn-sm">详情</a>
                <?php if ($p['status'] === 'delivered'): ?>
                    <button onclick="deleteParcel(<?= $p['id'] ?>)" class="btn btn-sm btn-danger">删除</button>
                <?php endif; ?>
            </td>
        </tr>
    <?php endforeach; ?>
    </tbody>
</table>

<!-- 分配骑手模态框（含地图搜索骑手） -->
<div id="assignModal" class="modal" style="display:none;">
    <div class="modal-content" style="max-width: 700px; width: 90%;">
        <h3>选择骑手</h3>
        <input type="hidden" id="assign_parcel_id">
        <!-- 搜索框 -->
        <div style="margin-bottom: 10px;">
            <input type="text" id="riderSearchInput" placeholder="输入骑手姓名或ID搜索..." style="width: 70%; padding: 8px; border:1px solid #ccc; border-radius:4px;">
            <button onclick="searchRiderInModal()" class="btn btn-primary">🔍 搜索</button>
            <button onclick="clearSearchInModal()" class="btn">显示全部</button>
        </div>
        <!-- 地图容器 -->
        <div id="assignMap" style="height: 250px; margin-bottom: 10px;"></div>
        <!-- 骑手列表 -->
        <div id="rider_list" style="max-height: 200px; overflow-y: auto;"></div>
        <button onclick="closeAssignModal()" class="btn">关闭</button>
    </div>
</div>

<!-- Leaflet 资源 -->
<link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css">
<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>

<script>
// ========== 全局变量 ==========
let assignMap = null;
let assignMarkers = {};
let allRidersData = [];
let selectedParcelId = null;

// 骑手图标
function createRiderIcon(status) {
    const color = status === 'online' ? '#2ecc71' : '#95a5a6';
    return L.divIcon({
        className: '',
        html: `<div style="background-color:${color}; color:white; border-radius:50%; width:28px; height:28px; display:flex; align-items:center; justify-content:center; font-weight:bold; border:2px solid white; box-shadow:0 2px 5px rgba(0,0,0,0.3);">R</div>`,
        iconSize: [28,28],
        iconAnchor: [14,14]
    });
}

// 打开分配模态框
async function openAssignModal(parcelId) {
    selectedParcelId = parcelId;
    document.getElementById('assign_parcel_id').value = parcelId;
    document.getElementById('assignModal').style.display = 'flex';

    // 初始化地图（如果尚未初始化）
    if (!assignMap) {
        assignMap = L.map('assignMap').setView([0, 0], 2);
        L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
            attribution: '&copy; OpenStreetMap contributors'
        }).addTo(assignMap);
    }

    // 清空旧标记
    for (let id in assignMarkers) {
        assignMap.removeLayer(assignMarkers[id]);
    }
    assignMarkers = {};

    // 加载骑手数据
    try {
        const resp = await fetch('../api/get_online_riders.php');
        allRidersData = await resp.json();
        renderRiders(allRidersData);
    } catch (err) {
        document.getElementById('rider_list').innerHTML = '<p>加载骑手失败，请重试。</p>';
        console.error(err);
    }
}

// 渲染骑手列表和地图标记
function renderRiders(riders) {
    const listDiv = document.getElementById('rider_list');
    if (riders.length === 0) {
        listDiv.innerHTML = '<p>没有骑手。</p>';
        return;
    }

    let html = '<table class="data-table" style="width:100%;">';
    html += '<thead><tr><th>ID</th><th>姓名</th><th>状态</th><th>位置坐标</th><th>操作</th></tr></thead><tbody>';
    riders.forEach(r => {
        const statusText = r.status === 'online' ? '在线' : '离线';
        const coords = r.latitude ? `${Number(r.latitude).toFixed(5)}, ${Number(r.longitude).toFixed(5)}` : '无位置';
        html += `<tr>
            <td>${r.id}</td>
            <td>${r.name}</td>
            <td>${statusText}</td>
            <td>${coords}</td>
            <td>
                <button onclick="confirmAssign(${r.id})" class="btn btn-sm btn-primary">分配</button>
                <button onclick="focusRiderOnMap(${r.id})" class="btn btn-sm">📍定位</button>
            </td>
        </tr>`;

        // 添加地图标记
        if (r.latitude && r.longitude) {
            const marker = L.marker([r.latitude, r.longitude], {icon: createRiderIcon(r.status)})
                .addTo(assignMap)
                .bindPopup(`<b>${r.name}</b> (${statusText})`);
            marker.on('click', function() {
                // 点击标记也可选中骑手
                // 可在此处高亮或设置选择
            });
            assignMarkers[r.id] = marker;
        }
    });
    html += '</tbody></table>';
    listDiv.innerHTML = html;

    // 自动调整地图视野
    const visibleMarkers = Object.values(assignMarkers);
    if (visibleMarkers.length > 0) {
        const group = L.featureGroup(visibleMarkers);
        assignMap.fitBounds(group.getBounds().pad(0.5));
    }
}

// 搜索骑手（在模态框内）
function searchRiderInModal() {
    const query = document.getElementById('riderSearchInput').value.trim().toLowerCase();
    if (!query) {
        renderRiders(allRidersData);
        return;
    }
    const filtered = allRidersData.filter(r =>
        r.name.toLowerCase().includes(query) || String(r.id) === query
    );
    // 先清空标记再渲染过滤结果
    for (let id in assignMarkers) {
        assignMap.removeLayer(assignMarkers[id]);
    }
    assignMarkers = {};
    renderRiders(filtered);
}

// 清除搜索
function clearSearchInModal() {
    document.getElementById('riderSearchInput').value = '';
    for (let id in assignMarkers) {
        assignMap.removeLayer(assignMarkers[id]);
    }
    assignMarkers = {};
    renderRiders(allRidersData);
}

// 在地图上定位骑手
function focusRiderOnMap(riderId) {
    const rider = allRidersData.find(r => r.id == riderId);
    if (rider && rider.latitude && rider.longitude) {
        assignMap.setView([rider.latitude, rider.longitude], 15);
        if (assignMarkers[riderId]) {
            assignMarkers[riderId].openPopup();
        }
    } else {
        alert('该骑手暂无位置记录');
    }
}

// 确认分配
async function confirmAssign(riderId) {
    if (!riderId) return alert('请选择骑手');
    const parcelId = selectedParcelId;
    const resp = await fetch('../api/assign_parcel.php', {
        method: 'POST',
        headers: {'Content-Type': 'application/x-www-form-urlencoded'},
        body: `parcel_id=${parcelId}&rider_id=${riderId}&csrf_token=<?= $_SESSION['csrf_token'] ?>`
    });
    const data = await resp.json();
    if (data.success) {
        alert('分配成功');
        location.reload();
    } else {
        alert('分配失败：' + (data.error || ''));
    }
}

// 关闭模态框
function closeAssignModal() {
    document.getElementById('assignModal').style.display = 'none';
}

// ========== 其他原有函数 ==========
async function autoAssign(parcelId) {
    if (!confirm('确定要智能分配这个包裹吗？系统将选择最合适的骑手。')) return;
    const resp = await fetch('../api/auto_assign.php', {
        method: 'POST',
        headers: {'Content-Type': 'application/x-www-form-urlencoded'},
        body: `parcel_id=${parcelId}&csrf_token=<?= $_SESSION['csrf_token'] ?>`
    });
    const data = await resp.json();
    if (data.success) {
        alert('分配成功，骑手：' + data.rider_name);
        location.reload();
    } else {
        alert('分配失败：' + (data.error || ''));
    }
}

function viewParcel(id) {
    window.open('parcel_form.php?id='+id, '_blank');
}

async function deleteParcel(parcelId) {
    if (!confirm('确定要永久删除这个已送达的包裹吗？')) return;
    const resp = await fetch('../api/delete_parcel.php', {
        method: 'POST',
        headers: {'Content-Type': 'application/x-www-form-urlencoded'},
        body: `parcel_id=${parcelId}&csrf_token=<?= $_SESSION['csrf_token'] ?>`
    });
    const data = await resp.json();
    if (data.success) location.reload();
    else alert('删除失败：' + (data.error || ''));
}
</script>

<?php require_once '../includes/footer.php'; ?>