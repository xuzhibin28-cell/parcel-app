<?php
$page_title = '添加包裹';
$allowed_roles = ['admin'];
require_once '../includes/auth.php';
require_once '../includes/header.php';

$pdo = Database::getInstance();
$parcel = null;
$edit_id = $_GET['id'] ?? 0;
$error = '';

if ($edit_id) {
    $stmt = $pdo->prepare("SELECT * FROM parcels WHERE id = ?");
    $stmt->execute([$edit_id]);
    $parcel = $stmt->fetch();
    if (!$parcel) {
        die("包裹不存在");
    }
    $page_title = '编辑包裹';
}

$parcel = $parcel ?? [];

// 获取当前分配的骑手信息（编辑时显示）
$assigned_rider_info = null;
if (!empty($parcel['assigned_rider'])) {
    $stmt = $pdo->prepare("SELECT u.id, u.name FROM users u WHERE u.id = ?");
    $stmt->execute([$parcel['assigned_rider']]);
    $assigned_rider_info = $stmt->fetch();
}

// 处理表单提交
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if ($_POST['csrf_token'] !== $_SESSION['csrf_token']) {
        die('CSRF token 错误');
    }

    $tracking   = sanitize($_POST['tracking_number'] ?? '');
    $sender     = sanitize($_POST['sender_name'] ?? '');
    $sender_addr = sanitize($_POST['sender_address'] ?? '');
    $recipient  = sanitize($_POST['recipient_name'] ?? '');
    $recipient_addr = sanitize($_POST['recipient_address'] ?? '');
    $phone      = sanitize($_POST['recipient_phone'] ?? '');
    $description = sanitize($_POST['description'] ?? '');
    $lat = floatval($_POST['latitude'] ?? 0);
    $lng = floatval($_POST['longitude'] ?? 0);
    $selected_rider_id = intval($_POST['selected_rider_id'] ?? 0);

    if (empty($recipient) || empty($recipient_addr)) {
        $error = '收件人和地址不能为空';
    } else {
        // 追踪号唯一性处理
        if (empty($tracking)) {
            do {
                $tracking = generateTrackingNumber();
                $stmt = $pdo->prepare("SELECT id FROM parcels WHERE tracking_number = ?");
                $stmt->execute([$tracking]);
            } while ($stmt->fetch());
        } else {
            $stmt = $pdo->prepare("SELECT id FROM parcels WHERE tracking_number = ? AND id != ?");
            $stmt->execute([$tracking, $edit_id]);
            if ($stmt->fetch()) {
                $error = '追踪号已存在，请更换追踪号或留空自动生成。';
            }
        }

        if (empty($error)) {
            if ($edit_id) {
                $stmt = $pdo->prepare("UPDATE parcels SET tracking_number=?, sender_name=?, sender_address=?, recipient_name=?, recipient_address=?, recipient_phone=?, description=?, latitude=?, longitude=?, source='admin', assigned_rider=? WHERE id=?");
                $stmt->execute([$tracking, $sender, $sender_addr, $recipient, $recipient_addr, $phone, $description, $lat, $lng, $selected_rider_id ? $selected_rider_id : null, $edit_id]);
            } else {
                $stmt = $pdo->prepare("INSERT INTO parcels (tracking_number, sender_name, sender_address, recipient_name, recipient_address, recipient_phone, description, latitude, longitude, source, status) VALUES (?,?,?,?,?,?,?,?,?, 'admin', 'pending')");
                $stmt->execute([$tracking, $sender, $sender_addr, $recipient, $recipient_addr, $phone, $description, $lat, $lng]);
            }
            redirect('parcels.php?msg=saved');
        }
    }
}
?>

<h1><?= $edit_id ? '编辑包裹' : '新增包裹' ?></h1>
<?php if ($error): ?><div class="alert alert-danger"><?= $error ?></div><?php endif; ?>

