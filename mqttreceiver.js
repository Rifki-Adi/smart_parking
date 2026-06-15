require('dotenv').config();
const mqtt = require('mqtt');
const { Pool } = require('pg');

// =====================================================
// ENV CONFIG
// =====================================================
const MQTT_HOST = process.env.MQTT_HOST || '07ea93ea62a6450eb50b1cb6e520eae3.s1.eu.hivemq.cloud';
const MQTT_PORT = Number(process.env.MQTT_PORT || 8883);
const MQTT_USER = process.env.MQTT_USER || 'Rifki';
const MQTT_PASS = process.env.MQTT_PASS || 'Kitaaja123';

// Format recommended dari Supabase:
// postgresql://postgres.xxxxx:PASSWORD@aws-0-xxxx.pooler.supabase.com:6543/postgres
const DATABASE_URL =
  process.env.DATABASE_URL ||
  process.env.SUPABASE_DB_URL ||
  'postgresql://postgres.mldjvttzxjrmgwbigaow:SkripsiCSN2022@aws-1-ap-southeast-1.pooler.supabase.com:6543/postgres';

const AUTO_RELEASE_SECONDS = Number(process.env.AUTO_RELEASE_SECONDS || 60);
const PARKING_FEE = Number(process.env.PARKING_FEE || 3000);
const BOOKING_FEE = Number(process.env.BOOKING_FEE || 5000);
const MIN_BOOKING_SALDO = Number(process.env.MIN_BOOKING_SALDO || 8000);
const TOTAL_SLOT = Number(process.env.TOTAL_SLOT || 4);

if (!DATABASE_URL) {
  console.error('[DB] DATABASE_URL / SUPABASE_DB_URL belum diisi.');
  process.exit(1);
}

const pool = new Pool({
  connectionString: DATABASE_URL,
  ssl: process.env.DB_SSL === 'false' ? false : { rejectUnauthorized: false },
});

const mqttUrl = `mqtts://${MQTT_HOST}:${MQTT_PORT}`;
const client = mqtt.connect(mqttUrl, {
  username: MQTT_USER,
  password: MQTT_PASS,
  clean: true,
  reconnectPeriod: 3000,
  connectTimeout: 10000,
});

// =====================================================
// TOPIC
// =====================================================
const TOPICS = {
  ESP32_SLOT_UPDATE: 'smartparking/esp32/slot/update',
  ESP32_GATE_IN_SCAN: 'smartparking/esp32/gate/in/scan',
  ESP32_GATE_OUT_SCAN: 'smartparking/esp32/gate/out/scan',
  ESP32_DEVICE_STATUS: 'smartparking/esp32/device/status',

  WEB_RESERVATION_CREATE: 'smartparking/web/reservation/create',
  WEB_RESERVATION_CANCEL: 'smartparking/web/reservation/cancel',
  WEB_TOPUP_CREATE: 'smartparking/web/topup/create',

  SERVER_SLOT_STATE: 'smartparking/server/slot/state',
  SERVER_GATE_IN_RESPONSE: 'smartparking/server/gate/in/response',
  SERVER_GATE_OUT_RESPONSE: 'smartparking/server/gate/out/response',
  SERVER_RESERVATION_RESPONSE: 'smartparking/server/reservation/response',
  SERVER_RESERVATION_CANCELLED: 'smartparking/server/reservation/cancelled',
  SERVER_RESERVATION_EXPIRED: 'smartparking/server/reservation/expired',
  SERVER_TRANSACTION_CREATED: 'smartparking/server/transaction/created',
  SERVER_DEVICE_STATUS: 'smartparking/server/device/status',
  SERVER_ERROR: 'smartparking/server/error',
};

// =====================================================
// HELPER
// =====================================================
function nowWibSql() {
  return "now() at time zone 'Asia/Jakarta'";
}

function safeJsonParse(buffer) {
  try {
    return JSON.parse(buffer.toString());
  } catch (err) {
    return null;
  }
}

function publish(topic, data, retain = false) {
  const payload = JSON.stringify({
    ...data,
    source: data.source || 'mqttreceiver.js',
    server_time: new Date().toISOString(),
  });

  client.publish(topic, payload, { qos: 0, retain }, (err) => {
    if (err) {
      console.error('[MQTT PUBLISH ERROR]', topic, err.message);
    } else {
      console.log('[MQTT PUBLISH]', topic, payload);
    }
  });
}

