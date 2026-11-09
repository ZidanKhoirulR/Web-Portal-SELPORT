document.addEventListener('DOMContentLoaded', function () {
    const otpFields = document.querySelectorAll('.otp-input');
    const otpForm = document.getElementById('otpForm');
    const verifyBtn = document.getElementById('verifyBtn');
    const resendBtn = document.getElementById('resendBtn');
    const alertContainer = document.getElementById('alert-container');

    otpFields.forEach((field, index) => {
        field.addEventListener('input', (e) => {
            const value = e.target.value;
            e.target.value = value.replace(/[^0-9]/g, '');

            if (e.target.value && index < otpFields.length - 1) {
                otpFields[index + 1].focus();
            }
        });

        field.addEventListener('keydown', (e) => {
            if (e.key === 'Backspace' && !e.target.value && index > 0) {
                otpFields[index - 1].focus();
            }
            if (e.key === 'Enter') {
                e.preventDefault();
                if (Array.from(otpFields).every(f => f.value)) {
                    submitOTP();
                }
            }
        });

        field.addEventListener('paste', (e) => {
            e.preventDefault();
            const data = e.clipboardData.getData('text').replace(/[^0-9]/g, '').split('');
            data.forEach((char, i) => {
                if (index + i < otpFields.length) {
                    otpFields[index + i].value = char;
                }
            });
            const nextIdx = Math.min(index + data.length, otpFields.length - 1);
            otpFields[nextIdx].focus();
        });
    });

    otpForm.addEventListener('submit', (e) => {
        e.preventDefault();
        submitOTP();
    });

    function submitOTP() {
        const otp = Array.from(otpFields).map(f => f.value).join('');
        if (otp.length !== 6) {
            showAlert('error', 'Silakan masukkan 6 digit kode OTP');
            return;
        }

        verifyBtn.disabled = true;
        const originalText = verifyBtn.innerHTML;
        verifyBtn.innerHTML = '<span class="spinner"></span> Memverifikasi...';

        const formData = new FormData(otpForm);
        formData.append('otp', otp);

        fetch((window.appBaseUrl || '') + '/activate/verify', {
            method: 'POST',
            body: formData
        })
            .then(res => res.json())
            .then(data => {
                if (data.success) {
                    showAlert('success', data.message);
                    setTimeout(() => window.location.href = (window.appBaseUrl || '') + '/auth', 2000);
                } else {
                    showAlert('error', data.message);
                    verifyBtn.disabled = false;
                    verifyBtn.innerHTML = originalText;
                    otpFields[0].focus();
                }
            })
            .catch(err => {
                console.error(err);
                showAlert('error', 'Terjadi kesalahan sistem');
                verifyBtn.disabled = false;
                verifyBtn.innerHTML = originalText;
            });
    }

    resendBtn.addEventListener('click', (e) => {
        e.preventDefault();

        resendBtn.style.pointerEvents = 'none';
        resendBtn.style.opacity = '0.7';

        const formData = new FormData();
        formData.append('csrf_token', otpForm.querySelector('[name="csrf_token"]').value);
        formData.append('token', otpForm.querySelector('[name="token"]').value);

        fetch((window.appBaseUrl || '') + '/activate/resend', {
            method: 'POST',
            body: formData
        })
            .then(async res => {
                const isJson = res.headers.get('content-type')?.includes('application/json');
                const data = isJson ? await res.json() : null;

                if (!res.ok) {
                    const error = (data && data.message) || res.statusText;
                    throw new Error(error);
                }
                return data;
            })
            .then(data => {
                if (data.success) {
                    showAlert('success', data.message);
                } else {
                    showAlert('error', data.message);
                }
            })
            .catch(err => {
                console.error(err);
                showAlert('error', err.message || 'Gagal mengirim ulang kode (Server Error)');
            })
            .finally(() => {
                setTimeout(() => {
                    resendBtn.style.pointerEvents = 'auto';
                    resendBtn.style.opacity = '1';
                }, 1000);
            });
    });

    function showAlert(type, message) {
        alertContainer.innerHTML = `<div class="alert alert-${type}">${message}</div>`;
    }

    otpFields[0].focus();
});