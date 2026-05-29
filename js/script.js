// --- Polling Realtime & Auto Refresh ---
let liveSlotInterval = null;
let userLiveInterval = null;
let timerInterval = null;
let liveTimeLeft = 0;

async function fetchLiveSlots() {
    try {
        const uid = typeof USER_ID !== 'undefined' ? USER_ID : '';
        const response = await fetch(`api.php?action=get_slots&uid=${uid}`);
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
                    </button>`;
            } 
            else if (slot.state === 'reserved_me') {
                slotElement.classList.add('slot-reserved-me');
                btnArea.innerHTML = `
                    <div class="badge bg-warning text-dark w-100 py-2 mt-2 rounded-pill border border-dark shadow-sm">
                    RESERVED (ANDA)
                    </div>`;
            } 
            else if (slot.state === 'reserved_other') {
                slotElement.classList.add('slot-reserved');
                btnArea.innerHTML = `
                    <div class="badge bg-secondary w-100 py-2 mt-2 rounded-pill shadow-sm">
                    RESERVED
                    </div>`;
            } 
            else if (slot.state === 'terisi' || slot.state === 'terisi_me') {
                slotElement.classList.add('slot-occupied');
                btnArea.innerHTML = `
                    <div class="badge bg-danger w-100 py-2 mt-2 rounded-pill shadow-sm">
                    TERISI
                    </div>`;
            }
        });

    } catch (e) {
        console.log("Menunggu koneksi slot...");
    }
}

// --- Timer reservasi lokal 1 detik ---
async function fetchUserLiveData() {
    try {
        const uid = typeof USER_ID !== 'undefined' ? USER_ID : '';
        if (!uid) return;

        const response = await fetch(`api.php?action=get_user_live_data&uid=${uid}`);
        const data = await response.json();

        liveTimeLeft = parseInt(data.time_left || 0);

        updateTimerDisplay();

    } catch (e) {
        console.log("Menunggu koneksi live data...");
    }
}

function updateTimerDisplay() {
    const timerEls = document.querySelectorAll(
        '#timer-reservasi, .timer-reservasi, [data-timer-reservasi]'
    );

    timerEls.forEach(timerEl => {
        if (liveTimeLeft > 0) {
            timerEl.innerText = liveTimeLeft + " detik";
        } else {
            timerEl.innerText = "Waktu habis";
        }
    });
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

function startUserPolling() {
    stopUserPolling();

    fetchLiveSlots();
    fetchUserLiveData();
    startLocalTimer();

    liveSlotInterval = setInterval(fetchLiveSlots, 10000);
    userLiveInterval = setInterval(fetchUserLiveData, 10000);
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

// --- Booking Slot ---
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
        let res = await fetch('api.php?action=book_slot', {
            method: 'POST',
            body: fd
        });

        let data = await res.json();
        
        if (data.status === 'success') {
            Swal.close();

            await Swal.fire({
                title: 'Berhasil!',
                text: `QR Reservasi: ${data.kode_booking}`,
                imageUrl: data.qr_url,
                imageWidth: 200,
                imageHeight: 200,
                confirmButtonColor: '#559da0',
                confirmButtonText: 'Lihat Reservasi'
            });

            window.location.href = "reservasi_saya.php";
        } else { 
            Swal.fire('Gagal!', data.message, 'error');
        }

        fetchLiveSlots();
        fetchUserLiveData();

    } catch (e) {
        Swal.fire('Error!', 'Koneksi ke server bermasalah.', 'error');
    }
}

// --- Batal Reservasi ---
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

            location.reload(); 
        } else {
            Swal.fire('Gagal!', data.message, 'error');
        }

    } catch (e) {
        Swal.fire('Error!', 'Gagal menghubungi server.', 'error');
    }
}

// --- Lihat QR Tiket ---
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

// --- QR Permanen ---
function showQRPermanen(token) {
    const url = `https://api.qrserver.com/v1/create-qr-code/?size=300x300&data=${token}`;

    Swal.fire({
        title: 'QR Stiker Kendaraan',
        text: 'Akses Permanen Anda',
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
            downloadQR(token, 'Permanen');
        }
    });
}

// --- Download QR ---
async function downloadQR(kode_atau_token, tipe) {
    const url = `https://api.qrserver.com/v1/create-qr-code/?size=300x300&data=${kode_atau_token}`;

    try {
        const resp = await fetch(url);
        const blob = await resp.blob();

        const a = document.createElement('a');
        a.href = URL.createObjectURL(blob);
        a.download = `QR_${tipe}_${kode_atau_token}.png`;
        a.click();
        
        Swal.fire({
            title: 'Tersimpan!',
            text: 'QR Code diunduh ke perangkat Anda.',
            icon: 'success',
            timer: 2000,
            showConfirmButton: false
        });

    } catch (e) { 
        Swal.fire('Gagal!', 'Terjadi masalah saat mengunduh gambar.', 'error'); 
    }
}

// --- Format Rupiah Top Up ---
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
