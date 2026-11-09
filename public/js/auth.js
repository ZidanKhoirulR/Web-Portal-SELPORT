function showLogin(event) {
    if (event) event.preventDefault();
    const container = document.getElementById('container');
    container.classList.remove('right-panel-active');

    setTimeout(() => {
        document.getElementById('loginCard').classList.remove('hidden');
        document.getElementById('forgotPasswordCard').classList.add('hidden');
    }, 300); // Wait for transition start
}

function showRegister(event) {
    if (event) event.preventDefault();
    const container = document.getElementById('container');
    container.classList.add('right-panel-active');

    currentStep = 1;
    showStep(currentStep);
}

function showForgotPassword(event) {
    if (event) event.preventDefault();
    document.getElementById('loginCard').classList.add('hidden');
    document.getElementById('forgotPasswordCard').classList.remove('hidden');
}

function togglePassword(inputId) {
    const input = document.getElementById(inputId);
    const button = input.nextElementSibling;
    const icon = button.querySelector('.eye-icon');

    if (input.type === 'password') {
        input.type = 'text';
        icon.innerHTML = `<path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"></path><circle cx="12" cy="12" r="3"></circle>`;
    } else {
        input.type = 'password';
        icon.innerHTML = `<path d="M17.94 17.94A10.07 10.07 0 0 1 12 20c-7 0-11-8-11-8a18.45 18.45 0 0 1 5.06-5.94M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 11 8 11 8a18.5 18.5 0 0 1-2.16 3.19m-6.72-1.07a3 3 0 1 1-4.24-4.24"></path><line x1="1" y1="1" x2="23" y2="23"></line>`;
    }
}

let currentStep = 1;
let totalSteps = 3;
let skipCompanyData = false;
let iti;        // iti for no_whatsapp (personal)
let itiTelp;    // iti for telp_perusahaan
let itiWa;      // iti for wa_perusahaan

const stepDescriptions = {
    1: "Lengkapi Data Pribadi Anda",
    2: "Lengkapi Data Perusahaan Anda",
    3: "Buat Password Untuk Akun Anda"
};

document.addEventListener('DOMContentLoaded', function () {
    showStep(currentStep);
    setupPasswordValidation();
    setupUsernameValidation();
    setupPhoneValidation();
    setupEmailValidation();

    setupCustomDropdown('dropdown-jenis_kelamin', 'jenis_kelamin');
    setupCustomDropdown('dropdown-kategori_pelayanan', 'kategori_pelayanan', (val) => {
        skipCompanyData = (val === 'Perorangan');

        // Toggle step 2 (company data) required based on Perorangan
        const step2Fields = document.querySelectorAll('.form-step[data-step="2"] input[required], .form-step[data-step="2"] select[required], .form-step[data-step="2"] textarea[required], .form-step[data-step="2"] input[type="hidden"][required]');
        step2Fields.forEach(field => {
            if (skipCompanyData) {
                field.removeAttribute('required');
            } else {
                field.setAttribute('required', 'required');
            }
        });

        // Toggle step 1 personal address: hide for Instansi & Perusahaan; show for Perorangan & Mahasiswa
        const personalAddressHidden = (val === 'Instansi' || val === 'Perusahaan');
        const isMahasiswaKategori = (val === 'Mahasiswa');
        const personalAddressGroups = document.querySelectorAll('.personal-address-group');
        const personalAddressFieldsAll = document.querySelectorAll('#alamat_tinggal, #provinsi_tinggal, #kabupaten_tinggal, #kecamatan_tinggal, #kodepos_tinggal');

        if (personalAddressHidden) {
            personalAddressGroups.forEach(group => group.style.display = 'none');
            personalAddressFieldsAll.forEach(field => field.removeAttribute('required'));
        } else {
            personalAddressGroups.forEach(group => group.style.display = '');
            // Untuk Mahasiswa: tampilkan tapi tidak wajib; untuk Perorangan: wajib
            if (isMahasiswaKategori) {
                personalAddressFieldsAll.forEach(field => field.removeAttribute('required'));
            } else {
                personalAddressFieldsAll.forEach(field => field.setAttribute('required', 'required'));
            }
        }

        // Update label alamat: hapus tanda (*) untuk Mahasiswa
        const personalAddressLabels = [
            { id: 'label-alamat-tinggal', text: 'Alamat Lengkap' },
            { id: 'label-provinsi-tinggal', text: 'Provinsi' },
            { id: 'label-kabupaten-tinggal', text: 'Kab/Kota' },
            { id: 'label-kecamatan-tinggal', text: 'Kecamatan' },
            { id: 'label-kodepos-tinggal', text: 'Kode Pos' }
        ];
        
        personalAddressLabels.forEach(({ id, text }) => {
            const label = document.getElementById(id);
            if (label) {
                if (isMahasiswaKategori) {
                    label.innerHTML = text; // Tanpa tanda (*)
                } else if (val === 'Perorangan') {
                    label.innerHTML = `${text} <span class="required">*</span>`; // Dengan tanda (*)
                }
            }
        });

        // Ganti label step 2: "Perusahaan" ↔ "Universitas" untuk Mahasiswa
        const companyLabelMap = {
            'label-nama-perusahaan': isMahasiswaKategori ? 'Nama Universitas' : 'Nama Perusahaan',
            'label-email-perusahaan': isMahasiswaKategori ? 'Email Universitas' : 'Email Perusahaan',
            'label-alamat-perusahaan': isMahasiswaKategori ? 'Alamat Universitas' : 'Alamat Perusahaan',
        };
        Object.entries(companyLabelMap).forEach(([id, text]) => {
            const lbl = document.getElementById(id);
            if (lbl) lbl.innerHTML = `${text} <span class="required">*</span>`;
        });

        // Ganti placeholder input step 2 sesuai kategori
        const namaInput = document.getElementById('nama_perusahaan');
        const emailInput = document.getElementById('email_perusahaan');
        const alamatInput = document.getElementById('alamat_perusahaan');
        if (namaInput) namaInput.placeholder = isMahasiswaKategori ? 'Masukkan nama universitas' : 'Masukkan nama perusahaan';
        if (emailInput) emailInput.placeholder = isMahasiswaKategori ? 'email@universitas.ac.id' : 'email@perusahaan.com';
        if (alamatInput) alamatInput.placeholder = isMahasiswaKategori ? 'Alamat Universitas' : 'Alamat Perusahaan';

        // Update step description untuk step 2
        stepDescriptions[2] = isMahasiswaKategori ? 'Lengkapi Data Universitas Anda' : 'Lengkapi Data Perusahaan Anda';
        if (currentStep === 2) {
            const descriptionElement = document.getElementById('step-description');
            if (descriptionElement) {
                descriptionElement.textContent = stepDescriptions[2];
            }
        }

        updateProgressIndicator(currentStep);
    });

    setupCustomDropdown('dropdown-provinsi_tinggal', 'provinsi_tinggal', (val) => {
        const name = document.querySelector(`#dropdown-provinsi_tinggal .option[data-value="${val}"]`)?.dataset.name || '';
        document.getElementById('provinsi_tinggal_name').value = name;
        loadKabupaten(val, 'kabupaten_tinggal');
    });
    setupCustomDropdown('dropdown-kabupaten_tinggal', 'kabupaten_tinggal', (val) => {
        const name = document.querySelector(`#dropdown-kabupaten_tinggal .option[data-value="${val}"]`)?.dataset.name || '';
        document.getElementById('kabupaten_tinggal_name').value = name;
        loadKecamatan(val, 'kecamatan_tinggal');
    });
    setupCustomDropdown('dropdown-kecamatan_tinggal', 'kecamatan_tinggal', (val) => {
        const name = document.querySelector(`#dropdown-kecamatan_tinggal .option[data-value="${val}"]`)?.dataset.name || '';
        document.getElementById('kecamatan_tinggal_name').value = name;
        loadPostalCode(val, 'kodepos_tinggal');
    });
    setupCustomDropdown('dropdown-kodepos_tinggal', 'kodepos_tinggal');

    setupCustomDropdown('dropdown-provinsi_perusahaan', 'provinsi_perusahaan', (val) => {
        const name = document.querySelector(`#dropdown-provinsi_perusahaan .option[data-value="${val}"]`)?.dataset.name || '';
        document.getElementById('provinsi_perusahaan_name').value = name;
        loadKabupaten(val, 'kabupaten_perusahaan');
    });
    setupCustomDropdown('dropdown-kabupaten_perusahaan', 'kabupaten_perusahaan', (val) => {
        const name = document.querySelector(`#dropdown-kabupaten_perusahaan .option[data-value="${val}"]`)?.dataset.name || '';
        document.getElementById('kabupaten_perusahaan_name').value = name;
        loadKecamatan(val, 'kecamatan_perusahaan');
    });
    setupCustomDropdown('dropdown-kecamatan_perusahaan', 'kecamatan_perusahaan', (val) => {
        const name = document.querySelector(`#dropdown-kecamatan_perusahaan .option[data-value="${val}"]`)?.dataset.name || '';
        document.getElementById('kecamatan_perusahaan_name').value = name;
        loadPostalCode(val, 'kodepos_perusahaan');
    });
    setupCustomDropdown('dropdown-kodepos_perusahaan', 'kodepos_perusahaan');

    loadProvinces();
    setupIntlTelInput();
    setupOtpListener();
    setupOtpKeydownListeners();
});

