document.addEventListener('DOMContentLoaded', function () {
    const navItems = document.querySelectorAll('.nav-item[data-section]');
    const contentArea = document.getElementById('contentArea');

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

    // Sidebar navigation
    navItems.forEach(item => {
        item.addEventListener('click', (e) => {
            if (item.classList.contains('logout')) return;
            e.preventDefault();
            const section = item.dataset.section;
            if (section) {
                // Close sidebar on mobile after nav click
                if (window.innerWidth <= 768) {
                    closeMobileSidebar();
                }
                window.loadKeuSection(section);
            }
        });
    });

    // Create overlay element for mobile sidebar
    let overlay = document.querySelector('.sidebar-overlay');
    if (!overlay) {
        overlay = document.createElement('div');
        overlay.className = 'sidebar-overlay';
        document.body.appendChild(overlay);
    }
    overlay.addEventListener('click', () => {
        closeMobileSidebar();
    });

    // Mobile menu toggle
    document.getElementById('menuToggle').addEventListener('click', () => {
        const sidebar = document.getElementById('sidebar');
        if (window.innerWidth <= 768) {
            const isOpen = sidebar.classList.contains('open');
            if (isOpen) {
                closeMobileSidebar();
            } else {
                sidebar.classList.add('open');
                overlay.classList.add('active');
            }
        } else {
            sidebar.classList.toggle('collapsed');
        }
    });

    function closeMobileSidebar() {
        const sidebar = document.getElementById('sidebar');
        const overlay = document.querySelector('.sidebar-overlay');
        sidebar.classList.remove('open');
        if (overlay) overlay.classList.remove('active');
    }

    window.addEventListener('resize', function () {
        const sidebar = document.getElementById('sidebar');
        const overlay = document.querySelector('.sidebar-overlay');
        if (!sidebar) return;
        if (window.innerWidth > 768) {
            sidebar.classList.remove('open');
            if (overlay) overlay.classList.remove('active');
        } else {
            sidebar.classList.remove('collapsed');
        }
    });

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
                const res = await fetch(`${BASE_URL}/keuangan/api/update-account`, {
                    method: 'POST',
                    body: formData
                });
                const json = await res.json();
                
                if (json.success) {
                    closeKeuModal('editProfileModal');
                    showAlert(json.message || 'Profil berhasil diperbarui!');
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
                    showAlert('Gagal memperbarui: ' + (json.message || 'Unknown error'), 'error');
                }
            } catch (err) {
                console.error(err);
                showAlert('Terjadi kesalahan saat menyimpan profil.', 'error');
            }
        });
    }

    const initialSection = SECTION || 'ringkasan';
    window.loadKeuSection(initialSection, false);
});

window.loadKeuSection = async function(section, pushState = true) {
    const container = document.getElementById('contentArea');
    try {
        const res = await fetch(`${BASE_URL}/keuangan/load/${section}?t=${Date.now()}`);
        const html = await res.text();

        container.innerHTML = html;

        document.querySelectorAll('.nav-item[data-section]').forEach(nav => {
            nav.classList.toggle('active', nav.dataset.section === section);
        });

        if (pushState) {
            history.pushState({ section, __selport_dashboard__: true }, '', `${BASE_URL}/keuangan/${section}`);
        }

        if (section === 'ringkasan') {
            loadStats();
            loadRecentPayments();
        } else if (section === 'kelola-pembayaran') {
            initKelolaPembayaran();
        } else if (section === 'kesanggupan-pembayaran') {
            initTemplateKesanggupan();
        }

    } catch (err) {
        container.innerHTML = `<div class="alert alert-danger">Gagal memuat halaman: ${err.message}</div>`;
    }
};

window.addEventListener('popstate', (e) => {
    // Abaikan guard state dari back-guard.js
    if (!e.state || e.state.__guard__) return;
    if (e.state.section) {
        window.loadKeuSection(e.state.section, false);
    } else {
        const pathParts = window.location.pathname.split('/');
        const sec = pathParts[pathParts.length - 1] || 'ringkasan';
        if (['dashboard', 'keuangan'].includes(sec)) {
            window.loadKeuSection('ringkasan', false);
        } else {
            window.loadKeuSection(sec, false);
        }
    }
});

// Modals
window.showEditProfileModal = function() {
    document.getElementById('profileMenu').classList.remove('show');
    const avatarSrc = KEU_AVATAR 
        ? `${BASE_URL}/public/avatar/${KEU_AVATAR}?t=${Date.now()}` 
        : `${BASE_URL}/public/avatar/avatar.png`;
    document.getElementById('editProfileAvatar').src = avatarSrc;
    document.getElementById('editNamaLengkap').value = KEU_NAME || '';
    document.getElementById('editUsername').value = KEU_USERNAME || '';
    document.getElementById('editPassword').value = '';
    document.getElementById('editProfileModal').classList.add('active');
};

window.closeKeuModal = function(id) {
    document.getElementById(id).classList.remove('active');
};

