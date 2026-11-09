document.addEventListener('DOMContentLoaded', function () {
    const navItems   = document.querySelectorAll('.nav-item[data-section]');
const contentArea = document.getElementById('contentArea');

// ── Profile dropdown ───────────────────────────────────────────────────
const profileToggle = document.getElementById('profileToggle');
const profileMenu   = document.getElementById('profileMenu');
if (profileToggle && profileMenu) {
    profileToggle.addEventListener('click', (e) => {
        e.stopPropagation();
        profileMenu.classList.toggle('show');
    });
    document.addEventListener('click', (e) => {
        if (!profileMenu.contains(e.target) && !profileToggle.contains(e.target)) {
            profileMenu.classList.remove('show');
        }
    });
}

// ── Sidebar navigation ─────────────────────────────────────────────────
navItems.forEach(item => {
    item.addEventListener('click', (e) => {
        if (item.classList.contains('logout')) return;
        e.preventDefault();
        const section = item.dataset.section;
        if (section) {
            if (window.innerWidth <= 768) {
                document.getElementById('sidebar')?.classList.remove('open');
                document.querySelector('.sidebar-overlay')?.classList.remove('active');
            }
            window.loadSertSection(section);
        }
    });
});

// ── Mobile menu toggle ─────────────────────────────────────────────────
let overlay = document.querySelector('.sidebar-overlay');
if (!overlay) {
    overlay = document.createElement('div');
    overlay.className = 'sidebar-overlay';
    document.body.appendChild(overlay);
}
overlay.addEventListener('click', () => {
    document.getElementById('sidebar')?.classList.remove('open');
    overlay.classList.remove('active');
});

const menuToggle = document.getElementById('menuToggle');
if (menuToggle) {
    menuToggle.addEventListener('click', () => {
        const sidebar = document.getElementById('sidebar');
        if (window.innerWidth <= 768) {
            if (sidebar.classList.contains('open')) {
                sidebar.classList.remove('open');
                overlay.classList.remove('active');
            } else {
                sidebar.classList.add('open');
                overlay.classList.add('active');
            }
        } else {
            sidebar.classList.toggle('collapsed');
        }
    });
}

window.addEventListener('resize', function () {
    const sidebar = document.getElementById('sidebar');
    if (!sidebar) return;
    if (window.innerWidth > 768) {
        sidebar.classList.remove('open');
        const overlay = document.querySelector('.sidebar-overlay');
        if(overlay) overlay.classList.remove('active');
    } else {
        sidebar.classList.remove('collapsed');
    }
});

    // ── Avatar preview on file select ──────────────────────────────────────
    const avatarInput = document.getElementById('editAvatarInput');
    if (avatarInput) {
        avatarInput.addEventListener('change', (e) => {
            const file = e.target.files[0];
            if (file) {
                const reader = new FileReader();
                reader.onload = (ev) => {
                    document.getElementById('editProfileAvatar').src = ev.target.result;
                };
                reader.readAsDataURL(file);
            }
        });
    }

    // ── Edit Profile form submit ───────────────────────────────────────────
    const editForm = document.getElementById('editProfileForm');
    if (editForm) {
        editForm.addEventListener('submit', async (e) => {
            e.preventDefault();
            const formData = new FormData(editForm);
            try {
                const res  = await fetch(`${BASE_URL}/sertifikasi/api/update-account`, {
                    method: 'POST',
                    body: formData
                });
                const json = await res.json();

                if (json.success) {
                    closeSertModal('editProfileModal');
                    showSertAlert(json.message || 'Profil berhasil diperbarui!');
                    if (json.avatar) {
                        document.querySelectorAll('.profile-avatar').forEach(el => {
                            el.src = `${BASE_URL}/public/avatar/${json.avatar}?t=${Date.now()}`;
                        });
                    }
                    if (json.nama) {
                        document.querySelectorAll('.profile-name').forEach(el => el.textContent = json.nama);
                    }
                } else {
                    showSertAlert('Gagal memperbarui: ' + (json.message || 'Unknown error'), 'error');
                }
            } catch (err) {
                console.error(err);
                showSertAlert('Terjadi kesalahan saat menyimpan profil.', 'error');
            }
        });
    }

    // ── Load initial section ────────────────────────────────────────────────
    // Cache konten ringkasan yang sudah di-embed di dashboard.html
    if (contentArea) {
        window._sertRingkasanCache = contentArea.innerHTML;
    }

    const initialSection = SECTION || 'ringkasan';
    if (initialSection === 'ringkasan') {
        // Konten ringkasan sudah di-embed, cukup load stats
        loadSertStats();
    } else {
        window.loadSertSection(initialSection, false);
    }
});


