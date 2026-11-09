const BASE_URL = (typeof window.BASE_URL !== 'undefined') ? window.BASE_URL : '/selport';

async function loadSection(sectionName, sampleType = null, orderId = null, filters = {}, options = {}) {
    const { targetSelector = '#dynamic-content', skipAnimation = false, skipScroll = false } = options;
    const contentContainer = document.querySelector(targetSelector);
    if (!contentContainer) return; // Exit if container doesn't exist (non-SPA page)

    if (!skipAnimation && typeof anime !== 'undefined' && contentContainer.children.length > 0) {
        await anime({
            targets: contentContainer,
            opacity: [1, 0],
            translateY: [0, 10],
            duration: 150, // Reduced from 250
            easing: 'easeInQuad'
        }).finished;
    }

    if (!skipAnimation) {
        contentContainer.innerHTML = '<div style="text-align: center; padding: 50px; color: #64748b; opacity: 0;" id="section-loader"><p>⏳ Loading...</p></div>';

        if (typeof anime !== 'undefined') {
            anime({
                targets: '#section-loader',
                opacity: [0, 1],
                duration: 100, // Reduced from 300
                easing: 'linear'
            });
        }
    }

    try {
        let url = `${BASE_URL}/dashboard/section/${sectionName}?_ts=${new Date().getTime()}`;
        if (sampleType) url += `&sample_type=${encodeURIComponent(sampleType)}`;
        // order_id dikirim ke server untuk memuat data; TIDAK pernah muncul di URL browser
        if (orderId) url += `&order_id=${encodeURIComponent(orderId)}`;


        Object.keys(filters).forEach(key => {
            if (filters[key]) url += `&${key}=${encodeURIComponent(filters[key])}`;
        });

        if (options.onlyTable) url += '&only_table=1';

        const response = await fetch(url);

        if (!response.ok) {
            throw new Error(`HTTP ${response.status}: ${response.statusText}`);
        }

        const result = await response.json();

        if (result.success) {
            if (!skipScroll) window.scrollTo(0, 0);

            contentContainer.innerHTML = result.html;

            executeScripts(contentContainer);

            let urlPath = sectionName;
            if (sectionName === 'questionnaire') urlPath = 'kuesioner';
            if (sectionName === 'kuesioner') urlPath = 'kuesioner';
            if (sectionName === 'profile') urlPath = 'myprofile';

            let newUrl = `${BASE_URL}/${urlPath}`;

            // Jika ada orderId, tukar dengan token aman sebelum push ke URL browser.
            // Ini mencegah angka id_arsip asli terekspos dan bisa ditebak/dimanipulasi.
            if (orderId && !window.isPopState) {
                try {
                    const tokenRes = await fetch(
                        `${BASE_URL}/api/order/token?id=${encodeURIComponent(orderId)}` +
                        `&section=${encodeURIComponent(sectionName)}` +
                        `&src=${encodeURIComponent(filters && filters.source ? filters.source : 'sil')}` +
                        `&sample=${encodeURIComponent(sampleType || '')}`
                    );
                    const tokenData = await tokenRes.json();
                    if (tokenData.success) {
                        newUrl += `?t=${encodeURIComponent(tokenData.token)}`;
                    }
                } catch (_) { /* fallback: tidak tambah param apapun */ }
            } else if (sampleType && !orderId) {
                newUrl += `?sample_type=${encodeURIComponent(sampleType)}`;
            }

            if (!window.isPopState) {
                history.pushState({ section: sectionName, __selport_dashboard__: true }, '', newUrl);
            }
            window.isPopState = false;

            const titleMap = {
                'beranda': 'Beranda',
                'pembayaran': 'Pembayaran',
                'kesanggupan': 'Kesanggupan Pembayaran',
                'permohonanpengecualian': 'Permohonan Pengecualian',
                'permohonanalat': 'Permohonan Alat',
                'kuesioner': 'Kuesioner Kepuasan',
                'keluhan': 'Keluhan',
                'detailorder': 'Detail Pesanan',
                'detailorderserti': 'Detail Pesanan',
                'detailorder-kalibrasi': 'Detail Pesanan',
                'services': 'Layanan',
                'profile': 'Profil Saya',
                'company': 'Profil Perusahaan'
            };
            if (titleMap[sectionName]) {
                document.title = titleMap[sectionName] + ' - Portal SELPORT';

            }

            updateNavActiveState(sectionName);

            if (sectionName === 'profile') await loadProfileData();
            if (sectionName === 'company' || sectionName === 'perusahaan') await loadProfileData();

            if (typeof anime !== 'undefined') {
                anime({
                    targets: contentContainer,
                    opacity: [0, 1],
                    translateY: [20, 0],
                    duration: 300,
                    easing: 'easeOutExpo'
                });

                if (sectionName === 'home' || sectionName === 'services') {
                    anime({
                        targets: '.service-grid .grid-item, .services-grid-redesign .service-card-v2',
                        opacity: [0, 1],
                        translateY: [30, 0],
                        scale: [0.95, 1],
                        delay: anime.stagger(100, { start: 200 }),
                        duration: 1000,
                        easing: 'easeOutElastic(1, .8)'
                    });
                }

                anime({
                    targets: '.section-header > *',
                    opacity: [0, 1],
                    translateX: [-20, 0],
                    delay: anime.stagger(50),
                    duration: 400,
                    easing: 'easeOutExpo'
                });

                if (sectionName === 'exemption') {
                    anime({
                        targets: '.exemption-container .content-card',
                        opacity: [0, 1],
                        translateY: [30, 0],
                        delay: anime.stagger(200, { start: 300 }),
                        duration: 800,
                        easing: 'easeOutExpo'
                    });

                    anime({
                        targets: '.exemption-container .form-group, .exemption-container input, .exemption-container select',
                        opacity: [0, 1],
                        translateY: [10, 0],
                        delay: anime.stagger(50, { start: 500 }),
                        duration: 600,
                        easing: 'easeOutQuad'
                    });

                    anime({
                        targets: '.exemption-container tbody tr',
                        opacity: [0, 1],
                        translateX: [-10, 0],
                        delay: anime.stagger(100, { start: 700 }),
                        duration: 600,
                        easing: 'easeOutQuad'
                    });
                }

                initPremiumHover();
            } else {
                contentContainer.style.opacity = '1';
            }

        } else {
            contentContainer.innerHTML = '<div class="alert alert-error">❌ Gagal memuat konten: ' + result.message + '</div>';
        }
    } catch (error) {
        contentContainer.innerHTML = `
            <div class="alert alert-error">
                <strong>❌ Terjadi kesalahan saat memuat konten</strong><br>
                <small>Error: ${error.message}</small>
            </div>
        `;
    }
}

