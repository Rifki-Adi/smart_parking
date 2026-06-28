(function () {
    function injectParkingFullStyle() {
        if (document.getElementById('parking-full-notice-style')) return;
        const style = document.createElement('style');
        style.id = 'parking-full-notice-style';
        style.textContent = `
            .parking-full-banner {
                display: flex;
                align-items: center;
                gap: 14px;
                width: 100%;
                padding: 16px 18px;
                margin: 0 0 18px 0;
                border-radius: 18px;
                background: linear-gradient(135deg, #dc2626, #991b1b);
                color: #ffffff;
                box-shadow: 0 12px 30px rgba(220, 38, 38, .28);
                border: 1px solid rgba(255,255,255,.25);
                animation: parkingFullPulse 1.3s ease-in-out infinite alternate;
            }
            .parking-full-banner.d-none { display: none !important; }
            .parking-full-icon {
                width: 48px;
                height: 48px;
                min-width: 48px;
                border-radius: 999px;
                display: flex;
                align-items: center;
                justify-content: center;
                background: rgba(255,255,255,.18);
                font-size: 24px;
                animation: parkingFullShake .85s ease-in-out infinite;
            }
            .parking-full-title {
                font-size: 18px;
                font-weight: 900;
                letter-spacing: .5px;
                line-height: 1.1;
            }
            .parking-full-subtitle {
                font-size: 13px;
                opacity: .95;
                margin-top: 4px;
            }
            .parking-full-area .slot-card,
            .parking-full-area [id^="slot-box-"] {
                animation: slotFullGlow 1.2s ease-in-out infinite alternate;
            }
            @keyframes parkingFullPulse {
                from { transform: translateY(0); box-shadow: 0 12px 30px rgba(220, 38, 38, .25); }
                to { transform: translateY(-2px); box-shadow: 0 16px 38px rgba(220, 38, 38, .45); }
            }
            @keyframes parkingFullShake {
                0%, 100% { transform: rotate(0deg); }
                25% { transform: rotate(-5deg); }
                75% { transform: rotate(5deg); }
            }
            @keyframes slotFullGlow {
                from { box-shadow: 0 0 0 rgba(220, 38, 38, 0); }
                to { box-shadow: 0 0 22px rgba(220, 38, 38, .55); }
            }
            @media (max-width: 576px) {
                .parking-full-banner { padding: 14px; gap: 10px; border-radius: 15px; }
                .parking-full-icon { width: 42px; height: 42px; min-width: 42px; font-size: 20px; }
                .parking-full-title { font-size: 15px; }
                .parking-full-subtitle { font-size: 12px; }
            }
        `;
        document.head.appendChild(style);
    }

    function ensureBanner() {
        injectParkingFullStyle();

        let banner = document.getElementById('parking-full-banner');
        const slotArea = document.getElementById('slot-area-container');

        if (!banner && slotArea && slotArea.parentNode) {
            banner = document.createElement('div');
            banner.id = 'parking-full-banner';
            banner.className = 'parking-full-banner d-none';
            banner.innerHTML = `
                <div class="parking-full-icon"><i class="fas fa-triangle-exclamation"></i></div>
                <div class="parking-full-text">
                    <div class="parking-full-title">SLOT PARKIR PENUH</div>
                    <div class="parking-full-subtitle">Semua slot sedang terisi atau sudah direservasi. QR masuk akan ditolak dan palang tetap tertutup.</div>
                </div>
            `;
            slotArea.parentNode.insertBefore(banner, slotArea);
        }

        return banner;
    }

    function isFreeSlot(slot) {
        const state = String(slot && slot.state ? slot.state : '').toLowerCase().trim();
        return state === 'kosong' || state === 'tersedia' || state === 'free' || state === 'slot-free';
    }

    function update(isFull, used, total, mode) {
        const banner = ensureBanner();
        const slotArea = document.getElementById('slot-area-container');
        const body = document.body;

        if (!banner) return;

        if (isFull) {
            banner.classList.remove('d-none');
            banner.classList.add('parking-full-show');
            if (slotArea) slotArea.classList.add('parking-full-area');
            if (body) body.classList.add('parking-full-active');

            const subtitle = banner.querySelector('.parking-full-subtitle');
            if (subtitle) {
                const info = total ? `Kapasitas penuh (${used}/${total}).` : 'Kapasitas penuh.';
                subtitle.textContent = `${info} QR masuk akan ditolak dan palang tetap tertutup.`;
            }
        } else {
            banner.classList.add('d-none');
            banner.classList.remove('parking-full-show');
            if (slotArea) slotArea.classList.remove('parking-full-area');
            if (body) body.classList.remove('parking-full-active');
        }
    }

    function updateFromSlots(slots, mode) {
        if (!Array.isArray(slots) || slots.length === 0) {
            update(false, 0, 0, mode);
            return;
        }

        const total = slots.length;
        const used = slots.filter(slot => !isFreeSlot(slot)).length;
        update(total > 0 && used >= total, used, total, mode);
    }

    window.ParkingFullNotice = {
        update,
        updateFromSlots,
        isFreeSlot
    };
})();