function setupCustomDropdown(dropdownId, inputId, onChange = null) {
    const dropdown = document.getElementById(dropdownId);
    if (!dropdown) return;

    const input = document.getElementById(inputId);
    const trigger = dropdown.querySelector('.select-trigger');
    const optionsContainer = dropdown.querySelector('.select-options');
    const selectedText = dropdown.querySelector('.selected-text');

    trigger.addEventListener('click', (e) => {
        document.querySelectorAll('.custom-select').forEach(d => {
            if (d !== dropdown) d.classList.remove('open');
        });
        dropdown.classList.toggle('open');
        e.stopPropagation();
    });

    optionsContainer.addEventListener('click', (e) => {
        const option = e.target.closest('.option');
        if (!option) return;

        const val = option.dataset.value;
        input.value = val;
        selectedText.textContent = option.textContent;

        optionsContainer.querySelectorAll('.option').forEach(o => o.classList.remove('selected'));
        option.classList.add('selected');

        dropdown.classList.remove('open');

        input.dispatchEvent(new Event('input', { bubbles: true }));
        input.dispatchEvent(new Event('change', { bubbles: true }));

        if (onChange) onChange(val);
    });

    document.addEventListener('click', (e) => {
        if (!dropdown.contains(e.target)) {
            dropdown.classList.remove('open');
        }
    });
}

function updateCustomDropdownOptions(dropdownId, options, placeholder = 'Pilih') {
    const dropdown = document.getElementById(dropdownId);
    if (!dropdown) return;

    const optionsContainer = dropdown.querySelector('.select-options');
    const selectedText = dropdown.querySelector('.selected-text');
    const input = dropdown.querySelector('input[type="hidden"]');

    optionsContainer.innerHTML = '';
    input.value = '';
    selectedText.textContent = placeholder;

    let html = `<div class="option" data-value="">${placeholder}</div>`;
    options.forEach(opt => {
        const dataKodepos = opt.kode_pos ? `data-kodepos="${opt.kode_pos}"` : '';
        html += `<div class="option" data-value="${opt.id}" data-name="${opt.name}" ${dataKodepos}>${opt.name}</div>`;
    });
    optionsContainer.innerHTML = html;
}

function setupOtpKeydownListeners() {
    const otpInput = document.getElementById('otp_code');
    const loginOtpInput = document.getElementById('login_otp_code');

    if (otpInput) {
        otpInput.addEventListener('keydown', function (e) {
            if (e.key === 'Enter') {
                e.preventDefault();
                const verifyBtn = document.getElementById('verifyOtpBtn');
                const sendBtn = document.getElementById('sendOtpBtn');
                if (verifyBtn && verifyBtn.style.display !== 'none') {
                    verifyOTP();
                } else if (sendBtn && sendBtn.style.display !== 'none' && !sendBtn.disabled) {
                    sendOTP();
                }
            }
        });
    }

    if (loginOtpInput) {
        loginOtpInput.addEventListener('keydown', function (e) {
            if (e.key === 'Enter') {
                e.preventDefault();
                const verifyBtn = document.getElementById('btnLoginVerifyOtp');
                const sendBtn = document.getElementById('btnLoginSendOtp');
                if (verifyBtn && verifyBtn.style.display !== 'none') {
                    verifyLoginOTP();
                } else if (sendBtn && sendBtn.style.display !== 'none' && !sendBtn.disabled) {
                    sendLoginOTP();
                }
            }
        });
    }
}

let isPhoneVerified = false;
let otpTimer = null;

function setupOtpListener() {
    const sendOtpBtn = document.getElementById('sendOtpBtn');
    const verifyOtpBtn = document.getElementById('verifyOtpBtn');

    if (sendOtpBtn) {
        sendOtpBtn.addEventListener('click', sendOTP);
    }

    if (verifyOtpBtn) {
        verifyOtpBtn.addEventListener('click', verifyOTP);
    }
}

async function sendOTP() {
    const phoneInput = document.getElementById('no_whatsapp');
    const sendOtpBtn = document.getElementById('sendOtpBtn');
    const otpWrapper = document.getElementById('otpWrapper');
    const verifyOtpBtn = document.getElementById('verifyOtpBtn');
    const feedback = document.getElementById('otp-feedback');

    if (!iti.isValidNumber()) {
        phoneInput.classList.add('error');
        feedback.textContent = 'Nomor HP tidak valid';
        feedback.className = 'username-feedback error';
        return;
    }

    const fullPhoneNumber = iti.getNumber();

    sendOtpBtn.disabled = true;
    sendOtpBtn.textContent = 'Mengirim...';
    feedback.textContent = '';

    try {
        const csrfToken = document.querySelector('input[name="csrf_token"]').value;
        const response = await fetch(window.appBaseUrl + '/api/send-otp', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded',
            },
            body: new URLSearchParams({
                phone: fullPhoneNumber,
                action: 'register',
                csrf_token: csrfToken
            })
        });

        const result = await response.json();

        if (result.success) {
            otpWrapper.style.display = 'block';
            verifyOtpBtn.style.display = 'block';
            sendOtpBtn.style.display = 'none'; // Hide send button temporarily

            let msg = 'Kode OTP terkirim via WhatsApp';
            feedback.textContent = msg;
            feedback.className = 'username-feedback success';

            startOtpTimer(600); // 10 minutes

        } else {
            feedback.textContent = result.message;
            feedback.className = 'username-feedback error';
            sendOtpBtn.disabled = false;
            sendOtpBtn.textContent = 'Kirim OTP';
        }

    } catch (error) {
        console.error(error);
        feedback.textContent = 'Gagal mengirim OTP. Coba lagi.';
        feedback.className = 'username-feedback error';
        sendOtpBtn.disabled = false;
        sendOtpBtn.textContent = 'Kirim OTP';
    }
}