function setupNavigation() {
    const navItems = document.querySelectorAll('.nav-item[data-section], .profile-menu-item[data-section]');
    const mobileToggle = document.getElementById('mobile-nav-toggle');
    const headerNav = document.getElementById('header-nav');

    if (mobileToggle) {
        mobileToggle.addEventListener('click', function () {
            this.classList.toggle('active');
            headerNav.classList.toggle('active');
        });
    }

    navItems.forEach(item => {
        item.addEventListener('click', function (e) {
            e.preventDefault();

            navItems.forEach(nav => nav.classList.remove('active'));

            this.classList.add('active');

            if (mobileToggle && mobileToggle.classList.contains('active')) {
                mobileToggle.classList.remove('active');
                headerNav.classList.remove('active');
            }

            const section = this.getAttribute('data-section');
            loadSection(section);
        });
    });

    // ── Dropdown: "Input Data Layanan" toggle ─────────────────────────────────
    const dropdownLayanan = document.getElementById('nav-dropdown-layanan');
    const toggleLayanan   = document.getElementById('nav-toggle-layanan');

    if (toggleLayanan && dropdownLayanan) {
        toggleLayanan.addEventListener('click', function (e) {
            e.preventDefault();
            e.stopPropagation();
            const isOpen = dropdownLayanan.classList.toggle('open');
            // On mobile, toggling also resets sub-dropdowns if closing
            if (!isOpen) {
                document.querySelectorAll('.nav-submenu-item.has-children').forEach(item => {
                    item.classList.remove('open');
                });
            }
        });
    }

    // ── Sub-dropdown: "Pengujian" toggle ──────────────────────────────────────
    const submenuPengujian = document.getElementById('submenu-pengujian');
    const submenuTogglePengujian = document.getElementById('submenu-toggle-pengujian');

    if (submenuTogglePengujian && submenuPengujian) {
        submenuTogglePengujian.addEventListener('click', function (e) {
            e.preventDefault();
            e.stopPropagation();
            submenuPengujian.classList.toggle('open');
        });
    }

    // ── Sub-dropdown: "Kalibrasi" toggle ─────────────────────────────────────
    const submenuKalibrasi = document.getElementById('submenu-kalibrasi');
    const submenuToggleKalibrasi = document.getElementById('submenu-toggle-kalibrasi');

    if (submenuToggleKalibrasi && submenuKalibrasi) {
        submenuToggleKalibrasi.addEventListener('click', function (e) {
            e.preventDefault();
            e.stopPropagation();
            submenuKalibrasi.classList.toggle('open');
        });
    }

    // ── nav-sub-link clicks (Permohonan Pengecualian, Permohonan Alat, etc.) ──────
    document.querySelectorAll('.nav-sub-link[data-section]').forEach(link => {
        link.addEventListener('click', function (e) {
            e.preventDefault();
            e.stopPropagation();

            // Close all dropdowns
            if (dropdownLayanan) dropdownLayanan.classList.remove('open');
            document.querySelectorAll('.nav-submenu-item.has-children').forEach(item => {
                item.classList.remove('open');
            });

            // Close mobile nav
            if (mobileToggle && mobileToggle.classList.contains('active')) {
                mobileToggle.classList.remove('active');
                headerNav.classList.remove('active');
            }

            const section = this.getAttribute('data-section');
            loadSection(section);
        });
    });

    // ── Close dropdowns when clicking outside ────────────────────────────────
    document.addEventListener('click', function (e) {
        if (mobileToggle && !mobileToggle.contains(e.target) && !headerNav.contains(e.target)) {
            mobileToggle.classList.remove('active');
            headerNav.classList.remove('active');
        }

        if (dropdownLayanan && !dropdownLayanan.contains(e.target)) {
            dropdownLayanan.classList.remove('open');
            document.querySelectorAll('.nav-submenu-item.has-children').forEach(item => {
                item.classList.remove('open');
            });
        }
    });

    if (document.getElementById('dynamic-content')) {
        const initialSection = window.INITIAL_SECTION || 'beranda';
        const initialSample  = window.INITIAL_SAMPLE_TYPE || null;

        if (!history.state) {
            history.replaceState({ section: initialSection }, '', window.location.href);
        }

        // Cek apakah URL mengandung token order (?t=...) dari navigasi sebelumnya.
        // Jika ada, resolve token ke order_id asli sebelum memuat section.
        const urlParams     = new URLSearchParams(window.location.search);
        const orderToken    = urlParams.get('t');

        window.isPopState = true; // Mencegah pushState pada loadSection pertama

        if (orderToken && (initialSection === 'detailorder' || initialSection === 'detailorderserti' || initialSection === 'detailorder-kalibrasi' || initialSection === 'download_berkas')) {
            // Resolve token → dapatkan order_id asli, lalu muat halaman
            fetch(`${BASE_URL}/api/order/resolve?t=${encodeURIComponent(orderToken)}`)
                .then(r => r.json())
                .then(data => {
                    if (data.success) {
                        const filters = data.source ? { source: data.source } : {};
                        loadSection(data.section || initialSection, data.sample || initialSample, data.id, filters)
                            .catch(err => console.error('Initial Load (token) Failure:', err));
                    } else {
                        // Token tidak valid — muat beranda sebagai fallback
                        console.warn('Token order tidak valid, redirect ke beranda.');
                        loadSection('beranda').catch(err => console.error(err));
                    }
                })
                .catch(() => loadSection('beranda'));
        } else {
            loadSection(initialSection, initialSample, null)
                .catch(err => console.error('Initial Load Failure:', err));
        }

        const activeNav = document.querySelector(`.nav-item[data-section="${initialSection}"]`);
        if (activeNav) {
            navItems.forEach(nav => nav.classList.remove('active'));
            activeNav.classList.add('active');
        }
    }

    document.body.addEventListener('click', async function (e) {
        const detailBtn = e.target.closest('.view-order-detail');
        if (!detailBtn) return;

        e.preventDefault();
        e.stopPropagation();

        const section = detailBtn.dataset.section;
        const sample  = detailBtn.dataset.sample || '';
        const id      = detailBtn.dataset.id || '';
        const source  = detailBtn.dataset.source || 'sil';

        if (!section) return;
        if (!id) {
            console.warn('Detail button clicked but data-id is empty.', { section, sample });
        }

        const filters = source ? { source } : {};
        // Muat konten terlebih dahulu (menggunakan order_id asli di server-side fetch)
        await loadSection(section, sample, id || null, filters);
    });
}

function showAlert(message, type) {
    const alert = document.getElementById('alert');
    alert.className = 'alert alert-' + type;
    alert.textContent = message;
    alert.style.display = 'block';

    setTimeout(() => {
        alert.style.display = 'none';
    }, 5000);
}

function toggleProfile() {
    const dropdown = document.getElementById('profile-dropdown');
    dropdown.classList.toggle('active');
}

document.addEventListener('click', function (e) {
    const profileDropdown = document.getElementById('profile-dropdown');
    const profileBtn = document.querySelector('.profile-btn');

    if (profileDropdown && profileDropdown.classList.contains('active')) {
        if (!profileDropdown.contains(e.target) && !profileBtn.contains(e.target)) {
            profileDropdown.classList.remove('active');
        }
    }
});

function closeModal(modalId) {
    const modal = document.getElementById(modalId);
    if (modal) modal.classList.remove('active');
}

async function loadProfileData() {
    try {
        const response = await fetch(`${BASE_URL}/dashboard/profile`);
        const result = await response.json();

        if (result.success && result.data) {
            const data = result.data;

            const fields = [
                'nama_lengkap', 'email', 'no_whatsapp', 'alamat_tinggal', 'kodepos_tinggal',
                'nama_perusahaan', 'alamat_perusahaan', 'telp_perusahaan', 'wa_perusahaan', 'fax_perusahaan', 'kodepos_perusahaan',
                'tanggal_lahir', 'jabatan'
            ];

            fields.forEach(field => {
                const element = document.getElementById(field);
                if (element) {
                    element.value = data[field] || '';
                }
            });

            await initAddressFields(data);

            if (data.nama_lengkap && document.getElementById('display_name_sidebar')) {
                document.getElementById('display_name_sidebar').textContent = data.nama_lengkap;
            }
            if (data.nama_perusahaan && document.getElementById('display_company_sidebar')) {
                document.getElementById('display_company_sidebar').textContent = data.nama_perusahaan;
            }

            if (data.profile_photo_url) {
                const preview = document.querySelector('.profile-avatar');
                if (preview) {
                    preview.src = data.profile_photo_url + '?t=' + new Date().getTime();
                }
            }
        }
    } catch (error) {
        showAlert('Gagal memuat data profil', 'error');
    }
}

async function loadSidebarCompanyData() {
    try {
        const response = await fetch(`${BASE_URL}/dashboard/profile`);
        const result = await response.json();
        if (result.success && result.data) {
            if (result.data.nama_perusahaan && document.getElementById('display_company_sidebar')) {
                document.getElementById('display_company_sidebar').textContent = result.data.nama_perusahaan;
            }
        }
    } catch (error) {
        console.error('Error loading sidebar data:', error);
    }
}