<form method="POST">
    <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
    <input type="hidden" name="selected_rider_id" id="selectedRiderId" value="<?= $parcel['assigned_rider'] ?? '' ?>">

    <div class="form-group">
        <label>追踪单号</label>
        <input type="text" name="tracking_number" value="<?= $parcel['tracking_number'] ?? '' ?>" placeholder="留空自动生成">
    </div>
    <div class="form-group">
        <label>发件人</label>
        <input type="text" name="sender_name" value="<?= $parcel['sender_name'] ?? '' ?>">
    </div>
    <div class="form-group">
        <label>发件人地址</label>
        <input type="text" name="sender_address" value="<?= $parcel['sender_address'] ?? '' ?>">
    </div>
    <div class="form-group">
        <label>收件人 *</label>
        <input type="text" name="recipient_name" value="<?= $parcel['recipient_name'] ?? '' ?>" required>
    </div>
    <div class="form-group" style="position: relative;">
        <label>收件人地址 *</label>
        <input type="text" id="recipient_address" name="recipient_address" value="<?= $parcel['recipient_address'] ?? '' ?>" required autocomplete="off" placeholder="输入地址搜索或点击地图">
        <div id="addressSuggestions" class="address-suggestions" style="display:none;"></div>
    </div>
    <div class="form-group">
        <label>收件人电话</label>
        <input type="text" name="recipient_phone" value="<?= $parcel['recipient_phone'] ?? '' ?>">
    </div>
    <div class="form-group">
        <label>包裹描述</label>
        <textarea name="description" rows="2"><?= $parcel['description'] ?? '' ?></textarea>
    </div>

    <!-- 地图：目的地选择 + 骑手位置 + 路线 -->
    <div class="form-group">
        <label>地图（点击选择目的地，骑手位置自动显示）</label>
        <input type="hidden" name="latitude" id="latInput" value="<?= $parcel['latitude'] ?? '' ?>">
        <input type="hidden" name="longitude" id="lngInput" value="<?= $parcel['longitude'] ?? '' ?>">
        <div id="map" style="height: 350px;"></div>
        <p>已选目的地坐标：<span id="destCoordsDisplay"><?= ($parcel['latitude'] ?? '') && ($parcel['longitude'] ?? '') ? $parcel['latitude'].', '.$parcel['longitude'] : '未设置' ?></span></p>
    </div>

    <!-- 骑手分配 -->
    <div class="form-group">
        <label>分配骑手（可选）</label>
        <div style="margin-bottom: 5px;">
            <input type="text" id="riderSearchInput" placeholder="输入骑手姓名或ID搜索..." style="width: 60%; padding: 8px;">
            <button type="button" onclick="searchRider()" class="btn btn-primary">🔍 搜索</button>
            <button type="button" onclick="clearRiderSearch()" class="btn">显示全部</button>
        </div>
        <div id="riderList" style="max-height: 150px; overflow-y:auto; border:1px solid #ddd; padding:5px;">
            <?php if ($assigned_rider_info): ?>
                <p>当前分配：<strong><?= $assigned_rider_info['name'] ?></strong></p>
            <?php else: ?>
                <p>尚未选择骑手</p>
            <?php endif; ?>
        </div>
    </div>

    <button type="submit" class="btn btn-primary">保存包裹</button>
</form>

<link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css">
<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
<style>
    .address-suggestions { border:1px solid #ccc; max-height:150px; overflow-y:auto; background:white; position:absolute; z-index:1000; width:calc(100% - 2px); }
    .address-suggestions div { padding:8px; cursor:pointer; }
    .address-suggestions div:hover { background:#f0f0f0; }
</style>
<script>
// ========== 地图初始化 ==========
let map, destMarker, routeLayer;
let riderMarkers = {};
let allRiders = [];
let selectedRiderId = null;

const latInput = document.getElementById('latInput');
const lngInput = document.getElementById('lngInput');
const destCoordsDisplay = document.getElementById('destCoordsDisplay');
const addressInput = document.getElementById('recipient_address');
const suggestionsDiv = document.getElementById('addressSuggestions');

let initLat = latInput.value ? parseFloat(latInput.value) : 39.9042;
let initLng = lngInput.value ? parseFloat(lngInput.value) : 116.4074;
map = L.map('map').setView([initLat, initLng], 13);
L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
    attribution: '&copy; OpenStreetMap contributors'
}).addTo(map);

