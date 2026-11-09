let currentSection = 'dashboard';
window.itemsPerPage = 5;
window.currentValidasiPage = 1;
window.allValidasiData = [];
window.currentAnnouncementPage = 1;
window.allAnnouncementData = [];

function renderPagination(currentPage, totalPages, container, sectionName) {
    if (!container) return;
    container.style.justifyContent = 'space-between';

    let totalItems = 0;
    if (sectionName === 'Validasi') totalItems = (window.allValidasiData || []).length;
    else if (sectionName === 'Announcement') totalItems = (window.allAnnouncementData || []).length;
    else if (sectionName === 'KelolaPembayaran') totalItems = (window._mitraPayData || []).length;
    else if (sectionName === 'Kesanggupan') totalItems = (window._mitraKesData || []).length;

    let perPage = window.itemsPerPage || 5;
    if (sectionName === 'KelolaPembayaran') perPage = window._mitraPaySize || 5;
    if (sectionName === 'Kesanggupan') perPage = window._mitraKesSize || 5;

    let paginationHTML = `<div style="display: flex; justify-content: space-between; align-items: center; width: 100%; flex-wrap: wrap; gap: 15px;">`;

    const startIdx = totalItems === 0 ? 0 : ((currentPage - 1) * perPage) + 1;
    const endIdx = Math.min(startIdx + perPage - 1, totalItems);

    paginationHTML += `<div style="display: flex; align-items: center; gap: 15px; font-size: 14px; color: #6c757d;">`;
    if (totalItems > 0) {
        paginationHTML += `<span>Menampilkan ${startIdx} - ${endIdx} dari ${totalItems} data</span>`;
    }

    paginationHTML += `
        <div style="display: flex; align-items: center; gap: 5px;">
            <span>Tampilkan</span>
            <select class="form-control" style="width: auto; padding: 4px 8px; height: auto; border-radius: 4px;" onchange="
                if('${sectionName}' === 'KelolaPembayaran') { window._mitraPaySize = parseInt(this.value); window._mitraPayPage = 1; window.mitraRenderPayTable(); }
                else if('${sectionName}' === 'Kesanggupan') { window._mitraKesSize = parseInt(this.value); window._mitraKesPage = 1; window.mitraRenderKesTable(); }
                else { window.itemsPerPage = parseInt(this.value); window['current${sectionName}Page'] = 1; if(window.render${sectionName}Table) window.render${sectionName}Table(); }
            ">
                <option value="5" ${perPage == 5 ? 'selected' : ''}>5</option>
                <option value="10" ${perPage == 10 ? 'selected' : ''}>10</option>
                <option value="25" ${perPage == 25 ? 'selected' : ''}>25</option>
                <option value="50" ${perPage == 50 ? 'selected' : ''}>50</option>
            </select>
        </div>
    </div>`;

    paginationHTML += `<div style="display: flex; gap: 5px;">`;
    paginationHTML += `<button class="page-btn" ${currentPage === 1 ? 'disabled' : `onclick="change${sectionName}Page(${currentPage - 1})"`}>Prev</button>`;

    let startPage = Math.max(1, currentPage - 2);
    let endPage = Math.min(totalPages, startPage + 4);
    if (endPage - startPage < 4) startPage = Math.max(1, endPage - 4);

    for (let i = startPage; i <= endPage; i++) {
        paginationHTML += `<button class="page-btn ${i === currentPage ? 'active' : ''}" onclick="change${sectionName}Page(${i})">${i}</button>`;
    }

    paginationHTML += `<button class="page-btn" ${currentPage >= totalPages ? 'disabled' : `onclick="change${sectionName}Page(${currentPage + 1})"`}>Next</button>`;
    paginationHTML += `</div></div>`;
    
    container.innerHTML = paginationHTML;
}


document.addEventListener('DOMContentLoaded', function () {
    const urlMap = {
        'ringkasan': 'dashboard',
        'validasi-pelanggan': 'customervalidation',
        'pemberitahuan': 'announcements',
        'pengecualian-dokumen': 'exemption-docs',
        'pengaduan-pelanggan': 'pengaduan-pelanggan',
        'kelola-pembayaran': 'kelola-pembayaran',
        'kesanggupan-pembayaran': 'kesanggupan-pembayaran',
        'kuesioner-pelanggan': 'kuesioner-pelanggan',
        'layanan-pelanggan': 'layanan-pelanggan'
    };

    setupNavigation(urlMap);

    const initialSection = SECTION || 'ringkasan';
    loadSection(initialSection, false);
    document.querySelectorAll('.nav-item').forEach(nav => {
        nav.classList.toggle('active', nav.dataset.section === initialSection);
    });

    const menuToggle = document.getElementById('menuToggle');
    if (menuToggle) {
        menuToggle.addEventListener('click', () => {
            const sidebar = document.getElementById('sidebar');
            if (window.innerWidth <= 768) {
                sidebar.classList.toggle('open');
            } else {
                sidebar.classList.toggle('collapsed');
            }
        });
    }

    window.addEventListener('popstate', (e) => {
        // Abaikan guard state dari back-guard.js
        if (!e.state || e.state.__guard__ || !e.state.section) return;
        loadSection(e.state.section, false);
        document.querySelectorAll('.nav-item').forEach(nav => {
            nav.classList.toggle('active', nav.dataset.section === e.state.section);
        });
    });

    // Profile dropdown toggle
    const profileToggle = document.getElementById('profileToggle');
    const profileMenu = document.getElementById('profileMenu');
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

    // Avatar preview on file select
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

    // Edit Profile form submit
    const editForm = document.getElementById('editProfileForm');
    if (editForm) {
        editForm.addEventListener('submit', async (e) => {
            e.preventDefault();
            const formData = new FormData(editForm);

            try {
                const res = await fetch(`${BASE_URL}/mitra/api/update-account`, {
                    method: 'POST',
                    body: formData
                });
                const json = await res.json();

                if (json.success) {
                    closeModal('editProfileModal');
                    showMessage('Berhasil', json.message || 'Profil berhasil diperbarui!', 'success');
                    // Refresh avatar and name in topbar
                    if (json.avatar) {
                        const avatarEls = document.querySelectorAll('.profile-avatar');
                        avatarEls.forEach(el => {
                            el.src = `${BASE_URL}/public/avatar/${json.avatar}?t=${Date.now()}`;
                        });
                    }
                    if (json.nama) {
                        const nameEls = document.querySelectorAll('.profile-name');
                        nameEls.forEach(el => el.textContent = json.nama);
                    }
                } else {
                    showMessage('Gagal', 'Gagal memperbarui: ' + (json.message || 'Unknown error'), 'error');
                }
            } catch (err) {
                console.error(err);
                showMessage('Gagal', 'Terjadi kesalahan saat menyimpan profil.', 'error');
            }
        });
    }
});

function setupNavigation(urlMap) {
    document.querySelectorAll('.nav-item[data-section]').forEach(item => {
        item.addEventListener('click', (e) => {
            e.preventDefault();
            const section = item.getAttribute('data-section');
            const semanticUrl = urlMap[section] || section;

            history.pushState({ section, __selport_dashboard__: true }, '', `${BASE_URL}/mitra/${semanticUrl}`);
            loadSection(section);

            document.querySelectorAll('.nav-item').forEach(nav => nav.classList.remove('active'));
            item.classList.add('active');

            if (window.innerWidth <= 768) {
                document.getElementById('sidebar').classList.remove('open');
            }
        });
    });
}