async function handleFullProfileUpdate(e) {
    e.preventDefault();
    const form = e.target;
    const formData = new FormData(form);

    formData.append('csrf_token', window.CSRF_TOKEN || '');
    formData.append('update_type', 'personal');

    const btn = form.querySelector('button[type="submit"]');
    const originalText = btn.innerHTML;

    btn.disabled = true;
    btn.innerHTML = 'Menyimpan...';

    try {
        const response = await fetch(`${BASE_URL}/dashboard/profile`, {
            method: 'POST',
            body: formData
        });

        const result = await response.json();

        if (result.success) {
            const nameField = document.getElementById('nama_lengkap');
            const sidebarName = document.getElementById('display_name_sidebar');
            if (nameField && sidebarName) sidebarName.textContent = nameField.value;

            // If a new photo was uploaded, update ALL photo elements immediately
            if (result.profile_photo_url) {
                const newPhotoUrl = result.profile_photo_url;
                // Update photo in form preview
                document.querySelectorAll('.profile-avatar').forEach(img => {
                    img.src = newPhotoUrl;
                });
                // Update photo in header/sidebar/navbar
                document.querySelectorAll('#header-profile-photo, #dropdown-profile-photo, .header-profile-img, .sidebar-profile-photo, .nav-profile-photo').forEach(img => {
                    img.src = newPhotoUrl;
                });
            }

            const notification = document.getElementById('profile-success-notification');
            if (notification) {
                notification.style.display = 'block';
                notification.scrollIntoView({ behavior: 'smooth', block: 'center' });
                setTimeout(() => {
                    notification.style.display = 'none';
                    loadSection('profile');
                }, 1500);
            } else {
                showAlert(result.message, 'success');
                loadSection('profile');
            }
        } else {
            showAlert(result.message, 'error');
        }
    } catch (error) {
        showAlert('Terjadi kesalahan: ' + error.message, 'error');
    } finally {
        btn.disabled = false;
        btn.innerHTML = originalText;
    }
}

async function handleCompanyPageUpdateFromSection(e) {
    e.preventDefault();
    const form = e.target;
    const formData = new FormData(form);

    formData.append('csrf_token', window.CSRF_TOKEN || '');
    formData.append('update_type', 'company');

    const btn = form.querySelector('button[type="submit"]');
    const originalText = btn.innerHTML;

    btn.disabled = true;
    btn.innerHTML = 'Menyimpan...';

    try {
        const response = await fetch(`${BASE_URL}/dashboard/profile`, {
            method: 'POST',
            body: formData
        });

        const result = await response.json();

        if (result.success) {
            const newName = document.getElementById('nama_perusahaan').value;
            if (document.getElementById('display_company_sidebar')) {
                document.getElementById('display_company_sidebar').textContent = newName;
            }

            const notification = document.getElementById('company-success-notification');
            if (notification) {
                notification.style.display = 'block';
                notification.scrollIntoView({ behavior: 'smooth', block: 'center' });
                setTimeout(() => {
                    notification.style.display = 'none';
                    loadSection('perusahaan');
                }, 1500);
            } else {
                showAlert(result.message, 'success');
                loadSection('perusahaan');
            }
        } else {
            showAlert(result.message, 'error');
        }
    } catch (error) {
        showAlert('Terjadi kesalahan: ' + error.message, 'error');
    } finally {
        btn.disabled = false;
        btn.innerHTML = originalText;
    }
}

async function loadProvinces() {
    try {
        const base = window.BASE_URL || '';
        const res = await fetch(`${base}/api/wilayah/provinsi`);
        const result = await res.json();
        if (!result.success) return;

        const personalProvinces = document.getElementById('provinsi_tinggal');
        const companyProvinces = document.getElementById('provinsi_perusahaan');

        result.data.forEach(p => {
            const opt = `<option value="${p.kode_provinsi}" data-name="${p.nama_provinsi}">${p.nama_provinsi}</option>`;
            if (personalProvinces) personalProvinces.insertAdjacentHTML('beforeend', opt);
            if (companyProvinces) companyProvinces.insertAdjacentHTML('beforeend', opt);
        });
    } catch (e) { console.error('loadProvinces error', e); }
}

async function loadKabupaten(kodeProvinsi, targetSelectId) {
    const targetSelect = document.getElementById(targetSelectId);
    if (!targetSelect) return;
    targetSelect.innerHTML = '<option value="">Pilih Kabupaten</option>';

    // Reset kecamatan & kodepos downstream
    const kecId = targetSelectId.replace('kabupaten', 'kecamatan');
    const kecEl = document.getElementById(kecId);
    if (kecEl) kecEl.innerHTML = '<option value="">Pilih Kecamatan</option>';
    const kodeposId = targetSelectId.replace('kabupaten', 'kodepos');
    const kodeposEl = document.getElementById(kodeposId);
    if (kodeposEl) kodeposEl.innerHTML = '<option value="">Pilih Kode Pos</option>';

    if (!kodeProvinsi) return;
    try {
        const base = window.BASE_URL || '';
        const res = await fetch(`${base}/api/wilayah/kabupaten?kode_provinsi=${encodeURIComponent(kodeProvinsi)}`);
        const result = await res.json();
        if (!result.success) return;
        result.data.forEach(k => {
            targetSelect.insertAdjacentHTML('beforeend',
                `<option value="${k.kode_kabupaten}" data-name="${k.nama_kabupaten}">${k.nama_kabupaten}</option>`);
        });
    } catch (e) { console.error('loadKabupaten error', e); }
}

async function loadKecamatan(kodeKabupaten, targetSelectId) {
    const targetSelect = document.getElementById(targetSelectId);
    if (!targetSelect) return;
    targetSelect.innerHTML = '<option value="">Pilih Kecamatan</option>';

    // Reset kodepos downstream
    const kodeposId = targetSelectId.replace('kecamatan', 'kodepos');
    const kodeposEl = document.getElementById(kodeposId);
    if (kodeposEl) kodeposEl.innerHTML = '<option value="">Pilih Kode Pos</option>';

    if (!kodeKabupaten) return;
    try {
        const base = window.BASE_URL || '';
        const res = await fetch(`${base}/api/wilayah/kecamatan?kode_kabupaten=${encodeURIComponent(kodeKabupaten)}`);
        const result = await res.json();
        if (!result.success) return;
        result.data.forEach(k => {
            targetSelect.insertAdjacentHTML('beforeend',
                `<option value="${k.kode_kecamatan}" data-name="${k.nama_kecamatan}" data-kodepos="${k.kode_pos || ''}">${k.nama_kecamatan}</option>`);
        });
    } catch (e) { console.error('loadKecamatan error', e); }
}

function updateRegionName(select, hiddenId) {
    const hidden = document.getElementById(hiddenId);
    if (!hidden) return;
    hidden.value = select.selectedIndex > 0
        ? (select.options[select.selectedIndex].getAttribute('data-name') || '')
        : '';
}

/**
 * Mengisi dropdown kode pos dari atribut data-kodepos pada opsi kecamatan yang dipilih.
 * Signature dipertahankan agar HTML tidak perlu diubah.
 */
function loadPostalCodes(districtName, regencyName, targetSelectId) {
    const kodeposEl = document.getElementById(targetSelectId);
    if (!kodeposEl) return;

    // Cari kecamatan select yang sesuai berdasarkan targetSelectId
    // pola: kodepos_tinggal → kecamatan_tinggal | kodepos_perusahaan → kecamatan_perusahaan
    const kecId = targetSelectId.replace('kodepos', 'kecamatan');
    const kecEl = document.getElementById(kecId);

    let kodePosValue = '';
    if (kecEl && kecEl.selectedIndex > 0) {
        kodePosValue = kecEl.options[kecEl.selectedIndex].getAttribute('data-kodepos') || '';
    }

    // Pastikan elemen adalah <select>; kalau sebelumnya diganti jadi <input>, kembalikan ke select
    if (kodeposEl.tagName === 'INPUT') {
        const parent = kodeposEl.parentElement;
        const newSelect = document.createElement('select');
        newSelect.id = targetSelectId;
        newSelect.name = kodeposEl.name;
        if (kodeposEl.className) newSelect.className = kodeposEl.className;
        parent.replaceChild(newSelect, kodeposEl);
    }

    const finalEl = document.getElementById(targetSelectId);
    finalEl.innerHTML = '<option value="">Pilih Kode Pos</option>';

    if (kodePosValue) {
        finalEl.insertAdjacentHTML('beforeend', `<option value="${kodePosValue}" selected>${kodePosValue}</option>`);
    }
}