let liveSlotInterval = null;
// userLiveInterval dihapus agar tidak bentrok dengan dashboard.php yang punya variable inline sendiri.
let timerInterval = null;
let liveTimeLeft = 0;
let liveSlotLoading = false;
let userLiveLoading = false;

// ===============================
// SLOT REALTIME
// ===============================
async function fetchLiveSlots() {
    if (liveSlotLoading) return;
    liveSlotLoading = true;
    try {
        const uid = typeof USER_ID !== 'undefined' ? USER_ID : '';

        const response = await fetch(`api.php?action=get_slots&uid=${uid}&_=${Date.now()}`);
        const slots = await response.json();

        slots.forEach(slot => {
            const slotElement = document.getElementById(`slot-box-${slot.slot_nomor}`);
            const btnArea = document.getElementById(`slot-btn-${slot.slot_nomor}`);

            if (!slotElement || !btnArea) return;

            slotElement.classList.remove(
                'slot-free',
                'slot-reserved',
                'slot-reserved-me',
                'slot-occupied'
            );

            if (slot.state === 'kosong') {
                slotElement.classList.add('slot-free');
                btnArea.innerHTML = `
                    <button onclick="bookingSlot('${slot.slot_nomor}')"
                        class="btn btn-success btn-sm w-100 rounded-pill mt-2 fw-bold shadow-sm">
                        RESERVASI
                    </button>
                `;
            } else if (slot.state === 'reserved_me') {
                slotElement.classList.add('slot-reserved-me');
                btnArea.innerHTML = `
                    <div class="badge bg-warning text-dark w-100 py-2 mt-2 rounded-pill border border-dark shadow-sm">
                        RESERVED (ANDA)
                    </div>
                `;
            } else if (slot.state === 'reserved_other') {
                slotElement.classList.add('slot-reserved');
                btnArea.innerHTML = `
                    <div class="badge bg-secondary w-100 py-2 mt-2 rounded-pill shadow-sm">
                        RESERVED
                    </div>
                `;
            } else {
                slotElement.classList.add('slot-occupied');
                btnArea.innerHTML = `
                    <div class="badge bg-danger w-100 py-2 mt-2 rounded-pill shadow-sm">
                        TERISI
                    </div>
                `;
            }
        });

        if (window.ParkingFullNotice && typeof window.ParkingFullNotice.updateFromSlots === 'function') {
            window.ParkingFullNotice.updateFromSlots(slots, 'user');
        }

    } catch (e) {
        // Gagal ambil slot diabaikan agar UI tidak berat
    } finally {
        liveSlotLoading = false;
    }
}

// ===============================
// TIMER RESERVASI USER
// ===============================
async function fetchUserLiveData() {
    if (userLiveLoading) return;
    userLiveLoading = true;
    try {
        const uid = typeof USER_ID !== 'undefined' ? USER_ID : '';

        const response = await fetch(`api.php?action=get_user_live_data&uid=${uid}&_=${Date.now()}`);
        const data = await response.json();

        liveTimeLeft = parseInt(data.time_left || 0);

        const saldoEl = document.getElementById('teks-saldo');
        if (saldoEl && typeof data.saldo !== 'undefined') {
            saldoEl.innerText = 'Rp ' + parseInt(data.saldo).toLocaleString('id-ID');
        }

        updateTimerDisplay();

    } catch (e) {
        // Gagal ambil live data diabaikan agar UI tidak berat
    } finally {
        userLiveLoading = false;
    }
}

function updateTimerDisplay() {
    const timerText = document.getElementById("timer-text");
    const badge = document.getElementById("countdown-badge");

    if (!timerText || !badge) return;

    if (liveTimeLeft > 0) {
        badge.style.display = "inline-block";

        const menit = Math.floor(liveTimeLeft / 60);
        const detik = liveTimeLeft % 60;

        timerText.innerText =
            `${String(menit).padStart(2, '0')}:${String(detik).padStart(2, '0')}`;
    } else {
        badge.style.display = "none";
    }
}

