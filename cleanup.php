<?php
require 'db_config.php';

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

    $conn->exec($sql);

    echo "cleanup selesai";

} catch (PDOException $e) {
    echo "error: " . $e->getMessage();
}
?>