async function initAddressFields(userData) {
    // Detect which dropdowns are present in the DOM to decide what to initialize.
    // This is more reliable than trusting history.state alone (which can be stale).
    const hasTinggalDd = !!document.getElementById('dd-provinsi_tinggal');
    const hasPerusahaanDd = !!document.getElementById('dd-provinsi_perusahaan');

    if (hasTinggalDd) {
        await initAddressFieldsCustom(userData);
    }
    if (hasPerusahaanDd) {
        await initAddressFieldsCustomPerusahaan(userData);
    }
}

// ── Helper: set label on a custom-select ─────────────────────────────────────
function setCustomSelectLabel(ddId, label) {
    const dd = document.getElementById(ddId);
    if (dd) {
        const span = dd.querySelector('.selected-text');
        if (span) span.textContent = label;
    }
}

// ── Helper: populate custom-select options from an array ──────────────────────
// NOTE: Intentionally does NOT call setupCustomDropdown here.
// Callers (initAddressFieldsCustom, initAddressFieldsCustomPerusahaan, etc.)
// are responsible for calling setupCustomDropdown WITH the correct onChange
// cascade handler AFTER populating options.
function populateCustomSelect(ddId, items, placeholder, valueKey, nameKey) {
    const dd = document.getElementById(ddId);
    if (!dd) return;
    const container = dd.querySelector('.select-options');
    if (!container) return;
    let html = `<div class="option" data-value="">${placeholder}</div>`;
    items.forEach(item => {
        html += `<div class="option" data-value="${item[valueKey]}" data-name="${item[nameKey]}">${item[nameKey]}</div>`;
    });
    container.innerHTML = html;
}

// ── Helper API Wilayah (untuk Custom Select di Dashboard) ─────────────────
async function loadKabupatenCustom(provinceId, targetInputId, ddPrefix = 'dd-') {
    const dropdownId = ddPrefix + targetInputId;
    const districtInputId = targetInputId.replace('kabupaten', 'kecamatan');
    const postalInputId = targetInputId.replace('kabupaten', 'kodepos');

    updateCustomDropdownOptions(dropdownId, [], 'Pilih Kabupaten');
    updateCustomDropdownOptions(ddPrefix + districtInputId, [], 'Pilih Kecamatan');
    updateCustomDropdownOptions(ddPrefix + postalInputId, [], 'Pilih Kode Pos');

    if (!provinceId) return;

    try {
        const base = window.BASE_URL || '';
        const response = await fetch(`${base}/api/wilayah/kabupaten?kode_provinsi=${encodeURIComponent(provinceId)}`);
        const result = await response.json();
        if (!result.success) return;

        const formatted = result.data.map(r => ({ id: r.kode_kabupaten, name: r.nama_kabupaten }));
        updateCustomDropdownOptions(dropdownId, formatted, 'Pilih Kabupaten');
    } catch (error) {
        console.error('Error loading regencies:', error);
    }
}

async function loadKecamatanCustom(regencyId, targetInputId, ddPrefix = 'dd-') {
    const dropdownId = ddPrefix + targetInputId;
    const postalInputId = targetInputId.replace('kecamatan', 'kodepos');

    updateCustomDropdownOptions(dropdownId, [], 'Pilih Kecamatan');
    updateCustomDropdownOptions(ddPrefix + postalInputId, [], 'Pilih Kode Pos');

    if (!regencyId) return;

    try {
        const base = window.BASE_URL || '';
        const response = await fetch(`${base}/api/wilayah/kecamatan?kode_kabupaten=${encodeURIComponent(regencyId)}`);
        const result = await response.json();
        if (!result.success) return;

        const formatted = result.data.map(d => ({ id: d.kode_kecamatan, name: d.nama_kecamatan, kode_pos: d.kode_pos }));
        updateCustomDropdownOptions(dropdownId, formatted, 'Pilih Kecamatan');
    } catch (error) {
        console.error('Error loading districts:', error);
    }
}

function loadPostalCodeCustom(districtId, targetInputId, ddPrefix = 'dd-') {
    const dropdownId = ddPrefix + targetInputId;
    updateCustomDropdownOptions(dropdownId, [], 'Pilih Kode Pos');

    if (!districtId) return;

    const districtOption = document.querySelector(`#${ddPrefix}${targetInputId.replace('kodepos', 'kecamatan')} .option[data-value="${districtId}"]`);
    const kodePos = districtOption?.dataset.kodepos || '';

    if (kodePos) {
        updateCustomDropdownOptions(dropdownId, [{ id: kodePos, name: kodePos }], 'Pilih Kode Pos');
        const input = document.getElementById(targetInputId);
        const firstOption = document.querySelector(`#${dropdownId} .option[data-value="${kodePos}"]`);
        if (firstOption) firstOption.click();
    }
}

async function initAddressFieldsCustom(userData) {
    const ddPrefix = 'dd-';

    // 1. Bind event cascade SATU KALI saja
    setupCustomDropdown(ddPrefix + 'provinsi_tinggal', 'provinsi_tinggal', (val) => {
        const name = document.querySelector(`#${ddPrefix}provinsi_tinggal .option[data-value="${val}"]`)?.dataset.name || '';
        document.getElementById('provinsi_tinggal_name').value = name;
        loadKabupatenCustom(val, 'kabupaten_tinggal', ddPrefix);
    });
    setupCustomDropdown(ddPrefix + 'kabupaten_tinggal', 'kabupaten_tinggal', (val) => {
        const name = document.querySelector(`#${ddPrefix}kabupaten_tinggal .option[data-value="${val}"]`)?.dataset.name || '';
        document.getElementById('kabupaten_tinggal_name').value = name;
        loadKecamatanCustom(val, 'kecamatan_tinggal', ddPrefix);
    });
    setupCustomDropdown(ddPrefix + 'kecamatan_tinggal', 'kecamatan_tinggal', (val) => {
        const name = document.querySelector(`#${ddPrefix}kecamatan_tinggal .option[data-value="${val}"]`)?.dataset.name || '';
        document.getElementById('kecamatan_tinggal_name').value = name;
        loadPostalCodeCustom(val, 'kodepos_tinggal', ddPrefix);
    });
    setupCustomDropdown(ddPrefix + 'kodepos_tinggal', 'kodepos_tinggal');

    // 2. Load list Provinsi awal
    const base = window.BASE_URL || '';
    let provinsiList = [];
    try {
        const res = await fetch(`${base}/api/wilayah/provinsi`);
        const result = await res.json();
        if (result.success) provinsiList = result.data;
    } catch (e) { }

    const formattedProv = provinsiList.map(p => ({ id: p.kode_provinsi, name: p.nama_provinsi }));
    updateCustomDropdownOptions(ddPrefix + 'provinsi_tinggal', formattedProv, 'Pilih Provinsi');

    // 3. Pre-fill berdasarkan userData
    if (!userData || !userData.provinsi_tinggal) return;
    const provItem = provinsiList.find(p => p.nama_provinsi.toLowerCase() === userData.provinsi_tinggal.toLowerCase());
    if (!provItem) return;

    // Simulate click on Provinsi
    const provOpt = document.querySelector(`#${ddPrefix}provinsi_tinggal .option[data-value="${provItem.kode_provinsi}"]`);
    if (provOpt) provOpt.click();

    // Tunggu sebentar lalu isi Kabupaten
    setTimeout(async () => {
        if (!userData.kabupaten_tinggal) return;
        const resKab = await fetch(`${base}/api/wilayah/kabupaten?kode_provinsi=${provItem.kode_provinsi}`);
        const resultKab = await resKab.json();
        if (!resultKab.success) return;

        const kabItem = resultKab.data.find(k => k.nama_kabupaten.toLowerCase() === userData.kabupaten_tinggal.toLowerCase());
        if (!kabItem) return;

        const kabOpt = document.querySelector(`#${ddPrefix}kabupaten_tinggal .option[data-value="${kabItem.kode_kabupaten}"]`);
        if (kabOpt) kabOpt.click();

        // Tunggu sebentar lalu isi Kecamatan
        setTimeout(async () => {
            if (!userData.kecamatan_tinggal) return;
            const resKec = await fetch(`${base}/api/wilayah/kecamatan?kode_kabupaten=${kabItem.kode_kabupaten}`);
            const resultKec = await resKec.json();
            if (!resultKec.success) return;

            const kecItem = resultKec.data.find(k => k.nama_kecamatan.toLowerCase() === userData.kecamatan_tinggal.toLowerCase());
            if (!kecItem) return;

            const kecOpt = document.querySelector(`#${ddPrefix}kecamatan_tinggal .option[data-value="${kecItem.kode_kecamatan}"]`);
            if (kecOpt) kecOpt.click();

            // Kodepos otomatis terisi dari loadPostalCodeCustom via cascade click kecamatan
        }, 100);
    }, 100);
}