async function loadSection(section, pushState = true) {
    currentSection = section;
    const container = document.getElementById('contentArea');

    try {
        const res = await fetch(`${BASE_URL}/mitra/load/${section}?t=${Date.now()}`);

        // Deteksi session expired: HTTP 401
        if (res.status === 401) {
            window.location.href = BASE_URL + '/login';
            return;
        }

        // Deteksi session expired: server redirect ke login
        if (res.url && res.url.includes('/login')) {
            window.location.href = BASE_URL + '/login';
            return;
        }

        const html = await res.text();

        // Deteksi jika konten adalah halaman login atau JSON sesi habis
        if (html.includes('id="loginForm"') || html.includes('"session_expired"')) {
            window.location.href = BASE_URL + '/login';
            return;
        }

        if (html.includes('Section not found')) {
            container.innerHTML = `<div class="alert alert-danger">${html}</div>`;
            return;
        }

        container.innerHTML = html;

        executeScripts(container);

        switch (section) {
            case 'ringkasan':
                loadDashboardStats();
                break;
            case 'validasi-pelanggan':
                loadValidasiCustomers();
                setupValidasiListeners();
                break;
            case 'pemberitahuan':
                loadAnnouncements();
                setupAnnouncementListeners();
                break;
            case 'pengecualian-dokumen':
                break;
            case 'pengaduan-pelanggan':
                break;
            case 'permohonan-alat':
                // Dropdown & search listeners diinit dari IIFE di permohonan-alat.html
                // Pastikan custom select terinisialisasi jika IIFE belum melakukannya
                if (typeof initAdminCustomSelects === 'function') {
                    initAdminCustomSelects();
                }
                break;
            case 'kelola-pembayaran':
                mitraLoadKelolaPembayaran();
                break;
            case 'kesanggupan-pembayaran':
                mitraLoadKesanggupan();
                break;
            case 'kuesioner-pelanggan':
                // Logic JS ada di dalam view (inline scripts)
                break;
            case 'layanan-pelanggan':
                // Logic JS ada di dalam view (inline scripts)
                break;
        }
    } catch (e) {
        console.error('Error loading section', e);
        container.innerHTML = '<div class="alert alert-danger">Gagal memuat halaman. Silakan <a href="' + BASE_URL + '/login">login kembali</a>.</div>';
    }
}

function loadAnnouncements() {
    fetch(`${BASE_URL}/mitra/api/announcements`)
        .then(r => r.json())
        .then(data => {
            window.allAnnouncementData = data.data || [];
            window.currentAnnouncementPage = 1;
            renderAnnouncementTable();
        });
}

function renderAnnouncementTable() {
    const tbody = document.querySelector('#announcementTable tbody');
    const paginationContainer = document.getElementById('announcementPagination');
    if (!tbody) return;

    const allData = window.allAnnouncementData || [];
    const perPage = window.itemsPerPage || 5;

    if (allData.length === 0) {
        tbody.innerHTML = '<tr><td colspan="4" style="text-align:center;">Tidak ada data</td></tr>';
        if (paginationContainer) paginationContainer.innerHTML = '';
        return;
    }

    const totalPages = Math.ceil(allData.length / perPage);
    window.currentAnnouncementPage = Math.min(Math.max(window.currentAnnouncementPage, 1), totalPages);

    const start = (window.currentAnnouncementPage - 1) * perPage;
    const end = start + perPage;
    const pageData = allData.slice(start, end);

    tbody.innerHTML = '';
    pageData.forEach(item => {
        const tr = document.createElement('tr');
        const date = new Date(item.created_at).toLocaleDateString('id-ID', { day: '2-digit', month: 'long', year: 'numeric' });
        const statusBadge = item.is_active == 1
            ? '<span style="color: #10b981; font-weight: 600;">Aktif</span>'
            : '<span style="color: #64748b; font-weight: 600;">Tidak Aktif</span>';

        tr.innerHTML = `
            <td>${date}</td>
            <td>${item.content.substring(0, 200)}${item.content.length > 200 ? '...' : ''}</td>
            <td>${statusBadge}</td>
            <td>
                <div style="display: flex; justify-content: center; gap: 6px;">
                    <button class="btn btn-sm btn-danger" onclick="deleteAnnouncement(${item.id})">Hapus</button>
                </div>
            </td>
        `;
        tbody.appendChild(tr);
    });

    renderPagination(window.currentAnnouncementPage, totalPages, paginationContainer, 'Announcement');
}

window.changeAnnouncementPage = function (page) {
    window.currentAnnouncementPage = page;
    renderAnnouncementTable();
};

function setupAnnouncementListeners() {
    let form = document.getElementById('announcementForm');
    if (form) {
        const newForm = form.cloneNode(true);
        form.parentNode.replaceChild(newForm, form);
        form = newForm;

        form.addEventListener('submit', (e) => {
            e.preventDefault();
            const formData = new FormData(form);
            // is_active is now a hidden input with value="true" or already in FormData

            fetch(`${BASE_URL}/mitra/api/announcements/save`, {
                method: 'POST',
                body: new URLSearchParams(formData)
            })
                .then(r => r.json())
                .then(data => {
                    if (data.success) {
                        closeModal('announcementModal');
                        showSuccessModal(data.message || 'Pemberitahuan Berhasil Disimpan');
                        loadAnnouncements();
                    } else {
                        alert('Error: ' + data.message);
                    }
                });
        });
    }
}

function showAnnouncementModal(item = null) {
    const modal = document.getElementById('announcementModal');
    const form = document.getElementById('announcementForm');

    if (item) {
        document.getElementById('announcementId').value = item.id;
        document.getElementById('announcementTitle').value = item.title;
        document.getElementById('announcementContent').value = item.content;
    } else {
        form.reset();
        document.getElementById('announcementId').value = '';
    }

    modal.style.display = 'flex';
}

function toggleAnnouncement(id, isActive) {
    fetch(`${BASE_URL}/mitra/api/announcements/toggle`, {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: `id=${id}&is_active=${isActive}`
    })
        .then(r => r.json())
        .then(data => {
            if (data.success) {
                loadAnnouncements();
            } else {
                alert('Error: ' + data.message);
            }
        });
}