window.showAlert = function(message, type = 'success') {
    const iconEl = document.getElementById('alertModalIcon');
    if (type === 'success') {
        iconEl.innerHTML = '<i class="ri-checkbox-circle-line" style="color:#1cc88a;"></i>';
    } else {
        iconEl.innerHTML = '<i class="ri-error-warning-line" style="color:#e74a3b;"></i>';
    }
    document.getElementById('alertModalMessage').textContent = message;
    document.getElementById('alertModal').classList.add('active');
};

// --- DATA LOGIC --- //

let memoryPaymentsData = [];
let currentPaymentsPage = 1;
let itemsPerPagePayments = 5;

function loadStats() {
    fetch(`${BASE_URL}/keuangan/api/stats`)
        .then(res => res.json())
        .then(data => {
            if(document.getElementById('stat-pay-total')) document.getElementById('stat-pay-total').textContent = data.pay_total || 0;
            if(document.getElementById('stat-pay-pending')) document.getElementById('stat-pay-pending').textContent = data.pay_pending || 0;
            if(document.getElementById('stat-pay-approved')) document.getElementById('stat-pay-approved').textContent = data.pay_approved || 0;
            if(document.getElementById('stat-pay-rejected')) document.getElementById('stat-pay-rejected').textContent = data.pay_rejected || 0;
            if(document.getElementById('stat-kes-total')) document.getElementById('stat-kes-total').textContent = data.kes_total || 0;
            if(document.getElementById('stat-kes-pending')) document.getElementById('stat-kes-pending').textContent = data.kes_pending || 0;
            if(document.getElementById('stat-kes-approved')) document.getElementById('stat-kes-approved').textContent = data.kes_approved || 0;
            if(document.getElementById('stat-kes-rejected')) document.getElementById('stat-kes-rejected').textContent = data.kes_rejected || 0;
        })
        .catch(console.error);
}

function loadRecentPayments() {
    const tbody = document.getElementById('recentPaymentsBody');
    if (!tbody) return;
    
    fetch(`${BASE_URL}/keuangan/api/payments?status=all&search=`)
        .then(res => res.json())
        .then(res => {
            if (res.data && res.data.length > 0) {
                let html = '';
                // Limit to 5
                const recent = res.data.slice(0, 5);
                recent.forEach(p => {
                    const date = new Date(p.created_at);
                    const formattedDate = date.toLocaleDateString('id-ID', { day: '2-digit', month: 'short', year: 'numeric' }) + ' ' + date.toLocaleTimeString('id-ID', { hour:'2-digit', minute:'2-digit' });
                    
                    let statusHtml = '';
                    if (p.status === 'Menunggu' || p.status === 'Pending') statusHtml = `<span style="color:#d97706; font-weight:500; font-size:0.85rem;">Pending</span>`;
                    else if (p.status === 'Diverifikasi') statusHtml = `<span style="color:#059669; font-weight:500; font-size:0.85rem;">Disetujui</span>`;
                    else statusHtml = `<span style="color:#dc2626; font-weight:500; font-size:0.85rem;">Perbaiki</span>`;

                    html += `<tr>
                        <td style="font-size:0.85rem; color:#858796;">${formattedDate}</td>
                        <td style="font-weight:600; color:#4e73df;">${p.order_no}</td>
                        <td>${statusHtml}</td>
                    </tr>`;
                });
                tbody.innerHTML = html;
            } else {
                tbody.innerHTML = `<tr><td colspan="3" style="text-align:center; color:#858796;">Belum ada data pembayaran.</td></tr>`;
            }
        })
        .catch(err => {
            tbody.innerHTML = `<tr><td colspan="3" style="text-align:center; color:#e74a3b;">Error memuat data</td></tr>`;
        });
}

function initKelolaPembayaran() {
    loadPaymentsData();

    if (typeof initAdminCustomSelects === 'function') initAdminCustomSelects();

    document.getElementById('paymentsSearch')?.addEventListener('input', applyPaymentsFilter);
    document.getElementById('paymentsFilter')?.addEventListener('change', applyPaymentsFilter);
    document.getElementById('paymentsMonth')?.addEventListener('change', applyPaymentsFilter);
    document.getElementById('paymentsYear')?.addEventListener('change', applyPaymentsFilter);
    document.getElementById('refreshPaymentsBtn')?.addEventListener('click', () => loadPaymentsData());
    document.getElementById('paymentsPageSize')?.addEventListener('change', (e) => {
        itemsPerPagePayments = e.target.value === 'all' ? 999999 : parseInt(e.target.value, 10);
        currentPaymentsPage = 1;
        applyPaymentsFilter();
    });
}

function loadPaymentsData() {
    const tbody = document.getElementById('paymentsBodyFull');
    if (!tbody) return;

    tbody.innerHTML = `<tr><td colspan="7" style="text-align:center; padding:24px; color:#858796;"><i class="ri-loader-4-line"></i> Memuat data...</td></tr>`;

    fetch(`${BASE_URL}/keuangan/api/payments?status=all&search=`)
        .then(res => res.json())
        .then(res => {
            if (res.data) {
                memoryPaymentsData = res.data;
                currentPaymentsPage = 1;
                populatePaymentsYearDropdown();
                // Set default dropdowns (already Menunggu and all in HTML, but we just apply filter)
                applyPaymentsFilter();
            }
        })
        .catch(() => {
            tbody.innerHTML = `<tr><td colspan="7" style="text-align:center; color:#e74a3b;">Error memuat data</td></tr>`;
        });
}