async function initAddressFieldsCustomPerusahaan(userData) {
    const ddPrefix = 'dd-';

    // 1. Bind event cascade SATU KALI saja
    setupCustomDropdown(ddPrefix + 'provinsi_perusahaan', 'provinsi_perusahaan', (val) => {
        const name = document.querySelector(`#${ddPrefix}provinsi_perusahaan .option[data-value="${val}"]`)?.dataset.name || '';
        document.getElementById('provinsi_perusahaan_name').value = name;
        loadKabupatenCustom(val, 'kabupaten_perusahaan', ddPrefix);
    });
    setupCustomDropdown(ddPrefix + 'kabupaten_perusahaan', 'kabupaten_perusahaan', (val) => {
        const name = document.querySelector(`#${ddPrefix}kabupaten_perusahaan .option[data-value="${val}"]`)?.dataset.name || '';
        document.getElementById('kabupaten_perusahaan_name').value = name;
        loadKecamatanCustom(val, 'kecamatan_perusahaan', ddPrefix);
    });
    setupCustomDropdown(ddPrefix + 'kecamatan_perusahaan', 'kecamatan_perusahaan', (val) => {
        const name = document.querySelector(`#${ddPrefix}kecamatan_perusahaan .option[data-value="${val}"]`)?.dataset.name || '';
        document.getElementById('kecamatan_perusahaan_name').value = name;
        loadPostalCodeCustom(val, 'kodepos_perusahaan', ddPrefix);
    });
    setupCustomDropdown(ddPrefix + 'kodepos_perusahaan', 'kodepos_perusahaan');

    // 2. Load list Provinsi awal
    const base = window.BASE_URL || '';
    let provinsiList = [];
    try {
        const res = await fetch(`${base}/api/wilayah/provinsi`);
        const result = await res.json();
        if (result.success) provinsiList = result.data;
    } catch (e) { }

    const formattedProv = provinsiList.map(p => ({ id: p.kode_provinsi, name: p.nama_provinsi }));
    updateCustomDropdownOptions(ddPrefix + 'provinsi_perusahaan', formattedProv, 'Pilih Provinsi');

    // 3. Pre-fill berdasarkan userData
    if (!userData || !userData.provinsi_perusahaan) return;
    const provItem = provinsiList.find(p => p.nama_provinsi.toLowerCase() === userData.provinsi_perusahaan.toLowerCase());
    if (!provItem) return;

    // Simulate click on Provinsi
    const provOpt = document.querySelector(`#${ddPrefix}provinsi_perusahaan .option[data-value="${provItem.kode_provinsi}"]`);
    if (provOpt) provOpt.click();

    // Tunggu sebentar lalu isi Kabupaten
    setTimeout(async () => {
        if (!userData.kabupaten_perusahaan) return;
        const resKab = await fetch(`${base}/api/wilayah/kabupaten?kode_provinsi=${provItem.kode_provinsi}`);
        const resultKab = await resKab.json();
        if (!resultKab.success) return;

        const kabItem = resultKab.data.find(k => k.nama_kabupaten.toLowerCase() === userData.kabupaten_perusahaan.toLowerCase());
        if (!kabItem) return;

        const kabOpt = document.querySelector(`#${ddPrefix}kabupaten_perusahaan .option[data-value="${kabItem.kode_kabupaten}"]`);
        if (kabOpt) kabOpt.click();

        // Tunggu sebentar lalu isi Kecamatan
        setTimeout(async () => {
            if (!userData.kecamatan_perusahaan) return;
            const resKec = await fetch(`${base}/api/wilayah/kecamatan?kode_kabupaten=${kabItem.kode_kabupaten}`);
            const resultKec = await resKec.json();
            if (!resultKec.success) return;

            const kecItem = resultKec.data.find(k => k.nama_kecamatan.toLowerCase() === userData.kecamatan_perusahaan.toLowerCase());
            if (!kecItem) return;

            const kecOpt = document.querySelector(`#${ddPrefix}kecamatan_perusahaan .option[data-value="${kecItem.kode_kecamatan}"]`);
            if (kecOpt) kecOpt.click();
        }, 100);
    }, 100);
}


// ── Native select version (perusahaan.html) ───────────────────────────────────
async function initAddressFieldsNative(userData) {
    await loadProvinces();

    if (userData.provinsi_tinggal) {
        const provSelect = document.getElementById('provinsi_tinggal');
        if (provSelect) {
            const opt = Array.from(provSelect.options).find(o => (o.getAttribute('data-name') || '').toLowerCase() === userData.provinsi_tinggal.toLowerCase());
            if (opt) {
                provSelect.value = opt.value;
                document.getElementById('provinsi_tinggal_name').value = userData.provinsi_tinggal;
                await loadKabupaten(opt.value, 'kabupaten_tinggal');

                const kabEl = document.getElementById('kabupaten_tinggal');
                const kabOpt = Array.from(kabEl.options).find(o => (o.getAttribute('data-name') || '').toLowerCase() === userData.kabupaten_tinggal.toLowerCase());
                if (kabOpt) {
                    kabEl.value = kabOpt.value;
                    document.getElementById('kabupaten_tinggal_name').value = userData.kabupaten_tinggal;
                    await loadKecamatan(kabOpt.value, 'kecamatan_tinggal');

                    const kecEl = document.getElementById('kecamatan_tinggal');
                    const kecOpt = Array.from(kecEl.options).find(o => (o.getAttribute('data-name') || '').toLowerCase() === userData.kecamatan_tinggal.toLowerCase());
                    if (kecOpt) {
                        kecEl.value = kecOpt.value;
                        document.getElementById('kecamatan_tinggal_name').value = userData.kecamatan_tinggal;
                        loadPostalCodes(userData.kecamatan_tinggal, userData.kabupaten_tinggal, 'kodepos_tinggal');
                        if (userData.kodepos_tinggal) {
                            const kpEl = document.getElementById('kodepos_tinggal');
                            if (kpEl) kpEl.value = userData.kodepos_tinggal;
                        }
                    }
                }
            }
        }
    }

    if (userData.provinsi_perusahaan) {
        const provSelect = document.getElementById('provinsi_perusahaan');
        if (provSelect) {
            const opt = Array.from(provSelect.options).find(o => (o.getAttribute('data-name') || '').toLowerCase() === userData.provinsi_perusahaan.toLowerCase());
            if (opt) {
                provSelect.value = opt.value;
                document.getElementById('provinsi_perusahaan_name').value = userData.provinsi_perusahaan;
                await loadKabupaten(opt.value, 'kabupaten_perusahaan');

                const kabEl = document.getElementById('kabupaten_perusahaan');
                const kabOpt = Array.from(kabEl.options).find(o => (o.getAttribute('data-name') || '').toLowerCase() === userData.kabupaten_perusahaan.toLowerCase());
                if (kabOpt) {
                    kabEl.value = kabOpt.value;
                    document.getElementById('kabupaten_perusahaan_name').value = userData.kabupaten_perusahaan;
                    await loadKecamatan(kabOpt.value, 'kecamatan_perusahaan');

                    const kecEl = document.getElementById('kecamatan_perusahaan');
                    const kecOpt = Array.from(kecEl.options).find(o => (o.getAttribute('data-name') || '').toLowerCase() === userData.kecamatan_perusahaan.toLowerCase());
                    if (kecOpt) {
                        kecEl.value = kecOpt.value;
                        document.getElementById('kecamatan_perusahaan_name').value = userData.kecamatan_perusahaan;
                        loadPostalCodes(userData.kecamatan_perusahaan, userData.kabupaten_perusahaan, 'kodepos_perusahaan');
                        if (userData.kodepos_perusahaan) {
                            const kpEl = document.getElementById('kodepos_perusahaan');
                            if (kpEl) kpEl.value = userData.kodepos_perusahaan;
                        }
                    }
                }
            }
        }
    }
}

