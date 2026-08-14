<?php
$page_title = '配送地图';
$allowed_roles = ['rider'];
require_once '../includes/auth.php';
require_once '../includes/header.php';

$pdo = Database::getInstance();
$riderId = $_SESSION['user_id'];

// 获取骑手所有未完成的包裹（有坐标），按接单顺序排序
$stmt = $pdo->prepare("
    SELECT p.*,
           (SELECT created_at FROM parcel_status_history 
            WHERE parcel_id = p.id AND new_status = 'out_for_delivery' 
            ORDER BY created_at ASC LIMIT 1) as accept_time
    FROM parcels p 
    WHERE p.assigned_rider = ? 
      AND p.status IN ('pending','out_for_delivery') 
      AND p.latitude IS NOT NULL 
    ORDER BY accept_time ASC, p.created_at ASC
");
$stmt->execute([$riderId]);
$myParcels = $stmt->fetchAll();
?>

<h1>配送地图</h1>

<!-- 图例 -->
<div style="margin-bottom: 8px; font-size: 0.9rem; background: #f9f9f9; padding: 6px 12px; border-radius: 4px; display: inline-block;">
    🔵 <strong>蓝色标记</strong> = 我的位置 &nbsp;&nbsp;|&nbsp;&nbsp;
    🟢 <strong>绿色标记</strong> = 顾客位置（送达目标）
</div>

<div id="riderMap" style="height: 70vh;"></div>

<div style="margin-top: 1rem;">
    <h3>📋 待配送订单（按接单顺序，先接先送）</h3>
    <ul id="parcelList" style="list-style: none; padding: 0;">
        <li>正在获取您的位置…</li>
    </ul>
</div>

<link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css">
<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
<script>
const map = L.map('riderMap').setView([0, 0], 13);
L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
    attribution: '&copy; OpenStreetMap contributors',
    maxZoom: 19
}).addTo(map);

const parcelData = <?= json_encode($myParcels) ?>;
const parcelMarkers = [];
let riderMarker = null;
let currentLat = null, currentLng = null;
let routeLayer = null;

const riderIcon = L.icon({
    iconUrl: 'https://raw.githubusercontent.com/pointhi/leaflet-color-markers/master/img/marker-icon-2x-blue.png',
    shadowUrl: 'https://cdnjs.cloudflare.com/ajax/libs/leaflet/1.9.4/images/marker-shadow.png',
    iconSize: [25, 41],
    iconAnchor: [12, 41]
});
const destIcon = L.icon({
    iconUrl: 'https://raw.githubusercontent.com/pointhi/leaflet-color-markers/master/img/marker-icon-2x-green.png',
    shadowUrl: 'https://cdnjs.cloudflare.com/ajax/libs/leaflet/1.9.4/images/marker-shadow.png',
    iconSize: [25, 41],
    iconAnchor: [12, 41]
});

// 距离计算
function getDistance(lat1, lon1, lat2, lon2) {
    const R = 6371000;
    const dLat = (lat2 - lat1) * Math.PI / 180;
    const dLon = (lon2 - lon1) * Math.PI / 180;
    const a = Math.sin(dLat/2) ** 2 +
              Math.cos(lat1 * Math.PI / 180) * Math.cos(lat2 * Math.PI / 180) *
              Math.sin(dLon/2) ** 2;
    return R * 2 * Math.atan2(Math.sqrt(a), Math.sqrt(1-a));
}

