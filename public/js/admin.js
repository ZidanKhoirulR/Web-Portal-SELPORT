document.addEventListener('DOMContentLoaded', () => {
    const urlMap = {
        'ringkasan': 'dashboard',
        'riwayat-tracking': 'trackinghistory',
        'blokir-ip': 'ipblocking',
        'data-pelanggan': 'customerdata',
        'kelola-pegawai': 'kelolapegawai',
        'perubahan-profil': 'profilechanges'
    };

    const sectionMap = Object.fromEntries(Object.entries(urlMap).map(([k, v]) => [v, k]));

    // Pagination Globals
    window.itemsPerPage = 5;
    window.currentTrackingPage = 1;
    window.currentTrackingData = [];
    window.currentIPPage = 1;
    window.currentIPData = [];
    window.currentChangesPage = 1;
    window.currentChangesData = [];
    window.currentCustomerPage = 1;
    window.currentCustomerData = [];
    window.currentPegawaiPage = 1;
    window.currentPegawaiData = [];
    window.pegawaiItemsPerPage = 5;

    const navItems = document.querySelectorAll('.nav-item[data-section]');
    navItems.forEach(item => {
        item.addEventListener('click', (e) => {
            e.preventDefault();
            const section = item.dataset.section;
            const semanticUrl = urlMap[section] || section;

            history.pushState({ section, __selport_dashboard__: true }, '', `${BASE_URL}/admin/${semanticUrl}`);
            loadSection(section);

            navItems.forEach(nav => nav.classList.remove('active'));
            item.classList.add('active');

            if (window.innerWidth <= 768) {
                document.getElementById('sidebar').classList.remove('open');
            }
        });
    });

    window.addEventListener('popstate', (e) => {
        // Abaikan guard state dari back-guard.js
        if (!e.state || e.state.__guard__ || !e.state.section) return;
        loadSection(e.state.section, false);
        navItems.forEach(nav => {
            nav.classList.toggle('active', nav.dataset.section === e.state.section);
        });
    });

    document.getElementById('menuToggle').addEventListener('click', () => {
        const sidebar = document.getElementById('sidebar');
        if (window.innerWidth <= 768) {
            sidebar.classList.toggle('open');
        } else {
            sidebar.classList.toggle('collapsed');
        }
    });

    document.querySelector('.close-sidebar')?.addEventListener('click', () => {
        document.getElementById('sidebar').classList.remove('open');
    });

    window.addEventListener('resize', function () {
        const sidebar = document.getElementById('sidebar');
        if (!sidebar) return;

        const isMobile = window.innerWidth <= 768;

        if (!isMobile) {
            sidebar.classList.remove('open');
        } else {
            sidebar.classList.remove('collapsed');
        }
    });

    const initialSection = SECTION || 'ringkasan';
    loadSection(initialSection, false);
    navItems.forEach(nav => nav.classList.toggle('active', nav.dataset.section === initialSection));

    setupForms();

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
                const res = await fetch(`${BASE_URL}/admin/profile/update`, {
                    method: 'POST',
                    body: formData
                });
                const json = await res.json();

                if (json.success) {
                    showAlert('Profil berhasil diperbarui!');
                    closeModal('editProfileModal');
                    // Refresh avatar and name in topbar
                    if (json.avatar) {
                        document.querySelector('.profile-avatar').src = `${BASE_URL}/public/avatar/${json.avatar}?t=${Date.now()}`;
                    }
                    if (json.nama) {
                        document.querySelector('.profile-name').textContent = json.nama;
                    }
                } else {
                    showAlert('Gagal memperbarui: ' + (json.message || 'Unknown error'), 'error');
                }
            } catch (err) {
                console.error(err);
                showAlert('Terjadi kesalahan saat menyimpan profil.', 'error');
            }
        });
    }
});

async function loadSection(section, pushState = true) {
    const container = document.getElementById('contentArea');

    try {
        const res = await fetch(`${BASE_URL}/admin/load/${section}`);
        const html = await res.text();

        if (html.includes('Section not found')) {
            container.innerHTML = `<div class="alert alert-danger">${html}</div>`;
            return;
        }

        container.innerHTML = html;

        switch (section) {
            case 'ringkasan':
                loadStats();
                break;
            case 'riwayat-tracking':
                loadTracking();
                break;
            case 'blokir-ip':
                loadIPs();
                break;
            case 'perubahan-profil':
                loadChanges();
                break;
            case 'data-pelanggan':
                loadCustomers();
                break;
            case 'kelola-pegawai':
                loadStaff();
                break;
        }
    } catch (e) {
        console.error('Error loading section', e);
        container.innerHTML = '<div class="alert alert-danger">Gagal memuat halaman</div>';
    }
}