if (latInput.value && lngInput.value) {
    destMarker = L.marker([initLat, initLng]).addTo(map).bindPopup('包裹目的地');
}

// 目的地设置
function setDestLocation(lat, lng, reverse = true) {
    latInput.value = lat;
    lngInput.value = lng;
    destCoordsDisplay.textContent = lat.toFixed(6)+', '+lng.toFixed(6);
    if (destMarker) destMarker.setLatLng([lat, lng]); else destMarker = L.marker([lat, lng]).addTo(map);
    if (reverse) reverseGeocode(lat, lng);
    // 如果已选择骑手，自动更新路线
    if (selectedRiderId) drawRouteToSelectedRider();
}

function reverseGeocode(lat, lng) {
    fetch(`https://nominatim.openstreetmap.org/reverse?format=json&lat=${lat}&lon=${lng}&addressdetails=1&accept-language=zh`)
        .then(r=>r.json()).then(d=>{ if(d.display_name){ addressInput.value = d.display_name; suggestionsDiv.style.display='none'; }}).catch(()=>{});
}

map.on('click', e => setDestLocation(e.latlng.lat, e.latlng.lng, true));

// 地址搜索建议
let debounceTimer;
addressInput.addEventListener('input', function(){
    clearTimeout(debounceTimer);
    const q = this.value.trim();
    if(q.length<3){ suggestionsDiv.style.display='none'; return; }
    debounceTimer = setTimeout(()=>{
        fetch(`https://nominatim.openstreetmap.org/search?format=json&q=${encodeURIComponent(q)}&addressdetails=1&limit=5&accept-language=zh`)
            .then(r=>r.json()).then(data=>{
                suggestionsDiv.innerHTML='';
                if(!data.length){ suggestionsDiv.style.display='none'; return; }
                data.forEach(place=>{
                    const div=document.createElement('div');
                    div.textContent=place.display_name;
                    div.onclick=()=>{
                        addressInput.value=place.display_name;
                        suggestionsDiv.style.display='none';
                        if(place.lat && place.lon){
                            setDestLocation(parseFloat(place.lat), parseFloat(place.lon), false);
                            map.setView([place.lat, place.lon],15);
                        }
                    };
                    suggestionsDiv.appendChild(div);
                });
                suggestionsDiv.style.display='block';
            }).catch(()=>{ suggestionsDiv.style.display='none'; });
    },300);
});
document.addEventListener('click', e=>{ if(!addressInput.contains(e.target)&&!suggestionsDiv.contains(e.target)) suggestionsDiv.style.display='none'; });

// ========== 骑手相关 ==========
function createRiderIcon(status) {
    const color = status === 'online' ? '#2ecc71' : '#95a5a6';
    return L.divIcon({
        className: '',
        html: `<div style="background-color:${color}; color:white; border-radius:50%; width:28px; height:28px; display:flex; align-items:center; justify-content:center; font-weight:bold; border:2px solid white; box-shadow:0 2px 5px rgba(0,0,0,0.3);">R</div>`,
        iconSize: [28,28],
        iconAnchor: [14,14]
    });
}

async function loadRiders() {
    try {
        const resp = await fetch('../api/get_online_riders.php');
        allRiders = await resp.json();
        renderRiders(allRiders);
    } catch(e) { console.error(e); }
}