async function verifyOTP() {
    const otpInput = document.getElementById('otp_code');
    const verifyBtn = document.getElementById('verifyOtpBtn');
    const sendBtn = document.getElementById('sendOtpBtn');
    const feedback = document.getElementById('otp-feedback');
    const phoneInput = document.getElementById('no_whatsapp');

    const otp = otpInput.value.trim();
    if (otp.length < 6) {
        feedback.textContent = 'Kode OTP harus 6 digit';
        feedback.className = 'username-feedback error';
        return;
    }

    verifyBtn.disabled = true;
    verifyBtn.textContent = 'Memverifikasi...';

    try {
        const csrfToken = document.querySelector('input[name="csrf_token"]').value;
        const response = await fetch(window.appBaseUrl + '/api/verify-otp', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded',
            },
            body: new URLSearchParams({
                phone: iti.getNumber(),
                otp: otp,
                csrf_token: csrfToken
            })
        });

        const result = await response.json();

        if (result.success) {
            isPhoneVerified = true;
            feedback.textContent = '✓ Nomor berhasil diverifikasi';
            feedback.className = 'username-feedback success';

            document.getElementById('otpWrapper').style.display = 'none';
            verifyBtn.style.display = 'none';
            sendBtn.style.display = 'block';
            sendBtn.textContent = 'Terverifikasi ✓';
            sendBtn.disabled = true;
            sendBtn.style.backgroundColor = '#10b981'; // Green
            sendBtn.style.borderColor = '#10b981';
            phoneInput.disabled = true;

            if (otpTimer) clearInterval(otpTimer);

            // Cek apakah nomor ini sudah terdaftar di database
            checkPhoneAvailability(iti.getNumber());

        } else {
            feedback.textContent = result.message;
            feedback.className = 'username-feedback error';
            verifyBtn.disabled = false;
            verifyBtn.textContent = 'Verifikasi';
        }

    } catch (error) {
        feedback.textContent = 'Terjadi kesalahan verifikasi';
        feedback.className = 'username-feedback error';
        verifyBtn.disabled = false;
        verifyBtn.textContent = 'Verifikasi';
    }
}

function startOtpTimer(duration) {
    const sendBtn = document.getElementById('sendOtpBtn');
    let timer = duration;

    if (otpTimer) clearInterval(otpTimer);

    otpTimer = setInterval(function () {
        const minutes = parseInt(timer / 60, 10);
        const seconds = parseInt(timer % 60, 10);

        const displaySeconds = seconds < 10 ? "0" + seconds : seconds;

        if (--timer < 0) {
            clearInterval(otpTimer);
            sendBtn.style.display = 'block';
            sendBtn.disabled = false;
            sendBtn.textContent = 'Kirim Ulang OTP';
            document.getElementById('verifyOtpBtn').style.display = 'none'; // Hide verify if expired? Optional.
        }
    }, 1000);
}

function setupKategoriPelayananListener() {
    const kategoriSelect = document.getElementById('kategori_pelayanan');
    if (kategoriSelect) {
        kategoriSelect.addEventListener('change', function () {
            skipCompanyData = (this.value === 'Perorangan');

            const step2Fields = document.querySelectorAll('.form-step[data-step="2"] input[required], .form-step[data-step="2"] select[required], .form-step[data-step="2"] textarea[required]');
            step2Fields.forEach(field => {
                if (skipCompanyData) {
                    field.removeAttribute('required');
                } else {
                    field.setAttribute('required', 'required');
                }
            });

            updateProgressIndicator(currentStep);
        });
    }
}

function changeStep(direction) {
    if (direction === 1 && !validateCurrentStep()) {
        return false;
    }

    if (direction === 1 && currentStep === 1 && skipCompanyData) {
        currentStep = 3;
    } else if (direction === -1 && currentStep === 3 && skipCompanyData) {
        currentStep = 1;
    } else {
        currentStep += direction;
    }

    showStep(currentStep);
}

function showStep(step) {
    const steps = document.querySelectorAll('.form-step');
    steps.forEach(s => s.classList.remove('active'));

    const currentStepElement = document.querySelector(`.form-step[data-step="${step}"]`);
    if (currentStepElement) {
        currentStepElement.classList.add('active');
    }

    const registerForm = document.getElementById('registerForm');
    if (registerForm) {
        registerForm.scrollTop = 0;
    }

    updateProgressIndicator(step);

    const descriptionElement = document.getElementById('step-description');
    if (descriptionElement) {
        descriptionElement.textContent = stepDescriptions[step];
    }

    updateNavigationButtons(step);
}

function updateProgressIndicator(step) {
    const dots = document.querySelectorAll('.dot');

    dots.forEach((dot, index) => {
        const stepNumber = index + 1;

        if (stepNumber === step) {
            dot.classList.add('active');
        } else {
            dot.classList.remove('active');
        }

        if (stepNumber === 2 && skipCompanyData) {
            dot.style.display = 'none';
        } else {
            dot.style.display = 'inline-block';
        }
    });
}

function updateNavigationButtons(step) {
    const prevBtn = document.getElementById('prevBtn');
    const nextBtn = document.getElementById('nextBtn');
    const submitBtn = document.getElementById('submitBtn');

    if (step === 1) {
        prevBtn.style.display = 'none';
    } else {
        prevBtn.style.display = 'block';
    }

    const isFinalStep = (step === totalSteps) || (step === 3 && skipCompanyData);

    if (isFinalStep) {
        nextBtn.style.display = 'none';
        submitBtn.style.display = 'block';
        // Ensure submit button state reflects password validity
        updateSubmitButtonState();
    } else {
        nextBtn.style.display = 'block';
        submitBtn.style.display = 'none';
    }
}

function validateCurrentStep() {
    const currentStepElement = document.querySelector(`.form-step[data-step="${currentStep}"]`);
    if (!currentStepElement) return false;

    const inputs = currentStepElement.querySelectorAll('input[required], textarea[required], select[required]');
    let isValid = true;

    // Clear previous error states (only visible fields)
    inputs.forEach(input => {
        if (input.type !== 'hidden') {
            input.classList.remove('error');
            void input.offsetWidth;
        }
    });

    inputs.forEach(input => {
        let isFieldValid = true;

        if (!input.value.trim()) {
            isFieldValid = false;
        } else {
            if (input.type === 'email') {
                if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(input.value)) {
                    isFieldValid = false;
                }
            }

            // Only use iti.isValidNumber() specifically for the no_whatsapp phone field
            if (input.type === 'tel' && input.id === 'no_whatsapp') {
                if (iti && !iti.isValidNumber()) {
                    isFieldValid = false;
                }
            }
        }

        if (!isFieldValid) {
            // Only show visual error on non-hidden fields
            if (input.type !== 'hidden') {
                input.classList.add('error');

                const removeError = () => {
                    input.classList.remove('error');
                    input.removeEventListener('input', removeError);
                    input.removeEventListener('change', removeError);
                };
                input.addEventListener('input', removeError);
                input.addEventListener('change', removeError);
            }
            isValid = false;
        }
    });

    if (currentStep === 1) {
        // Cek email tidak duplikat
        if (!isEmailAvailable) {
            const emailInput = document.getElementById('email');
            if (emailInput) emailInput.classList.add('error');
            const emailFeedback = document.getElementById('email-feedback');
            if (emailFeedback) {
                emailFeedback.textContent = '✗ Email sudah terdaftar, gunakan email lain';
                emailFeedback.className = 'username-feedback error';
            }
            isValid = false;
        }
    }

    if (currentStep === 3) {
        const username = document.getElementById('username');
        const password = document.getElementById('password');
        const confirmPassword = document.getElementById('password_confirmation');

        if (username.value.length < 4) {
            username.classList.add('error');
            isValid = false;
        }

        const usernameStatus = document.getElementById('username-status');
        if (!usernameStatus.classList.contains('available')) {
            username.classList.add('error');
            isValid = false;
        }

        if (!isPasswordValid(password.value)) {
            password.classList.add('error');
            isValid = false;
        }

        if (password.value !== confirmPassword.value) {
            confirmPassword.classList.add('error');
            isValid = false;
        }

        [username, password, confirmPassword].forEach(field => {
            const removeError = () => field.classList.remove('error');
            field.addEventListener('input', removeError);
        });
    }

    return isValid;
}