async function changePassword(e) {
    e.preventDefault();

    const form = e.target;
    const formData = new FormData(form);

    const newPassword = formData.get('new_password');
    const confirmPassword = formData.get('confirm_password');

    if (newPassword.length < 6) {
        showAlert('Password baru minimal 6 karakter', 'error');
        return;
    }

    if (newPassword !== confirmPassword) {
        showAlert('Konfirmasi kata sandi tidak cocok', 'error');
        return;
    }

    try {
        const response = await fetch(`${BASE_URL}/dashboard/change-password`, {
            method: 'POST',
            body: formData
        });

        const result = await response.json();

        if (result.success) {
            showAlert(result.message, 'success');
            form.reset();
        } else {
            showAlert(result.message, 'error');
        }
    } catch (error) {
        showAlert('Terjadi kesalahan: ' + error.message, 'error');
    }
}

function togglePasswordVisibility(inputId) {
    const input = document.getElementById(inputId);
    const icon = document.getElementById(inputId + '-icon');

    if (!input) return;

    if (input.type === 'password') {
        input.type = 'text';
        if (icon) icon.innerHTML = `<svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"></path><circle cx="12" cy="12" r="3"></circle></svg>`;
    } else {
        input.type = 'password';
        if (icon) icon.innerHTML = `<svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M17.94 17.94A10.07 10.07 0 0 1 12 20c-7 0-11-8-11-8a18.45 18.45 0 0 1 5.06-5.94M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 11 8 11 8a18.5 18.5 0 0 1-2.16 3.19m-6.72-1.07a3 3 0 1 1-4.24-4.24"></path><line x1="1" y1="1" x2="23" y2="23"></line></svg>`;
    }
}

function previewImage(input) {
    if (input.files && input.files[0]) {
        const reader = new FileReader();
        reader.onload = function (e) {
            const preview = document.querySelector('.profile-avatar');
            if (preview) {
                preview.src = e.target.result;
            }
        }
        reader.readAsDataURL(input.files[0]);
    }
}

document.addEventListener('DOMContentLoaded', function () {

    setupNavigation();

    initializeNotifications();

    document.addEventListener('click', function (e) {
        const notificationDropdown = document.getElementById('notification-dropdown');
        const notificationBtn = document.querySelector('.notification-btn');

        if (notificationDropdown && notificationDropdown.classList.contains('active')) {
            if (!notificationDropdown.contains(e.target) && !notificationBtn.contains(e.target)) {
                notificationDropdown.classList.remove('active');
            }
        }
    });

    const logoutBtn = document.querySelector('.logout');
    if (logoutBtn) {
        logoutBtn.addEventListener('click', function (e) {
            e.preventDefault();
            window.location.href = `${BASE_URL}/logout`;
        });
    }

    window.addEventListener('popstate', async function (e) {
        // Abaikan guard state dari back-guard.js
        if (!e.state || e.state.__guard__) return;

        window.isPopState = true;

        const section = e.state.section || window.INITIAL_SECTION || 'beranda';

        // Cek apakah URL saat ini (setelah back/forward) mengandung token order
        const urlParams  = new URLSearchParams(window.location.search);
        const orderToken = urlParams.get('t');

        if (orderToken && (section === 'detailorder' || section === 'detailorderserti' || section === 'download_berkas')) {
            // Resolve token terlebih dahulu, baru muat section
            try {
                const res  = await fetch(`${BASE_URL}/api/order/resolve?t=${encodeURIComponent(orderToken)}`);
                const data = await res.json();
                if (data.success) {
                    const filters = data.source ? { source: data.source } : {};
                    loadSection(data.section || section, data.sample || null, data.id, filters);
                } else {
                    loadSection('beranda');
                }
            } catch (_) {
                loadSection(section);
            }
        } else {
            loadSection(section);
        }
    });

});

function updateNavActiveState(sectionName) {
    const navItems = document.querySelectorAll('.nav-item[data-section], .profile-menu-item[data-section]');
    navItems.forEach(nav => {
        if (nav.getAttribute('data-section') === sectionName) {
            nav.classList.add('active');
        } else {
            nav.classList.remove('active');
        }
    });

    // Handle sub-link active state (inside dropdowns)
    document.querySelectorAll('.nav-sub-link[data-section]').forEach(link => {
        if (link.getAttribute('data-section') === sectionName) {
            link.classList.add('active');
        } else {
            link.classList.remove('active');
        }
    });

    // Sections that belong under "Input Data Layanan" dropdown
    const layananChildSections = ['permohonanpengecualian', 'permohonan-alat'];
    const toggleLayanan = document.getElementById('nav-toggle-layanan');
    if (toggleLayanan) {
        if (layananChildSections.includes(sectionName)) {
            toggleLayanan.classList.add('child-active');
        } else {
            toggleLayanan.classList.remove('child-active');
        }
    }
}

// ─── Live Notification System ─────────────────────────────────────────────────
let _notifData = [];
let _notifPollTimer = null;

function initializeNotifications() {
    fetchNotifications();
    // Poll setiap 30 detik
    _notifPollTimer = setInterval(fetchNotifications, 30000);
}

async function fetchNotifications() {
    try {
        const res = await fetch(`${BASE_URL}/dashboard/notifikasi/belum-dibaca`);
        const json = await res.json();
        if (json.success) {
            _notifData = json.data || [];
        } else {
            _notifData = [];
        }
    } catch (e) {
        console.error('[Notifikasi] Gagal memuat:', e);
        _notifData = [];
    }
    renderNotifications();
    updateNotificationBadge();
}

function toggleNotifications() {
    const dropdown = document.getElementById('notification-dropdown');
    dropdown.classList.toggle('active');
    // Refresh saat buka dropdown
    if (dropdown.classList.contains('active')) {
        fetchNotifications();
    }
}

function renderNotifications() {
    const list = document.getElementById('notification-list');
    if (!list) return;

    list.innerHTML = '';

    if (!_notifData.length) {
        list.innerHTML = `
            <div class="notification-empty">
                <span>Tidak ada notifikasi baru</span>
            </div>`;
        return;
    }

    _notifData.forEach(notif => {
        const item = document.createElement('div');
        item.className = `notification-item ${notif.read ? 'read' : 'unread'}`;
        item.innerHTML = `
            <div class="notification-content">
                <h4>${escHtml(notif.title)}</h4>
                <p>${notif.message}</p>
                <span class="notification-time">${escHtml(notif.time)}</span>
            </div>
        `;
        list.appendChild(item);
    });
}

function escHtml(str) {
    if (!str) return '';
    const d = document.createElement('div');
    d.textContent = str;
    return d.innerHTML;
}

async function onNotifClick(notif) {
    // Tandai sebagai dibaca
    try {
        await fetch(`${BASE_URL}/dashboard/notifikasi/tandai-dibaca`, {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: `id=${notif.id}`
        });
    } catch (e) {}

    // Tutup dropdown
    const dropdown = document.getElementById('notification-dropdown');
    if (dropdown) dropdown.classList.remove('active');

    // Navigasi ke link terkait jika tersedia
    if (notif.link) {
        // Cek apakah link berupa section internal (misal /beranda, /pembayaran, /permohonanalat)
        const sectionMatch = notif.link.match(/^\/([\w-]+)$/);
        if (sectionMatch && typeof loadSection === 'function') {
            loadSection(sectionMatch[1]);
        } else {
            window.location.href = BASE_URL + notif.link;
        }
    }

    // Hapus dari array lokal & re-render
    _notifData = _notifData.filter(n => n.id !== notif.id);
    renderNotifications();
    updateNotificationBadge();
}

function updateNotificationBadge() {
    const badge = document.getElementById('notification-badge');
    if (!badge) return;
    const count = _notifData.length;

    if (count > 0) {
        badge.style.display = 'flex';
        badge.textContent = count > 99 ? '99+' : count;
    } else {
        badge.style.display = 'none';
    }
}

