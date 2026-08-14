<?php
$page_title = '实时骑手地图';
$allowed_roles = ['admin'];
require_once '../includes/auth.php';
require_once '../includes/header.php';
?>
<h1>实时骑手位置</h1>

<div style="margin-bottom: 10px;">
    <input type="text" id="riderSearch" placeholder="输入骑手姓名或ID搜索..." style="padding: 8px; width: 300px; border: 1px solid #ccc; border-radius: 4px;">
    <button onclick="focusRider()" class="btn btn-primary">🔍 定位骑手</button>
    <button onclick="clearRoute()" class="btn btn-secondary">🗑️ 清除路线</button>
    <span id="searchResult" style="margin-left: 10px; font-size: 0.9rem;"></span>
</div>

<div id="map" style="height: 600px;"></div>
<p style="margin-top:5px; font-size:0.9rem; color:#666;">💡 点击骑手标记或搜索骑手，自动显示到最近包裹的路线</p>

<link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css">
<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
<script>
const map = L.map('map', { zoomAnimation: false }).setView([0, 0], 2);
L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
    attribution: '&copy; OpenStreetMap contributors',
    maxZoom: 19
}).addTo(map);

let allRiders = [];
let pendingParcels = [];
const markers = {};
let routeLayer = null;
let selectedRiderId = null;

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

// 获取所有骑手
async function fetchRiders() {
    try {
        const resp = await fetch('../api/get_online_riders.php');
        if (!resp.ok) throw new Error('HTTP ' + resp.status);
        const riders = await resp.json();
        allRiders = riders;

        const newIds = new Set(riders.map(r => String(r.id)));

        // 移除已不存在的骑手标记
        for (let id in markers) {
            if (!newIds.has(id)) {
                map.removeLayer(markers[id]);
                delete markers[id];
            }
        }

        riders.forEach(rider => {
            if (rider.latitude && rider.longitude) {
                const id = String(rider.id);
                const statusText = rider.status === 'online' ? '在线' : '离线';
                if (markers[id]) {
                    markers[id].setLatLng([rider.latitude, rider.longitude]);
                    markers[id].setPopupContent(`<b>${rider.name}</b> (${statusText})<br>更新: ${rider.last_update ?? '无'}`);
                } else {
                    markers[id] = L.marker([rider.latitude, rider.longitude], {
                        icon: createRiderIcon(rider.status)
                    }).addTo(map)
                      .bindPopup(`<b>${rider.name}</b> (${statusText})<br>更新: ${rider.last_update ?? '无'}`)
                      .on('click', () => showNearestRoute(rider.id));
                }
            }
        });

        // 第一次加载自动调整视野
        if (Object.keys(markers).length > 0 && map.getZoom() === 2) {
            const group = L.featureGroup(Object.values(markers));
            map.fitBounds(group.getBounds().pad(0.5));
        }

        // 如果已选择骑手，自动重新绘制路线（位置可能已变）
        if (selectedRiderId) {
            const rider = allRiders.find(r => r.id == selectedRiderId);
            if (rider && rider.latitude && rider.longitude) {
                showNearestRoute(rider.id);
            }
        }
    } catch (err) {
        console.error('获取骑手失败', err);
    }
}

// 获取所有待分配包裹
async function fetchPendingParcels() {
    try {
        const resp = await fetch('../api/get_pending_parcels.php');
        if (resp.ok) {
            pendingParcels = await resp.json();
        }
    } catch (err) {
        console.error('获取包裹失败', err);
    }
}

// 计算距离（Haversine）
function getDistance(lat1, lon1, lat2, lon2) {
    const R = 6371000;
    const dLat = (lat2 - lat1) * Math.PI / 180;
    const dLon = (lon2 - lon1) * Math.PI / 180;
    const a = Math.sin(dLat/2) ** 2 +
              Math.cos(lat1 * Math.PI / 180) * Math.cos(lat2 * Math.PI / 180) *
              Math.sin(dLon/2) ** 2;
    return R * 2 * Math.atan2(Math.sqrt(a), Math.sqrt(1-a));
}

// 找到离骑手最近的包裹
function findNearestParcel(riderLat, riderLng) {
    let minDist = Infinity;
    let nearest = null;
    pendingParcels.forEach(p => {
        if (p.latitude && p.longitude) {
            const dist = getDistance(riderLat, riderLng, parseFloat(p.latitude), parseFloat(p.longitude));
            if (dist < minDist) {
                minDist = dist;
                nearest = p;
            }
        }
    });
    return nearest;
}

// 绘制路线
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

// 显示到最近包裹的路线
async function showNearestRoute(riderId) {
    selectedRiderId = riderId;
    const rider = allRiders.find(r => r.id == riderId);
    if (!rider || !rider.latitude || !rider.longitude) {
        alert('骑手暂无位置记录');
        return;
    }
    if (pendingParcels.length === 0) {
        alert('暂无待分配包裹');
        return;
    }
    const nearest = findNearestParcel(rider.latitude, rider.longitude);
    if (nearest) {
        await drawRoute(rider.latitude, rider.longitude, parseFloat(nearest.latitude), parseFloat(nearest.longitude));
        const bounds = L.latLngBounds([[rider.latitude, rider.longitude], [nearest.latitude, nearest.longitude]]);
        map.fitBounds(bounds, {padding: [30,30]});
        document.getElementById('searchResult').textContent = `路线已显示：${rider.name} → ${nearest.recipient_name}`;
    }
}

// 清除路线
function clearRoute() {
    if (routeLayer) {
        map.removeLayer(routeLayer);
        routeLayer = null;
    }
    selectedRiderId = null;
    document.getElementById('searchResult').textContent = '';
}

// 搜索定位骑手（并显示路线）
function focusRider() {
    const query = document.getElementById('riderSearch').value.trim().toLowerCase();
    if (!query) {
        document.getElementById('searchResult').textContent = '请输入骑手姓名或ID';
        return;
    }
    const found = allRiders.find(rider =>
        rider.name.toLowerCase().includes(query) || String(rider.id) === query
    );
    if (!found) {
        document.getElementById('searchResult').textContent = '未找到该骑手';
        return;
    }
    if (!found.latitude || !found.longitude) {
        document.getElementById('searchResult').textContent = `骑手 ${found.name} 暂无位置记录`;
        return;
    }
    map.setView([found.latitude, found.longitude], 15);
    if (markers[found.id]) markers[found.id].openPopup();
    showNearestRoute(found.id);
}

// 初始化
document.addEventListener('DOMContentLoaded', async function() {
    await fetchPendingParcels();
    await fetchRiders();
    setInterval(fetchRiders, 10000); // 每10秒刷新骑手位置
});

// 回车搜索
document.getElementById('riderSearch').addEventListener('keyup', function(e) {
    if (e.key === 'Enter') focusRider();
});
</script>
<?php require_once '../includes/footer.php'; ?>