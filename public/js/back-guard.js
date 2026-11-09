/**
 * ============================================================
 *  Back Button Guard
 * ============================================================
 */
(function () {
    'use strict';

    var GUARD_KEY = '__selport_dashboard__';

    var guardState = {};
    guardState[GUARD_KEY] = true;
    guardState.__guard__ = true;

    // ── 1. Tandai entry saat ini sebagai milik dashboard ──
    var currentState = history.state || {};
    currentState[GUARD_KEY] = true;
    history.replaceState(currentState, '', window.location.href);

    // ── 2. Push satu guard entry di atas ──
    history.pushState(guardState, '', window.location.href);

    // ── 3. Capture-phase popstate: jalankan sebelum listener portal ──
    window.addEventListener('popstate', function (e) {
        var state = e.state;

        // Kasus A: tidak ada marker dashboard (halaman auth/landing) → blokir
        if (!state || !state[GUARD_KEY]) {
            history.pushState(guardState, '', window.location.href);
            return;
        }

        // Kasus B: ada marker tapi TIDAK ada section (guard entry atau initial entry)
        // → push guard baru agar user tetap terperangkap, jangan navigasi keluar
        if (!state.section) {
            history.pushState(guardState, '', window.location.href);
            return;
        }

        // Kasus C: ada section → biarkan listener portal menangani navigasi SPA
    }, true); // capture phase = dijalankan sebelum listener bubble phase lainnya

    console.log('[BackGuard] Guard aktif — tombol back ke auth/landing diblokir permanen');
})();