async function loadStats() {
    try {
        const res = await fetch(`${BASE_URL}/admin/api/stats`);
        const data = await res.json();

        document.getElementById('stat-users').textContent = data.users;
        document.getElementById('stat-admins').textContent = data.admins;
        document.getElementById('stat-blocked').textContent = data.blocked;
        if (document.getElementById('stat-mitra')) document.getElementById('stat-mitra').textContent = data.mitra || 0;
        if (document.getElementById('stat-keuangan')) document.getElementById('stat-keuangan').textContent = data.keuangan || 0;
        if (document.getElementById('stat-sertifikasi')) document.getElementById('stat-sertifikasi').textContent = data.sertifikasi || 0;
    } catch (e) {
        console.error('Error loading stats', e);
    }
}

/**
 * Mengambil bagian tanggal dari datetime string (format: DD-MM-YYYY atau YYYY-MM-DD)
 */
function formatTrackingDate(datetimeStr) {
    if (!datetimeStr || datetimeStr === '-') return '-';
    const datePart = datetimeStr.split(' ')[0];
    const b = datePart.split('-');
    if (b.length !== 3) return datePart;
    const months = ['Januari', 'Februari', 'Maret', 'April', 'Mei', 'Juni', 'Juli', 'Agustus', 'September', 'Oktober', 'November', 'Desember'];
    
    let d, m, y;
    if (b[0].length === 4) {
        y = b[0]; m = parseInt(b[1], 10); d = parseInt(b[2], 10);
    } else {
        d = parseInt(b[0], 10); m = parseInt(b[1], 10); y = b[2];
    }
    const dayFinal = d < 10 ? '0' + d : d;
    return dayFinal + ' ' + months[m - 1] + ' ' + y;
}

/**
 * Mengambil bagian jam dari datetime string dalam format 24 jam (HH:mm:ss)
 */
function formatTrackingTime(datetimeStr) {
    if (!datetimeStr) return '-';
    const dt = new Date(datetimeStr);
    if (isNaN(dt.getTime())) {
        // Coba ambil bagian waktu manual
        const parts = datetimeStr.split(' ');
        return parts[1] || '-';
    }
    const hh = String(dt.getHours()).padStart(2, '0');
    const mm = String(dt.getMinutes()).padStart(2, '0');
    const ss = String(dt.getSeconds()).padStart(2, '0');
    return `${hh}:${mm}:${ss}`;
}

async function loadTracking(search = '') {
    let url = `${BASE_URL}/admin/api/tracking`;
    if (search) {
        url += `?search=${encodeURIComponent(search)}`;
    }

    const res = await fetch(url);
    const json = await res.json();

    currentTrackingData = json.data || [];
    currentTrackingPage = 1;

    const searchInput = document.getElementById('trackingSearch');
    if (searchInput) {
        searchInput.value = search;
        searchInput.onkeyup = debounce((e) => {
            loadTracking(e.target.value);
        }, 500);
    }

    renderTrackingTable();
}

function renderTrackingTable() {
    const tbody = document.querySelector('#trackingTable tbody');
    const paginationContainer = document.getElementById('trackingPagination');
    if (!tbody) return;

    if (currentTrackingData.length === 0) {
        tbody.innerHTML = '<tr><td colspan="7" style="text-align:center;">Tidak ada data</td></tr>';
        if (paginationContainer) paginationContainer.innerHTML = '';
        return;
    }

    const totalPages = Math.ceil(currentTrackingData.length / itemsPerPage);
    if (currentTrackingPage > totalPages) currentTrackingPage = totalPages;
    if (currentTrackingPage < 1) currentTrackingPage = 1;

    const start = (currentTrackingPage - 1) * itemsPerPage;
    const end = start + itemsPerPage;
    const pageData = currentTrackingData.slice(start, end);

    tbody.innerHTML = pageData.map(item => `
        <tr>
            <td>${formatTrackingDate(item.created_at)}</td>
            <td>${formatTrackingTime(item.created_at)}</td>
            <td>${item.username || 'Guest'}</td>
            <td>${item.ip_address}</td>
            <td>${item.device_type} (${item.os})</td>
            <td>${item.action}</td>
            <td>${item.url}</td>
        </tr>
    `).join('');

    renderPagination(currentTrackingPage, totalPages, paginationContainer, 'Tracking');
}

