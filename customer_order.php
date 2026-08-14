<?php
require_once 'includes/config.php';
require_once 'includes/db.php';
require_once 'includes/functions.php';

// 初始化顾客 token
if (empty($_SESSION['customer_token'])) {
    $_SESSION['customer_token'] = bin2hex(random_bytes(32));
}

$success = false;
$tracking = '';
$error = '';

// 预填数据处理（来自修改再下单）
$prefill = null;
$reorder_tracking = sanitize($_GET['reorder'] ?? '');
if (!empty($reorder_tracking)) {
    if (in_array($reorder_tracking, $_SESSION['my_orders'] ?? [])) {
        $pdo = Database::getInstance();
        $stmt = $pdo->prepare("SELECT * FROM parcels WHERE tracking_number = ? AND status = 'cancelled'");
        $stmt->execute([$reorder_tracking]);
        $prefill = $stmt->fetch();
        if (!$prefill) {
            $error = '该订单无法重新下单（可能已非取消状态）。';
        }
    } else {
        $error = '无权操作该订单。';
    }
}

// 表单提交处理
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['customer_token']) {
        die('非法请求');
    }

    $sender_name    = sanitize($_POST['sender_name'] ?? '');
    $sender_addr    = sanitize($_POST['sender_address'] ?? '');
    $recipient_name = sanitize($_POST['recipient_name'] ?? '');
    $recipient_addr = sanitize($_POST['recipient_address'] ?? '');
    $recipient_phone = sanitize($_POST['recipient_phone'] ?? '');
    $description    = sanitize($_POST['description'] ?? '');
    $lat = floatval($_POST['latitude'] ?? 0);
    $lng = floatval($_POST['longitude'] ?? 0);

    if (empty($recipient_name)) {
        $error = '请填写收件人姓名。';
    } elseif (empty($lat) || empty($lng)) {
        $error = '请点击地图选择位置或使用定位按钮。';
    } else {
        $tracking = generateTrackingNumber();
        $pdo = Database::getInstance();
        $stmt = $pdo->prepare("INSERT INTO parcels (tracking_number, sender_name, sender_address, recipient_name, recipient_address, recipient_phone, description, latitude, longitude, source, status) 
                               VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'customer', 'pending')");
        $stmt->execute([$tracking, $sender_name, $sender_addr, $recipient_name, $recipient_addr, $recipient_phone, $description, $lat, $lng]);

        // 将追踪号存入会话
        if (!isset($_SESSION['my_orders'])) {
            $_SESSION['my_orders'] = [];
        }
        if (!in_array($tracking, $_SESSION['my_orders'])) {
            $_SESSION['my_orders'][] = $tracking;
        }

        $success = true;
        $_SESSION['customer_token'] = bin2hex(random_bytes(32));
    }
}
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $prefill ? '修改并重新下单' : '顾客下单' ?> - 包裹配送系统</title>
    <link rel="stylesheet" href="assets/css/style.css">
    <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css">
    <script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
    <style>
        .order-container { max-width: 650px; margin: 30px auto; background: white; padding: 2rem; border-radius: 8px; box-shadow: 0 2px 10px rgba(0,0,0,0.1); }
        #selectionMap { height: 280px; margin: 10px 0; display: none; border: 1px solid #ddd; border-radius: 4px; }
        .success-box { text-align: center; }
        .success-box h2 { color: #27ae60; }
        .track-number { font-size: 1.5rem; font-weight: bold; background: #f4f7fc; padding: 10px; border-radius: 5px; }
        .btn-locate { background: #e67e22; color: white; }
        .coords { font-size: 0.9em; color: #666; }
        .address-suggestions {
            border: 1px solid #ccc;
            max-height: 150px;
            overflow-y: auto;
            background: white;
            position: absolute;
            z-index: 1000;
            width: calc(100% - 2px);
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
            border-radius: 0 0 4px 4px;
        }
        .address-suggestions div {
            padding: 8px;
            cursor: pointer;
        }
        .address-suggestions div:hover {
            background-color: #f0f0f0;
        }
        .selected-address {
            margin-top: 5px;
            font-size: 0.9rem;
            color: #27ae60;
            display: none;
        }
        .map-hint { font-size: 0.8rem; color: #888; margin-top: 4px; }
    </style>
</head>
<body class="login-page">
<div class="order-container">
    <?php if ($success): ?>
        <div class="success-box">
            <h2>✅ 下单成功！</h2>
            <p>您的包裹追踪号：</p>
            <div class="track-number"><?= $tracking ?></div>
            <p>骑手接单后将为您配送。</p>
            <a href="customer_dashboard.php?tracking=<?= $tracking ?>" class="btn btn-primary">查看包裹进度</a>
            <br><br>
            <a href="customer_home.php" class="btn">返回首页</a>
            <a href="customer_order.php" class="btn">再下一单</a>
        </div>
    <?php else: ?>
        <h2><?= $prefill ? '✏️ 修改订单信息并重新下单' : '📦 顾客下单' ?></h2>
        <?php if ($error): ?><div class="alert alert-danger"><?= $error ?></div><?php endif; ?>
        <form method="POST" id="orderForm">
            <input type="hidden" name="csrf_token" value="<?= $_SESSION['customer_token'] ?>">
            <input type="hidden" name="latitude" id="latInput" value="<?= $prefill['latitude'] ?? '' ?>">
            <input type="hidden" name="longitude" id="lngInput" value="<?= $prefill['longitude'] ?? '' ?>">

            <div class="form-group">
                <label>发件人姓名</label>
                <input type="text" name="sender_name" value="<?= $_POST['sender_name'] ?? $prefill['sender_name'] ?? '' ?>">
            </div>
            <div class="form-group">
                <label>发件人地址（可选）</label>
                <input type="text" name="sender_address" value="<?= $_POST['sender_address'] ?? $prefill['sender_address'] ?? '' ?>">
            </div>
            <div class="form-group">
                <label>收件人姓名 *</label>
                <input type="text" name="recipient_name" required value="<?= $_POST['recipient_name'] ?? $prefill['recipient_name'] ?? '' ?>">
            </div>
            <div class="form-group">
                <label>收件人电话</label>
                <input type="text" name="recipient_phone" value="<?= $_POST['recipient_phone'] ?? $prefill['recipient_phone'] ?? '' ?>">
            </div>
            <div class="form-group" style="position: relative;">
                <label>收件地址</label>
                <input type="text" name="recipient_address" id="addressInput" autocomplete="off" placeholder="输入地址搜索，或在地图上点选" value="<?= $_POST['recipient_address'] ?? $prefill['recipient_address'] ?? '' ?>">
                <div id="addressSuggestions" class="address-suggestions" style="display:none;"></div>
                <div id="selectedAddressDisplay" class="selected-address"></div>
            </div>
            <div class="form-group">
                <label>包裹描述 / 内容（选填）</label>
                <textarea name="description" rows="2"><?= $_POST['description'] ?? $prefill['description'] ?? '' ?></textarea>
            </div>
            <div class="form-group">
                <button type="button" id="locateBtn" class="btn btn-locate">📍 定位到我当前的位置</button>
                <span id="locationStatus" class="coords"></span>
                <div id="selectionMap"></div>
                <p class="map-hint">💡 点击地图上的任意位置即可选择该地点作为收件地址</p>
            </div>
            <button type="submit" class="btn btn-primary">提交订单</button>
        </form>
        <p style="margin-top:1rem;">
            <a href="customer_home.php">← 返回首页</a> | <a href="index.php">员工登录</a>
        </p>
    <?php endif; ?>
</div>

<script>
let map, selectionMarker;
const latInput = document.getElementById('latInput');
const lngInput = document.getElementById('lngInput');
const addressInput = document.getElementById('addressInput');
const statusSpan = document.getElementById('locationStatus');
const mapDiv = document.getElementById('selectionMap');
const selectedDisplay = document.getElementById('selectedAddressDisplay');
const locateBtn = document.getElementById('locateBtn');

const initLat = parseFloat(latInput.value) || null;
const initLng = parseFloat(lngInput.value) || null;

function initMap(lat = 39.9042, lng = 116.4074, zoom = 13) {
    mapDiv.style.display = 'block';
    if (!map) {
        map = L.map('selectionMap').setView([lat, lng], zoom);
        L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
            attribution: '&copy; OpenStreetMap contributors'
        }).addTo(map);
        map.on('click', function(e) {
            setLocation(e.latlng.lat, e.latlng.lng);
            reverseGeocode(e.latlng.lat, e.latlng.lng);
        });
    } else {
        map.setView([lat, lng], zoom);
    }
    if (initLat && initLng && !selectionMarker) {
        setLocation(initLat, initLng);
        if (addressInput.value) {
            selectedDisplay.textContent = '📍 原地址：' + addressInput.value;
            selectedDisplay.style.display = 'block';
        }
        statusSpan.textContent = `坐标：${initLat.toFixed(5)}, ${initLng.toFixed(5)}`;
    }
}

function setLocation(lat, lng) {
    latInput.value = lat;
    lngInput.value = lng;
    statusSpan.textContent = `已定位：${lat.toFixed(5)}, ${lng.toFixed(5)}`;
    if (selectionMarker) {
        selectionMarker.setLatLng([lat, lng]);
    } else {
        selectionMarker = L.marker([lat, lng]).addTo(map).bindPopup('选择的地址');
    }
}

function reverseGeocode(lat, lng) {
    selectedDisplay.style.display = 'none';
    fetch(`https://nominatim.openstreetmap.org/reverse?format=json&lat=${lat}&lon=${lng}&addressdetails=1&accept-language=zh`)
        .then(response => response.json())
        .then(data => {
            if (data && data.display_name) {
                addressInput.value = data.display_name;
                selectedDisplay.textContent = '📍 您选择的地址：' + data.display_name;
                selectedDisplay.style.display = 'block';
            }
        })
        .catch(err => console.error('反向地理编码失败:', err));
}

locateBtn.addEventListener('click', function() {
    if (!navigator.geolocation) {
        alert('您的浏览器不支持定位');
        return;
    }
    statusSpan.textContent = '正在定位...';
    navigator.geolocation.getCurrentPosition(function(pos) {
        const lat = pos.coords.latitude;
        const lng = pos.coords.longitude;
        initMap(lat, lng, 15);
        setLocation(lat, lng);
        reverseGeocode(lat, lng);
    }, function(err) {
        alert('定位失败，请检查定位权限或手动点击地图选择位置');
        initMap();
    }, { enableHighAccuracy: true });
});

// 如果有预填坐标，直接显示地图
if (initLat && initLng) {
    initMap(initLat, initLng, 15);
}

// 地址搜索建议
const suggestionsDiv = document.getElementById('addressSuggestions');
let debounceTimer;
addressInput.addEventListener('input', function() {
    clearTimeout(debounceTimer);
    const query = this.value.trim();
    if (query.length < 3) {
        suggestionsDiv.style.display = 'none';
        return;
    }
    debounceTimer = setTimeout(() => {
        fetch(`https://nominatim.openstreetmap.org/search?format=json&q=${encodeURIComponent(query)}&addressdetails=1&limit=5&accept-language=zh`)
            .then(response => response.json())
            .then(data => {
                suggestionsDiv.innerHTML = '';
                if (data.length === 0) {
                    suggestionsDiv.style.display = 'none';
                    return;
                }
                data.forEach(place => {
                    const div = document.createElement('div');
                    div.textContent = place.display_name;
                    div.addEventListener('click', function() {
                        addressInput.value = place.display_name;
                        selectedDisplay.textContent = '📍 您选择的地址：' + place.display_name;
                        selectedDisplay.style.display = 'block';
                        if (place.lat && place.lon) {
                            const lat = parseFloat(place.lat);
                            const lng = parseFloat(place.lon);
                            latInput.value = lat;
                            lngInput.value = lng;
                            if (map) {
                                map.setView([lat, lng], 15);
                                setLocation(lat, lng);
                            }
                        }
                        suggestionsDiv.style.display = 'none';
                    });
                    suggestionsDiv.appendChild(div);
                });
                suggestionsDiv.style.display = 'block';
            })
            .catch(err => {
                console.error('地址搜索失败:', err);
                suggestionsDiv.style.display = 'none';
            });
    }, 300);
});

document.addEventListener('click', function(e) {
    if (!addressInput.contains(e.target) && !suggestionsDiv.contains(e.target)) {
        suggestionsDiv.style.display = 'none';
    }
});
</script>
</body>
</html>