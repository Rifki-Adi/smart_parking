<?php

require 'db_config.php';

$stmt = $conn->query("
SELECT id, user_id, kode_booking, created_at
FROM reservasi
WHERE status = 'pending'
");

$data = $stmt->fetchAll(PDO::FETCH_ASSOC);

$current = time();

foreach($data as $row){

$created = strtotime(substr($row['created_at'],0,19));

if(($current - $created) > 60){

$del = $conn->prepare("
DELETE FROM reservasi
WHERE id = ?
");

$del->execute([$row['id']]);

}

}

echo "cleanup selesai";

?>
