let watchId = null;
const toggleBtn = document.getElementById('toggleOnline');

function getCsrfToken() {
    const meta = document.querySelector('meta[name="csrf-token"]');
    return meta ? meta.content : '';
}

async function sendLocation(lat, lng) {
    try {
        await fetch('../api/update_location.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: `lat=${lat}&lng=${lng}&csrf_token=${getCsrfToken()}`
        });
    } catch (e) {
        console.error('位置上报失败', e);
    }
}

function startTracking() {
    if (!navigator.geolocation) return;
    watchId = navigator.geolocation.watchPosition(
        pos => sendLocation(pos.coords.latitude, pos.coords.longitude),
        err => console.error(err),
        { enableHighAccuracy: true, timeout: 10000, maximumAge: 0 }
    );
}

function stopTracking() {
    if (watchId) {
        navigator.geolocation.clearWatch(watchId);
        watchId = null;
    }
}

if (toggleBtn) {
    toggleBtn.addEventListener('click', async () => {
        const goingOnline = toggleBtn.classList.contains('btn-success');
        const status = goingOnline ? 'online' : 'offline';
        try {
            const resp = await fetch('../api/update_status.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: `status=${status}&csrf_token=${getCsrfToken()}`
            });
            const data = await resp.json();

            if (data.success) {
                if (goingOnline) {
                    startTracking();
                    toggleBtn.textContent = '下线';
                    toggleBtn.className = 'btn btn-danger';
                } else {
                    stopTracking();
                    toggleBtn.textContent = '上线';
                    toggleBtn.className = 'btn btn-success';
                }
            } else {
                alert(data.error || '操作失败，请重试。');
            }
        } catch (err) {
            alert('网络错误，请稍后重试');
            console.error(err);
        }
    });

    // 页面加载时如果已经在线，自动开始追踪
    if (toggleBtn.classList.contains('btn-danger')) {
        startTracking();
    }
}