function publishError(message, extra = {}) {
  publish(TOPICS.SERVER_ERROR, {
    event: 'error',
    status: 'error',
    message,
    ...extra,
  });
}

async function logMqttMessage({ messageId, requestId, topic, payload, status = 'received', sentAt = null, processedAt = false }) {
  const id = messageId || requestId || `msg-${Date.now()}-${Math.random().toString(16).slice(2)}`;

  try {
    let latencyMs = null;
    if (sentAt && Number(sentAt) > 1700000000000) {
      latencyMs = Date.now() - Number(sentAt);
    }

    if (processedAt) {
      await pool.query(
        `insert into mqtt_message_log (message_id, topic, payload, status, sent_at, processed_at, latency_ms)
         values ($1, $2, $3::jsonb, $4, $5, ${nowWibSql()}, $6)`,
        [id, topic, JSON.stringify(payload || {}), status, sentAt, latencyMs]
      );
    } else {
      await pool.query(
        `insert into mqtt_message_log (message_id, topic, payload, status, sent_at, latency_ms)
         values ($1, $2, $3::jsonb, $4, $5, $6)`,
        [id, topic, JSON.stringify(payload || {}), status, sentAt, latencyMs]
      );
    }
  } catch (err) {
    // Tidak wajib. Kalau tabel mqtt_message_log belum dibuat, worker tetap jalan.
  }
}

async function getSlotByNomor(slotNomor) {
  const result = await pool.query(
    `select id, slot_nomor, terisi from slot where slot_nomor::int = $1 limit 1`,
    [Number(slotNomor)]
  );
  return result.rows[0] || null;
}

async function publishSlotState(source = 'mqttreceiver.js', retain = true) {
  const slotsResult = await pool.query(
    `select id, slot_nomor, terisi from slot order by slot_nomor::int asc limit $1`,
    [TOTAL_SLOT]
  );

  const reservasiResult = await pool.query(
    `select slot_id, user_id, status, kode_booking
     from reservasi
     where status = 'check-in'
        or (status = 'pending' and created_at >= (${nowWibSql()} - ($1 || ' seconds')::interval))`,
    [AUTO_RELEASE_SECONDS]
  );

  const reservasiMap = new Map();
  for (const r of reservasiResult.rows) {
    reservasiMap.set(Number(r.slot_id), r);
  }

  const slots = slotsResult.rows.map((s) => {
    const terisi = s.terisi === true || s.terisi === 't' || s.terisi === 1 || s.terisi === '1';
    const res = reservasiMap.get(Number(s.id));

    let status = 'kosong';
    if (terisi) status = 'terisi';
    else if (res && res.status === 'pending') status = 'reserved';
    else if (res && res.status === 'check-in') status = 'check-in';

    return {
      slot_id: Number(s.id),
      slot_nomor: Number(s.slot_nomor),
      terisi,
      status,
      reservasi_status: res ? res.status : null,
      kode_booking: res ? res.kode_booking : null,
    };
  });

  publish(TOPICS.SERVER_SLOT_STATE, {
    event: 'slot_state',
    source,
    slots,
  }, retain);
}

async function recordRiwayatSlot({ slotId, terisi }) {
  try {
    if (terisi) {
      const active = await pool.query(
        `select id from riwayat_slot where slot_id = $1 and status = 'aktif' limit 1`,
        [slotId]
      );

      if (active.rows.length > 0) return;

      const reservasi = await pool.query(
        `select id, user_id
         from reservasi
         where slot_id = $1 and status = 'check-in'
         order by created_at desc
         limit 1`,
        [slotId]
      );

      const reservasiId = reservasi.rows[0] ? reservasi.rows[0].id : null;
      const userId = reservasi.rows[0] ? reservasi.rows[0].user_id : null;

      await pool.query(
        `insert into riwayat_slot (slot_id, reservasi_id, user_id, waktu_mulai, status)
         values ($1, $2, $3, ${nowWibSql()}, 'aktif')`,
        [slotId, reservasiId, userId]
      );
    } else {
      await pool.query(
        `update riwayat_slot
         set waktu_selesai = ${nowWibSql()},
             durasi_menit = greatest(1, floor(extract(epoch from ((${nowWibSql()}) - waktu_mulai)) / 60)::int),
             status = 'selesai'
         where slot_id = $1 and status = 'aktif'`,
        [slotId]
      );
    }
  } catch (err) {
    // Kalau tabel riwayat_slot belum ada, worker tetap jalan.
    if (err.code !== '42P01') {
      console.error('[RIWAYAT SLOT ERROR]', err.message);
    }
  }
}