function populatePaymentsYearDropdown() {
    const years = [...new Set(memoryPaymentsData.map(p => {
        return p.created_at ? p.created_at.substring(0, 4) : null;
    }).filter(Boolean))].sort((a, b) => b - a);

    const yearOptions = document.getElementById('paymentsYearOptions');
    if (yearOptions) {
        yearOptions.innerHTML = '<div class="option selected" data-value="all">Semua Tahun</div>';
        years.forEach(y => {
            yearOptions.innerHTML += `<div class="option" data-value="${y}">${y}</div>`;
        });
        
        // Hanya me-re-bind event click ke options yang baru dibuat, bukan keseluruhan dropdown
        const dropdown = document.getElementById('dropdown-paymentsYear');
        if (dropdown) {
            const options = dropdown.querySelectorAll('.option');
            const hiddenInput = dropdown.querySelector('input[type="hidden"]');
            const selectedText = dropdown.querySelector('.selected-text');

            options.forEach(opt => {
                opt.addEventListener('click', () => {
                    const val = opt.dataset.value;
                    const text = opt.textContent;

                    options.forEach(o => o.classList.remove('selected'));
                    opt.classList.add('selected');

                    selectedText.textContent = text;
                    if (hiddenInput) {
                        hiddenInput.value = val;
                        hiddenInput.dispatchEvent(new Event('change', { bubbles: true }));
                    }

                    dropdown.classList.remove('open');
                });
            });
        }
    }
}

function applyPaymentsFilter() {
    const search = (document.getElementById('paymentsSearch')?.value || '').toLowerCase();
    const status = document.getElementById('paymentsFilter')?.value || 'Menunggu';
    const month = document.getElementById('paymentsMonth')?.value || 'all';
    const year = document.getElementById('paymentsYear')?.value || 'all';

    const filtered = memoryPaymentsData.filter(p => {
        const matchSearch = !search || 
            (p.nama_perusahaan || '').toLowerCase().includes(search) || 
            (p.nama_lengkap || '').toLowerCase().includes(search) ||
            (p.keterangan || '').toLowerCase().includes(search);
        
        // Match status (if "all" we'd skip, but HTML currently doesn't have "all" for status, default is "Menunggu")
        const matchStatus = status === 'all' || p.status === status;

        const datePart = p.created_at ? p.created_at.split(' ')[0] : '';
        const rowYear = datePart ? datePart.substring(0, 4) : '';
        const rowMonth = datePart ? datePart.substring(5, 7) : '';

        const matchMonth = month === 'all' || rowMonth === month;
        const matchYear = year === 'all' || rowYear === year;

        return matchSearch && matchStatus && matchMonth && matchYear;
    });

    currentPaymentsPage = 1;
    renderPaymentsTable(filtered);
}

