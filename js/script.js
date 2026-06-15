// API endpoint. Jika api.php berada satu folder, biarkan 'api.php'.
const API_URL = 'api.php';

// FIX: cegah fetch menumpuk.
const __activeRequests = {};
async function guardedFetch(key, url, options = {}) {
    if (__activeRequests[key]) return null;
    __activeRequests[key] = true;
    const controller = new AbortController();
    const timeoutId = setTimeout(() => controller.abort(), 8000);
    try {
        return await fetch(url, { ...options, signal: controller.signal });
    } finally {
        clearTimeout(timeoutId);
        __activeRequests[key] = false;
    }
}

// Realtime web via MQTT WebSocket. Pastikan halaman memuat mqtt.min.js jika memakai script.js ini.
const MQTT_WEB_URL = "wss://07ea93ea62a6450eb50b1cb6e520eae3.s1.eu.hivemq.cloud:8883/mqtt";
const MQTT_WEB_USER = "Rifki";
const MQTT_WEB_PASS = "Kitaaja123";


let liveSlotInterval = null;
let userLiveInterval = null;
let timerInterval = null;
let liveTimeLeft = 0;

// ===============================
// SLOT REALTIME
// ===============================
async function fetchLiveSlots() {
    try {
        const uid = typeof USER_ID !== 'undefined' ? USER_ID : '';

        const response = await guardedFetch("slots", `${API_URL}?action=get_slots&uid=${uid}&_=${Date.now()}`); if (!response) return;
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

    } catch (e) {
        console.log("Gagal ambil slot:", e);
    }
}

// ===============================
// TIMER RESERVASI USER
// ===============================
async function fetchUserLiveData() {
    try {
        const uid = typeof USER_ID !== 'undefined' ? USER_ID : '';

        const response = await guardedFetch("user_live", `${API_URL}?action=get_user_live_data&uid=${uid}&_=${Date.now()}`); if (!response) return;
        const data = await response.json();

        liveTimeLeft = parseInt(data.time_left || 0);

        const saldoEl = document.getElementById('teks-saldo');
        if (saldoEl && typeof data.saldo !== 'undefined') {
            saldoEl.innerText = 'Rp ' + parseInt(data.saldo).toLocaleString('id-ID');
        }

        updateTimerDisplay();

    } catch (e) {
        console.log("Gagal ambil live data:", e);
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
// POLLING USER DASHBOARD
// ===============================
function startUserPolling() {
    stopUserPolling();

    fetchLiveSlots();
    fetchUserLiveData();

    startLocalTimer();

    liveSlotInterval = setInterval(fetchLiveSlots, 30000);
    userLiveInterval = setInterval(fetchUserLiveData, 30000);
}

function stopUserPolling() {
    if (liveSlotInterval) {
        clearInterval(liveSlotInterval);
        liveSlotInterval = null;
    }

    if (userLiveInterval) {
        clearInterval(userLiveInterval);
        userLiveInterval = null;
    }
}

if (document.getElementById('slot-area-container')) {
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
            Reservasi hangus otomatis jika tidak check-in dalam 1 menit.
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
        let res = await guardedFetch('book_slot', `${API_URL}?action=book_slot`, {
            method: 'POST',
            body: fd
        }); if (!res) return;

        let data = await res.json();

        if (data.status === 'success' || data.status === 'accepted') {
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
        let res = await guardedFetch('cancel_booking', `${API_URL}?action=cancel_booking`, {
            method: 'POST',
            body: fd
        }); if (!res) return;

        let data = await res.json();

        if (data.status === 'success' || data.status === 'accepted') {
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