function setupPasswordValidation() {
    const passwordInput = document.getElementById('password');
    const confirmPasswordInput = document.getElementById('password_confirmation');

    if (passwordInput) {
        passwordInput.addEventListener('input', validatePassword);
    }

    if (confirmPasswordInput) {
        confirmPasswordInput.addEventListener('input', checkPasswordMatch);
    }
}

function validatePassword() {
    const password = document.getElementById('password').value;

    const lengthReq = document.getElementById('req-length');
    if (lengthReq) {
        if (password.length >= 8) {
            lengthReq.classList.add('valid');
        } else {
            lengthReq.classList.remove('valid');
        }
    }

    const uppercaseReq = document.getElementById('req-uppercase');
    if (uppercaseReq) {
        if (/[A-Z]/.test(password)) {
            uppercaseReq.classList.add('valid');
        } else {
            uppercaseReq.classList.remove('valid');
        }
    }

    const numberReq = document.getElementById('req-number');
    if (numberReq) {
        if (/[0-9]/.test(password)) {
            numberReq.classList.add('valid');
        } else {
            numberReq.classList.remove('valid');
        }
    }

    const specialReq = document.getElementById('req-special');
    if (specialReq) {
        if (/[!@#$%^&*()_+\-=\[\]{}|;:,.<>?]/.test(password)) {
            specialReq.classList.add('valid');
        } else {
            specialReq.classList.remove('valid');
        }
    }

    checkPasswordMatch();
    updateSubmitButtonState();
}

function isPasswordValid(password) {
    return password.length >= 8 &&
        /[A-Z]/.test(password) &&
        /[0-9]/.test(password) &&
        /[!@#$%^&*()_+\-=\[\]{}|;:,.<>?]/.test(password);
}

function checkPasswordMatch() {
    const password = document.getElementById('password').value;
    const confirmPassword = document.getElementById('password_confirmation').value;

    if (!confirmPassword) {
        resetMatchBars();
        updateSubmitButtonState();
        return;
    }

    const bar1 = document.getElementById('match-bar-1');
    const bar2 = document.getElementById('match-bar-2');
    const bar3 = document.getElementById('match-bar-3');
    const message = document.getElementById('match-message');

    if (password === confirmPassword && password.length > 0) {
        bar1.className = 'match-bar green';
        bar2.className = 'match-bar green';
        bar3.className = 'match-bar green';
        message.textContent = '✓ Kata sandi cocok';
        message.className = 'match-message green';
    } else if (password.startsWith(confirmPassword) || confirmPassword.startsWith(password)) {
        bar1.className = 'match-bar yellow';
        bar2.className = 'match-bar yellow';
        bar3.className = 'match-bar';
        message.textContent = '⚠ Kata sandi agak cocok';
        message.className = 'match-message yellow';
    } else {
        bar1.className = 'match-bar red';
        bar2.className = 'match-bar';
        bar3.className = 'match-bar';
        message.textContent = '✗ Kata sandi tidak cocok';
        message.className = 'match-message red';
    }

    updateSubmitButtonState();
}

function resetMatchBars() {
    const bar1 = document.getElementById('match-bar-1');
    const bar2 = document.getElementById('match-bar-2');
    const bar3 = document.getElementById('match-bar-3');
    const message = document.getElementById('match-message');

    if (bar1) bar1.className = 'match-bar';
    if (bar2) bar2.className = 'match-bar';
    if (bar3) bar3.className = 'match-bar';
    if (message) {
        message.textContent = '';
        message.className = 'match-message';
    }
}

function updateSubmitButtonState() {
    const submitBtn = document.getElementById('submitBtn');
    if (!submitBtn) return;

    const pwd = document.getElementById('password');
    const cpwd = document.getElementById('password_confirmation');
    if (!pwd || !cpwd) return;

    const passwordOk = isPasswordValid(pwd.value);
    const passwordsMatch = pwd.value === cpwd.value && pwd.value.length > 0;

    if (passwordOk && passwordsMatch) {
        submitBtn.disabled = false;
        submitBtn.style.opacity = '1';
        submitBtn.style.cursor = 'pointer';
    } else {
        submitBtn.disabled = true;
        submitBtn.style.opacity = '0.5';
        submitBtn.style.cursor = 'not-allowed';
    }
}

function setupPhoneValidation() {
    // Pengecekan duplikat no HP HANYA dilakukan setelah OTP berhasil diverifikasi.
    // Blur-check dihapus agar tidak mengganggu alur tombol OTP sebelum verifikasi.
    // (Tidak ada listener di sini — check dipanggil manual dari verifyOTP)
}

function checkPhoneAvailability(fullPhone) {
    // Hanya dipanggil SETELAH OTP berhasil diverifikasi
    const csrfToken = document.querySelector('#registerForm input[name="csrf_token"]')?.value || document.querySelector('input[name="csrf_token"]')?.value;
    const feedback = document.getElementById('otp-feedback');

    if (!feedback) return;

    const body = new URLSearchParams();
    body.append('phone', fullPhone);
    if (csrfToken) body.append('csrf_token', csrfToken);

    fetch(window.appBaseUrl + '/check-phone', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        credentials: 'same-origin',
        body: body.toString()
    })
        .then(r => r.json())
        .then(data => {
            if (!data.available) {
                // Nomor sudah terdaftar: batalkan verifikasi dan tampilkan pesan
                if (data.message.includes('sudah terdaftar')) {
                    feedback.innerHTML = '✗ ' + data.message + '. Silakan <a href="' + window.appBaseUrl + '/auth" style="color:inherit;text-decoration:underline;font-weight:bold;">login di sini</a>';
                } else {
                    feedback.textContent = '✗ ' + data.message;
                }
                feedback.className = 'username-feedback error';
                isPhoneVerified = false;

                // Kembalikan tampilan button OTP ke kondisi awal
                const sendBtn = document.getElementById('sendOtpBtn');
                const phoneInput = document.getElementById('no_whatsapp');
                if (sendBtn) {
                    sendBtn.disabled = false;
                    sendBtn.style.backgroundColor = '';
                    sendBtn.style.borderColor = '';
                    sendBtn.textContent = 'Ganti Nomor';
                    sendBtn.style.display = 'block';
                }
                if (phoneInput) phoneInput.disabled = false;
            }
            // Jika available = true: nomor valid & unik, tidak perlu ubah apapun
        })
        .catch(() => {
            // Abaikan error jaringan — state verifikasi tetap terjaga
        });
}

function setupEmailValidation() {
    const emailInput = document.getElementById('email');
    if (!emailInput) return;

    let emailTimeout;
    emailInput.addEventListener('input', function () {
        clearTimeout(emailTimeout);
        const email = this.value.trim();
        const feedbackEl = document.getElementById('email-feedback');

        if (!feedbackEl) return;

        if (!email || !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email)) {
            feedbackEl.textContent = '';
            feedbackEl.className = 'username-feedback';
            return;
        }

        feedbackEl.textContent = 'Memeriksa email...';
        feedbackEl.className = 'username-feedback';

        emailTimeout = setTimeout(() => {
            checkEmailAvailability(email);
        }, 500);
    });
}