window.changeTrackingPage = function (page) {
    currentTrackingPage = page;
    renderTrackingTable();
};

window.showDeleteHistoryModal = function () {
    document.getElementById('deleteHistoryModal').classList.add('active');
};

window.showDeleteChangesHistoryModal = function () {
    document.getElementById('deleteChangesHistoryModal').classList.add('active');
};

window.deleteProfileChangesHistory = function (range) {
    const text = range === 'all' ? 'SEMUA history perubahan profil' : (range === 'day' ? 'history perubahan 24 jam terakhir' : 'history perubahan 1 jam terakhir');

    // Setup Custom Confirm Modal
    document.getElementById('confirmModalMessage').innerHTML = `Apakah Anda yakin ingin menghapus <strong>${text}</strong>?<br>Data yang dihapus tidak dapat dikembalikan!`;
    const confirmBtn = document.getElementById('confirmModalOkBtn');

    // Hapus event listener lama
    const newConfirmBtn = confirmBtn.cloneNode(true);
    confirmBtn.parentNode.replaceChild(newConfirmBtn, confirmBtn);

    document.getElementById('confirmModal').classList.add('active');

    newConfirmBtn.addEventListener('click', async () => {
        // Tutup modal konfirmasi
        closeModal('confirmModal');

        try {
            const formData = new FormData();
            formData.append('range', range);

            const resp = await fetch(`${BASE_URL}/admin/profile-changes/delete`, {
                method: 'POST',
                body: formData
            });
            const res = await resp.json();

            if (res.success) {
                closeModal('deleteChangesHistoryModal');
                showAlert("History berhasil dihapus");
                loadChanges();
            } else {
                showAlert(res.message || "Gagal menghapus history", "error");
            }
        } catch (e) {
            console.error(e);
            showAlert("Terjadi kesalahan sistem", "error");
        }
    });
};

window.deleteHistory = function (range) {
    const text = range === 'all' ? 'SEMUA riwayat' : (range === 'day' ? 'riwayat 24 jam terakhir' : 'riwayat 1 jam terakhir');

    // Setup Custom Confirm Modal
    document.getElementById('confirmModalMessage').innerHTML = `Apakah Anda yakin ingin menghapus <strong>${text}</strong>?<br>Data yang dihapus tidak dapat dikembalikan!`;
    const confirmBtn = document.getElementById('confirmModalOkBtn');

    // Hapus event listener lama dengan clone node untuk mencegah execute ganda
    const newConfirmBtn = confirmBtn.cloneNode(true);
    confirmBtn.parentNode.replaceChild(newConfirmBtn, confirmBtn);

    document.getElementById('confirmModal').classList.add('active');

    newConfirmBtn.addEventListener('click', async () => {
        // Tutup modal konfirmasi
        closeModal('confirmModal');

        const formData = new FormData();
        formData.append('range', range);

        try {
            const res = await fetch(`${BASE_URL}/admin/tracking/delete`, {
                method: 'POST',
                body: formData
            });
            const json = await res.json();

            if (json.success) {
                showAlert('Riwayat berhasil dihapus.');
                closeModal('deleteHistoryModal');
                loadTracking();
            } else {
                showAlert('Gagal menghapus: ' + json.message, 'error');
            }
        } catch (e) {
            console.error(e);
            showAlert('Terjadi kesalahan saat menghapus data.', 'error');
        }
    });
}

function debounce(func, wait) {
    let timeout;
    return function (...args) {
        const context = this;
        clearTimeout(timeout);
        timeout = setTimeout(() => func.apply(context, args), wait);
    };
}

async function loadIPs(search = '') {
    let url = `${BASE_URL}/admin/api/blocked-ips`;
    if (search) {
        url += `?search=${encodeURIComponent(search)}`;
    }

    const res = await fetch(url);
    const json = await res.json();

    currentIPData = json.data || [];
    currentIPPage = 1;

    const searchInput = document.getElementById('ipSearch');
    if (searchInput) {
        searchInput.value = search;
        searchInput.onkeyup = debounce((e) => {
            loadIPs(e.target.value);
        }, 500);
    }

    renderIPTable();
}