function renderPaymentsTable(dataToRender = null) {
    const tbody = document.getElementById('paymentsBodyFull');
    const paginationContainer = document.getElementById('paymentsPagination');
    if (!tbody || !paginationContainer) return;

    const data = dataToRender || memoryPaymentsData;
    const totalData = data.length;
    const perPage   = itemsPerPagePayments;
    const totalPages = Math.ceil(totalData / perPage);

    if (totalData === 0) {
        tbody.innerHTML = `<tr><td colspan="7" style="text-align:center; padding:40px; color:#858796;"><i class="ri-inbox-line" style="font-size:2rem; display:block; margin-bottom:8px;"></i>Tidak ada data pembayaran ditemukan.</td></tr>`;
        ['paymentsStart','paymentsEnd','paymentsTotal'].forEach(id => {
            const el = document.getElementById(id);
            if (el) el.textContent = 0;
        });
        paginationContainer.innerHTML = '';
        return;
    }

    if (currentPaymentsPage < 1) currentPaymentsPage = 1;
    if (currentPaymentsPage > totalPages) currentPaymentsPage = totalPages;

    const startIndex = (currentPaymentsPage - 1) * perPage;
    const endIndex   = Math.min(startIndex + perPage, totalData);
    const paged      = data.slice(startIndex, endIndex);

    // Status config
    const statusCfg = {
        'Menunggu':    { bg: 'transparent', color: '#d97706', label: 'Pending'     },
        'Pending':     { bg: 'transparent', color: '#d97706', label: 'Pending'     },
        'Diverifikasi':{ bg: 'transparent', color: '#059669', label: 'Disetujui'   },
        'Gagal':       { bg: 'transparent', color: '#dc2626', label: 'Perbaiki'  },
    };

    let html = '';
    paged.forEach(p => {
        const date = new Date(p.created_at);
        const tglStr = date.toLocaleDateString('id-ID', { day: '2-digit', month: 'short', year: 'numeric' });

        const st = statusCfg[p.status] || statusCfg['Pending'];
        const labelText = p.ui_status_label || st.label || p.status;
        const statusBadge = `<span style="color:${st.color}; font-size:0.85rem; white-space:nowrap; font-weight:600;">${labelText}</span>`;


        const catatanHtml = p.reason
            ? `<div style="font-size:0.82rem; color:#111; word-break:break-word;">
                   ${p.reason}
               </div>`
            : '<span style="color:#94a3b8; font-size:0.8rem;">-</span>';

        const dikelolaHtml = p.verified_by_name
            ? `<span style="font-size:0.8rem; color:#111;">${p.verified_by_name}</span>`
            : '<span style="color:#94a3b8; font-size:0.8rem;">-</span>';

        const editBtn = `<button
            onclick="openKeuPaymentEditModal(${p.id})"
            style="display:inline-flex; align-items:center; gap:5px; padding:6px 14px; background:#62A0FD; color:#fff; border:none; border-radius:6px; font-size:0.78rem; font-weight:600; cursor:pointer; transition:all 0.2s; white-space:nowrap; box-shadow:0 4px 12px rgba(98,160,253,0.3);">
            <i class="ri-edit-line"></i> Kelola
        </button>`;

        html += `<tr style="transition:background 0.15s;" onmouseover="this.style.background='#f8fafc'" onmouseout="this.style.background=''">
            <td style="vertical-align:middle; font-size:0.82rem; color:#111; white-space:nowrap;">${tglStr}</td>
            <td style="vertical-align:middle; font-size:0.85rem; color:#111;">${p.nama_perusahaan || p.nama_lengkap || '-'}</td>
            <td style="vertical-align:middle; font-size:0.82rem; color:#111; word-break:break-word;">${p.keterangan || '-'}</td>
            <td style="vertical-align:middle; text-align:center;">${catatanHtml}</td>
            <td style="vertical-align:middle; text-align:center;">${dikelolaHtml}</td>
            <td style="vertical-align:middle; text-align:center;">${statusBadge}</td>
            <td style="vertical-align:middle; text-align:center;">${editBtn}</td>
        </tr>`;
    });

    tbody.innerHTML = html;

    document.getElementById('paymentsStart').textContent = startIndex + 1;
    document.getElementById('paymentsEnd').textContent   = endIndex;
    document.getElementById('paymentsTotal').textContent = totalData;

    let pHtml = `<button class="page-btn ${currentPaymentsPage===1?'disabled':''}" onclick="changePaymentsPage(${currentPaymentsPage-1})" ${currentPaymentsPage===1?'disabled':''}>&#171;</button>`;
    let sp = Math.max(1, currentPaymentsPage - 2), ep = Math.min(totalPages, currentPaymentsPage + 2);
    if (sp > 1) pHtml += `<button class="page-btn" onclick="changePaymentsPage(1)">1</button>${sp > 2 ? '<span class="page-btn" style="cursor:default;">…</span>' : ''}`;
    for (let i = sp; i <= ep; i++) pHtml += `<button class="page-btn ${i===currentPaymentsPage?'active':''}" onclick="changePaymentsPage(${i})">${i}</button>`;
    if (ep < totalPages) pHtml += `${ep < totalPages-1 ? '<span class="page-btn" style="cursor:default;">…</span>' : ''}<button class="page-btn" onclick="changePaymentsPage(${totalPages})">${totalPages}</button>`;
    pHtml += `<button class="page-btn ${currentPaymentsPage===totalPages?'disabled':''}" onclick="changePaymentsPage(${currentPaymentsPage+1})" ${currentPaymentsPage===totalPages?'disabled':''}>&#187;</button>`;
    paginationContainer.innerHTML = pHtml;
}

window.changePaymentsPage = function(page) {
    currentPaymentsPage = page;
    renderPaymentsTable();
};

// ── Modal Sunting Pembayaran (gaya Mitra) ──────────────────────────────────

window.openKeuPaymentEditModal = function(id) {
    const p = memoryPaymentsData.find(x => x.id == id);
    if (!p) return;

    document.getElementById('keuPayEditId').value = id;
    document.getElementById('keuPayEditFileName').value = p.file_name || '';

    // Map status DB → nilai dropdown (Pending & Menunggu keduanya → 'Menunggu')
    const st = p.status === 'Diverifikasi' ? 'Diverifikasi' : p.status === 'Gagal' ? 'Gagal' : 'Menunggu';
    const selHidden = document.getElementById('keuPayEditStatus');
    const selText = document.getElementById('text-keuPayEditStatus');
    const dropdownWrap = document.getElementById('dropdown-keuPayEditStatus');
    
    if (selHidden) selHidden.value = st;
    const labelMap = {'Diverifikasi': 'Disetujui', 'Gagal': 'Perbaiki', 'Menunggu': 'Pending'};
    if (selText) selText.textContent = labelMap[st] || 'Pending';
    
    if (dropdownWrap) {
        dropdownWrap.querySelectorAll('.option').forEach(opt => {
            if (opt.dataset.value === st) {
                opt.classList.add('selected');
            } else {
                opt.classList.remove('selected');
            }
        });
    }

    // Set alasan/catatan textarea
    document.getElementById('keuPayEditReason').value = p.reason || '';

    // Set preview link
    const link = document.getElementById('keuPayPreviewLink');
    if (p.preview_token) {
        link.href = `${BASE_URL}/dokumen/view/${p.preview_token}`;
        link.target = '_blank';
        link.style.opacity = '1';
        link.style.pointerEvents = 'auto';
    } else {
        link.href = '#';
        link.style.opacity = '0.4';
        link.style.pointerEvents = 'none';
    }

    document.getElementById('keuPaymentEditModal').style.display = 'flex';
};