let isEmailAvailable = true;

function checkEmailAvailability(email) {
    const csrfToken = document.querySelector('#registerForm input[name="csrf_token"]')?.value || document.querySelector('input[name="csrf_token"]')?.value;
    const feedbackEl = document.getElementById('email-feedback');

    if (!feedbackEl) return;

    const body = new URLSearchParams();
    body.append('email', email);
    if (csrfToken) body.append('csrf_token', csrfToken);

    fetch(window.appBaseUrl + '/check-email', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        credentials: 'same-origin',
        body: body.toString()
    })
        .then(r => r.json())
        .then(data => {
            if (data.available) {
                feedbackEl.textContent = data.message ? '✓ ' + data.message : '';
                feedbackEl.className = 'username-feedback success';
                isEmailAvailable = true;
                const emailInput = document.getElementById('email');
                if (emailInput) emailInput.classList.remove('error');
            } else {
                feedbackEl.textContent = '✗ ' + data.message;
                feedbackEl.className = 'username-feedback error';
                isEmailAvailable = false;
                const emailInput = document.getElementById('email');
                if (emailInput) emailInput.classList.add('error');
            }
        })
        .catch(() => {
            isEmailAvailable = true;
        });
}

function setupUsernameValidation() {
    const usernameInput = document.getElementById('username');
    if (usernameInput) {
        let usernameTimeout;
        usernameInput.addEventListener('input', function () {
            clearTimeout(usernameTimeout);
            const username = this.value.trim();

            if (username.length < 4) {
                resetUsernameStatus();
                return;
            }

            showLoading();

            usernameTimeout = setTimeout(() => {
                checkUsernameAvailability(username);
            }, 500);
        });
    }
}

function checkUsernameAvailability(username) {
    const csrfToken = document.querySelector('#registerForm input[name="csrf_token"]')?.value || document.querySelector('input[name="csrf_token"]')?.value;

    console.log('Checking username availability:', username);
    console.log('CSRF Token being sent:', csrfToken);

    const body = new URLSearchParams();
    body.append('username', username);
    if (csrfToken) {
        body.append('csrf_token', csrfToken);
    }

    fetch(window.appBaseUrl + '/check-username', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/x-www-form-urlencoded',
        },
        credentials: 'same-origin', // Ensure cookies/session are sent
        body: body.toString()
    })
        .then(response => response.json())
        .then(data => {
            const statusIcon = document.getElementById('username-status');
            const feedback = document.getElementById('username-feedback');

            if (data.available) {
                statusIcon.className = 'username-status available';
                feedback.textContent = '✓ ' + data.message;
                feedback.className = 'username-feedback success';
            } else {
                statusIcon.className = 'username-status taken';
                feedback.textContent = '✗ ' + data.message;
                feedback.className = 'username-feedback error';
            }
        })
        .catch(error => {
            console.error('Error checking username:', error);
            resetUsernameStatus();
        });
}

function showLoading() {
    const statusIcon = document.getElementById('username-status');
    const feedback = document.getElementById('username-feedback');
    if (statusIcon) statusIcon.className = 'username-status loading';
    if (feedback) {
        feedback.textContent = 'Memeriksa...';
        feedback.className = 'username-feedback';
    }
}

function resetUsernameStatus() {
    const statusIcon = document.getElementById('username-status');
    const feedback = document.getElementById('username-feedback');
    if (statusIcon) statusIcon.className = 'username-status';
    if (feedback) {
        feedback.textContent = '';
        feedback.className = 'username-feedback';
    }
}

async function loadProvinces() {
    try {
        const base = window.appBaseUrl || window.BASE_URL || '';
        const response = await fetch(`${base}/api/wilayah/provinsi`);
        const result = await response.json();
        if (!result.success) return;

        const formattedProvinces = result.data.map(p => ({ id: p.kode_provinsi, name: p.nama_provinsi }));

        updateCustomDropdownOptions('dropdown-provinsi_tinggal', formattedProvinces, 'Pilih Provinsi');
        updateCustomDropdownOptions('dropdown-provinsi_perusahaan', formattedProvinces, 'Pilih Provinsi');

    } catch (error) {
        console.error('Error loading provinces:', error);
    }
}

async function loadKabupaten(provinceId, targetInputId) {
    const dropdownId = 'dropdown-' + targetInputId;
    const districtInputId = targetInputId.replace('kabupaten', 'kecamatan');
    const postalInputId = targetInputId.replace('kabupaten', 'kodepos');

    updateCustomDropdownOptions(dropdownId, [], 'Pilih Kab/Kota');
    updateCustomDropdownOptions('dropdown-' + districtInputId, [], 'Pilih Kecamatan');
    updateCustomDropdownOptions('dropdown-' + postalInputId, [], 'Pilih Kode Pos');

    if (!provinceId) return;

    try {
        const base = window.appBaseUrl || window.BASE_URL || '';
        const response = await fetch(`${base}/api/wilayah/kabupaten?kode_provinsi=${encodeURIComponent(provinceId)}`);
        const result = await response.json();
        if (!result.success) return;

        const formatted = result.data.map(r => ({ id: r.kode_kabupaten, name: r.nama_kabupaten }));
        updateCustomDropdownOptions(dropdownId, formatted, 'Pilih Kab/Kota');
    } catch (error) {
        console.error('Error loading regencies:', error);
    }
}

async function loadKecamatan(regencyId, targetInputId) {
    const dropdownId = 'dropdown-' + targetInputId;
    const postalInputId = targetInputId.replace('kecamatan', 'kodepos');

    updateCustomDropdownOptions(dropdownId, [], 'Pilih Kecamatan');
    updateCustomDropdownOptions('dropdown-' + postalInputId, [], 'Pilih Kode Pos');

    if (!regencyId) return;

    try {
        const base = window.appBaseUrl || window.BASE_URL || '';
        const response = await fetch(`${base}/api/wilayah/kecamatan?kode_kabupaten=${encodeURIComponent(regencyId)}`);
        const result = await response.json();
        if (!result.success) return;

        const formatted = result.data.map(d => ({ id: d.kode_kecamatan, name: d.nama_kecamatan, kode_pos: d.kode_pos }));
        updateCustomDropdownOptions(dropdownId, formatted, 'Pilih Kecamatan');
    } catch (error) {
        console.error('Error loading districts:', error);
    }
}

function loadPostalCode(districtId, targetInputId) {
    const dropdownId = 'dropdown-' + targetInputId;
    updateCustomDropdownOptions(dropdownId, [], 'Pilih Kode Pos');

    if (!districtId) return;

    const districtOption = document.querySelector(`.custom-select .option[data-value="${districtId}"]`);
    const kodePos = districtOption?.dataset.kodepos || '';

    if (kodePos) {
        updateCustomDropdownOptions(dropdownId, [{ id: kodePos, name: kodePos }], 'Pilih Kode Pos');
        const input = document.getElementById(targetInputId);
        const firstOption = document.querySelector(`#${dropdownId} .option[data-value="${kodePos}"]`);
        if (firstOption) firstOption.click();
    }
}