function loadDashboardStats() {
    fetch(`${BASE_URL}/mitra/api/stats`)
        .then(r => r.json())
        .then(data => {
            // Validasi Pelanggan
            if (document.getElementById('stat-vp-pending')) document.getElementById('stat-vp-pending').textContent = data.vp_pending || 0;
            if (document.getElementById('stat-vp-approved')) document.getElementById('stat-vp-approved').textContent = data.vp_approved || 0;
            if (document.getElementById('stat-vp-rejected')) document.getElementById('stat-vp-rejected').textContent = data.vp_rejected || 0;

            // Permohonan Pengecualian
            if (document.getElementById('stat-pp-pending')) document.getElementById('stat-pp-pending').textContent = data.pp_pending || 0;
            if (document.getElementById('stat-pp-approved')) document.getElementById('stat-pp-approved').textContent = data.pp_approved || 0;
            if (document.getElementById('stat-pp-rejected')) document.getElementById('stat-pp-rejected').textContent = data.pp_rejected || 0;

            // Permohonan Alat
            if (document.getElementById('stat-pa-pending')) document.getElementById('stat-pa-pending').textContent = data.pa_pending || 0;
            if (document.getElementById('stat-pa-approved')) document.getElementById('stat-pa-approved').textContent = data.pa_approved || 0;
            if (document.getElementById('stat-pa-rejected')) document.getElementById('stat-pa-rejected').textContent = data.pa_rejected || 0;

            // Kelola Pembayaran
            if (document.getElementById('stat-kp-pending')) document.getElementById('stat-kp-pending').textContent = data.kp_pending || 0;
            if (document.getElementById('stat-kp-approved')) document.getElementById('stat-kp-approved').textContent = data.kp_approved || 0;
            if (document.getElementById('stat-kp-rejected')) document.getElementById('stat-kp-rejected').textContent = data.kp_rejected || 0;

            // Kesanggupan Pembayaran
            if (document.getElementById('stat-ksp-pending')) document.getElementById('stat-ksp-pending').textContent = data.ksp_pending || 0;
            if (document.getElementById('stat-ksp-approved')) document.getElementById('stat-ksp-approved').textContent = data.ksp_approved || 0;
            if (document.getElementById('stat-ksp-rejected')) document.getElementById('stat-ksp-rejected').textContent = data.ksp_rejected || 0;

            // Layanan Pelanggan
            if (document.getElementById('stat-lp-keluhan')) document.getElementById('stat-lp-keluhan').textContent = data.lp_keluhan || 0;
            if (document.getElementById('stat-lp-layanan')) document.getElementById('stat-lp-layanan').textContent = data.lp_layanan || 0;

            // Announcements & Responden
            if (document.getElementById('stat-announcements')) document.getElementById('stat-announcements').textContent = data.announcements || 0;
            if (document.getElementById('stat-responden')) document.getElementById('stat-responden').textContent = data.total_responden || 0;
        });
}


function loadValidasiCustomers(search = '', status = 'pending') {
    const url = `${BASE_URL}/mitra/api/pending-customers?search=${search}&status=${status}`;
    fetch(url)
        .then(r => r.json())
        .then(data => {
            window.allValidasiData = data.data || [];
            window.currentValidasiPage = 1;
            renderValidasiTable();
        });
}

function renderValidasiTable() {
    const tbody = document.querySelector('#validasiTable tbody');
    const paginationContainer = document.getElementById('validasiPagination');
    if (!tbody) return;

    const allData = window.allValidasiData || [];
    const perPage = window.itemsPerPage || 5;

    if (allData.length === 0) {
        tbody.innerHTML = '<tr><td colspan="7" style="text-align:center;">Tidak ada data</td></tr>';
        if (paginationContainer) paginationContainer.innerHTML = '';
        return;
    }

    const totalPages = Math.ceil(allData.length / perPage);
    window.currentValidasiPage = Math.min(Math.max(window.currentValidasiPage, 1), totalPages);

    const start = (window.currentValidasiPage - 1) * perPage;
    const end = start + perPage;
    const pageData = allData.slice(start, end);

    tbody.innerHTML = '';
    pageData.forEach(customer => {
        const tr = document.createElement('tr');

        let statusColor = '#6c757d'; // secondary
        let statusText = customer.status;

        if (customer.status === 'pending') {
            statusColor = '#f59e0b'; // warning
            statusText = 'Pending';
        } else if (customer.status === 'active') {
            statusColor = '#10b981'; // success
            statusText = 'Disetujui';
        } else if (customer.status === 'rejected') {
            statusColor = '#ef4444'; // danger
            statusText = 'Ditolak';
        }

        tr.innerHTML = `
            <td>${new Date(customer.created_at).toLocaleDateString('id-ID', { day: '2-digit', month: 'long', year: 'numeric' })}</td>
            <td>${customer.nama_lengkap}</td>
            <td>${customer.email}</td>
            <td>${customer.kategori_pelanggan || '-'}</td>
            <td><span style="color: ${statusColor}; font-weight: 500;">${statusText}</span></td>
            <td>${customer.verified_by_name || '-'}</td>
            <td>
                <div style="display: flex; justify-content: center; gap: 6px;">
                    <button class="btn btn-icon btn-info" onclick="showCustomerDetail(${customer.id})" title="Detail">
                        <i class="ri-search-line"></i>
                    </button>
                    ${customer.status === 'pending' ? `
                        <button class="btn btn-icon btn-success" onclick="validateCustomer(${customer.id}, 'approve')" title="Setujui">
                            <i class="ri-check-line"></i>
                        </button>
                        <button class="btn btn-icon btn-danger" onclick="validateCustomer(${customer.id}, 'reject')" title="Tolak">
                            <i class="ri-close-line"></i>
                        </button>
                    ` : ''}
                </div>
            </td>
        `;
        tbody.appendChild(tr);
    });

    renderPagination(window.currentValidasiPage, totalPages, paginationContainer, 'Validasi');
}

window.changeValidasiPage = function (page) {
    window.currentValidasiPage = page;
    renderValidasiTable();
};

function setupValidasiListeners() {
    const searchInput = document.getElementById('customerSearch');
    const statusFilter = document.getElementById('statusFilter');

    if (searchInput) {
        searchInput.addEventListener('input', () => {
            loadValidasiCustomers(searchInput.value, statusFilter.value);
        });
    }

    if (document.getElementById('dropdown-statusFilter')) {
        setupCustomDropdown('dropdown-statusFilter', 'statusFilter', (val) => {
            loadValidasiCustomers(searchInput ? searchInput.value : '', val);
        });
    }
}

function setupCustomDropdown(dropdownId, inputId, onChange = null) {
    const dropdown = document.getElementById(dropdownId);
    if (!dropdown) return;

    const input = document.getElementById(inputId);
    const trigger = dropdown.querySelector('.select-trigger');
    const optionsContainer = dropdown.querySelector('.select-options');
    const selectedText = dropdown.querySelector('.selected-text');

    trigger.addEventListener('click', (e) => {
        document.querySelectorAll('.custom-select, .admin-custom-select').forEach(d => {
            if (d !== dropdown) d.classList.remove('open');
        });
        dropdown.classList.toggle('open');
        e.stopPropagation();
    });

    optionsContainer.addEventListener('click', (e) => {
        const option = e.target.closest('.option');
        if (!option) return;

        const val = option.dataset.value;
        if (input) input.value = val;
        selectedText.textContent = option.textContent;

        optionsContainer.querySelectorAll('.option').forEach(o => o.classList.remove('selected'));
        option.classList.add('selected');

        dropdown.classList.remove('open');

        if (input) input.dispatchEvent(new Event('change', { bubbles: true }));

        if (onChange) onChange(val);
    });

    document.addEventListener('click', (e) => {
        if (!dropdown.contains(e.target)) {
            dropdown.classList.remove('open');
        }
    });
}