async function markAllRead() {
    try {
        await fetch(`${BASE_URL}/dashboard/notifikasi/tandai-dibaca`, {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: 'all=1'
        });
    } catch (e) {}
    _notifData = [];
    renderNotifications();
    updateNotificationBadge();
}

// FAB Logic
function initFab() {
    const fabBtn = document.querySelector('.fab-button');
    if (fabBtn) {
        fabBtn.addEventListener('click', function(e) {
            e.preventDefault();
            const section = this.getAttribute('data-section') || 'keluhan';
            loadSection(section);
        });
    }
}

document.addEventListener('DOMContentLoaded', function () {
    initFab();
});


function showConfirm(message, onConfirm, type = 'confirm') {
    const modal = document.getElementById('confirm-modal');
    const msgElem = document.getElementById('confirm-message');
    const titleElem = document.getElementById('confirm-title');
    const btnOk = document.getElementById('confirm-ok');
    const btnCancel = document.getElementById('confirm-cancel');
    const iconContainer = document.getElementById('modal-icon-container');

    if (!modal) {
        if (confirm(message)) onConfirm();
        return;
    }

    const modalBox = modal.querySelector('.modal-box');
    modalBox?.classList.remove('modal-error');

    titleElem.textContent = 'Konfirmasi';
    msgElem.textContent = message;
    btnCancel.style.display = 'inline-block';
    btnOk.textContent = 'Iya';
    btnCancel.textContent = 'Tidak';

    if (iconContainer) {
        iconContainer.style.background = 'transparent';
        if (type === 'warning') {
            // Warning/destructive: exclamation triangle icon (amber)
            iconContainer.innerHTML = `<svg xmlns="http://www.w3.org/2000/svg" width="64" height="64" viewBox="0 0 24 24" fill="none" stroke="#f59e0b" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                <path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"></path>
                <line x1="12" y1="9" x2="12" y2="13"></line>
                <line x1="12" y1="17" x2="12.01" y2="17"></line>
            </svg>`;
        } else {
            // Normal confirm: question mark circle icon (indigo)
            iconContainer.innerHTML = `<svg xmlns="http://www.w3.org/2000/svg" width="64" height="64" viewBox="0 0 24 24" fill="none" stroke="#6366f1" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                <circle cx="12" cy="12" r="10"></circle>
                <path d="M9.09 9a3 3 0 0 1 5.83 1c0 2-3 3-3 3"></path>
                <line x1="12" y1="17" x2="12.01" y2="17"></line>
            </svg>`;
        }
    }

    modal.classList.add('active');

    const close = () => {
        modal.classList.remove('active');
    };

    const newBtnOk = btnOk.cloneNode(true);
    const newBtnCancel = btnCancel.cloneNode(true);
    btnOk.parentNode.replaceChild(newBtnOk, btnOk);
    btnCancel.parentNode.replaceChild(newBtnCancel, btnCancel);

    newBtnCancel.addEventListener('click', close);
    newBtnOk.addEventListener('click', () => {
        close();
        onConfirm();
    });

    modal.onclick = (e) => {
        if (e.target === modal) close();
    };
}

function showMessage(title, message, type = 'success', callback = null) {
    const modal = document.getElementById('confirm-modal');
    const msgElem = document.getElementById('confirm-message');
    const titleElem = document.getElementById('confirm-title');
    const btnOk = document.getElementById('confirm-ok');
    const btnCancel = document.getElementById('confirm-cancel');
    const iconContainer = document.getElementById('modal-icon-container');

    if (!modal) {
        alert(message);
        if (callback) callback();
        return;
    }

    titleElem.textContent = title;
    msgElem.textContent = message;
    btnCancel.style.display = 'none'; // Hide cancel button

    const modalBox = modal.querySelector('.modal-box');

    if (iconContainer) {
        iconContainer.style.background = 'transparent';
        if (type === 'error') {
            // Error: X circle icon (red)
            modalBox?.classList.add('modal-error');
            iconContainer.innerHTML = `<svg xmlns="http://www.w3.org/2000/svg" width="64" height="64" viewBox="0 0 24 24" fill="none" stroke="#ef4444" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                <circle cx="12" cy="12" r="10"></circle>
                <line x1="15" y1="9" x2="9" y2="15"></line>
                <line x1="9" y1="9" x2="15" y2="15"></line>
            </svg>`;
        } else if (type === 'success') {
            // Success: checkmark circle icon (emerald)
            modalBox?.classList.remove('modal-error');
            iconContainer.innerHTML = `<svg xmlns="http://www.w3.org/2000/svg" width="64" height="64" viewBox="0 0 24 24" fill="none" stroke="#10b981" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                <circle cx="12" cy="12" r="10"></circle>
                <polyline points="9 12 12 15 16 10"></polyline>
            </svg>`;
        } else if (type === 'warning') {
            // Warning: exclamation triangle icon (amber)
            modalBox?.classList.remove('modal-error');
            iconContainer.innerHTML = `<svg xmlns="http://www.w3.org/2000/svg" width="64" height="64" viewBox="0 0 24 24" fill="none" stroke="#f59e0b" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                <path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"></path>
                <line x1="12" y1="9" x2="12" y2="13"></line>
                <line x1="12" y1="17" x2="12.01" y2="17"></line>
            </svg>`;
        } else {
            // Info/default: info circle icon (indigo)
            modalBox?.classList.remove('modal-error');
            iconContainer.innerHTML = `<svg xmlns="http://www.w3.org/2000/svg" width="64" height="64" viewBox="0 0 24 24" fill="none" stroke="#6366f1" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                <circle cx="12" cy="12" r="10"></circle>
                <line x1="12" y1="16" x2="12" y2="12"></line>
                <line x1="12" y1="8" x2="12.01" y2="8"></line>
            </svg>`;
        }
    }

    modal.classList.add('active');

    const close = () => {
        modal.classList.remove('active');
        if (callback) callback();
    };

    const newBtnOk = btnOk.cloneNode(true);
    btnOk.parentNode.replaceChild(newBtnOk, btnOk);

    newBtnOk.textContent = 'OK';
    newBtnOk.addEventListener('click', close);

    modal.onclick = (e) => {
        if (e.target === modal) close();
    };
}

function initPengaduan() {
    loadComplaints();
    loadTopics();
}

function showForm() {
    const mainView = document.getElementById('complaint-main-view');
    const formSection = document.getElementById('form-section');
    if (mainView) mainView.style.display = 'none';
    if (formSection) formSection.style.display = 'block';
    window.scrollTo(0, 0);
}

function hideForm() {
    const mainView = document.getElementById('complaint-main-view');
    const formSection = document.getElementById('form-section');
    if (formSection) formSection.style.display = 'none';
    if (mainView) mainView.style.display = 'block';
    const form = document.getElementById('complaint-form');
    if (form) form.reset();
}

async function loadTopics() {
    try {
        const response = await fetch(BASE_URL + '/dashboard/pengaduan/topics');
        const result = await response.json();
        if (result.success) {
            const select = document.getElementById('topic-select');
            if (select) {
                while (select.options.length > 1) select.remove(1);
                result.data.forEach(topic => {
                    select.add(new Option(topic.name, topic.id));
                });
            }
        }
    } catch (e) {
        console.error('Error loading topics', e);
    }
}

async function loadComplaints() {
    const loading = document.getElementById('complaints-loading');
    const empty = document.getElementById('complaints-empty');
    const list = document.getElementById('complaints-list-container');

    if (!loading || !list) return;

    loading.style.display = 'block';
    list.innerHTML = '';
    if (empty) empty.style.display = 'none';

    try {
        const response = await fetch(BASE_URL + '/dashboard/pengaduan/list');
        const result = await response.json();
        loading.style.display = 'none';

        if (result.success && result.data.length > 0) {
            renderCards(result.data);
        } else {
            if (empty) empty.style.display = 'block';
        }
    } catch (e) {
        loading.style.display = 'none';
        console.error('Error loading complaints', e);
        showAlert('Gagal memuat data', 'error');
    }
}