async function cleanupExpiredReservations() {
  const db = await pool.connect();
  try {
    await db.query('begin');
    const expired = await db.query(
      `delete from reservasi
       where status = 'pending'
         and created_at < (${nowWibSql()} - ($1 || ' seconds')::interval)
       returning id, user_id, slot_id, kode_booking`,
      [AUTO_RELEASE_SECONDS]
    );

    for (const row of expired.rows) {
      await db.query(
        `insert into transaksi (user_id, tipe, jumlah, keterangan)
         values ($1, 'hangus', 0, $2)`,
        [row.user_id, `Waktu Habis Tiket ${row.kode_booking}`]
      );
    }

    await db.query('commit');

    if (expired.rows.length > 0) {
      publish(TOPICS.SERVER_RESERVATION_EXPIRED, {
        event: 'reservation_expired',
        status: 'expired',
        count: expired.rows.length,
        data: expired.rows,
      });

      await publishSlotState('auto_release');
    }
  } catch (err) {
    await db.query('rollback');
    console.error('[CLEANUP ERROR]', err.message);
  } finally {
    db.release();
  }
}

// =====================================================
// PROCESS: SLOT UPDATE DARI ESP32
// =====================================================
async function processSlotUpdate(payload) {
  const slots = Array.isArray(payload.slots) ? payload.slots : [];
  if (slots.length === 0) return;

  for (const item of slots) {
    const slotNomor = Number(item.slot_nomor);
    const terisi = item.terisi === true || item.status === 'terisi' || item.terisi === 1 || item.terisi === '1';

    const slot = await getSlotByNomor(slotNomor);
    if (!slot) continue;

    await pool.query(
      `update slot
       set terisi = $1
       where id = $2 and terisi is distinct from $1`,
      [terisi, slot.id]
    );

    await recordRiwayatSlot({ slotId: slot.id, terisi });
  }

  await publishSlotState('esp32_slot_update');
}

// =====================================================
// PROCESS: SCAN QR MASUK/KELUAR
// =====================================================
function gateResponseTopic(gate) {
  return gate === 'out' ? TOPICS.SERVER_GATE_OUT_RESPONSE : TOPICS.SERVER_GATE_IN_RESPONSE;
}

function sendGateResponse({ gate, requestId, status, message, openGate = false, extra = {} }) {
  publish(gateResponseTopic(gate), {
    event: 'gate_response',
    gate,
    request_id: requestId || null,
    status,
    message,
    open_gate: openGate,
    ...extra,
  });
}