function renderRiders(riders) {
    // 清除旧骑手标记
    for (let id in riderMarkers) map.removeLayer(riderMarkers[id]);
    riderMarkers = {};
    const listDiv = document.getElementById('riderList');
    if(!riders.length){ listDiv.innerHTML='<p>没有骑手</p>'; return; }
    let html = '<table style="width:100%;"><tr><th>ID</th><th>姓名</th><th>状态</th><th>操作</th></tr>';
    riders.forEach(r=>{
        const status = r.status==='online'?'在线':'离线';
        html += `<tr><td>${r.id}</td><td>${r.name}</td><td>${status}</td>
            <td><button type="button" onclick="selectRider(${r.id},'${r.name}')">选择</button>
            <button type="button" onclick="locateRider(${r.id})">📍定位</button></td></tr>`;
        if (r.latitude && r.longitude) {
            const marker = L.marker([r.latitude, r.longitude], {icon: createRiderIcon(r.status)})
                .addTo(map).bindPopup(`<b>${r.name}</b> (${status})`);
            riderMarkers[r.id] = marker;
        }
    });
    html += '</table>';
    listDiv.innerHTML = html;
    // 自动调整地图视野以包含所有骑手和目的地
    const allMarkers = Object.values(riderMarkers);
    if (destMarker) allMarkers.push(destMarker);
    if(allMarkers.length){
        const group = L.featureGroup(allMarkers);
        map.fitBounds(group.getBounds().pad(0.5));
    }
}

function searchRider() {
    const q = document.getElementById('riderSearchInput').value.trim().toLowerCase();
    const filtered = allRiders.filter(r=>r.name.toLowerCase().includes(q)||String(r.id)===q);
    renderRiders(filtered);
}
function clearRiderSearch() { document.getElementById('riderSearchInput').value=''; renderRiders(allRiders); }

function selectRider(id, name) {
    selectedRiderId = id;
    document.getElementById('selectedRiderId').value = id;
    alert('已选择骑手：'+name);
    drawRouteToSelectedRider();
}

function locateRider(id) {
    const rider = allRiders.find(r => r.id == id);
    if (rider && rider.latitude && rider.longitude) {
        map.setView([rider.latitude, rider.longitude], 15);
        if (riderMarkers[id]) riderMarkers[id].openPopup();
        drawRoute(rider.latitude, rider.longitude);
    } else {
        alert('该骑手暂无位置记录');
    }
}

// 路线绘制
async function drawRoute(riderLat, riderLng) {
    const destLat = parseFloat(latInput.value);
    const destLng = parseFloat(lngInput.value);
    if (!destLat || !destLng) {
        alert('请先设置包裹目的地（点击地图或搜索地址）');
        return;
    }
    if (routeLayer) map.removeLayer(routeLayer);
    try {
        const url = `https://router.project-osrm.org/route/v1/driving/${riderLng},${riderLat};${destLng},${destLat}?overview=full&geometries=geojson`;
        const resp = await fetch(url);
        const data = await resp.json();
        if (data.code === 'Ok' && data.routes.length > 0) {
            routeLayer = L.geoJSON(data.routes[0].geometry, { style: { color: '#3388ff', weight: 5, opacity: 0.7 } }).addTo(map);
        } else {
            routeLayer = L.polyline([[riderLat, riderLng], [destLat, destLng]], {color:'#3388ff', weight:3, dashArray:'5,10'}).addTo(map);
        }
    } catch(e) {
        routeLayer = L.polyline([[riderLat, riderLng], [destLat, destLng]], {color:'#3388ff', weight:3, dashArray:'5,10'}).addTo(map);
    }
    // 调整地图显示路线
    const bounds = L.latLngBounds([[riderLat, riderLng], [destLat, destLng]]);
    map.fitBounds(bounds, {padding:[30,30]});
}

function drawRouteToSelectedRider() {
    if (!selectedRiderId) return;
    const rider = allRiders.find(r => r.id == selectedRiderId);
    if (rider && rider.latitude && rider.longitude) drawRoute(rider.latitude, rider.longitude);
    else alert('所选骑手暂无位置记录');
}

// 初始化加载骑手
loadRiders();
</script>
<?php require_once '../includes/footer.php'; ?>