function renderCards(data) {
    const container = document.getElementById('complaints-list-container');
    if (!container) return;

    data.forEach(item => {
        const invoiceId = 'INV2025' + item.id;
        const category = item.topic_name || item.problem_type || 'Umum';
        const dateStr = new Date(item.created_at || Date.now()).toLocaleDateString('id-ID', {
            day: 'numeric', month: 'long', year: 'numeric'
        });

        let displayTitle = item.reason.split('\n')[0] || item.reason;
        if (displayTitle.length > 80) displayTitle = displayTitle.substring(0, 80) + '...';

        let badgeClass = 'badge-pending';
        let statusLabel = item.status;

        if (item.status === 'Pending') { statusLabel = 'Menunggu'; badgeClass = 'badge-pending'; }
        else if (item.status === 'In Progress') { statusLabel = 'Proses'; badgeClass = 'badge-proses'; }
        else if (item.status === 'Resolved') { statusLabel = 'Selesai'; badgeClass = 'badge-selesai'; }
        else if (item.status === 'Closed') { statusLabel = 'Ditutup'; badgeClass = 'badge-closed'; }

        const card = document.createElement('div');
        card.className = 'complaint-card';
        card.onclick = () => viewComplaintDetail(item.id);

        card.innerHTML = `
            <div class="card-top">
                <h3 class="card-title">${displayTitle}</h3>
                <span class="card-badge ${badgeClass}">${statusLabel}</span>
            </div>
            <div class="card-middle">
                <p class="card-meta">${category} • ${invoiceId}</p>
            </div>
            <div class="card-bottom">
                Terakhir diperbarui: ${dateStr}
            </div>
        `;
        container.appendChild(card);
    });
}

async function submitComplaint(e) {
    e.preventDefault();
    const btn = e.target.querySelector('button[type="submit"]');
    const originalText = btn.innerHTML;
    btn.innerHTML = 'Mengirim...';
    btn.disabled = true;

    const formData = new FormData();
    const title = document.getElementById('complaint-title').value;
    const desc = document.getElementById('complaint-desc').value;
    const topic = document.getElementById('topic-select').value;

    const combinedReason = title + '\n\n' + desc;

    formData.append('topic_id', topic);
    formData.append('reason', combinedReason);

    try {
        const response = await fetch(BASE_URL + '/dashboard/pengaduan/submit', {
            method: 'POST',
            body: formData
        });
        const result = await response.json();

        if (result.success) {
            showAlert('Pengaduan berhasil dikirim', 'success');
            hideForm();
            loadSection('beranda');
        } else {
            showAlert(result.message || 'Gagal mengirim pengaduan', 'error');
        }
    } catch (error) {
        console.error(error);
        showAlert('Terjadi kesalahan sistem', 'error');
    } finally {
        btn.innerHTML = originalText;
        btn.disabled = false;
    }
}

function toggleFaq(element) {
    const content = element.nextElementSibling;
    if (content) {
        content.classList.toggle('active');
        element.classList.toggle('active');
    }
}

function filterFaq() {
    const query = document.getElementById('faq-search').value.toLowerCase();
    const items = document.querySelectorAll('.accordion-item');
    const categories = document.querySelectorAll('.faq-category-title');

    items.forEach(item => {
        const text = item.innerText.toLowerCase();
        if (text.includes(query)) {
            item.style.display = 'block';
        } else {
            item.style.display = 'none';
        }
    });

    categories.forEach(cat => {
        let next = cat.nextElementSibling;
        let hasVisible = false;
        while (next && !next.classList.contains('faq-category-title') && !next.classList.contains('faq-footer')) {
            if (next.classList.contains('accordion-item') && next.style.display !== 'none') {
                hasVisible = true;
                break;
            }
            next = next.nextElementSibling;
        }
        cat.style.display = hasVisible ? 'block' : 'none';
    });
}

function openChat() {
    const faqContainer = document.querySelector('.faq-container');
    const chatSection = document.getElementById('chat-section');
    if (faqContainer) faqContainer.style.display = 'none';
    if (chatSection) {
        chatSection.style.display = 'block';
        if (typeof loadGeneralChat === 'function') {
            loadGeneralChat();
        }
    }
}

function closeChat() {
    const faqContainer = document.querySelector('.faq-container');
    const chatSection = document.getElementById('chat-section');
    if (chatSection) chatSection.style.display = 'none';
    if (faqContainer) faqContainer.style.display = 'block';
}

function executeScripts(container) {
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
}

function initPremiumHover() {
    if (typeof anime === 'undefined') return;

    const hoverableTargets = document.querySelectorAll('.grid-item, .btn, .btn-primary, .btn-primary-red, .service-card-v2, .fab-button, .nav-item, .profile-card, .stat-card, .info-card, .exemption-container button');

    hoverableTargets.forEach(el => {
        el.onmouseenter = () => {
            anime({
                targets: el,
                scale: 1.03,
                duration: 400,
                easing: 'easeOutElastic(1, .6)'
            });
        };
        el.onmouseleave = () => {
            anime({
                targets: el,
                scale: 1,
                duration: 400,
                easing: 'easeOutElastic(1, .6)'
            });
        };
    });
}

document.addEventListener('DOMContentLoaded', () => {
    if (typeof anime !== 'undefined') initPremiumHover();
});

function setupCustomDropdown(dropdownId, inputId, onChange = null) {
    const dropdown = document.getElementById(dropdownId);
    if (!dropdown) return;

    const input = document.getElementById(inputId);
    const trigger = dropdown.querySelector('.select-trigger');
    const optsCont = dropdown.querySelector('.select-options');

    if (!trigger || !optsCont) return;

    // ── Trigger: open/close ───────────────────────────────────────────────────
    const handleTriggerClick = (e) => {
        e.stopPropagation();
        // Close every other custom-select
        document.querySelectorAll('.custom-select').forEach(d => {
            if (d !== dropdown) d.classList.remove('open');
        });
        dropdown.classList.toggle('open');
    };

    if (trigger._customClick) trigger.removeEventListener('click', trigger._customClick);
    trigger._customClick = handleTriggerClick;
    trigger.addEventListener('click', handleTriggerClick);

    // ── Option click: select value ────────────────────────────────────────────
    const handleOptionClick = (e) => {
        const option = e.target.closest('.option');
        if (!option) return;

        const val = option.dataset.value;
        const selText = dropdown.querySelector('.selected-text'); // always re-query
        const optsNow = dropdown.querySelector('.select-options');

        if (input) input.value = val;
        if (selText) selText.textContent = option.textContent.trim();
        if (optsNow) optsNow.querySelectorAll('.option').forEach(o => o.classList.remove('selected'));
        option.classList.add('selected');
        dropdown.classList.remove('open');

        if (input) {
            input.dispatchEvent(new Event('input', { bubbles: true }));
            input.dispatchEvent(new Event('change', { bubbles: true }));
        }

        if (onChange) onChange(val);
    };

    if (optsCont._customClick) optsCont.removeEventListener('click', optsCont._customClick);
    optsCont._customClick = handleOptionClick;
    optsCont.addEventListener('click', handleOptionClick);

    // ── Document click: close when clicking outside (bound only ONCE per element)
    if (!dropdown._docClickBound) {
        dropdown._docClickBound = true;
        document.addEventListener('click', (e) => {
            if (!dropdown.contains(e.target)) {
                dropdown.classList.remove('open');
            }
        });
    }
}

function updateCustomDropdownOptions(dropdownId, options, placeholder = 'Pilih') {
    const dropdown = document.getElementById(dropdownId);
    if (!dropdown) return;

    const optionsContainer = dropdown.querySelector('.select-options');
    const selectedText = dropdown.querySelector('.selected-text');
    const input = dropdown.querySelector('input[type="hidden"]');

    optionsContainer.innerHTML = '';
    if (input) input.value = '';
    selectedText.textContent = placeholder;

    let html = `<div class="option" data-value="">${placeholder}</div>`;
    options.forEach(opt => {
        const kodeposAttr = opt.kode_pos ? ` data-kodepos="${opt.kode_pos}"` : '';
        html += `<div class="option" data-value="${opt.id}" data-name="${opt.name}"${kodeposAttr}>${opt.name}</div>`;
    });
    optionsContainer.innerHTML = html;
}