async function processGateScan(payload, gate) {
  const qr = String(payload.qr_code || '').trim();
  const requestId = payload.request_id || null;

  if (!qr) {
    sendGateResponse({ gate, requestId, status: 'error', message: 'QR kosong', openGate: false });
    return;
  }

  const db = await pool.connect();
  try {
    await db.query('begin');

    if (qr.startsWith('PK-')) {
      const bookingResult = await db.query(
        `select * from reservasi
         where kode_booking = $1
           and (status = 'check-in'
                or (status = 'pending' and created_at >= (${nowWibSql()} - ($2 || ' seconds')::interval)))
         limit 1`,
        [qr, AUTO_RELEASE_SECONDS]
      );

      const booking = bookingResult.rows[0];
      if (!booking) {
        await db.query('commit');
        sendGateResponse({ gate, requestId, status: 'error', message: 'QR Tdk Dikenali / Tiket Hangus', openGate: false });
        return;
      }

      const uid = booking.user_id;

      if (booking.status === 'pending') {
        if (gate === 'out') {
          await db.query('commit');
          sendGateResponse({ gate, requestId, status: 'error', message: 'Belum Scan Masuk', openGate: false });
          return;
        }

        const saldoResult = await db.query(`select saldo from profiles where id = $1 limit 1`, [uid]);
        const saldo = Number(saldoResult.rows[0]?.saldo || 0);

        if (saldo < PARKING_FEE) {
          await db.query('commit');
          sendGateResponse({ gate, requestId, status: 'error', message: 'Saldo Tdk Cukup', openGate: false });
          return;
        }

        await db.query(
          `insert into transaksi (user_id, tipe, jumlah, keterangan)
           values ($1, 'parkir', 0, 'Masuk / Check-In (Biaya dipotong saat keluar)')`,
          [uid]
        );
        await db.query(`update reservasi set status = 'check-in' where id = $1`, [booking.id]);

        await db.query('commit');
        sendGateResponse({ gate, requestId, status: 'success', message: 'Reservasi Valid - Silakan Masuk', openGate: true, extra: { reservasi_id: booking.id, slot_id: booking.slot_id } });
        await publishSlotState('gate_checkin');
        return;
      }

      if (booking.status === 'check-in') {
        if (gate === 'in') {
          await db.query('commit');
          sendGateResponse({ gate, requestId, status: 'error', message: 'Mobil Sudah di Dalam', openGate: false });
          return;
        }

        await db.query(`update profiles set saldo = saldo - $1 where id = $2`, [PARKING_FEE, uid]);
        await db.query(
          `insert into transaksi (user_id, tipe, jumlah, keterangan)
           values ($1, 'checkout', $2, 'Keluar / Check-Out (Pembayaran Biaya Parkir)')`,
          [uid, PARKING_FEE]
        );
        await db.query(`update reservasi set status = 'selesai' where id = $1`, [booking.id]);
        await db.query(`update slot set terisi = false where id = $1`, [booking.slot_id]);
        await recordRiwayatSlot({ slotId: booking.slot_id, terisi: false });

        await db.query('commit');
        sendGateResponse({ gate, requestId, status: 'success', message: `Check-out Berhasil - Saldo -Rp${PARKING_FEE}`, openGate: true, extra: { reservasi_id: booking.id, slot_id: booking.slot_id } });
        await publishSlotState('gate_checkout');
        return;
      }
    }

    // QR stiker / permanen
    const userResult = await db.query(
      `select id, saldo, plat_nomor
       from profiles
       where qr_token_permanen = $1 or concat('STIKER-', plat_nomor) = $1
       limit 1`,
      [qr]
    );

    const user = userResult.rows[0];
    if (!user) {
      await db.query('commit');
      sendGateResponse({ gate, requestId, status: 'error', message: 'Stiker Tidak Valid', openGate: false });
      return;
    }

    const uid = user.id;
    const cekInResult = await db.query(
      `select * from reservasi where user_id = $1 and status = 'check-in' order by created_at desc limit 1`,
      [uid]
    );
    const cekIn = cekInResult.rows[0];

    if (cekIn) {
      if (gate === 'in') {
        await db.query('commit');
        sendGateResponse({ gate, requestId, status: 'error', message: 'Mobil Sudah di Dalam', openGate: false });
        return;
      }

      await db.query(`update profiles set saldo = saldo - $1 where id = $2`, [PARKING_FEE, uid]);
      await db.query(
        `insert into transaksi (user_id, tipe, jumlah, keterangan)
         values ($1, 'checkout', $2, 'Keluar / Check-Out (Pembayaran Biaya Parkir)')`,
        [uid, PARKING_FEE]
      );
      await db.query(`update reservasi set status = 'selesai' where id = $1`, [cekIn.id]);
      await db.query(`update slot set terisi = false where id = $1`, [cekIn.slot_id]);
      await recordRiwayatSlot({ slotId: cekIn.slot_id, terisi: false });

      await db.query('commit');
      sendGateResponse({ gate, requestId, status: 'success', message: `Check-out Berhasil - Saldo -Rp${PARKING_FEE}`, openGate: true, extra: { reservasi_id: cekIn.id, slot_id: cekIn.slot_id } });
      await publishSlotState('gate_checkout_stiker');
      return;
    }

    if (gate === 'out') {
      await db.query('commit');
      sendGateResponse({ gate, requestId, status: 'error', message: 'Belum Scan Masuk', openGate: false });
      return;
    }

    const pendingResult = await db.query(
      `select id from reservasi
       where user_id = $1 and status = 'pending'
         and created_at >= (${nowWibSql()} - ($2 || ' seconds')::interval)
       limit 1`,
      [uid, AUTO_RELEASE_SECONDS]
    );

    if (pendingResult.rows.length > 0) {
      await db.query('commit');
      sendGateResponse({ gate, requestId, status: 'error', message: 'Punya Tiket Aktif, Gunakan QR Aplikasi', openGate: false });
      return;
    }

    const slotResult = await db.query(
      `select id, slot_nomor
       from slot
       where terisi = false
         and slot_nomor::int <= $1
         and id not in (
           select slot_id from reservasi
           where status = 'check-in'
              or (status = 'pending' and created_at >= (${nowWibSql()} - ($2 || ' seconds')::interval))
         )
       order by slot_nomor::int asc
       limit 1`,
      [TOTAL_SLOT, AUTO_RELEASE_SECONDS]
    );

    const slotKosong = slotResult.rows[0];
    if (!slotKosong) {
      await db.query('commit');
      sendGateResponse({ gate, requestId, status: 'error', message: 'Parkir Penuh', openGate: false });
      return;
    }

    if (Number(user.saldo || 0) < PARKING_FEE) {
      await db.query('commit');
      sendGateResponse({ gate, requestId, status: 'error', message: 'Saldo Tdk Cukup', openGate: false });
      return;
    }

    const kodeLangsung = `PL-${Math.random().toString(16).slice(2, 8).toUpperCase()}`;

    await db.query(
      `insert into transaksi (user_id, tipe, jumlah, keterangan)
       values ($1, 'parkir', 0, 'Masuk / Check-In Langsung (Biaya dipotong saat keluar)')`,
      [uid]
    );

    const insertRes = await db.query(
      `insert into reservasi (user_id, slot_id, kode_booking, status, created_at)
       values ($1, $2, $3, 'check-in', ${nowWibSql()})
       returning id`,
      [uid, slotKosong.id, kodeLangsung]
    );

    await db.query('commit');
    sendGateResponse({ gate, requestId, status: 'success', message: 'Akses Stiker Valid - Silakan Masuk', openGate: true, extra: { reservasi_id: insertRes.rows[0].id, slot_id: slotKosong.id } });
    await publishSlotState('gate_stiker_masuk');
  } catch (err) {
    await db.query('rollback');
    console.error('[GATE SCAN ERROR]', err.message);
    sendGateResponse({ gate, requestId, status: 'error', message: 'Kesalahan Sistem', openGate: false });
  } finally {
    db.release();
  }
}