function renderIPTable() {
    const tbody = document.querySelector('#ipTable tbody');
    const paginationContainer = document.getElementById('ipPagination');
    if (!tbody) return;

    if (currentIPData.length === 0) {
        tbody.innerHTML = '<tr><td colspan="5" style="text-align:center;">Tidak ada data</td></tr>';
        if (paginationContainer) paginationContainer.innerHTML = '';
        return;
    }

    const totalPages = Math.ceil(currentIPData.length / itemsPerPage);
    if (currentIPPage > totalPages) currentIPPage = totalPages;
    if (currentIPPage < 1) currentIPPage = 1;

    const start = (currentIPPage - 1) * itemsPerPage;
    const end = start + itemsPerPage;
    const pageData = currentIPData.slice(start, end);

    tbody.innerHTML = pageData.map(item => `
        <tr>
            <td>${item.ip_address}</td>
            <td>${item.reason}</td>
            <td>${item.blocked_by || 'System'}</td>
            <td>${formatTrackingDate(item.blocked_at)} ${formatTrackingTime(item.blocked_at)}</td>
            <td><button class="btn btn-secondary" onclick="unblockIP(${item.id})">Unblock</button></td>
        </tr>
    `).join('');

    renderPagination(currentIPPage, totalPages, paginationContainer, 'IP');
}

window.changeIPPage = function (page) {
    currentIPPage = page;
    renderIPTable();
};

async function loadChanges(search = '') {
    let url = `${BASE_URL}/admin/api/profile-changes`;
    if (search) {
        url += `?search=${encodeURIComponent(search)}`;
    }

    const res = await fetch(url);
    const json = await res.json();

    currentChangesData = json.data || [];
    currentChangesPage = 1;

    const searchInput = document.getElementById('changesSearch');
    if (searchInput) {
        searchInput.value = search;
        searchInput.onkeyup = debounce((e) => {
            loadChanges(e.target.value);
        }, 500);
    }

    renderChangesTable();
}

function renderChangesTable() {
    const tbody = document.querySelector('#changesTable tbody');
    const paginationContainer = document.getElementById('changesPagination');
    if (!tbody) return;

    if (currentChangesData.length === 0) {
        tbody.innerHTML = '<tr><td colspan="6" style="text-align:center;">Tidak ada data</td></tr>';
        if (paginationContainer) paginationContainer.innerHTML = '';
        return;
    }

    const totalPages = Math.ceil(currentChangesData.length / itemsPerPage);
    if (currentChangesPage > totalPages) currentChangesPage = totalPages;
    if (currentChangesPage < 1) currentChangesPage = 1;

    const start = (currentChangesPage - 1) * itemsPerPage;
    const end = start + itemsPerPage;
    const pageData = currentChangesData.slice(start, end);

    tbody.innerHTML = pageData.map(item => `
        <tr>
            <td>${formatTrackingDate(item.created_at)}</td>
            <td>${item.username}</td>
            <td>${item.field_name}</td>
            <td>${item.old_value || '-'}</td>
            <td>${item.new_value}</td>
            <td>${item.ip_address}</td>
        </tr>
    `).join('');

    renderPagination(currentChangesPage, totalPages, paginationContainer, 'Changes');
}

window.changeChangesPage = function (page) {
    currentChangesPage = page;
    renderChangesTable();
};