function setupIntlTelInput() {
    const itiOptions = {
        initialCountry: "id",
        separateDialCode: true,
        utilsScript: "https://cdnjs.cloudflare.com/ajax/libs/intl-tel-input/17.0.19/js/utils.js",
        preferredCountries: ["id", "my", "sg", "th", "ph", "vn"]
    };

    const phoneInput = document.querySelector("#no_whatsapp");
    if (phoneInput) {
        iti = window.intlTelInput(phoneInput, itiOptions);
    }

    const telpInput = document.querySelector("#telp_perusahaan");
    if (telpInput) {
        itiTelp = window.intlTelInput(telpInput, itiOptions);
    }

    const waInput = document.querySelector("#wa_perusahaan");
    if (waInput) {
        itiWa = window.intlTelInput(waInput, itiOptions);
    }
}

document.addEventListener('DOMContentLoaded', function () {
    const prevBtn = document.getElementById('prevBtn');
    const nextBtn = document.getElementById('nextBtn');

    if (prevBtn) {
        prevBtn.addEventListener('click', () => changeStep(-1));
    }

    if (nextBtn) {
        nextBtn.addEventListener('click', () => changeStep(1));
    }

    const registerForm = document.getElementById('registerForm');
    if (registerForm) {
        registerForm.addEventListener('submit', function (e) {
            if (currentStep === 1) {
                const verifyBtn = document.getElementById('verifyOtpBtn');
                const sendBtn = document.getElementById('sendOtpBtn');
                if (verifyBtn && verifyBtn.style.display !== 'none') {
                    e.preventDefault();
                    verifyOTP();
                    return false;
                } else if (sendBtn && sendBtn.style.display !== 'none' && !sendBtn.disabled) {
                    e.preventDefault();
                    sendOTP();
                    return false;
                }
            }

            // Extra validation: block submit if password invalid or mismatch
            const pwd = document.getElementById('password');
            const cpwd = document.getElementById('password_confirmation');
            if (pwd && cpwd) {
                if (!isPasswordValid(pwd.value)) {
                    e.preventDefault();
                    pwd.classList.add('error');
                    showCustomAlert('Password belum memenuhi persyaratan (min 8 karakter, huruf kapital, angka, dan simbol).');
                    return false;
                }
                if (pwd.value !== cpwd.value) {
                    e.preventDefault();
                    cpwd.classList.add('error');
                    showCustomAlert('Password dan Konfirmasi Password tidak cocok.');
                    return false;
                }
            }

            if (!validateCurrentStep()) {
                e.preventDefault();
                showCustomAlert('Mohon lengkapi semua field dengan benar sebelum mendaftar.');
                return false;
            }

            // Inject full international number for all phone fields before submit
            if (iti) {
                const phoneInput = document.querySelector("#no_whatsapp");
                phoneInput.disabled = false;
                phoneInput.value = iti.getNumber();
            }
            if (itiTelp) {
                const telpInput = document.querySelector("#telp_perusahaan");
                if (telpInput && telpInput.value.trim()) {
                    telpInput.value = itiTelp.getNumber();
                }
            }
            if (itiWa) {
                const waInput = document.querySelector("#wa_perusahaan");
                if (waInput) {
                    waInput.disabled = false;
                    waInput.value = itiWa.getNumber();
                }
            }
        });
    }
});

let isLoginVerified = false;
let loginOtpTimer = null;
// 'wa' = via WhatsApp, 'email' = via Email
let loginOtpChannel = 'wa';

document.addEventListener('DOMContentLoaded', function () {
    setupLoginOtpListeners();
});

function setupLoginOtpListeners() {
    const btnNext   = document.getElementById('btnLoginNext');
    const btnSend   = document.getElementById('btnLoginSendOtp');
    const btnVerify = document.getElementById('btnLoginVerifyOtp');
    const btnSwitch = document.getElementById('btnSwitchOtpChannel');

    if (btnNext)   btnNext.addEventListener('click', handleLoginNext);
    if (btnSend)   btnSend.addEventListener('click', sendLoginOTP);
    if (btnVerify) btnVerify.addEventListener('click', verifyLoginOTP);
    if (btnSwitch) {
        btnSwitch.addEventListener('click', function (e) {
            e.preventDefault();
            // Toggle channel
            loginOtpChannel = (loginOtpChannel === 'wa') ? 'email' : 'wa';
            // Update teks link switch
            updateSwitchLink();
            // Kirim ulang OTP dengan channel baru
            sendLoginOTP();
        });
    }

    const loginForm = document.getElementById('loginForm');
    if (loginForm) {
        loginForm.addEventListener('submit', function (e) {
            // Jika OTP sudah terverifikasi, biarkan form submit berjalan normal
            if (isLoginVerified) {
                return true;
            }

            const otpSection = document.getElementById('loginOtpSection');
            const btnSubmit  = document.getElementById('btnLoginSubmit');
            const btnVerifyEl = document.getElementById('btnLoginVerifyOtp');
            const btnSendEl   = document.getElementById('btnLoginSendOtp');

            if (otpSection && otpSection.style.display !== 'none' && btnSubmit && btnSubmit.style.display === 'none') {
                e.preventDefault();
                if (btnVerifyEl && btnVerifyEl.style.display !== 'none') {
                    verifyLoginOTP();
                } else if (btnSendEl && btnSendEl.style.display !== 'none' && !btnSendEl.disabled) {
                    sendLoginOTP();
                }
                return false;
            }

            if (otpSection && otpSection.style.display === 'none' && btnSubmit && btnSubmit.style.display === 'none') {
                e.preventDefault();
                handleLoginNext();
            }
        });
    }
}

function updateSwitchLink() {
    const btnSwitch = document.getElementById('btnSwitchOtpChannel');
    if (!btnSwitch) return;

    if (loginOtpChannel === 'wa') {
        btnSwitch.textContent = 'Kirim melalui Email';
    } else {
        btnSwitch.textContent = 'Kirim melalui No HP (WhatsApp)';
    }
}

function showOtpChannelInfo(channel, maskedEmail) {
    const channelInfo = document.getElementById('loginOtpChannelInfo');
    const btnSwitch   = document.getElementById('btnSwitchOtpChannel');

    if (!channelInfo || !btnSwitch) return;

    channelInfo.style.display = 'block';

    if (channel === 'email') {
        btnSwitch.textContent = 'Kirim melalui No HP (WhatsApp)';
    } else {
        btnSwitch.textContent = 'Kirim melalui Email';
    }
}

function handleLoginNext() {
    const usernameInput = document.getElementById('login-username');
    const passwordInput = document.getElementById('login-password');
    const btnNext = document.getElementById('btnLoginNext');
    const otpSection = document.getElementById('loginOtpSection');
    const csrfToken = document.querySelector('input[name="csrf_token"]').value;

    if (!usernameInput || !passwordInput || !btnNext || !otpSection) {
        console.error('Login form elements missing');
        return;
    }

    if (!usernameInput.value.trim() || !passwordInput.value.trim()) {
        showCustomAlert('Harap isi Username atau Password');
        return;
    }

    btnNext.disabled = true;
    btnNext.textContent = 'Memvalidasi...';

    fetch(window.appBaseUrl + '/validate-login', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/x-www-form-urlencoded',
        },
        body: new URLSearchParams({
            username: usernameInput.value.trim(),
            password: passwordInput.value,
            csrf_token: csrfToken
        })
    })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                usernameInput.readOnly = true;
                passwordInput.readOnly = true;

                // Reset channel ke WA (default) setiap kali OTP section dibuka
                loginOtpChannel = 'wa';
                const channelInfo = document.getElementById('loginOtpChannelInfo');
                if (channelInfo) channelInfo.style.display = 'none';
                const btnSend = document.getElementById('btnLoginSendOtp');
                if (btnSend) btnSend.textContent = 'Kirim OTP';

                otpSection.style.setProperty('display', 'block', 'important');
                btnNext.style.setProperty('display', 'none', 'important');
            } else {
                showCustomAlert(data.message);
                btnNext.disabled = false;
                btnNext.textContent = 'Lanjutkan';
            }
        })
        .catch(error => {
            console.error('Error validating login:', error);
            showCustomAlert('Terjadi kesalahan server saat memvalidasi.');
            btnNext.disabled = false;
            btnNext.textContent = 'Lanjutkan';
        });
}

