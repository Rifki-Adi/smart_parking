<?php
require 'db_config.php';
require_once 'mqtt_helper.php';

try {
    $sql = "
    WITH expired AS (
        DELETE FROM reservasi
        WHERE status = 'pending'
        AND created_at < (NOW() - INTERVAL '60 seconds')
        RETURNING user_id, kode_booking
    )
    INSERT INTO transaksi (user_id, tipe, jumlah, keterangan)
    SELECT 
        user_id,
        'hangus',
        0,
        'Waktu Habis Tiket ' || kode_booking
    FROM expired
    ";

    $affected = $conn->exec($sql);

    if ($affected > 0 && function_exists('smartparking_publish_refresh')) {
        smartparking_publish_refresh($conn, 'reservation_expired', 'cleanup.php', [
            'reason' => 'auto_release',
            'affected_rows' => $affected
        ]);
    }

    echo "cleanup selesai";

} catch (PDOException $e) {
    echo "error: " . $e->getMessage();
}
?>