async function loadCustomers(search = '') {
    // Setup search input listener
    const searchInput = document.getElementById('customerSearch');
    if (searchInput && !searchInput._listenerBound) {
        searchInput._listenerBound = true;
        searchInput.addEventListener('input', debounce(() => {
            window.currentCustomerPage = 1;
            renderCustomerTable();
        }, 400));
    }
    if (searchInput) searchInput.value = search;

    // Setup kategori filter dropdown — pasang listener langsung (tidak pakai initAdminCustomSelects
    // agar tidak bergantung pada class .initialized yang mungkin sudah terpasang)
    const kategoriDropdown = document.getElementById('dropdown-customerKategori');
    if (kategoriDropdown && !kategoriDropdown._listenerBound) {
        kategoriDropdown._listenerBound = true;

        const trigger  = kategoriDropdown.querySelector('.select-trigger');
        const optEls   = kategoriDropdown.querySelectorAll('.option');
        const hiddenIn = kategoriDropdown.querySelector('input[type="hidden"]');
        const selText  = kategoriDropdown.querySelector('.selected-text');

        // Buka / tutup dropdown
        trigger.addEventListener('click', (e) => {
            e.stopPropagation();
            document.querySelectorAll('.admin-custom-select.open').forEach(d => {
                if (d !== kategoriDropdown) d.classList.remove('open');
            });
            kategoriDropdown.classList.toggle('open');
        });

        // Pilih opsi
        optEls.forEach(opt => {
            opt.addEventListener('click', () => {
                const val  = opt.dataset.value;
                const text = opt.textContent;
                optEls.forEach(o => o.classList.remove('selected'));
                opt.classList.add('selected');
                selText.textContent = text;
                if (hiddenIn) hiddenIn.value = val;
                kategoriDropdown.classList.remove('open');
                window.currentCustomerPage = 1;
                renderCustomerTable();
            });
        });
    }

    // Fetch data dari server
    const res = await fetch(`${BASE_URL}/admin/api/customers`);
    const json = await res.json();

    window.allCustomerData = json.data || [];
    window.currentCustomerPage = 1;

    renderCustomerTable();
}

function renderCustomerTable() {
    const tbody = document.querySelector('#customerTable tbody');
    const paginationContainer = document.getElementById('customerPagination');
    if (!tbody) return;

    const allData = window.allCustomerData || [];
    const page = window.currentCustomerPage || 1;
    const perPage = window.itemsPerPage || 5;

    // Ambil nilai pencarian dan filter kategori
    const searchVal = (document.getElementById('customerSearch')?.value || '').toLowerCase().trim();
    const kategoriVal = (document.getElementById('customerKategoriFilter')?.value || '').toLowerCase().trim();

    // Filter client-side berdasarkan pencarian dan kategori
    const data = allData.filter(item => {
        const matchSearch = !searchVal || [
            item.username, item.nama_lengkap, item.email,
            item.no_whatsapp, item.nama_perusahaan, item.kategori
        ].some(v => (v || '').toLowerCase().includes(searchVal));
        const matchKategori = !kategoriVal || (item.kategori || '').toLowerCase() === kategoriVal;
        return matchSearch && matchKategori;
    });

    window.currentCustomerData = data;

    if (data.length === 0) {
        tbody.innerHTML = '<tr><td colspan="7" style="text-align:center;">Tidak ada data</td></tr>';
        if (paginationContainer) paginationContainer.innerHTML = '';
        return;
    }

    const totalPages = Math.ceil(data.length / perPage);
    window.currentCustomerPage = Math.min(Math.max(page, 1), totalPages);

    const start = (window.currentCustomerPage - 1) * perPage;
    const end = start + perPage;
    const pageData = data.slice(start, end);

    // Label kategori: capitalize first letter
    const formatKategori = (k) => k ? k.charAt(0).toUpperCase() + k.slice(1) : '-';

    const formatTanggalDaftar = (dateStr) => {
        if (!dateStr || dateStr === '-') return '-';
        const b = dateStr.split(' ')[0].split('-');
        if (b.length !== 3) return dateStr;
        const months = ['Januari', 'Februari', 'Maret', 'April', 'Mei', 'Juni', 'Juli', 'Agustus', 'September', 'Oktober', 'November', 'Desember'];
        
        let d, m, y;
        if (b[0].length === 4) {
            y = b[0]; m = parseInt(b[1], 10); d = parseInt(b[2], 10);
        } else {
            d = parseInt(b[0], 10); m = parseInt(b[1], 10); y = b[2];
        }
        const day = d < 10 ? '0' + d : d;
        return day + ' ' + months[m - 1] + ' ' + y;
    };

    tbody.innerHTML = pageData.map(item => `
        <tr>
            <td>${formatTanggalDaftar(item.tanggal_daftar)}</td>
            <td>${item.username || '-'}</td>
            <td>${item.nama_lengkap || '-'}</td>
            <td>${item.email || '-'}</td>
            <td>${item.no_whatsapp || '-'}</td>
            <td>${item.nama_perusahaan || '-'}</td>
            <td>${formatKategori(item.kategori)}</td>
        </tr>
    `).join('');

    renderPagination(window.currentCustomerPage, totalPages, paginationContainer, 'Customer');
}

window.changeCustomerPage = function (page) {
    window.currentCustomerPage = page;
    renderCustomerTable();
};

