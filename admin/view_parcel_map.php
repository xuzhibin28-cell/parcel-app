<?php
$page_title = '包裹目的地地图';
$allowed_roles = ['admin'];
require_once '../includes/auth.php';
require_once '../includes/header.php';

$parcelId = intval($_GET['parcel_id'] ?? 0);

$pdo = Database::getInstance();
$stmt = $pdo->prepare("SELECT * FROM parcels WHERE id = ?");
$stmt->execute([$parcelId]);
$parcel = $stmt->fetch();

if (!$parcel) {
    echo "<p>包裹不存在。</p>";
    require_once '../includes/footer.php';
    exit;
}
?>

<h1>包裹目的地</h1>
<p><strong>追踪号：</strong><?= $parcel['tracking_number'] ?></p>
<p><strong>收件人：</strong><?= sanitize($parcel['recipient_name']) ?></p>
<p><strong>地址：</strong><?= sanitize($parcel['recipient_address'] ?? '未提供') ?></p>
<p><strong>GPS 坐标：</strong><?= $parcel['latitude'] ? $parcel['latitude'] . ', ' . $parcel['longitude'] : '未定位' ?></p>

<?php if ($parcel['latitude']): ?>
    <div id="map" style="height: 500px;"></div>
    <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css">
    <script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
    <script>
        const map = L.map('map').setView([<?= $parcel['latitude'] ?>, <?= $parcel['longitude'] ?>], 15);
        L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
            attribution: '&copy; OpenStreetMap contributors'
        }).addTo(map);
        L.marker([<?= $parcel['latitude'] ?>, <?= $parcel['longitude'] ?>])
            .addTo(map)
            .bindPopup('<?= sanitize($parcel['recipient_name']) ?><br><?= sanitize($parcel['recipient_address'] ?? '') ?>')
            .openPopup();
    </script>
<?php else: ?>
    <p>该包裹没有坐标，无法显示地图。</p>
<?php endif; ?>

<?php require_once '../includes/footer.php'; ?>