// 更新包裹列表（显示包裹内容、地址、距离、显示路线按钮）
function updateParcelList(lat, lng) {
    const listEl = document.getElementById('parcelList');
    if (parcelData.length === 0) {
        listEl.innerHTML = '<li>没有待配送的包裹</li>';
        return;
    }

    let html = '';
    parcelData.forEach((p, index) => {
        const dist = lat && lng ? getDistance(lat, lng, parseFloat(p.latitude), parseFloat(p.longitude)) : null;
        const distStr = dist ? `📏 距离约 ${(dist / 1000).toFixed(2)} 公里` : '';
        html += `
            <li style="padding: 8px; border-bottom: 1px solid #ddd; background: ${index === 0 ? '#fffde7' : 'transparent'};">
                <strong>#${index + 1}</strong> - ${p.recipient_name} 
                <span class="status-badge status-${p.status}">${p.status}</span>
                <br>📦 <strong>包裹：</strong>${p.description || '未填写'}
                <br>📍 ${p.recipient_address ?? '坐标 ' + p.latitude + ',' + p.longitude}
                ${distStr ? '<br>' + distStr : ''}
                <br>
                <button onclick="showRouteTo(${p.id}, ${p.latitude}, ${p.longitude})" class="btn btn-sm btn-info">🗺️ 显示路线</button>
            </li>
        `;
    });
    listEl.innerHTML = html;

    // 更新标记 popup（显示送达目标和坐标）
    parcelMarkers.forEach((marker, idx) => {
        const p = parcelData[idx];
        const dist = lat && lng ? getDistance(lat, lng, parseFloat(p.latitude), parseFloat(p.longitude)) : null;
        const distStr = dist ? `距离约 ${(dist / 1000).toFixed(2)} 公里` : '';
        marker.setPopupContent(`
            👤 <b>${p.recipient_name}</b>（顾客） #${idx + 1}<br>
            📦 <b>包裹：</b>${p.description || '未填写'}<br>
            📍 <b>送达目标：</b>${p.recipient_address ?? '坐标 ' + p.latitude + ',' + p.longitude}<br>
            ${distStr}<br>
            <button onclick="showRouteTo(${p.id}, ${p.latitude}, ${p.longitude})" class="btn btn-sm">显示路线</button>
        `);
    });
}

// 使用免费 OSRM 服务绘制路线（蓝线）
async function drawRoute(fromLat, fromLng, toLat, toLng) {
    if (routeLayer) map.removeLayer(routeLayer);
    try {
        const url = `https://router.project-osrm.org/route/v1/driving/${fromLng},${fromLat};${toLng},${toLat}?overview=full&geometries=geojson`;
        const resp = await fetch(url);
        const data = await resp.json();
        if (data.code === 'Ok' && data.routes.length > 0) {
            routeLayer = L.geoJSON(data.routes[0].geometry, {
                style: { color: '#3388ff', weight: 5, opacity: 0.7 }
            }).addTo(map);
        } else {
            routeLayer = L.polyline([[fromLat, fromLng], [toLat, toLng]], {color: '#3388ff', weight: 3, dashArray: '5,10'}).addTo(map);
        }
    } catch (e) {
        routeLayer = L.polyline([[fromLat, fromLng], [toLat, toLng]], {color: '#3388ff', weight: 3, dashArray: '5,10'}).addTo(map);
    }
}

function showRouteTo(parcelId, lat, lng) {
    if (currentLat && currentLng) {
        drawRoute(currentLat, currentLng, lat, lng);
    } else {
        alert('正在获取您的位置，请稍候...');
    }
}

// 自动显示到第一个包裹（优先配送）的路线
function focusOnFirstParcel() {
    if (parcelData.length === 0 || !currentLat || !currentLng) return;
    const first = parcelData[0];
    showRouteTo(first.id, parseFloat(first.latitude), parseFloat(first.longitude));
    map.setView([parseFloat(first.latitude), parseFloat(first.longitude)], 15);
}

// 添加目的地标记
parcelData.forEach((p, index) => {
    const marker = L.marker([p.latitude, p.longitude], {
        icon: destIcon,
        parcel: p
    }).addTo(map).bindPopup(`👤 <b>${p.recipient_name}</b>（顾客） #${index + 1}<br>📍 ${p.recipient_address ?? '坐标 ' + p.latitude + ',' + p.longitude}`);
    parcelMarkers.push(marker);
});

// 实时跟踪骑手位置
function startTracking() {
    if (!navigator.geolocation) {
        alert('您的浏览器不支持地理位置');
        return;
    }
    navigator.geolocation.watchPosition(
        position => {
            const lat = position.coords.latitude;
            const lng = position.coords.longitude;
            currentLat = lat;
            currentLng = lng;

            if (!riderMarker) {
                riderMarker = L.marker([lat, lng], {icon: riderIcon}).addTo(map)
                    .bindPopup('🛵 <b>我的位置</b>（骑手）');
            } else {
                riderMarker.setLatLng([lat, lng]);
            }

            map.setView([lat, lng], map.getZoom() || 15);
            updateParcelList(lat, lng);

            // 如果尚未选择路线，自动显示到第一个包裹的路线（优先配送）
            if (!routeLayer && parcelData.length > 0) {
                focusOnFirstParcel();
            }
        },
        error => {
            console.warn('定位失败', error);
            document.getElementById('parcelList').innerHTML = '<li>无法获取位置，请检查定位权限</li>';
        },
        { enableHighAccuracy: true, maximumAge: 3000, timeout: 10000 }
    );
}

startTracking();
</script>

<?php require_once '../includes/footer.php'; ?>