function initAdminCustomSelects(onChange) {
    document.querySelectorAll('.admin-custom-select:not(.initialized)').forEach(dropdown => {
        dropdown.classList.add('initialized');
        const trigger = dropdown.querySelector('.select-trigger');
        const options = dropdown.querySelectorAll('.option');
        const hiddenInput = dropdown.querySelector('input[type="hidden"]');
        const selectedText = dropdown.querySelector('.selected-text');

        // Toggle open/close
        trigger.addEventListener('click', (e) => {
            e.stopPropagation();
            // Close all other dropdowns first
            document.querySelectorAll('.admin-custom-select.open, .custom-select.open').forEach(d => {
                if (d !== dropdown) d.classList.remove('open');
            });
            dropdown.classList.toggle('open');
        });

        // Select option
        options.forEach(opt => {
            opt.addEventListener('click', () => {
                const val = opt.dataset.value;
                const text = opt.textContent;

                // Update selected state
                options.forEach(o => o.classList.remove('selected'));
                opt.classList.add('selected');

                selectedText.textContent = text;
                if (hiddenInput) {
                    hiddenInput.value = val;
                    hiddenInput.dispatchEvent(new Event('change', { bubbles: true }));
                }

                dropdown.classList.remove('open');

                if (typeof onChange === 'function') onChange(val, hiddenInput);
            });
        });
    });

    if (!window.customSelectsGlobalBound) {
        window.customSelectsGlobalBound = true;
        // Close dropdowns when clicking outside
        document.addEventListener('click', () => {
            document.querySelectorAll('.admin-custom-select.open, .custom-select.open').forEach(d => {
                d.classList.remove('open');
            });
        });
    }
}

function showCustomerDetail(userId) {
    const statusFilter = document.getElementById('statusFilter');
    const currentStatus = statusFilter ? statusFilter.value : 'pending';

    fetch(`${BASE_URL}/mitra/api/pending-customers?search=&status=${currentStatus}`)
        .then(r => r.json())
        .then(data => {
            const customer = data.data.find(c => c.id == userId);
            if (!customer) return;

            let html = `
                <div class="detail-grid">
                    <div class="detail-section">
                        <h4>Data Akun</h4>
                        <div class="detail-item">
                            <span class="detail-label">Username</span>
                            <span class="detail-value">${customer.username}</span>
                        </div>
                        <div class="detail-item">
                            <span class="detail-label">Email</span>
                            <span class="detail-value">${customer.email}</span>
                        </div>
                        <div class="detail-item">
                            <span class="detail-label">Kategori</span>
                            <span class="detail-value">${customer.kategori_pelanggan || '-'}</span>
                        </div>
                         <div class="detail-item">
                            <span class="detail-label">Status</span>
                            <span class="badge ${customer.status === 'pending' ? 'badge-warning' : (customer.status === 'active' ? 'badge-success' : 'badge-danger')}">${customer.status}</span>
                        </div>
                        ${customer.status === 'rejected' ? `
                        <div class="detail-item" style="margin-top: 15px; background: #fff1f2; padding: 10px; border-radius: 6px; border-left: 3px solid #ef4444;">
                            <span class="detail-label" style="color: #991b1b; font-weight: 700;">Alasan Penolakan</span>
                            <span class="detail-value" style="color: #7f1d1d; font-size: 0.9rem;">${customer.rejection_reason || 'Tidak ada alasan spesifik.'}</span>
                        </div>
                        ` : ''}
                    </div>

                    <div class="detail-section">
                        <h4>Data Pribadi</h4>
                        <div class="detail-item">
                            <span class="detail-label">Nama Lengkap</span>
                            <span class="detail-value">${customer.nama_lengkap}</span>
                        </div>
                        <div class="detail-item">
                            <span class="detail-label">Jenis Kelamin</span>
                            <span class="detail-value">${customer.jenis_kelamin}</span>
                        </div>
                        <div class="detail-item">
                            <span class="detail-label">No. WhatsApp</span>
                            <span class="detail-value">${customer.no_whatsapp}</span>
                        </div>
                    </div>

                    <div class="detail-section full-width">
                        <h4>Alamat Domisili</h4>
                        <div class="detail-item">
                            <span class="detail-label">Alamat Lengkap</span>
                            <span class="detail-value">${customer.alamat_tinggal}</span>
                        </div>
                        <div class="detail-addr-grid">
                            <div class="detail-item">
                                <span class="detail-label">Provinsi</span>
                                <span class="detail-value">${customer.provinsi_tinggal}</span>
                            </div>
                            <div class="detail-item">
                                <span class="detail-label">Kabupaten/Kota</span>
                                <span class="detail-value">${customer.kabupaten_tinggal}</span>
                            </div>
                            <div class="detail-item">
                                <span class="detail-label">Kecamatan</span>
                                <span class="detail-value">${customer.kecamatan_tinggal}</span>
                            </div>
                            <div class="detail-item">
                                <span class="detail-label">Kode Pos</span>
                                <span class="detail-value">${customer.kodepos_tinggal}</span>
                            </div>
                        </div>
                    </div>
            `;

            if (customer.nama_perusahaan) {
                html += `
                    <div class="detail-section full-width">
                        <h4>Data Perusahaan</h4>
                        <div class="detail-addr-grid">
                            <div class="detail-item">
                                <span class="detail-label">Nama Perusahaan</span>
                                <span class="detail-value">${customer.nama_perusahaan}</span>
                            </div>
                            <div class="detail-item">
                                <span class="detail-label">Email Perusahaan</span>
                                <span class="detail-value">${customer.email_perusahaan}</span>
                            </div>
                            <div class="detail-item">
                                <span class="detail-label">Telepon</span>
                                <span class="detail-value">${customer.telp_perusahaan}</span>
                            </div>
                            <div class="detail-item">
                                <span class="detail-label">WhatsApp Perusahaan</span>
                                <span class="detail-value">${customer.wa_perusahaan}</span>
                            </div>
                        </div>
                        <div class="detail-item" style="margin-top: 10px;">
                            <span class="detail-label">Alamat Perusahaan</span>
                            <span class="detail-value">${customer.alamat_perusahaan}</span>
                        </div>
                    </div>
                `;
            }

            html += `</div>`; // Close grid

            document.getElementById('detailContent').innerHTML = html;
            document.getElementById('detailModal').style.display = 'flex';
        });
}

function validateCustomer(userId, action) {
    if (action === 'approve') {
        showApprovalModal(userId);
    } else if (action === 'reject') {
        showRejectConfirmationModal().then(confirmed => {
            if (!confirmed) return;

            showRejectReasonModal(userId);
        });
    }
}