// =====================================================
// PROCESS: RESERVASI DARI WEBSITE
// =====================================================
async function processReservationCreate(payload) {
  const requestId = payload.request_id || `res-${Date.now()}`;
  const uid = payload.user_id;
  const slotNomor = Number(payload.slot_nomor);

  if (!uid || !slotNomor) {
    publish(TOPICS.SERVER_RESERVATION_RESPONSE, { request_id: requestId, status: 'error', message: 'Data reservasi tidak lengkap' });
    return;
  }

  const db = await pool.connect();
  try {
    await db.query('begin');

    if (slotNomor > TOTAL_SLOT) {
      await db.query('commit');
      publish(TOPICS.SERVER_RESERVATION_RESPONSE, { request_id: requestId, status: 'error', message: 'Slot tidak tersedia' });
      return;
    }

    const saldoResult = await db.query(`select saldo from profiles where id = $1 limit 1`, [uid]);
    const saldo = Number(saldoResult.rows[0]?.saldo || 0);

    if (saldo < MIN_BOOKING_SALDO) {
      await db.query('commit');
      publish(TOPICS.SERVER_RESERVATION_RESPONSE, { request_id: requestId, status: 'error', message: 'Saldo tidak cukup' });
      return;
    }

    const aktifUser = await db.query(
      `select id from reservasi
       where user_id = $1
         and (status = 'check-in'
              or (status = 'pending' and created_at >= (${nowWibSql()} - ($2 || ' seconds')::interval)))
       limit 1`,
      [uid, AUTO_RELEASE_SECONDS]
    );

    if (aktifUser.rows.length > 0) {
      await db.query('commit');
      publish(TOPICS.SERVER_RESERVATION_RESPONSE, { request_id: requestId, status: 'error', message: 'Anda sudah memiliki tiket aktif atau sedang parkir' });
      return;
    }

    const slot = await db.query(`select id from slot where slot_nomor::int = $1 limit 1`, [slotNomor]);
    if (slot.rows.length === 0) {
      await db.query('commit');
      publish(TOPICS.SERVER_RESERVATION_RESPONSE, { request_id: requestId, status: 'error', message: 'Slot tidak ditemukan' });
      return;
    }

    const slotId = slot.rows[0].id;

    const aktifSlot = await db.query(
      `select id from reservasi
       where slot_id = $1
         and (status = 'check-in'
              or (status = 'pending' and created_at >= (${nowWibSql()} - ($2 || ' seconds')::interval)))
       limit 1`,
      [slotId, AUTO_RELEASE_SECONDS]
    );

    const slotTerisi = await db.query(`select terisi from slot where id = $1`, [slotId]);
    const terisi = slotTerisi.rows[0]?.terisi === true;

    if (aktifSlot.rows.length > 0 || terisi) {
      await db.query('commit');
      publish(TOPICS.SERVER_RESERVATION_RESPONSE, { request_id: requestId, status: 'error', message: 'Slot sudah diambil atau terisi' });
      return;
    }

    const kode = `PK-${Math.random().toString(16).slice(2, 8).toUpperCase()}`;

    const ins = await db.query(
      `insert into reservasi (user_id, slot_id, kode_booking, status, created_at)
       values ($1, $2, $3, 'pending', ${nowWibSql()})
       returning id`,
      [uid, slotId, kode]
    );

    await db.query(`update profiles set saldo = saldo - $1 where id = $2`, [BOOKING_FEE, uid]);
    await db.query(
      `insert into transaksi (user_id, tipe, jumlah, keterangan)
       values ($1, 'reservasi', $2, 'Biaya Reservasi (Booking Slot)')`,
      [uid, BOOKING_FEE]
    );

    await db.query('commit');

    publish(TOPICS.SERVER_RESERVATION_RESPONSE, {
      event: 'reservation_created',
      request_id: requestId,
      status: 'success',
      message: 'Reservasi berhasil',
      reservasi_id: ins.rows[0].id,
      user_id: uid,
      slot_nomor: slotNomor,
      slot_id: slotId,
      kode_booking: kode,
      qr_url: `https://api.qrserver.com/v1/create-qr-code/?size=300x300&data=${encodeURIComponent(kode)}`,
      timeout_seconds: AUTO_RELEASE_SECONDS,
    });

    await publishSlotState('reservation_create');
  } catch (err) {
    await db.query('rollback');
    console.error('[RESERVATION CREATE ERROR]', err.message);
    publish(TOPICS.SERVER_RESERVATION_RESPONSE, { request_id: requestId, status: 'error', message: 'Gagal memproses reservasi' });
  } finally {
    db.release();
  }
}