async function loadStaff(search = '', role = '') {
    let url = `${BASE_URL}/admin/api/pegawai`;
    const params = [];
    if (search) params.push(`search=${encodeURIComponent(search)}`);
    if (role) params.push(`role=${encodeURIComponent(role)}`);
    if (params.length) url += '?' + params.join('&');

    const res = await fetch(url);
    const json = await res.json();

    // Pasang listener search SEKALI SAJA
    const searchInput = document.getElementById('pegawaiSearch');
    if (searchInput && !searchInput._listenerBound) {
        searchInput._listenerBound = true;
        searchInput.addEventListener('input', debounce((e) => {
            const filterVal = document.getElementById('pegawaiRoleFilter')?.value || '';
            loadStaff(e.target.value, filterVal);
        }, 500));
    }
    if (searchInput) searchInput.value = search;

    // Pasang listener dropdown filter role SEKALI SAJA
    const roleFilterDropdown = document.getElementById('dropdown-pegawaiRole');
    if (roleFilterDropdown && !roleFilterDropdown._listenerBound) {
        roleFilterDropdown._listenerBound = true;
        initAdminCustomSelects.call(null, function (val, inputEl) {
            // Hanya untuk dropdown filter (bukan dropdown per baris)
            if (inputEl && inputEl.id === 'pegawaiRoleFilter') {
                const searchVal = document.getElementById('pegawaiSearch')?.value || '';
                loadStaff(searchVal, val);
            }
        });
    }

    // Sync nilai filter
    const roleFilterInput = document.getElementById('pegawaiRoleFilter');
    if (roleFilterInput) roleFilterInput.value = role;

    window.currentPegawaiData = json.data || [];
    window.currentPegawaiPage = 1;
    renderPegawaiTable();
}

function renderPegawaiTable() {
    const tbody = document.querySelector('#pegawaiTable tbody');
    const paginationContainer = document.getElementById('pegawaiPagination');
    if (!tbody) return;

    const data = window.currentPegawaiData || [];
    const page = window.currentPegawaiPage || 1;
    const perPage = window.pegawaiItemsPerPage || 5;

    if (data.length === 0) {
        tbody.innerHTML = '<tr><td colspan="3" style="text-align:center;">Tidak ada data</td></tr>';
        if (paginationContainer) paginationContainer.innerHTML = '';
        return;
    }

    const totalPages = Math.ceil(data.length / perPage);
    window.currentPegawaiPage = Math.min(Math.max(page, 1), totalPages);

    const start = (window.currentPegawaiPage - 1) * perPage;
    const end = start + perPage;
    const pageData = data.slice(start, end);

    tbody.innerHTML = pageData.map(item => {
        const currentRole = item.si_selport || '';
        return `
        <tr>
            <td>${item.login || '-'}</td>
            <td>${item.nama_user || '-'}</td>
            <td>
                <div class="admin-custom-select" style="width: 160px; margin: 0 auto;">
                    <button type="button" class="select-trigger">
                        <span class="selected-text">${currentRole === 'admin' ? 'Admin' : (currentRole === 'mitra' ? 'Mitra' : (currentRole === 'keuangan' ? 'Keuangan' : (currentRole === 'sertifikasi' ? 'Sertifikasi' : 'Tidak Ada')))}</span>
                        <svg class="select-arrow" xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="6 9 12 15 18 9"></polyline></svg>
                    </button>
                    <div class="select-options">
                        <div class="option ${currentRole === '' ? 'selected' : ''}" data-value="">Tidak Ada</div>
                        <div class="option ${currentRole === 'admin' ? 'selected' : ''}" data-value="admin">Admin</div>
                        <div class="option ${currentRole === 'mitra' ? 'selected' : ''}" data-value="mitra">Mitra</div>
                        <div class="option ${currentRole === 'keuangan' ? 'selected' : ''}" data-value="keuangan">Keuangan</div>
                        <div class="option ${currentRole === 'sertifikasi' ? 'selected' : ''}" data-value="sertifikasi">Sertifikasi</div>
                    </div>
                    <input type="hidden" class="staff-role-select" data-userid="${item.id_user}" value="${currentRole}">
                </div>
            </td>
        </tr>`;
    }).join('');

    renderPagination(window.currentPegawaiPage, totalPages, paginationContainer, 'Pegawai');

    // Init HANYA untuk dropdown role per baris (bukan dropdown filter halaman)
    initAdminCustomSelects(function (val, inputObj) {
        if (inputObj && inputObj.classList.contains('staff-role-select')) {
            updateStaffRole(inputObj.dataset.userid, val);
        }
    });
}