function showApprovalModal(userId) {
    const statusFilter = document.getElementById('statusFilter');
    const currentStatus = statusFilter ? statusFilter.value : 'pending';

    fetch(`${BASE_URL}/mitra/api/pending-customers?search=&status=${currentStatus}`)
        .then(r => r.json())
        .then(data => {
            const customer = data.data.find(c => c.id == userId);
            if (!customer) return;

            document.getElementById('approvalUserId').value = userId;

            let html = `
                <div class="detail-grid">
                    <div class="detail-section">
                        <h4>Data Akun</h4>
                        <div class="detail-item">
                            <span class="detail-label">Username</span>
                            <span class="detail-value">${customer.username}</span>
                        </div>
                        <div class="detail-item">
                            <span class="detail-label">Email</span>
                            <span class="detail-value">${customer.email}</span>
                        </div>
                        <div class="detail-item">
                            <span class="detail-label">Kategori</span>
                            <span class="detail-value">${customer.kategori_pelanggan || '-'}</span>
                        </div>
                    </div>

                    <div class="detail-section">
                        <h4>Data Pribadi</h4>
                        <div class="detail-item">
                            <span class="detail-label">Nama Lengkap</span>
                            <span class="detail-value">${customer.nama_lengkap}</span>
                        </div>
                        <div class="detail-item">
                            <span class="detail-label">Jenis Kelamin</span>
                            <span class="detail-value">${customer.jenis_kelamin || '-'}</span>
                        </div>
                        <div class="detail-item">
                            <span class="detail-label">No. WhatsApp</span>
                            <span class="detail-value">${customer.no_whatsapp || '-'}</span>
                        </div>
                    </div>

                    <div class="detail-section full-width">
                        <h4>Alamat Domisili</h4>
                        <div class="detail-item">
                            <span class="detail-label">Alamat Lengkap</span>
                            <span class="detail-value">${customer.alamat_tinggal || '-'}</span>
                        </div>
                        <div class="detail-addr-grid">
                            <div class="detail-item">
                                <span class="detail-label">Provinsi</span>
                                <span class="detail-value">${customer.provinsi_tinggal || '-'}</span>
                            </div>
                            <div class="detail-item">
                                <span class="detail-label">Kabupaten/Kota</span>
                                <span class="detail-value">${customer.kabupaten_tinggal || '-'}</span>
                            </div>
                            <div class="detail-item">
                                <span class="detail-label">Kecamatan</span>
                                <span class="detail-value">${customer.kecamatan_tinggal || '-'}</span>
                            </div>
                            <div class="detail-item">
                                <span class="detail-label">Kode Pos</span>
                                <span class="detail-value">${customer.kodepos_tinggal || '-'}</span>
                            </div>
                        </div>
                    </div>
            `;

            if (customer.nama_perusahaan) {
                html += `
                    <div class="detail-section full-width">
                        <h4>Data Perusahaan (Input User)</h4>
                        <div class="detail-addr-grid">
                            <div class="detail-item">
                                <span class="detail-label">Nama Perusahaan</span>
                                <span class="detail-value">${customer.nama_perusahaan}</span>
                            </div>
                            <div class="detail-item">
                                <span class="detail-label">Email Perusahaan</span>
                                <span class="detail-value">${customer.email_perusahaan || '-'}</span>
                            </div>
                            <div class="detail-item">
                                <span class="detail-label">Telepon</span>
                                <span class="detail-value">${customer.telp_perusahaan || '-'}</span>
                            </div>
                            <div class="detail-item">
                                <span class="detail-label">WhatsApp Perusahaan</span>
                                <span class="detail-value">${customer.wa_perusahaan || '-'}</span>
                            </div>
                        </div>
                        <div class="detail-item" style="margin-top: 10px;">
                            <span class="detail-label">Alamat Perusahaan</span>
                            <span class="detail-value">${customer.alamat_perusahaan || '-'}</span>
                        </div>
                    </div>
                `;
            }

            html += `</div>`;

            document.getElementById('approvalContent').innerHTML = html;

            const searchInput = document.getElementById('silCustomerSearch');
            const hiddenInput = document.getElementById('selectedIdCustomer');
            const resultsDiv = document.getElementById('silCustomerResults');

            if (searchInput) {
                searchInput.value = '';
            }
            if (hiddenInput) hiddenInput.value = '';
            if (resultsDiv) {
                resultsDiv.style.display = 'none';
                resultsDiv.innerHTML = '';
            }

            const detailsDiv = document.getElementById('silCompanyDetails');
            if (detailsDiv) {
                detailsDiv.style.display = 'none';
                document.getElementById('detailCodex').textContent = '';
                document.getElementById('detailNama').textContent = '';
                document.getElementById('detailAlamat').textContent = '';
                document.getElementById('detailEmail').textContent = '';
                document.getElementById('detailContact').textContent = '';
            }

            document.getElementById('approvalModal').style.display = 'flex';
        });
}

function setupAutocompleteListeners() {
    const searchInput = document.getElementById('silCustomerSearch');
    const resultsDiv = document.getElementById('silCustomerResults');
    const hiddenInput = document.getElementById('selectedIdCustomer');
    let timeoutId;

    if (!searchInput) return;

    searchInput.addEventListener('input', function () {
        const query = this.value.trim();

        if (hiddenInput.value) hiddenInput.value = ''; // Clear ID if user types

        clearTimeout(timeoutId);

        if (query.length < 2) {
            resultsDiv.style.display = 'none';
            return;
        }

        resultsDiv.style.display = 'block';
        resultsDiv.innerHTML = '<div class="autocomplete-loading">Mencari...</div>';

        timeoutId = setTimeout(() => {
            fetch(`${BASE_URL}/mitra/api/sil-customers?search=${encodeURIComponent(query)}`)
                .then(r => r.json())
                .then(data => {
                    resultsDiv.innerHTML = '';
                    if (data.success && data.data.length > 0) {
                        let exactMatchFound = false;
                        data.data.forEach(item => {
                            const codexText = item.kodex_perusahaan ? ` | Codex: <strong>${item.kodex_perusahaan}</strong>` : '';
                            const alamatText = item.alamatcustomer ? `<div style="font-size: 11px; color: #64748b; margin-top: 4px; line-height: 1.3;">${item.alamatcustomer}</div>` : '';
                            const div = document.createElement('div');
                            div.className = 'autocomplete-item';
                            div.style.padding = '10px 12px';
                            div.style.borderBottom = '1px solid #f1f5f9';
                            div.innerHTML = `
                                <div style="color: #0f172a; font-size: 13px;">
                                    <strong>${item.nmcustomer}</strong><span style="color: #0ea5e9;">${codexText}</span>
                                </div>
                                ${alamatText}
                            `;

                            const selectItem = function () {
                                searchInput.value = item.nmcustomer;
                                hiddenInput.value = item.id_customer;
                                resultsDiv.style.display = 'none';

                                const detailsDiv = document.getElementById('silCompanyDetails');
                                if (detailsDiv) {
                                    detailsDiv.style.display = 'block';
                                    document.getElementById('detailCodex').textContent = item.kodex_perusahaan || '-';
                                    document.getElementById('detailNama').textContent = item.nmcustomer || '-';
                                    document.getElementById('detailAlamat').textContent = item.alamatcustomer || '-';
                                    document.getElementById('detailEmail').textContent = item.emailcustomer || '-';
                                    document.getElementById('detailContact').textContent = item.contactperson || '-';
                                }
                            };

                            div.addEventListener('click', selectItem);
                            resultsDiv.appendChild(div);

                            const safeStr = (str) => String(str || '').trim().toLowerCase();
                            if (safeStr(query) === safeStr(item.nmcustomer) || (item.kodex_perusahaan && safeStr(query) === safeStr(item.kodex_perusahaan))) {
                                selectItem();
                                exactMatchFound = true;
                            }
                        });
                    } else {
                        resultsDiv.innerHTML = '<div class="autocomplete-loading">Tidak ada perusahaan ditemukan. Coba kata kunci lain.</div>';
                    }
                })
                .catch(err => {
                    console.error(err);
                    resultsDiv.innerHTML = '<div class="autocomplete-loading">Gagal memuat data</div>';
                });
        }, 300);
    });

    document.addEventListener('click', function (e) {
        if (resultsDiv && searchInput && !searchInput.contains(e.target) && !resultsDiv.contains(e.target)) {
            resultsDiv.style.display = 'none';
        }
    });
}