async function processReservationCancel(payload) {
  const requestId = payload.request_id || `cancel-${Date.now()}`;
  const uid = payload.user_id;
  const kode = payload.kode_booking;

  if (!uid || !kode) {
    publish(TOPICS.SERVER_RESERVATION_CANCELLED, { request_id: requestId, status: 'error', message: 'Data cancel tidak lengkap' });
    return;
  }

  const db = await pool.connect();
  try {
    await db.query('begin');
    const del = await db.query(
      `delete from reservasi where kode_booking = $1 and user_id = $2 and status = 'pending' returning id, slot_id`,
      [kode, uid]
    );

    if (del.rows.length === 0) {
      await db.query('commit');
      publish(TOPICS.SERVER_RESERVATION_CANCELLED, { request_id: requestId, status: 'error', message: 'Reservasi tidak ditemukan' });
      return;
    }

    await db.query(
      `insert into transaksi (user_id, tipe, jumlah, keterangan)
       values ($1, 'batal', 0, $2)`,
      [uid, `Batal Manual Tiket ${kode}`]
    );

    await db.query('commit');
    publish(TOPICS.SERVER_RESERVATION_CANCELLED, { request_id: requestId, status: 'success', message: 'Reservasi berhasil dibatalkan', kode_booking: kode });
    await publishSlotState('reservation_cancel');
  } catch (err) {
    await db.query('rollback');
    console.error('[RESERVATION CANCEL ERROR]', err.message);
    publish(TOPICS.SERVER_RESERVATION_CANCELLED, { request_id: requestId, status: 'error', message: 'Gagal membatalkan reservasi' });
  } finally {
    db.release();
  }
}