window.changePegawaiPage = function (page) {
    window.currentPegawaiPage = page;
    renderPegawaiTable();
};

async function updateStaffRole(idUser, role) {
    const formData = new FormData();
    formData.append('id_user', idUser);
    formData.append('role', role);

    try {
        const res = await fetch(`${BASE_URL}/admin/api/pegawai/update-role`, {
            method: 'POST',
            body: formData
        });
        const json = await res.json();

        if (json.success) {
            // Subtle visual feedback without full reload
            const el = event?.target;
            if (el) {
                el.style.borderColor = '#22c55e';
                setTimeout(() => el.style.borderColor = '', 1500);
            }
        } else {
            showAlert('Gagal: ' + (json.message || 'Terjadi kesalahan'), 'error');
        }
    } catch (e) {
        console.error('Error updating staff role:', e);
        showAlert('Terjadi kesalahan saat memperbarui role', 'error');
    }
}

window.showBlockModal = function () {
    document.getElementById('blockModal').classList.add('active');
};

window.showEditProfileModal = function () {
    document.getElementById('profileMenu').classList.remove('show');
    const avatarSrc = ADMIN_AVATAR
        ? `${BASE_URL}/public/avatar/${ADMIN_AVATAR}?t=${Date.now()}`
        : `${BASE_URL}/public/avatar/avatar.png`;
    document.getElementById('editProfileAvatar').src = avatarSrc;
    document.getElementById('editNamaLengkap').value = ADMIN_NAME || '';
    document.getElementById('editUsername').value = ADMIN_USERNAME || '';
    document.getElementById('editPassword').value = '';
    document.getElementById('editProfileModal').classList.add('active');
};

window.closeModal = function (id) {
    document.getElementById(id).classList.remove('active');
};

function setupForms() {
    document.getElementById('blockForm')?.addEventListener('submit', async (e) => {
        e.preventDefault();

        const ipInput   = e.target.querySelector('[name="ip_address"]');
        const reasonInput = e.target.querySelector('[name="reason"]');
        const ip     = ipInput?.value?.trim();
        const reason = reasonInput?.value?.trim();

        // Validasi format IP sederhana
        const ipRegex = /^(\d{1,3}\.){3}\d{1,3}$/;
        if (!ip || !ipRegex.test(ip)) {
            showAlert('Format IP Address tidak valid. Contoh: 192.168.1.1', 'error');
            return;
        }

        if (!reason) {
            showAlert('Alasan pemblokiran tidak boleh kosong.', 'error');
            return;
        }

        const submitBtn = e.target.querySelector('[type="submit"]');
        if (submitBtn) { submitBtn.disabled = true; submitBtn.textContent = 'Memproses...'; }

        try {
            const res  = await fetch(`${BASE_URL}/admin/ip/block`, {
                method: 'POST',
                body: new FormData(e.target)
            });
            const json = await res.json();

            if (json.success) {
                e.target.reset();
                closeModal('blockModal');
                showAlert('IP ' + ip + ' berhasil diblokir.');
                loadIPs();
            } else {
                showAlert(json.message || 'Gagal memblokir IP. Coba lagi.', 'error');
            }
        } catch (err) {
            console.error('Block IP error:', err);
            showAlert('Terjadi kesalahan saat memblokir IP.', 'error');
        } finally {
            if (submitBtn) { submitBtn.disabled = false; submitBtn.textContent = 'Blokir IP'; }
        }
    });
}

window.showAlert = function (message, type = 'success') {
    const iconEl = document.getElementById('alertModalIcon');
    if (type === 'success') {
        iconEl.innerHTML = '<i class="ri-checkbox-circle-line" style="color:#1cc88a;"></i>';
    } else {
        iconEl.innerHTML = '<i class="ri-error-warning-line" style="color:#e74a3b;"></i>';
    }
    document.getElementById('alertModalMessage').textContent = message;
    document.getElementById('alertModal').classList.add('active');
};