window.closeKeuPaymentEditModal = function() {
    document.getElementById('keuPaymentEditModal').style.display = 'none';
};

window.keuPaySimpanPerubahan = function() {
    const id     = document.getElementById('keuPayEditId').value;
    const status = document.getElementById('keuPayEditStatus').value;
    const reason = document.getElementById('keuPayEditReason').value.trim();
    const btn    = document.getElementById('keuPayBtnSimpan');
    const orig   = btn.innerHTML;
    btn.disabled = true;
    btn.innerHTML = '<i class="ri-loader-4-line"></i> Menyimpan...';

    fetch(`${BASE_URL}/keuangan/api/payments/update`, {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: `id=${id}&status=${encodeURIComponent(status)}&reason=${encodeURIComponent(reason)}`
    })
    .then(r => r.json())
    .then(data => {
        closeKeuPaymentEditModal();
        if (data.success) {
            showAlert('Status pembayaran berhasil disimpan.', 'success');
            loadPaymentsData(document.getElementById('paymentsSearch')?.value || '', document.getElementById('paymentsFilter')?.value || 'all');
        } else {
            showAlert(data.message || 'Gagal menyimpan.', 'error');
        }
    })
    .catch(() => { closeKeuPaymentEditModal(); showAlert('Terjadi kesalahan sistem.', 'error'); })
    .finally(() => { btn.disabled = false; btn.innerHTML = orig; });
};



// Tutup modal saat klik backdrop
document.addEventListener('click', function(e) {
    const modal = document.getElementById('keuPaymentEditModal');
    if (modal && e.target === modal) closeKeuPaymentEditModal();
});