async function processTopupCreate(payload) {
  const requestId = payload.request_id || `topup-${Date.now()}`;
  const uid = payload.user_id;
  const jumlah = Number(payload.jumlah || 0);

  if (!uid || jumlah <= 0) {
    publish(TOPICS.SERVER_TRANSACTION_CREATED, { request_id: requestId, status: 'error', message: 'Data top up tidak valid' });
    return;
  }

  const db = await pool.connect();
  try {
    await db.query('begin');
    await db.query(`update profiles set saldo = saldo + $1 where id = $2`, [jumlah, uid]);
    await db.query(
      `insert into transaksi (user_id, tipe, jumlah, keterangan)
       values ($1, 'topup', $2, 'Top Up Saldo')`,
      [uid, jumlah]
    );
    await db.query('commit');

    publish(TOPICS.SERVER_TRANSACTION_CREATED, { request_id: requestId, status: 'success', message: 'Top up berhasil', user_id: uid, jumlah });
  } catch (err) {
    await db.query('rollback');
    console.error('[TOPUP ERROR]', err.message);
    publish(TOPICS.SERVER_TRANSACTION_CREATED, { request_id: requestId, status: 'error', message: 'Top up gagal' });
  } finally {
    db.release();
  }
}

// =====================================================
// MQTT EVENT
// =====================================================
client.on('connect', async () => {
  console.log('[MQTT] Connected to HiveMQ Cloud');

  const topics = [
    'smartparking/esp32/#',
    'smartparking/web/#',
  ];

  for (const t of topics) {
    client.subscribe(t, { qos: 0 }, (err) => {
      if (err) console.error('[MQTT SUBSCRIBE ERROR]', t, err.message);
      else console.log('[MQTT SUBSCRIBE]', t);
    });
  }

  publish(TOPICS.SERVER_DEVICE_STATUS, {
    event: 'worker_online',
    status: 'online',
    message: 'mqttreceiver.js aktif',
  });

  try {
    await publishSlotState('worker_startup');
  } catch (err) {
    console.error('[STARTUP SLOT STATE ERROR]', err.message);
  }
});

client.on('reconnect', () => console.log('[MQTT] Reconnecting...'));
client.on('error', (err) => console.error('[MQTT ERROR]', err.message));

client.on('message', async (topic, buffer) => {
  const payload = safeJsonParse(buffer);

  if (!payload) {
    publishError('Payload MQTT bukan JSON valid', { topic, raw: buffer.toString() });
    return;
  }

  console.log('[MQTT RECEIVE]', topic, payload);

  await logMqttMessage({
    messageId: payload.message_id,
    requestId: payload.request_id,
    topic,
    payload,
    sentAt: payload.sent_at || null,
  });

  try {
    await cleanupExpiredReservations();

    if (topic === TOPICS.ESP32_SLOT_UPDATE) {
      await processSlotUpdate(payload);
    }
    else if (topic === TOPICS.ESP32_GATE_IN_SCAN) {
      await processGateScan(payload, 'in');
    }
    else if (topic === TOPICS.ESP32_GATE_OUT_SCAN) {
      await processGateScan(payload, 'out');
    }
    else if (topic === TOPICS.WEB_RESERVATION_CREATE) {
      await processReservationCreate(payload);
    }
    else if (topic === TOPICS.WEB_RESERVATION_CANCEL) {
      await processReservationCancel(payload);
    }
    else if (topic === TOPICS.WEB_TOPUP_CREATE) {
      await processTopupCreate(payload);
    }

    await logMqttMessage({
      messageId: payload.message_id,
      requestId: payload.request_id,
      topic,
      payload,
      status: 'processed',
      sentAt: payload.sent_at || null,
      processedAt: true,
    });
  } catch (err) {
    console.error('[PROCESS ERROR]', topic, err.message);
    publishError('Gagal memproses pesan MQTT', { topic, message: err.message });
  }
});

// Auto release tetap dicek berkala walaupun tidak ada pesan masuk.
setInterval(cleanupExpiredReservations, 5000);
setInterval(() => publishSlotState('interval_refresh').catch(() => {}), 10000);

process.on('SIGINT', async () => {
  console.log('\n[STOP] Menutup mqttreceiver.js');
  client.end(true);
  await pool.end();
  process.exit(0);
});

// =====================================================
// AZURE HEALTH SERVER
// Wajib untuk Azure App Service agar proses Node dianggap aktif.
// =====================================================
const http = require('http');
const PORT = process.env.PORT || 8080;

http.createServer((req, res) => {
  res.writeHead(200, { 'Content-Type': 'application/json' });
  res.end(JSON.stringify({
    status: 'ok',
    service: 'mqttreceiver.js',
    mqtt: client.connected ? 'connected' : 'disconnected',
    uptime: process.uptime()
  }));
}).listen(PORT, () => {
  console.log(`[HTTP] Health server running on port ${PORT}`);
});