function confirmApproval() {
    const userId = document.getElementById('approvalUserId').value;
    const idCustomer = document.getElementById('selectedIdCustomer').value;

    if (!idCustomer) {
        alert('Silakan cari dan pilih perusahaan terlebih dahulu!');
        return;
    }

    showConfirmationModal(
        'Konfirmasi Persetujuan',
        'Apakah Anda yakin ingin menyetujui pelanggan ini?'
    ).then(confirmed => {
        if (!confirmed) return;

        // Close all modals first
        closeModal('approvalModal');
        closeModal('confirmationModal');

        // Show loading indicator
        showLoadingModal('Memproses validasi pelanggan...');

        fetch(`${BASE_URL}/mitra/api/validate-customer`, {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: `user_id=${userId}&action=approve&id_customer=${idCustomer}`
        })
            .then(r => r.json())
            .then(data => {
                // Hide loading with a small delay to ensure it's visible
                setTimeout(() => {
                    closeLoadingModal();

                    if (data.success) {
                        if (typeof Swal !== 'undefined') {
                            Swal.fire({
                                icon: 'success',
                                title: 'Berhasil',
                                text: data.message || 'Pelanggan berhasil disetujui',
                                confirmButtonColor: '#10b981'
                            }).then(() => {
                                loadValidasiCustomers();
                                loadDashboardStats();
                            });
                        } else {
                            // Show success modal after loading is closed
                            setTimeout(() => {
                                showSuccessModal(data.message || 'Pelanggan berhasil disetujui');
                            }, 200);

                            // Reload data
                            setTimeout(() => {
                                loadValidasiCustomers();
                                loadDashboardStats();
                            }, 500);
                        }
                    } else {
                        alert('Error: ' + data.message);
                    }
                }, 500);
            })
            .catch(error => {
                closeLoadingModal();
                alert('Terjadi kesalahan: ' + error.message);
            });
    });
}

let confirmationResolve = null;

function showConfirmationModal(title, message) {
    return new Promise((resolve) => {
        confirmationResolve = resolve;

        document.getElementById('confirmationTitle').textContent = title;
        document.getElementById('confirmationMessage').textContent = message;
        document.getElementById('confirmationModal').style.display = 'flex';
    });
}

function closeConfirmationModal(confirmed) {
    document.getElementById('confirmationModal').style.display = 'none';

    if (confirmationResolve) {
        confirmationResolve(confirmed);
        confirmationResolve = null;
    }
}

let rejectConfirmationResolve = null;
let currentRejectUserId = null;

function showRejectConfirmationModal(message = 'Apakah yakin ingin menghapus data pelanggan ini?') {
    return new Promise((resolve) => {
        rejectConfirmationResolve = resolve;
        document.getElementById('rejectConfirmationMessage').textContent = message;
        document.getElementById('rejectConfirmationModal').style.display = 'flex';
    });
}

let successModalCallback = null;

function showSuccessModal(message, callback = null) {
    const successModal = document.getElementById('successModal');
    if (!successModal) {
        console.error('Success modal not found!');
        alert(message);
        return;
    }

    document.getElementById('successMessage').textContent = message;
    successModalCallback = callback;

    // Ensure modal is visible with proper z-index
    successModal.style.display = 'flex';
    successModal.style.zIndex = '3000';
}

function showMessage(title, message, type = 'success', callback = null) {
    if (type === 'success') {
        showSuccessModal(message, callback);
    } else {
        alert(title + ': ' + message);
        if (callback) callback();
    }
}

function closeSuccessModal() {
    document.getElementById('successModal').style.display = 'none';
    if (successModalCallback) {
        successModalCallback();
        successModalCallback = null;
    }
}

function deleteAnnouncement(id) {
    showRejectConfirmationModal('Apakah Anda yakin ingin menghapus pengumuman ini?').then(confirmed => {
        if (!confirmed) return;

        fetch(`${BASE_URL}/mitra/api/announcements/delete`, {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: `id=${id}`
        })
            .then(r => r.json())
            .then(data => {
                if (data.success) {
                    showSuccessModal(data.message || 'Pemberitahuan Berhasil Dihapus');
                    loadAnnouncements();
                } else {
                    alert('Error: ' + data.message);
                }
            });
    });
}

function closeRejectConfirmationModal(confirmed) {
    document.getElementById('rejectConfirmationModal').style.display = 'none';
    if (rejectConfirmationResolve) {
        rejectConfirmationResolve(confirmed);
        rejectConfirmationResolve = null;
    }
}

function showRejectReasonModal(userId) {
    currentRejectUserId = userId;
    document.getElementById('rejectionReasonInput').value = ''; // Reset input
    document.getElementById('rejectReasonModal').style.display = 'flex';
}

function closeRejectReasonModal() {
    document.getElementById('rejectReasonModal').style.display = 'none';
    currentRejectUserId = null;
}

function submitRejection() {
    const reason = document.getElementById('rejectionReasonInput').value;

    if (!reason.trim()) {
        alert('Mohon isi alasan penolakan.');
        return;
    }

    if (!currentRejectUserId) return;

    fetch(`${BASE_URL}/mitra/api/validate-customer`, {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: `user_id=${currentRejectUserId}&action=reject&reason=${encodeURIComponent(reason)}`
    })
        .then(r => r.json())
        .then(data => {
            if (data.success) {
                showSuccessModal(data.message);
                closeRejectReasonModal();
                loadValidasiCustomers();
                loadDashboardStats();
            } else {
                alert('Error: ' + data.message);
            }
        })
        .catch(err => {
            console.error(err);
            alert('Terjadi kesalahan sistem');
        });
}

function closeModal(modalId) {
    document.getElementById(modalId).style.display = 'none';
}

window.showEditProfileModal = function () {
    document.getElementById('profileMenu').classList.remove('show');
    const avatarSrc = MITRA_AVATAR
        ? `${BASE_URL}/public/avatar/${MITRA_AVATAR}?t=${Date.now()}`
        : `${BASE_URL}/public/avatar/avatar.png`;
    document.getElementById('editProfileAvatar').src = avatarSrc;
    document.getElementById('editNamaLengkap').value = MITRA_NAME || '';
    document.getElementById('editUsername').value = MITRA_USERNAME || '';
    document.getElementById('editPassword').value = '';
    document.getElementById('editProfileModal').style.display = 'flex';
};

window.onclick = function (event) {
    if (event.target.classList.contains('modal')) {
        event.target.style.display = 'none';
    }
}

