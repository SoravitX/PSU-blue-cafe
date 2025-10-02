<?php
declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

require __DIR__.'/../db.php';
$conn->set_charset('utf8mb4');
date_default_timezone_set('Asia/Bangkok');
$conn->query("SET time_zone = '+07:00'");

$oid = (int)($_GET['id'] ?? 0);
if ($oid <= 0) { echo json_encode(['ok'=>false,'error'=>'missing id']); exit; }

/* header */
$stmt = $conn->prepare("
  SELECT
    o.order_id, o.user_id, o.order_time, o.status, o.total_price,
    COALESCE(u.username,'user') AS username,
    o.order_date, o.order_seq,
    CONCAT(DATE_FORMAT(o.order_date,'%y%m%d'), '-', LPAD(o.order_seq,3,'0')) AS order_code
  FROM orders o
  LEFT JOIN users u ON u.user_id=o.user_id
  WHERE o.order_id=?
  LIMIT 1
");
$stmt->bind_param('i', $oid);
$stmt->execute();
$h = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$h) { echo json_encode(['ok'=>false,'error'=>'not found']); exit; }

/* lines (คงเดิม) */
$lines = [];
$stmt = $conn->prepare("
  SELECT d.order_detail_id,d.menu_id,d.quantity,d.note,d.total_price,
         m.name AS menu_name
  FROM order_details d
  JOIN menu m ON m.menu_id=d.menu_id
  WHERE d.order_id=?
  ORDER BY d.order_detail_id
");
$stmt->bind_param('i', $oid);
$stmt->execute();
$res = $stmt->get_result();
while($r = $res->fetch_assoc()) $lines[] = $r;
$stmt->close();

echo json_encode(['ok'=>true,'order'=>$h,'lines'=>$lines], JSON_UNESCAPED_UNICODE);