// ── Load section via AJAX ─────────────────────────────────────────────────
window.loadSertSection = async function (section, pushState = true) {
    const container = document.getElementById('contentArea');
    if (!container) return;

    // ── Ringkasan: restore dari cache, tanpa AJAX ─────────────────────────
    if (section === 'ringkasan') {
        if (window._sertRingkasanCache) {
            container.innerHTML = window._sertRingkasanCache;
        }
        document.querySelectorAll('.nav-item[data-section]').forEach(nav => {
            nav.classList.toggle('active', nav.dataset.section === 'ringkasan');
        });
        if (pushState) {
            history.pushState(
                { section: 'ringkasan', __selport_dashboard__: true },
                '',
                `${BASE_URL}/sertifikasi/dashboard`
            );
        }
        loadSertStats();
        return;
    }

    container.innerHTML = `<div style="display:flex;align-items:center;justify-content:center;padding:60px;color:#858796;gap:10px;">
        <i class="ri-loader-4-line" style="font-size:1.5rem;animation:spin 1s linear infinite;"></i>
        <span>Memuat halaman...</span>
    </div>
    <style>@keyframes spin{from{transform:rotate(0deg)}to{transform:rotate(360deg)}}</style>`;

    try {
        const timestamp = new Date().getTime();
        const res  = await fetch(`${BASE_URL}/sertifikasi/load/${section}?_t=${timestamp}`);
        const html = await res.text();

        container.innerHTML = html;

        // Execute inline scripts (innerHTML does not run <script> tags)
        const scripts = container.querySelectorAll('script');
        scripts.forEach(oldScript => {
            const newScript = document.createElement('script');
            Array.from(oldScript.attributes).forEach(attr => {
                newScript.setAttribute(attr.name, attr.value);
            });
            if (oldScript.innerHTML) {
                newScript.appendChild(document.createTextNode(oldScript.innerHTML));
            }
            oldScript.parentNode.replaceChild(newScript, oldScript);
        });

        // Update active nav
        document.querySelectorAll('.nav-item[data-section]').forEach(nav => {
            nav.classList.toggle('active', nav.dataset.section === section);
        });

        if (pushState) {
            history.pushState(
                { section, __selport_dashboard__: true },
                '',
                `${BASE_URL}/sertifikasi/${section}`
            );
        }

    } catch (err) {
        container.innerHTML = `<div class="alert alert-danger">Gagal memuat halaman: ${err.message}</div>`;
    }
};


// ── Popstate ─────────────────────────────────────────────────────────────
window.addEventListener('popstate', (e) => {
    if (!e.state || e.state.__guard__) return;
    if (e.state.section) {
        window.loadSertSection(e.state.section, false);
    } else {
        const parts = window.location.pathname.split('/');
        const sec   = parts[parts.length - 1] || 'ringkasan';
        window.loadSertSection(['sertifikasi', 'dashboard'].includes(sec) ? 'ringkasan' : sec, false);
    }
});

// ── Modals ────────────────────────────────────────────────────────────────
window.showEditProfileModal = function () {
    document.getElementById('profileMenu')?.classList.remove('show');
    const avatarSrc = SERTI_AVATAR
        ? `${BASE_URL}/public/avatar/${SERTI_AVATAR}?t=${Date.now()}`
        : `${BASE_URL}/public/avatar/avatar.png`;
    document.getElementById('editProfileAvatar').src   = avatarSrc;
    document.getElementById('editNamaLengkap').value   = SERTI_NAME     || '';
    document.getElementById('editUsername').value      = SERTI_USERNAME  || '';
    document.getElementById('editPassword').value      = '';
    document.getElementById('editProfileModal').classList.add('active');
};

window.closeSertModal = function (id) {
    const el = document.getElementById(id);
    if (el) el.classList.remove('active');
};

window.showSertAlert = function (message, type = 'success') {
    const iconEl = document.getElementById('alertModalIcon');
    iconEl.innerHTML = type === 'success'
        ? '<i class="ri-checkbox-circle-line" style="color:#1cc88a;"></i>'
        : '<i class="ri-error-warning-line" style="color:#e74a3b;"></i>';
    document.getElementById('alertModalMessage').textContent = message;
    document.getElementById('alertModal').classList.add('active');
};

window.showConfirmSert = function (title, message, onConfirm) {
    document.getElementById('confirmSertTitle').textContent = title;
    document.getElementById('confirmSertMessage').textContent = message;
    
    const okBtn = document.getElementById('confirmSertOkBtn');
    // Remove old listeners to prevent multiple triggers
    const newOkBtn = okBtn.cloneNode(true);
    okBtn.parentNode.replaceChild(newOkBtn, okBtn);
    
    newOkBtn.addEventListener('click', function() {
        closeSertModal('confirmSertModal');
        if (typeof onConfirm === 'function') onConfirm();
    });
    
    document.getElementById('confirmSertModal').classList.add('active');
};

// ── Stats loader ──────────────────────────────────────────────────────────
function loadSertStats() {
    fetch(`${BASE_URL}/sertifikasi/api/stats`)
        .then(r => r.json())
        .then(data => {
            const set = (id, val) => {
                const el = document.getElementById(id);
                if (el) el.textContent = val ?? 0;
            };
            set('stat-total-pendaftaran',      data.total_pendaftaran);
            set('stat-total-verifikasi',       data.total_verifikasi);
            set('stat-pending-pendaftaran',    data.pending_pendaftaran);
            set('stat-disetujui-pendaftaran',  data.disetujui_pendaftaran);
            set('stat-pending-verifikasi',     data.pending_verifikasi);
            set('stat-disetujui-verifikasi',   data.disetujui_verifikasi);
        })
        .catch(console.error);
}