function startLocalTimer() {
    if (timerInterval) return;

    timerInterval = setInterval(() => {
        if (liveTimeLeft > 0) {
            liveTimeLeft--;
        }

        updateTimerDisplay();
    }, 1000);
}

// ===============================
// REALTIME USER DASHBOARD VIA MQTT EVENT
// ===============================
function refreshUserRealtime(info = {}) {
    const eventName = (info && info.event) ? info.event : '';

    // Perubahan sensor slot cukup refresh slot.
    fetchLiveSlots();

    // Data user/saldo/tiket hanya perlu refresh untuk event yang berkaitan dengan user/transaksi.
    if (eventName !== 'slot_state' && eventName !== 'slot_hardware_updated') {
        fetchUserLiveData();
    }
}

function startUserPolling() {
    fetchLiveSlots();
    fetchUserLiveData();
    startLocalTimer();

    window.smartParkingRealtimeRefresh = refreshUserRealtime;
    if (typeof window.smartParkingStartMqttRealtime === 'function') {
        window.smartParkingStartMqttRealtime();
    }
}

function stopUserPolling() {
    if (typeof window.smartParkingStopMqttRealtime === 'function') {
        window.smartParkingStopMqttRealtime();
    }
}

if (!window.SMARTPARKING_NOTICE_ONLY && document.getElementById('slot-area-container')) {
    startUserPolling();

    document.addEventListener("visibilitychange", () => {
        if (document.hidden) {
            stopUserPolling();
        } else {
            startUserPolling();
        }
    });
}

// ===============================
// BOOKING SLOT
// ===============================
async function bookingSlot(nomor) {
    const result = await Swal.fire({
        title: `Reservasi Slot ${nomor}?`,
        html: `Biaya reservasi <b>Rp 5.000</b> akan dipotong.<br>
        <small class="text-danger fw-bold mt-2 d-block">
            <i class="fas fa-exclamation-circle me-1"></i>
            Reservasi hangus otomatis jika tidak check-in dalam 5 menit.
        </small>`,
        icon: 'question',
        showCancelButton: true,
        confirmButtonColor: '#1a365d',
        cancelButtonColor: '#e74c3c',
        confirmButtonText: 'Ya, Reservasi!',
        cancelButtonText: 'Batal',
        reverseButtons: true
    });

    if (!result.isConfirmed) return;

    Swal.fire({
        title: 'Memproses...',
        allowOutsideClick: false,
        didOpen: () => Swal.showLoading()
    });

    let fd = new FormData();
    fd.append('user_id', USER_ID);
    fd.append('slot_nomor', nomor);

    try {
        let res = await fetch('api.php?action=book_slot', {
            method: 'POST',
            body: fd
        });

        let data = await res.json();

        if (data.status === 'success') {
            await Swal.fire({
                title: 'Berhasil!',
                text: `Reservasi berhasil diamankan.`,
                icon: 'success',
                confirmButtonColor: '#559da0'
            });

            fetchUserLiveData();
            fetchLiveSlots();
        } else {
            Swal.fire('Gagal!', data.message, 'error');
        }

    } catch (e) {
        Swal.fire('Error!', 'Koneksi bermasalah.', 'error');
    }
}

// ===============================
// BATAL RESERVASI
// ===============================
async function cancelBooking(kode) {
    const result = await Swal.fire({
        title: 'Batalkan Reservasi?',
        html: `Menghapus reservasi <b>${kode}</b>.<br>
        <small class="text-danger">Saldo Rp 5.000 tidak akan dikembalikan.</small>`,
        icon: 'warning',
        showCancelButton: true,
        confirmButtonColor: '#e74c3c',
        cancelButtonColor: '#95a5a6',
        confirmButtonText: 'Ya, Batalkan',
        cancelButtonText: 'Kembali',
        reverseButtons: true
    });

    if (!result.isConfirmed) return;

    Swal.fire({
        title: 'Menghapus...',
        allowOutsideClick: false,
        didOpen: () => Swal.showLoading()
    });

    let fd = new FormData();
    fd.append('kode_booking', kode);
    fd.append('user_id', USER_ID);

    try {
        let res = await fetch('api.php?action=cancel_booking', {
            method: 'POST',
            body: fd
        });

        let data = await res.json();

        if (data.status === 'success') {
            await Swal.fire({
                icon: 'success',
                title: 'Berhasil!',
                text: data.message,
                timer: 2000,
                showConfirmButton: false
            });

            fetchUserLiveData();
            fetchLiveSlots();
        } else {
            Swal.fire('Gagal!', data.message, 'error');
        }

    } catch (e) {
        Swal.fire('Error!', 'Gagal menghubungi server.', 'error');
    }
}