async function updateUsername(e) {
    e.preventDefault();
    const formData = new FormData(e.target);

    try {
        const res = await fetch(`${BASE_URL}/mitra/api/update-account`, {
            method: 'POST',
            body: formData
        });
        const data = await res.json();

        if (data.success) {
            showSuccessModal(data.message, () => location.reload());
        } else {
            alert(data.message);
        }
    } catch (err) {
        console.error('Error updating username', err);
        alert('Gagal memperbarui username');
    }
}

async function updatePassword(e) {
    e.preventDefault();
    const formData = new FormData(e.target);

    try {
        const res = await fetch(`${BASE_URL}/mitra/api/update-account`, {
            method: 'POST',
            body: formData
        });
        const data = await res.json();

        if (data.success) {
            showSuccessModal(data.message, () => location.reload());
        } else {
            alert(data.message);
        }
    } catch (err) {
        console.error('Error updating password', err);
        alert('Gagal memperbarui password');
    }
}

function executeScripts(container) {
    // Process <script> tags
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

    // Process <link rel="stylesheet"> tags (not loaded by browser when injected via innerHTML)
    const links = container.querySelectorAll('link[rel="stylesheet"]');
    links.forEach(link => {
        const href = link.getAttribute('href');
        if (!href) return;
        // Check if this stylesheet is already loaded to avoid duplicates
        const alreadyLoaded = Array.from(document.querySelectorAll('link[rel="stylesheet"]'))
            .some(existingLink => existingLink.getAttribute('href') === href);
        if (!alreadyLoaded) {
            const newLink = document.createElement('link');
            newLink.rel = 'stylesheet';
            newLink.href = href;
            document.head.appendChild(newLink);
        }
        // Remove the in-content link tag to keep DOM clean
        link.remove();
    });
}

document.addEventListener('DOMContentLoaded', setupAutocompleteListeners);


// =========================================================
// SESSION-SAFE FETCH HELPER
// =========================================================
function safeFetchJson(url, options) {
    return fetch(url, options).then(res => {
        // Jika server redirect ke login
        if (res.url && res.url.includes('/login')) {
            window.location.href = BASE_URL + '/login';
            return null;
        }
        // Jika server mengembalikan 401 (session expired)
        if (res.status === 401) {
            window.location.href = BASE_URL + '/login';
            return null;
        }
        // Jika server error (5xx), jangan logout — kembalikan null saja
        if (res.status >= 500) {
            console.error('Server error:', res.status, url);
            return null;
        }
        const ct = res.headers.get('content-type') || '';
        if (!ct.includes('application/json')) {
            // Bukan JSON dan bukan error server → kemungkinan redirect login
            window.location.href = BASE_URL + '/login';
            return null;
        }
        return res.json().then(data => {
            if (data && data.session_expired) {
                window.location.href = BASE_URL + '/login';
                return null;
            }
            return data;
        });
    }).catch(err => {
        console.error('Fetch error:', err, url);
        return null;
    });
}


// =========================================================
// HELPER
// =========================================================
function _escHtml(str) {
    if (!str) return '';
    return String(str).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
}

window.openModal = function (id) { document.getElementById(id).style.display = 'flex'; };

// =========================================================
// LOADING MODAL
// =========================================================
function showLoadingModal(message = 'Memproses...') {
    let loadingModal = document.getElementById('loadingModal');

    if (!loadingModal) {
        // Create loading modal if it doesn't exist
        loadingModal = document.createElement('div');
        loadingModal.id = 'loadingModal';
        loadingModal.className = 'modal';
        loadingModal.style.zIndex = '2500';
        loadingModal.innerHTML = `
            <div class="modal-content confirmation-modal-content" style="max-width: 400px;">
                <div class="confirmation-icon">
                    <div class="spinner"></div>
                </div>
                <h3 id="loadingMessage">${message}</h3>
                <p style="color: #64748b; font-size: 14px;">Mohon tunggu sebentar...</p>
            </div>
        `;
        document.body.appendChild(loadingModal);

        // Add spinner CSS if not exists
        if (!document.getElementById('spinnerStyle')) {
            const style = document.createElement('style');
            style.id = 'spinnerStyle';
            style.textContent = `
                .spinner {
                    border: 4px solid #f3f4f6;
                    border-top: 4px solid #3b82f6;
                    border-radius: 50%;
                    width: 48px;
                    height: 48px;
                    animation: spin 1s linear infinite;
                    margin: 0 auto;
                }
                @keyframes spin {
                    0% { transform: rotate(0deg); }
                    100% { transform: rotate(360deg); }
                }
            `;
            document.head.appendChild(style);
        }
    } else {
        document.getElementById('loadingMessage').textContent = message;
    }

    loadingModal.style.display = 'flex';
}

function closeLoadingModal() {
    const loadingModal = document.getElementById('loadingModal');
    if (loadingModal) {
        loadingModal.style.display = 'none';
    }
}

// =========================================================
// MITRA - KELOLA PEMBAYARAN (View Only)
// =========================================================
let _mitraPayData = [];
let _mitraPayPage = 1;
let _mitraPaySize = 5;

function mitraLoadKelolaPembayaran() {
    const search = (document.getElementById('mitra-paymentsSearch') || {}).value || '';
    const status = (document.getElementById('mitra-paymentsFilter') || {}).value || 'all';

    safeFetchJson(`${BASE_URL}/mitra/api/pembayaran?search=${encodeURIComponent(search)}&status=${encodeURIComponent(status)}`)
        .then(data => {
            if (!data) return;
            _mitraPayData = data.data || [];
            _mitraPayPage = 1;
            const sizeSelPay = document.getElementById('mitra-paymentsPageSize');
            _mitraPaySize = sizeSelPay ? (sizeSelPay.value === 'all' ? 99999 : parseInt(sizeSelPay.value)) : 5;
            mitraRenderPayTable();
        });

    // bind events once
    const searchEl = document.getElementById('mitra-paymentsSearch');
    if (searchEl && !searchEl.dataset.bound) {
        searchEl.dataset.bound = '1';
        searchEl.addEventListener('input', () => mitraLoadKelolaPembayaran());
    }
    const sizeEl = document.getElementById('mitra-paymentsPageSize');
    if (sizeEl && !sizeEl.dataset.bound) {
        sizeEl.dataset.bound = '1';
        sizeEl.addEventListener('change', () => {
            _mitraPaySize = sizeEl.value === 'all' ? 99999 : parseInt(sizeEl.value);
            _mitraPayPage = 1;
            mitraRenderPayTable();
        });
    }
    if (typeof initAdminCustomSelects === 'function') {
        initAdminCustomSelects((val, inp) => {
            if (inp && inp.id === 'mitra-paymentsFilter') mitraLoadKelolaPembayaran();
        });
    }
}