function startLoginOtpCooldown(duration) {
    const btnSend   = document.getElementById('btnLoginSendOtp');
    const btnSwitch = document.getElementById('btnSwitchOtpChannel');
    let remaining   = duration;

    if (loginOtpTimer) clearInterval(loginOtpTimer);

    btnSend.disabled = true;
    // Nonaktifkan link switch selama countdown
    if (btnSwitch) btnSwitch.classList.add('disabled');

    loginOtpTimer = setInterval(function () {
        const minutes     = Math.floor(remaining / 60);
        const seconds     = remaining % 60;
        const displaySecs = seconds < 10 ? '0' + seconds : seconds;

        btnSend.textContent = 'Kirim Ulang (' + minutes + ':' + displaySecs + ')';

        if (--remaining < 0) {
            clearInterval(loginOtpTimer);
            loginOtpTimer = null;
            btnSend.disabled = false;
            btnSend.textContent = 'Kirim Ulang';
            // Aktifkan kembali link switch setelah countdown selesai
            if (btnSwitch) btnSwitch.classList.remove('disabled');
        }
    }, 1000);
}

function sendLoginOTP() {
    const btnSend   = document.getElementById('btnLoginSendOtp');
    const feedback  = document.getElementById('login-otp-feedback');
    const btnVerify = document.getElementById('btnLoginVerifyOtp');
    const otpInput  = document.getElementById('login_otp_code');
    const btnSwitch = document.getElementById('btnSwitchOtpChannel');
    const username  = document.getElementById('login-username').value.trim();
    const csrfToken = document.querySelector('input[name="csrf_token"]').value;

    btnSend.disabled = true;
    btnSend.textContent = 'Mengirim...';
    feedback.textContent = '';
    // Nonaktifkan link switch saat sedang mengirim
    if (btnSwitch) btnSwitch.classList.add('disabled');

    // Tentukan endpoint berdasarkan channel saat ini
    const isEmail = (loginOtpChannel === 'email');
    const endpoint = isEmail
        ? window.appBaseUrl + '/api/send-otp-email'
        : window.appBaseUrl + '/api/send-otp';

    fetch(endpoint, {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: new URLSearchParams({
            username:   username,
            action:     'login',
            csrf_token: csrfToken
        })
    })
    .then(response => response.json())
    .then(result => {
        if (result.success) {
            feedback.textContent = isEmail
                ? ('OTP terkirim ke email: ' + (result.masked_email || ''))
                : 'OTP terkirim via WhatsApp';
            feedback.className = 'username-feedback success';

            btnVerify.style.display = 'block';
            otpInput.focus();

            // Tampilkan link switch channel
            showOtpChannelInfo(loginOtpChannel, result.masked_email || '');

            // Mulai countdown 60 detik (link switch diaktifkan kembali setelah selesai)
            startLoginOtpCooldown(60);
        } else {
            feedback.textContent = result.message;
            feedback.className = 'username-feedback error';
            btnSend.disabled = false;
            // Kembalikan teks tombol sesuai apakah sudah pernah dikirim
            const hasBeenSent = document.getElementById('loginOtpChannelInfo').style.display !== 'none';
            btnSend.textContent = hasBeenSent ? 'Kirim Ulang' : 'Kirim OTP';
            // Aktifkan kembali link switch jika gagal
            if (btnSwitch && hasBeenSent) btnSwitch.classList.remove('disabled');
        }
    })
    .catch(error => {
        console.error('Error sending OTP:', error);
        feedback.textContent = 'Terjadi kesalahan saat mengirim OTP';
        feedback.className = 'username-feedback error';
        btnSend.disabled = false;
        btnSend.textContent = 'Kirim OTP';
        if (btnSwitch) btnSwitch.classList.remove('disabled');
    });
}


function verifyLoginOTP() {
    const otpInput = document.getElementById('login_otp_code');
    const btnVerify = document.getElementById('btnLoginVerifyOtp');
    const btnSubmit = document.getElementById('btnLoginSubmit');
    const feedback = document.getElementById('login-otp-feedback');
    const otpSection = document.getElementById('loginOtpSection');
    const username = document.getElementById('login-username').value.trim();
    const csrfToken = document.querySelector('input[name="csrf_token"]').value;
    const otp = otpInput.value.trim();

    if (otp.length < 4) {
        feedback.textContent = 'Kode OTP minimal 4 karakter';
        feedback.className = 'username-feedback error';
        return;
    }

    btnVerify.disabled = true;
    btnVerify.textContent = 'Memverifikasi...';

    fetch(window.appBaseUrl + '/api/verify-otp', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/x-www-form-urlencoded',
        },
        body: new URLSearchParams({
            username: username,
            otp: otp,
            csrf_token: csrfToken
        })
    })
        .then(response => response.json())
        .then(result => {
            if (result.success) {
                feedback.textContent = 'Verifikasi Berhasil! Mengalihkan ke dashboard...';
                feedback.className = 'username-feedback success';

                btnVerify.textContent = 'Terverifikasi ✓';
                btnVerify.style.backgroundColor = '#10b981'; // Green
                btnVerify.disabled = true;

                // Hentikan timer cooldown OTP
                if (loginOtpTimer) {
                    clearInterval(loginOtpTimer);
                    loginOtpTimer = null;
                }

                // Sembunyikan button kirim OTP
                const btnSend = document.getElementById('btnLoginSendOtp');
                if (btnSend) btnSend.style.display = 'none';

                // Tandai OTP sudah terverifikasi agar interceptor form tidak menghalangi
                isLoginVerified = true;

                // Auto-submit form login ke server
                setTimeout(() => {
                    const loginForm = document.getElementById('loginForm');
                    if (loginForm) {
                        // Aktifkan kembali field username & password agar terkirim lewat form
                        const usernameInput = document.getElementById('login-username');
                        const passwordInput = document.getElementById('login-password');
                        if (usernameInput) usernameInput.readOnly = false;
                        if (passwordInput) passwordInput.readOnly = false;
                        loginForm.submit();
                    }
                }, 800);
            } else {
                feedback.textContent = result.message;
                feedback.className = 'username-feedback error';
                btnVerify.disabled = false;
                btnVerify.textContent = 'Verifikasi';
            }
        })
        .catch(error => {
            console.error('Error verifying OTP:', error);
            feedback.textContent = 'Terjadi kesalahan verifikasi';
            feedback.className = 'username-feedback error';
            btnVerify.disabled = false;
            btnVerify.textContent = 'Verifikasi';
        });
}

document.addEventListener('DOMContentLoaded', function () {
    const btnVerifyUsername = document.getElementById('btnVerifyUsername');
    const btnSendWhatsApp = document.getElementById('btnSendWhatsApp');
    const btnSendEmail = document.getElementById('btnSendEmail');

    if (btnVerifyUsername) {
        btnVerifyUsername.addEventListener('click', verifyForgotUsername);
    }

    if (btnSendWhatsApp) {
        btnSendWhatsApp.addEventListener('click', () => sendResetLink('whatsapp'));
    }

    if (btnSendEmail) {
        btnSendEmail.addEventListener('click', () => sendResetLink('email'));
    }

    const forgotUsernameInput = document.getElementById('forgot-username');
    if (forgotUsernameInput) {
        forgotUsernameInput.addEventListener('keydown', function (e) {
            if (e.key === 'Enter') {
                e.preventDefault();
                verifyForgotUsername();
            }
        });
    }
});