async function unblockIP(id) {
    // Setup Custom Confirm Modal
    document.getElementById('confirmModalMessage').innerHTML = `Apakah Anda yakin ingin melakukan <strong>Unblock</strong> pada IP ini?`;
    const confirmBtn = document.getElementById('confirmModalOkBtn');

    // Hapus event listener lama
    const newConfirmBtn = confirmBtn.cloneNode(true);
    confirmBtn.parentNode.replaceChild(newConfirmBtn, confirmBtn);

    document.getElementById('confirmModal').classList.add('active');

    newConfirmBtn.addEventListener('click', async () => {
        closeModal('confirmModal');

        const formData = new FormData();
        formData.append('id', id);

        try {
            await fetch(`${BASE_URL}/admin/ip/unblock`, {
                method: 'POST',
                body: formData
            });
            loadIPs();
        } catch (e) {
            console.error('Failed to unblock IP', e);
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
            document.querySelectorAll('.admin-custom-select.open').forEach(d => {
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
            document.querySelectorAll('.admin-custom-select.open').forEach(d => {
                d.classList.remove('open');
            });
        });
    }
}

function renderPagination(currentPage, totalPages, container, sectionName) {
    if (!container) return;

    const perPageVar = (sectionName === 'Pegawai') ? 'pegawaiItemsPerPage' : 'itemsPerPage';
    const perPage = window[perPageVar] || 5;
    
    const dataVar = (sectionName === 'Customer') ? 'currentCustomerData' : 'current' + sectionName + 'Data';
    const dataArr = window[dataVar] || [];
    const totalItems = dataArr.length;

    let start = 0;
    let end = 0;
    if (totalItems > 0) {
        start = ((currentPage - 1) * perPage) + 1;
        end = Math.min(currentPage * perPage, totalItems);
    }

    let paginationHTML = `
    <div style="display:flex; justify-content:space-between; align-items:center; width:100%; flex-wrap:wrap; gap:10px;">
        <div style="display:flex; align-items:center; gap:15px; font-size:0.85rem; color:#64748b;">
            <span>Menampilkan ${start} - ${end} dari ${totalItems} data</span>
            <div style="display:flex; align-items:center; gap:8px;">
                <span>Tampilkan</span>
                <select class="form-control" style="width:70px; display:inline-block; padding:4px 8px; font-size:0.85rem; height:auto; border-radius:4px;" onchange="changeItemsPerPage(this.value, '${sectionName}')">
                    <option value="5" ${perPage == 5 ? 'selected' : ''}>5</option>
                    <option value="10" ${perPage == 10 ? 'selected' : ''}>10</option>
                    <option value="25" ${perPage == 25 ? 'selected' : ''}>25</option>
                    <option value="50" ${perPage == 50 ? 'selected' : ''}>50</option>
                </select>
            </div>
        </div>
        <div style="display:flex; gap:5px;">`;

    paginationHTML += `<button class="page-btn" ${currentPage <= 1 ? 'disabled' : `onclick="change${sectionName}Page(${currentPage - 1})"`}>Prev</button>`;

    let startPage = Math.max(1, currentPage - 2);
    let endPage = Math.min(totalPages || 1, startPage + 4);
    if (endPage - startPage < 4) startPage = Math.max(1, endPage - 4);

    for (let i = startPage; i <= endPage; i++) {
        paginationHTML += `<button class="page-btn ${i === currentPage ? 'active' : ''}" onclick="change${sectionName}Page(${i})">${i}</button>`;
    }

    paginationHTML += `<button class="page-btn" ${currentPage >= totalPages ? 'disabled' : `onclick="change${sectionName}Page(${currentPage + 1})"`}>Next</button>`;
    
    paginationHTML += `</div></div>`;
    container.innerHTML = paginationHTML;
}

window.changeItemsPerPage = function(val, sectionName) {
    const newVal = parseInt(val, 10);
    if (sectionName === 'Pegawai') {
        window.pegawaiItemsPerPage = newVal;
        window.currentPegawaiPage = 1;
        renderPegawaiTable();
    } else if (sectionName === 'Customer') {
        window.itemsPerPage = newVal;
        window.currentCustomerPage = 1;
        renderCustomerTable();
    } else if (sectionName === 'IP') {
        window.itemsPerPage = newVal;
        window.currentIPPage = 1;
        renderIPTable();
    } else if (sectionName === 'Changes') {
        window.itemsPerPage = newVal;
        window.currentChangesPage = 1;
        renderChangesTable();
    } else if (sectionName === 'Tracking') {
        window.itemsPerPage = newVal;
        window.currentTrackingPage = 1;
        renderTrackingTable();
    }
}
