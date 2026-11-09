/**
 * ============================================================
 *  Session Timeout Manager
 * ============================================================
*/

(function () {
    'use strict';

    // ── KONFIGURASI ──
    var IDLE_LIMIT = 10 * 60 * 1000;   // 10 menit
    var CHECK_INTERVAL = 5 * 1000;          // cek tiap 5 detik
    var REDIRECT_SECS = 10;                // countdown redirect
    var STORAGE_KEY = 'selport_idle_ts';

    // ── STATE ──
    var _expired = false;
    var _intervalId = null;
    var _cntTimerId = null;

    // ═══════════════════════════════════════════════════════════
    //  UTILITAS
    // ═══════════════════════════════════════════════════════════

    function saveTimestamp() {
        if (_expired) return;
        try {
            localStorage.setItem(STORAGE_KEY, String(Date.now()));
        } catch (e) { }
    }

    function getTimestamp() {
        try {
            var v = localStorage.getItem(STORAGE_KEY);
            return v ? parseInt(v, 10) : 0;
        } catch (e) { return 0; }
    }

    function removeTimestamp() {
        try { localStorage.removeItem(STORAGE_KEY); } catch (e) { }
    }

    function getIdleMs() {
        var ts = getTimestamp();
        return ts ? (Date.now() - ts) : 0;
    }

    function isIdle() {
        return getIdleMs() >= IDLE_LIMIT;
    }

    function getLandingUrl() {
        var base = '';
        try { base = (typeof BASE_URL !== 'undefined' && BASE_URL) ? BASE_URL : (typeof window.BASE_URL !== 'undefined' ? window.BASE_URL : ''); } catch (e) { }
        return base + '/logout';
    }

    // ═══════════════════════════════════════════════════════════
    //  MODAL SESSION EXPIRED
    // ═══════════════════════════════════════════════════════════

    function showExpiredModal() {
        if (_expired) return;
        _expired = true;

        // Stop interval checker
        if (_intervalId) { clearInterval(_intervalId); _intervalId = null; }

        // Hapus timestamp
        removeTimestamp();

        console.warn('[SessionTimeout] Sesi kedaluwarsa — menampilkan modal');

        var modal = document.getElementById('sessionExpiredModal');
        if (!modal) {
            // Tidak ada modal di DOM → langsung redirect
            alert('Sesi Anda telah habis. Silakan login ulang.');
            window.location.href = getLandingUrl();
            return;
        }

        // Tampilkan modal
        modal.style.display = 'flex';

        // Animasi masuk
        var box = modal.querySelector('.session-modal-content');
        if (box) {
            box.style.animation = 'none';
            box.offsetWidth; // force reflow
            box.style.animation = 'sessionModalIn 0.4s cubic-bezier(0.34,1.56,0.64,1) forwards';
        }

        // Blokir semua klik di belakang modal
        modal.addEventListener('click', function (e) {
            if (e.target === modal) e.stopPropagation();
        });

        // Mulai countdown redirect
        startCountdown();
    }

    function doRedirect() {
        if (_cntTimerId) clearTimeout(_cntTimerId);
        removeTimestamp();
        try { localStorage.removeItem('selport_auth'); } catch (e) { }
        window.location.href = getLandingUrl();
    }

    function startCountdown() {
        var el = document.getElementById('sessionRedirectCountdown');
        var sisa = REDIRECT_SECS;

        function tick() {
            if (el) el.textContent = 'Anda akan dialihkan dalam ' + sisa + ' detik\u2026';
            if (sisa <= 0) {
                doRedirect();
                return;
            }
            sisa--;
            _cntTimerId = setTimeout(tick, 1000);
        }

        if (_cntTimerId) clearTimeout(_cntTimerId);
        tick();
    }

    // ═══════════════════════════════════════════════════════════
    //  PENGECEKAN PERIODIK
    // ═══════════════════════════════════════════════════════════

    function checkNow() {
        if (_expired) return;
        if (isIdle()) {
            showExpiredModal();
        }
    }

    // ═══════════════════════════════════════════════════════════
    //  RESET AKTIVITAS
    // ═══════════════════════════════════════════════════════════

    function onUserActivity() {
        if (_expired) return;
        saveTimestamp();
    }

    // ═══════════════════════════════════════════════════════════
    //  INISIALISASI
    // ═══════════════════════════════════════════════════════════

    function init() {
        console.log('[SessionTimeout] Init — idle limit:', IDLE_LIMIT / 1000, 'detik');

        // ① Cek apakah sudah timeout saat page load / refresh
        if (isIdle()) {
            console.log('[SessionTimeout] Sudah idle saat page load → tampilkan modal');
            setTimeout(showExpiredModal, 300);
            return; // jangan mulai timer
        }

        // ② Catat aktivitas awal (halaman baru dimuat = user aktif)
        saveTimestamp();

        // ③ Mulai interval checker (tiap 5 detik)
        _intervalId = setInterval(checkNow, CHECK_INTERVAL);

        // ④ Cek saat tab kembali visible (paling penting!)
        document.addEventListener('visibilitychange', function () {
            if (!document.hidden) {
                console.log('[SessionTimeout] Tab visible — cek idle');
                checkNow();
            }
        });

        // ⑤ Cek saat window dapat focus (user kembali ke tab/browser)
        window.addEventListener('focus', function () {
            console.log('[SessionTimeout] Window focus — cek idle');
            checkNow();
        });

        // ⑥ Event aktivitas user (reset timer)
        var events = ['mousedown', 'keydown', 'touchstart', 'scroll', 'wheel'];
        for (var i = 0; i < events.length; i++) {
            document.addEventListener(events[i], onUserActivity, { passive: true });
        }

        // ⑦ Intercept klik pada elemen navigasi (capture phase)
        document.addEventListener('click', function (e) {
            // Jika sudah expired → blokir semua KECUALI klik di dalam modal sesi
            if (_expired) {
                // Izinkan klik di dalam sessionExpiredModal (tombol Login Ulang)
                if (e.target.closest && e.target.closest('#sessionExpiredModal')) return;
                e.preventDefault();
                e.stopImmediatePropagation();
                return;
            }

            // Jika target adalah nav-item → cek dulu sebelum navigasi
            var nav = e.target.closest ? e.target.closest('[data-section]') : null;
            if (nav && isIdle()) {
                e.preventDefault();
                e.stopImmediatePropagation();
                showExpiredModal();
            }
        }, true);

        // ⑧ Sync antar tab browser
        window.addEventListener('storage', function (e) {
            if (e.key !== STORAGE_KEY || _expired) return;
            if (!e.newValue) {
                // Timestamp dihapus di tab lain → sesi habis
                showExpiredModal();
            }
        });

        console.log('[SessionTimeout] Siap — timer berjalan');
    }

    // ═══════════════════════════════════════════════════════════
    //  PUBLIC API
    // ═══════════════════════════════════════════════════════════

    window.SessionTimeout = {
        redirect: doRedirect,
        touch: onUserActivity,
        expire: showExpiredModal
    };

    // ═══════════════════════════════════════════════════════════
    //  AUTO START
    // ═══════════════════════════════════════════════════════════

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }

})();