async function verifyForgotUsername() {
    const usernameInput = document.getElementById('forgot-username');
    const feedback = document.getElementById('forgot-feedback');
    const btnVerify = document.getElementById('btnVerifyUsername');
    const username = usernameInput.value.trim();

    if (!username) {
        feedback.textContent = 'Username harus diisi';
        feedback.className = 'username-feedback error';
        return;
    }

    btnVerify.disabled = true;
    btnVerify.textContent = 'Memverifikasi...';
    feedback.textContent = '';

    try {
        const csrfToken = document.querySelector('input[name="csrf_token"]')?.value;

        const response = await fetch(window.appBaseUrl + '/forgot-password', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded',
                'X-Requested-With': 'XMLHttpRequest'
            },
            body: new URLSearchParams({
                action: 'verify_username',
                username: username,
                csrf_token: csrfToken
            })
        });

        const result = await response.json();

        if (result.success) {
            feedback.textContent = '✓ ' + result.message;
            feedback.className = 'username-feedback success';

            setTimeout(() => {
                document.getElementById('usernameVerificationSection').style.display = 'none';
                document.getElementById('deliveryMethodSection').style.display = 'block';
                document.getElementById('forgot-description').textContent = 'Pilih metode pengiriman';

                if (!result.has_email) {
                    const emailBtn = document.getElementById('btnSendEmail');
                    emailBtn.disabled = true;
                    emailBtn.title = 'Email tidak tersedia untuk akun ini';
                    emailBtn.style.opacity = '0.5';
                }

                if (!result.has_phone) {
                    const waBtn = document.getElementById('btnSendWhatsApp');
                    waBtn.disabled = true;
                    waBtn.title = 'WhatsApp tidak tersedia untuk akun ini';
                    waBtn.style.opacity = '0.5';
                }
            }, 800);

        } else {
            feedback.textContent = '✗ ' + result.message;
            feedback.className = 'username-feedback error';
            btnVerify.disabled = false;
            btnVerify.textContent = 'Verifikasi';
        }

    } catch (error) {
        console.error('Error verifying username:', error);
        feedback.textContent = 'Terjadi kesalahan. Silakan coba lagi.';
        feedback.className = 'username-feedback error';
        btnVerify.disabled = false;
        btnVerify.textContent = 'Verifikasi';
    }
}

async function sendResetLink(method) {
    const feedback = document.getElementById('delivery-feedback');
    const btnWhatsApp = document.getElementById('btnSendWhatsApp');
    const btnEmail = document.getElementById('btnSendEmail');
    const selectedBtn = method === 'whatsapp' ? btnWhatsApp : btnEmail;
    const methodLabel = method === 'whatsapp' ? 'WhatsApp' : 'Email';

    // Save original HTML to restore later
    const originalHTML = selectedBtn.innerHTML;

    selectedBtn.disabled = true;
    selectedBtn.innerHTML = '<span>Mengirim...</span>';
    feedback.textContent = '';

    try {
        const csrfToken = document.querySelector('input[name="csrf_token"]')?.value;

        const response = await fetch(window.appBaseUrl + '/forgot-password', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded',
                'X-Requested-With': 'XMLHttpRequest'
            },
            body: new URLSearchParams({
                action: 'send_link',
                delivery_method: method,
                csrf_token: csrfToken || ''
            })
        });

        const result = await response.json();

        if (result.success) {
            feedback.textContent = '✓ ' + (result.message || 'Link reset password berhasil dikirim ke ' + methodLabel + ' Anda.');
            feedback.className = 'username-feedback success';

            setTimeout(() => {
                showLogin(null);
                resetForgotPasswordForm();
            }, 2000);

        } else {
            feedback.textContent = '✗ ' + (result.message || 'Gagal mengirim link reset password. Silakan coba lagi.');
            feedback.className = 'username-feedback error';
            selectedBtn.disabled = false;
            selectedBtn.innerHTML = originalHTML;
        }

    } catch (error) {
        console.error('Error sending reset link:', error);
        feedback.textContent = 'Terjadi kesalahan. Silakan coba lagi.';
        feedback.className = 'username-feedback error';
        selectedBtn.disabled = false;
        selectedBtn.innerHTML = originalHTML;
    }
}

function resetForgotPassword(event) {
    if (event) event.preventDefault();
    resetForgotPasswordForm();
}

function resetForgotPasswordForm() {
    document.getElementById('forgot-username').value = '';

    document.getElementById('forgot-feedback').textContent = '';
    document.getElementById('forgot-feedback').className = 'username-feedback';
    document.getElementById('delivery-feedback').textContent = '';
    document.getElementById('delivery-feedback').className = 'username-feedback';

    const btnVerify = document.getElementById('btnVerifyUsername');
    btnVerify.disabled = false;
    btnVerify.textContent = 'Verifikasi';

    const btnWhatsApp = document.getElementById('btnSendWhatsApp');
    btnWhatsApp.disabled = false;
    btnWhatsApp.style.opacity = '1';
    btnWhatsApp.innerHTML = '<svg width="24" height="24" viewBox="0 0 24 24" fill="currentColor"><path d="M17.472 14.382c-.297-.149-1.758-.867-2.03-.967-.273-.099-.471-.148-.67.15-.197.297-.767.966-.94 1.164-.173.199-.347.223-.644.075-.297-.15-1.255-.463-2.39-1.475-.883-.788-1.48-1.761-1.653-2.059-.173-.297-.018-.458.13-.606.134-.133.298-.347.446-.52.149-.174.198-.298.298-.497.099-.198.05-.371-.025-.52-.075-.149-.669-1.612-.916-2.207-.242-.579-.487-.5-.669-.51-.173-.008-.371-.01-.57-.01-.198 0-.52.074-.792.372-.272.297-1.04 1.016-1.04 2.479 0 1.462 1.065 2.875 1.213 3.074.149.198 2.096 3.2 5.077 4.487.709.306 1.262.489 1.694.625.712.227 1.36.195 1.871.118.571-.085 1.758-.719 2.006-1.413.248-.694.248-1.289.173-1.413-.074-.124-.272-.198-.57-.347m-5.421 7.403h-.004a9.87 9.87 0 01-5.031-1.378l-.361-.214-3.741.982.998-3.648-.235-.374a9.86 9.86 0 01-1.51-5.26c.001-5.45 4.436-9.884 9.888-9.884 2.64 0 5.122 1.03 6.988 2.898a9.825 9.825 0 012.893 6.994c-.003 5.45-4.437 9.884-9.885 9.884m8.413-18.297A11.815 11.815 0 0012.05 0C5.495 0 .16 5.335.157 11.892c0 2.096.547 4.142 1.588 5.945L0 24l6.305-1.654a11.882 11.882 0 005.683 1.448h.005c6.554 0 11.89-5.335 11.893-11.893a11.821 11.821 0 00-3.48-8.413z"/></svg><span>Kirim via WhatsApp</span>';

    const btnEmail = document.getElementById('btnSendEmail');
    btnEmail.disabled = false;
    btnEmail.style.opacity = '1';
    btnEmail.innerHTML = '<svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"></path><polyline points="22,6 12,13 2,6"></polyline></svg><span>Kirim via Email</span>';

    document.getElementById('usernameVerificationSection').style.display = 'block';
    document.getElementById('deliveryMethodSection').style.display = 'none';
    document.getElementById('forgot-description').textContent = 'Masukkan username untuk verifikasi';
}