// ===============================
// QR
// ===============================
function lihatQR(kode) {
    const url = `https://api.qrserver.com/v1/create-qr-code/?size=300x300&data=${kode}`;

    Swal.fire({
        title: 'QR Reservasi',
        text: `Kode: ${kode}`,
        imageUrl: url,
        imageWidth: 220,
        imageHeight: 220,
        showCancelButton: true,
        confirmButtonColor: '#559da0',
        cancelButtonColor: '#1a365d',
        confirmButtonText: '<i class="fas fa-download"></i> Unduh QR',
        cancelButtonText: 'Tutup'
    }).then((result) => {
        if (result.isConfirmed) {
            downloadQR(kode, 'Reservasi');
        }
    });
}

function showQRPermanen(token) {
    const url = `https://api.qrserver.com/v1/create-qr-code/?size=300x300&data=${token}`;

    Swal.fire({
        title: 'QR Stiker Kendaraan',
        html: `
            <div class="mb-3 text-center">
                <img src="${url}" width="200" height="200" class="rounded shadow-sm border p-2">
            </div>
            <p class="text-muted small mb-1">Kode Verifikasi:</p>
            <div class="input-group justify-content-center px-4">
                <input type="text" id="qr-copy-text" class="form-control text-center fw-bold bg-light" value="${token}" readonly>
                <button class="btn btn-outline-secondary" onclick="copyToClipboard('qr-copy-text')" type="button">
                    <i class="fas fa-copy"></i>
                </button>
            </div>
        `,
        showCancelButton: true,
        confirmButtonColor: '#559da0',
        cancelButtonColor: '#1a365d',
        confirmButtonText: '<i class="fas fa-download"></i> Unduh',
        cancelButtonText: 'Tutup'
    }).then((result) => {
        if (result.isConfirmed) {
            downloadQR(token, 'Permanen');
        }
    });
}

function copyToClipboard(elementId) {
    const copyText = document.getElementById(elementId);
    if (!copyText) return;

    copyText.select();
    copyText.setSelectionRange(0, 99999);

    navigator.clipboard.writeText(copyText.value).then(() => {
        Swal.fire({
            title: 'Tersalin!',
            text: copyText.value,
            icon: 'success',
            confirmButtonColor: '#559da0'
        });
    });
}

async function downloadQR(kode, tipe) {
    const url = `https://api.qrserver.com/v1/create-qr-code/?size=300x300&data=${kode}`;

    try {
        const resp = await fetch(url);
        const blob = await resp.blob();

        const a = document.createElement('a');
        a.href = URL.createObjectURL(blob);
        a.download = `QR_${tipe}_${kode}.png`;
        a.click();

    } catch (e) {
        Swal.fire('Gagal!', 'Terjadi masalah saat mengunduh QR.', 'error');
    }
}

// ===============================
// FORMAT RUPIAH TOP UP
// ===============================
function formatRupiahManual(inputElement) {
    let angka_asli = inputElement.value.replace(/[^0-9]/g, '');

    let hiddenInput = document.getElementById('nominal_asli');
    if (hiddenInput) {
        hiddenInput.value = angka_asli;
    }

    let sisa = angka_asli.length % 3;
    let rupiah = angka_asli.substr(0, sisa);
    let ribuan = angka_asli.substr(sisa).match(/\d{3}/g);

    if (ribuan) {
        let separator = sisa ? '.' : '';
        rupiah += separator + ribuan.join('.');
    }

    inputElement.value = rupiah;
}

function setNominal(angka) {
    let inputTampil = document.getElementById('inputNominal');
    let inputAsli = document.getElementById('nominal_asli');

    if (inputTampil && inputAsli) {
        inputAsli.value = angka;

        let angkaStr = angka.toString();
        let sisa = angkaStr.length % 3;
        let rupiah = angkaStr.substr(0, sisa);
        let ribuan = angkaStr.substr(sisa).match(/\d{3}/g);

        if (ribuan) {
            rupiah += (sisa ? '.' : '') + ribuan.join('.');
        }

        inputTampil.value = rupiah;
    }
}