function initAdminCustomSelects(onChange) {
    document.querySelectorAll('.admin-custom-select:not(.initialized)').forEach(dropdown => {
        dropdown.classList.add('initialized');
        const trigger = dropdown.querySelector('.select-trigger');
        const options = dropdown.querySelectorAll('.option');
        const hiddenInput = dropdown.querySelector('input[type="hidden"]');
        const selectedText = dropdown.querySelector('.selected-text');

        trigger.addEventListener('click', (e) => {
            e.stopPropagation();
            document.querySelectorAll('.admin-custom-select.open').forEach(d => {
                if (d !== dropdown) d.classList.remove('open');
            });
            dropdown.classList.toggle('open');
        });

        options.forEach(opt => {
            opt.addEventListener('click', () => {
                const val = opt.dataset.value;
                const text = opt.textContent;

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
        document.addEventListener('click', () => {
            document.querySelectorAll('.admin-custom-select.open').forEach(d => {
                d.classList.remove('open');
            });
        });
    }
}



// =========================================================
// TEMPLATE KESANGGUPAN (KEUANGAN)
// =========================================================
let keuKesanggupanData = [];
let currentKeuKesanggupanPage = 1;
let itemsPerPageKeuKesanggupan = 5;

function initTemplateKesanggupan() {
    loadKeuKesanggupanData();
    if (typeof initAdminCustomSelects === 'function') initAdminCustomSelects();

    document.getElementById('kesanggupanSearch')?.addEventListener('input', () => applyKeuKesanggupanFilter());
    document.getElementById('kesanggupanFilter')?.addEventListener('change', () => applyKeuKesanggupanFilter());
    document.getElementById('kesanggupanMonth')?.addEventListener('change', () => applyKeuKesanggupanFilter());
    document.getElementById('kesanggupanYear')?.addEventListener('change', () => applyKeuKesanggupanFilter());
    document.getElementById('refreshKesanggupanBtn')?.addEventListener('click', () => loadKeuKesanggupanData());
    document.getElementById('kesanggupanPageSize')?.addEventListener('change', (e) => {
        itemsPerPageKeuKesanggupan = e.target.value === 'all' ? 999999 : parseInt(e.target.value, 10);
        currentKeuKesanggupanPage = 1;
        applyKeuKesanggupanFilter();
    });

    // Template aktif
    loadKeuTemplateAktif();

    // Upload template dropzone
    const dz = document.getElementById('template-dropzone');
    const inp = document.getElementById('inp-template-file');
    const btnUp = document.getElementById('btn-upload-template');
    if (dz && inp && btnUp) {
        dz.addEventListener('click', () => inp.click());
        inp.addEventListener('change', function() {
            if (this.files && this.files[0]) {
                document.getElementById('template-dz-text').textContent = this.files[0].name;
                document.getElementById('template-dz-subtext').textContent = 'File terpilih';
                btnUp.disabled = false; btnUp.style.opacity = '1'; btnUp.style.cursor = 'pointer';
            }
        });
        btnUp.addEventListener('click', function() {
            if (!inp.files || !inp.files[0]) return;
            const fd = new FormData();
            fd.append('file', inp.files[0]);
            btnUp.disabled = true;
            btnUp.innerHTML = '<i class="ri-loader-4-line"></i> Mengupload...';
            fetch(`${BASE_URL}/keuangan/api/kesanggupan-pembayaran/upload`, { method: 'POST', body: fd })
                .then(r => r.text())
                .then(text => {
                    try {
                        const data = JSON.parse(text);
                        if (data.success) {
                            showAlert('Template berhasil diunggah.', 'success');
                            inp.value = '';
                            document.getElementById('template-dz-text').textContent = 'Klik atau seret file .doc/.docx';
                            document.getElementById('template-dz-subtext').textContent = 'Maksimal 10MB';
                            btnUp.disabled = true; btnUp.style.opacity = '0.5'; btnUp.style.cursor = 'not-allowed';
                            btnUp.innerHTML = '<i class="ri-upload-2-line"></i> Upload';
                            loadKeuTemplateAktif();
                        } else {
                            showAlert(data.message || 'Gagal mengunggah template.', 'error');
                            btnUp.disabled = false; btnUp.style.opacity = '1';
                            btnUp.innerHTML = '<i class="ri-upload-2-line"></i> Upload';
                        }
                    } catch (e) {
                        console.error('Server response was not JSON:', text);
                        showAlert('Error Server: ' + text.substring(0, 50), 'error');
                        btnUp.disabled = false;
                        btnUp.innerHTML = '<i class="ri-upload-2-line"></i> Upload';
                    }
                })
                .catch(err => {
                    showAlert('Terjadi kesalahan jaringan.', 'error');
                    btnUp.disabled = false;
                    btnUp.innerHTML = '<i class="ri-upload-2-line"></i> Upload';
                });
        });
    }
}

function loadKeuTemplateAktif() {
    const container = document.getElementById('template-aktif-container');
    if (!container) return;
    fetch(`${BASE_URL}/keuangan/api/kesanggupan-pembayaran`)
        .then(r => r.json())
        .then(res => {
            if (res.success && res.data) {
                const t = res.data;
                container.innerHTML = `<div style="display:flex;align-items:center;gap:10px;">
                    <i class="ri-file-word-line" style="font-size:1.5rem;color:#4e73df;"></i>
                    <div><div style="font-weight:600;color:#2d3748;font-size:0.9rem;">${t.nama_asli}</div>
                    <div style="font-size:0.75rem;color:#94a3b8;margin-top:2px;">Aktif sejak: ${t.created_at_formatted || t.created_at || '-'}</div></div>
                    <a href="${BASE_URL}/keuangan/template/kesanggupan" style="margin-left:auto;padding:5px 12px;background:#4e73df;color:#fff;border-radius:6px;font-size:0.8rem;text-decoration:none;">
                        <i class="ri-download-line"></i> Unduh</a>
                </div>`;
            } else {
                container.innerHTML = `<p style="color:#94a3b8;margin:0;font-size:0.85rem;">Belum ada template aktif.</p>`;
            }
        })
        .catch(() => { container.innerHTML = `<p style="color:#e74a3b;margin:0;">Gagal memuat template.</p>`; });
}

function loadKeuKesanggupanData() {
    const tbody = document.getElementById('list-kesanggupan-pelanggan');
    if (!tbody) return;
    tbody.innerHTML = `<tr><td colspan="7" style="text-align:center;padding:20px;color:#858796;">Memuat data...</td></tr>`;

    fetch(`${BASE_URL}/keuangan/api/kesanggupan/uploads`)
        .then(r => r.json())
        .then(res => {
            keuKesanggupanData = res.data || [];
            currentKeuKesanggupanPage = 1;
            populateKeuKesanggupanYearDropdown();
            applyKeuKesanggupanFilter();
        })
        .catch(() => {
            tbody.innerHTML = `<tr><td colspan="7" style="text-align:center;color:#e74a3b;">Gagal memuat data.</td></tr>`;
        });
}

function populateKeuKesanggupanYearDropdown() {
    const years = [...new Set(keuKesanggupanData.map(p => {
        return p.created_at ? p.created_at.substring(0, 4) : null;
    }).filter(Boolean))].sort((a, b) => b - a);

    const yearOptions = document.getElementById('kesanggupanYearOptions');
    if (yearOptions) {
        yearOptions.innerHTML = '<div class="option selected" data-value="all">Semua Tahun</div>';
        years.forEach(y => {
            yearOptions.innerHTML += `<div class="option" data-value="${y}">${y}</div>`;
        });
        
        // Hanya me-re-bind event click ke options yang baru dibuat, bukan keseluruhan dropdown
        const dropdown = document.getElementById('dropdown-kesanggupanYear');
        if (dropdown) {
            const options = dropdown.querySelectorAll('.option');
            const hiddenInput = dropdown.querySelector('input[type="hidden"]');
            const selectedText = dropdown.querySelector('.selected-text');

            options.forEach(opt => {
                opt.addEventListener('click', () => {
                    const val = opt.dataset.value;
                    const text = opt.textContent;

                    options.forEach(o => o.classList.remove('selected'));
                    opt.classList.add('selected');

                    selectedText.textContent = text;
                    if (hiddenInput) {
                        hiddenInput.value = val;
                        hiddenInput.dispatchEvent(new Event('change', { bubbles: true }));
                    }

                    dropdown.classList.remove('open');
                });
            });
        }
    }
}

function applyKeuKesanggupanFilter() {
    const search = (document.getElementById('kesanggupanSearch')?.value || '').toLowerCase();
    const status = document.getElementById('kesanggupanFilter')?.value || 'pending';
    const month = document.getElementById('kesanggupanMonth')?.value || 'all';
    const year = document.getElementById('kesanggupanYear')?.value || 'all';

    const filtered = keuKesanggupanData.filter(r => {
        const matchSearch = !search || 
            (r.nama_perusahaan || '').toLowerCase().includes(search) || 
            (r.nama_lengkap || '').toLowerCase().includes(search) || 
            (r.keterangan || '').toLowerCase().includes(search);
        
        // Use "pending" as the default filter to match the default behavior for kesanggupan
        const matchStatus = status === 'all' || r.status === status;

        const datePart = r.created_at ? r.created_at.split(' ')[0] : '';
        const rowYear = datePart ? datePart.substring(0, 4) : '';
        const rowMonth = datePart ? datePart.substring(5, 7) : '';

        const matchMonth = month === 'all' || rowMonth === month;
        const matchYear = year === 'all' || rowYear === year;

        return matchSearch && matchStatus && matchMonth && matchYear;
    });
    currentKeuKesanggupanPage = 1;
    renderKeuKesanggupanTable(filtered);
}

function renderKeuKesanggupanTable(data) {
    if (!data) data = keuKesanggupanData;
    const tbody = document.getElementById('list-kesanggupan-pelanggan');
    if (!tbody) return;

    const totalData = data.length;
    const pageSize = (document.getElementById('kesanggupanPageSize')?.value === 'all') ? Math.max(totalData, 1) : Math.max(parseInt(document.getElementById('kesanggupanPageSize')?.value || itemsPerPageKeuKesanggupan, 10), 1);
    const totalPages = Math.ceil(totalData / pageSize);

    if (currentKeuKesanggupanPage < 1) currentKeuKesanggupanPage = 1;
    if (currentKeuKesanggupanPage > totalPages) currentKeuKesanggupanPage = totalPages;

    const start = (currentKeuKesanggupanPage - 1) * pageSize;
    const end = Math.min(start + pageSize, totalData);

    if (totalData === 0) {
        tbody.innerHTML = `<tr><td colspan="7" style="text-align:center;padding:30px;color:#858796;">Tidak ada data ditemukan.</td></tr>`;
        document.getElementById('kesanggupanStart').textContent = 0;
        document.getElementById('kesanggupanEnd').textContent = 0;
        document.getElementById('kesanggupanTotal').textContent = 0;
        document.getElementById('kesanggupanPagination').innerHTML = '';
        return;
    }

    const statusMap = {
        'Disetujui': {bg:'transparent',color:'#059669'},
        'Perbaiki':  {bg:'transparent',color:'#dc2626'},
        'Pending':   {bg:'transparent',color:'#d97706'}
    };

    tbody.innerHTML = data.slice(start, end).map(r => {
        const st = statusMap[r.ui_status] || statusMap['Pending'];
        const labelText = r.ui_status_label || r.ui_status || r.status;
        const statusBadge = `<span style="color:${st.color}; font-size:0.85rem; font-weight:600;">${labelText}</span>`;
        const suntingBtn = `<button onclick="openKeuKesanggupanModal(${r.id})" style="display:inline-flex; align-items:center; gap:5px; padding:6px 14px; background:#62A0FD; color:#fff; border:none; border-radius:6px; font-size:0.78rem; font-weight:600; cursor:pointer; transition:all 0.2s; white-space:nowrap; box-shadow:0 4px 12px rgba(98,160,253,0.3);">
            <i class="ri-edit-line"></i> Kelola
        </button>`;
        return `<tr style="transition:background 0.15s;" onmouseover="this.style.background='#f8fafc'" onmouseout="this.style.background=''">
            <td style="vertical-align:middle; font-size:0.82rem; color:#111; white-space:nowrap;">${r.created_at_formatted || r.created_at || '-'}</td>
            <td style="vertical-align:middle; font-size:0.85rem; color:#111;">${r.nama_perusahaan || r.nama_lengkap || '-'}</td>
            <td style="vertical-align:middle; font-size:0.82rem; color:#111; word-break:break-word;">${r.keterangan || '-'}</td>
            <td style="vertical-align:middle; font-size:0.82rem; color:#111;">${r.note || '-'}</td>
            <td style="vertical-align:middle; font-size:0.85rem; color:#111;">${r.verified_by_name || '-'}</td>
            <td style="vertical-align:middle; text-align:center;">${statusBadge}</td>
            <td style="vertical-align:middle; text-align:center;">${suntingBtn}</td>
        </tr>`;
    }).join('');

    document.getElementById('kesanggupanStart').textContent = start + 1;
    document.getElementById('kesanggupanEnd').textContent = end;
    document.getElementById('kesanggupanTotal').textContent = totalData;

    let pHtml = `<button class="page-btn ${currentKeuKesanggupanPage===1?'disabled':''}" onclick="changeKeuKesanggupanPage(${currentKeuKesanggupanPage-1})" ${currentKeuKesanggupanPage===1?'disabled':''}>&#171;</button>`;
    for (let i = 1; i <= totalPages; i++) pHtml += `<button class="page-btn ${i===currentKeuKesanggupanPage?'active':''}" onclick="changeKeuKesanggupanPage(${i})">${i}</button>`;
    pHtml += `<button class="page-btn ${currentKeuKesanggupanPage===totalPages?'disabled':''}" onclick="changeKeuKesanggupanPage(${currentKeuKesanggupanPage+1})" ${currentKeuKesanggupanPage===totalPages?'disabled':''}>&#187;</button>`;
    document.getElementById('kesanggupanPagination').innerHTML = pHtml;
}

window.changeKeuKesanggupanPage = function(page) {
    currentKeuKesanggupanPage = page;
    applyKeuKesanggupanFilter();
};

// ── Modal Sunting Kesanggupan ──────────────────────────────────────────────

window.openKeuKesanggupanModal = function(id) {
    const r = keuKesanggupanData.find(x => x.id == id);
    if (!r) return;

    document.getElementById('keuKesEditId').value = id;
    document.getElementById('keuKesEditFileName').value = r.file_name || '';
    document.getElementById('keuKesEditNote').value = r.note || '';

    // Set status dropdown
    const currentStatus = r.ui_status || 'Pending';
    const selHidden = document.getElementById('keuKesEditStatus');
    const selText   = document.getElementById('text-keuKesEditStatus');
    const dropdownWrap = document.getElementById('dropdown-keuKesEditStatus');
    if (selHidden) selHidden.value = currentStatus;
    if (selText)   selText.textContent = currentStatus;
    if (dropdownWrap) {
        dropdownWrap.querySelectorAll('.option').forEach(opt => {
            opt.classList.toggle('selected', opt.dataset.value === currentStatus);
        });
    }
    if (typeof initAdminCustomSelects === 'function') initAdminCustomSelects();

    // Preview link (secured with token)
    const link = document.getElementById('keuKesPreviewLink');
    if (link) {
        if (r.preview_token) {
            link.href = `${BASE_URL}/dokumen/view/${r.preview_token}`;
            link.target = '_blank';
            link.style.opacity = '1';
            link.style.pointerEvents = 'auto';
        } else {
            link.href = '#';
            link.style.opacity = '0.4';
            link.style.pointerEvents = 'none';
        }
    }

    document.getElementById('keuKesanggupanEditModal').style.display = 'flex';
};

window.closeKeuKesanggupanModal = function() {
    document.getElementById('keuKesanggupanEditModal').style.display = 'none';
};

window.keuKesSimpanPerubahan = function() {
    const id     = document.getElementById('keuKesEditId').value;
    const status = document.getElementById('keuKesEditStatus').value;
    const note   = document.getElementById('keuKesEditNote').value.trim();
    
    if (!note) {
        showAlert('Catatan / Alasan wajib diisi.', 'error');
        return;
    }

    const btn    = document.getElementById('keuKesBtnSimpan');
    const orig   = btn.innerHTML;
    btn.disabled = true;
    btn.innerHTML = '<i class="ri-loader-4-line"></i> Menyimpan...';

    fetch(`${BASE_URL}/keuangan/api/kesanggupan/validate`, {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: `id=${id}&status=${encodeURIComponent(status)}&note=${encodeURIComponent(note)}`
    })
    .then(r => r.json())
    .then(data => {
        closeKeuKesanggupanModal();
        if (data.success) {
            showAlert('Status kesanggupan berhasil disimpan.', 'success');
            loadKeuKesanggupanData();
        } else {
            showAlert(data.message || 'Gagal menyimpan.', 'error');
        }
    })
    .catch(() => { closeKeuKesanggupanModal(); showAlert('Terjadi kesalahan sistem.', 'error'); })
    .finally(() => { btn.disabled = false; btn.innerHTML = orig; });
};

// Tutup modal saat klik backdrop
document.addEventListener('click', function(e) {
    const modal = document.getElementById('keuKesanggupanEditModal');
    if (modal && e.target === modal) closeKeuKesanggupanModal();
});