function mitraRenderPayTable() {
    const tbody = document.getElementById('mitra-paymentsBody');
    const startEl = document.getElementById('mitra-paymentsStart');
    const endEl = document.getElementById('mitra-paymentsEnd');
    const totalEl = document.getElementById('mitra-paymentsTotal');
    const pagEl = document.getElementById('mitra-paymentsPagination');

    if (!tbody) return;

    const total = _mitraPayData.length;
    const size = _mitraPaySize;
    const page = _mitraPayPage;
    const start = size === 99999 ? 0 : (page - 1) * size;
    const slice = _mitraPayData.slice(start, size === 99999 ? total : start + size);

    if (startEl) startEl.textContent = total === 0 ? 0 : start + 1;
    if (endEl) endEl.textContent = start + slice.length;
    if (totalEl) totalEl.textContent = total;

    const statusBadge = (s, row) => {
        const label = (row && row.ui_status_label) ? row.ui_status_label : s;
        let color = '#64748b';
        if (s === 'Disetujui') color = '#10b981';
        else if (s === 'Perbaiki') color = '#ef4444';
        else if (s === 'Pending') color = '#f59e0b';
        return `<span style="color:${color};font-weight:600;">${_escHtml(label)}</span>`;
    };

    if (slice.length === 0) {
        tbody.innerHTML = '<tr><td colspan="6" style="text-align:center;padding:30px;color:#858796;">Tidak ada data.</td></tr>';
    } else {
        tbody.innerHTML = slice.map(row => `
            <tr>
                <td>${_escHtml(row.tanggal_upload_formatted || row.created_at || '-')}</td>
                <td>${_escHtml(row.nama_perusahaan || '-')}</td>
                <td>${_escHtml(row.keterangan || '-')}</td>
                <td>${_escHtml(row.catatan || '-')}</td>
                <td>${_escHtml(row.dikelola_oleh || '-')}</td>
                <td style="text-align:center;">${statusBadge(row.status, row)}</td>
            </tr>`).join('');
    }

    const totalPages = Math.ceil(total / (size === 99999 ? total || 1 : size));
    if (pagEl) {
        renderPagination(page, totalPages, pagEl, 'KelolaPembayaran');
    }
}

window.changeKelolaPembayaranPage = function(page) {
    _mitraPayPage = page;
    mitraRenderPayTable();
};
// =========================================================
// MITRA - KESANGGUPAN PEMBAYARAN (View Only)
// =========================================================
let _mitraKesData = [];
let _mitraKesPage = 1;
let _mitraKesSize = 5;

function mitraLoadKesanggupan() {
    const search = (document.getElementById('mitra-kesanggupanSearch') || {}).value || '';
    const status = (document.getElementById('mitra-kesanggupanFilter') || {}).value || 'all';

    // Jalankan kedua API secara PARALEL
    Promise.all([
        safeFetchJson(`${BASE_URL}/mitra/api/kesanggupan/uploads?search=${encodeURIComponent(search)}&status=${encodeURIComponent(status)}`),
        safeFetchJson(`${BASE_URL}/mitra/api/kesanggupan-pembayaran`)
    ]).then(([data, tpl]) => {
        // Render tabel data
        if (data) {
            _mitraKesData = data.data || [];
            _mitraKesPage = 1;
            const sizeSelKes = document.getElementById('mitra-kesanggupanPageSize');
            _mitraKesSize = sizeSelKes ? (sizeSelKes.value === 'all' ? 99999 : parseInt(sizeSelKes.value)) : 5;
            mitraRenderKesTable();
        }

        // Render template aktif
        const cont = document.getElementById('mitra-template-aktif-container');
        if (cont && tpl) {
            if (tpl.data && tpl.data.file_name) {
                cont.innerHTML = `
                    <div style="display:flex;align-items:center;gap:10px;">
                        <i class="ri-file-word-line" style="font-size:1.5rem;color:#4e73df;"></i>
                        <div>
                            <div style="font-weight:600;font-size:0.85rem;color:#374151;">${_escHtml(tpl.data.file_name)}</div>
                            <div style="font-size:0.75rem;color:#94a3b8;margin-top:2px;">
                                Diupload: ${_escHtml(tpl.data.uploaded_at || '-')}
                            </div>
                        </div>
                    </div>`;
            } else {
                cont.innerHTML = '<p style="color:#94a3b8;margin:0;font-size:0.85rem;">Belum ada template aktif.</p>';
            }
        }
    });

    const searchEl = document.getElementById('mitra-kesanggupanSearch');
    if (searchEl && !searchEl.dataset.bound) {
        searchEl.dataset.bound = '1';
        searchEl.addEventListener('input', () => mitraLoadKesanggupan());
    }
    const sizeEl = document.getElementById('mitra-kesanggupanPageSize');
    if (sizeEl && !sizeEl.dataset.bound) {
        sizeEl.dataset.bound = '1';
        sizeEl.addEventListener('change', () => {
            _mitraKesSize = sizeEl.value === 'all' ? 99999 : parseInt(sizeEl.value);
            _mitraKesPage = 1;
            mitraRenderKesTable();
        });
    }
    if (typeof initAdminCustomSelects === 'function') {
        initAdminCustomSelects((val, inp) => {
            if (inp && inp.id === 'mitra-kesanggupanFilter') mitraLoadKesanggupan();
        });
    }
}

function mitraRenderKesTable() {
    const tbody = document.getElementById('mitra-kesanggupanBody');
    const startEl = document.getElementById('mitra-kesanggupanStart');
    const endEl = document.getElementById('mitra-kesanggupanEnd');
    const totalEl = document.getElementById('mitra-kesanggupanTotal');
    const pagEl = document.getElementById('mitra-kesanggupanPagination');

    if (!tbody) return;

    const total = _mitraKesData.length;
    const size = _mitraKesSize;
    const page = _mitraKesPage;
    const start = size === 99999 ? 0 : (page - 1) * size;
    const slice = _mitraKesData.slice(start, size === 99999 ? total : start + size);

    if (startEl) startEl.textContent = total === 0 ? 0 : start + 1;
    if (endEl) endEl.textContent = start + slice.length;
    if (totalEl) totalEl.textContent = total;

    const statusBadge = (s, label) => {
        const colorMap = {
            'Disetujui': '#10b981',
            'Perbaiki': '#ef4444',
            'Pending': '#f59e0b',
        };
        const color = colorMap[s] || '#64748b';
        const text = label || s;
        return `<span style="color:${color};font-weight:600;">${_escHtml(text)}</span>`;
    };

    if (slice.length === 0) {
        tbody.innerHTML = '<tr><td colspan="6" style="text-align:center;padding:30px;color:#858796;">Tidak ada data.</td></tr>';
    } else {
        tbody.innerHTML = slice.map(row => `
            <tr>
                <td>${_escHtml(row.created_at_formatted || row.created_at || '-')}</td>
                <td>${_escHtml(row.nama_perusahaan || '-')}</td>
                <td>${_escHtml(row.keterangan || '-')}</td>
                <td>${_escHtml(row.note || '-')}</td>
                <td>${_escHtml(row.dikelola_oleh || '-')}</td>
                <td style="text-align:center;">${statusBadge(row.ui_status || row.status, row.ui_status_label)}</td>
            </tr>`).join('');
    }

    const totalPages = Math.ceil(total / (size === 99999 ? total || 1 : size));
    if (pagEl) {
        renderPagination(page, totalPages, pagEl, 'Kesanggupan');
    }
}

window.changeKesanggupanPage = function(page) {
    _mitraKesPage = page;
    mitraRenderKesTable();
};
