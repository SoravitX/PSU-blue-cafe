<?php
// SelectRole/export_orders.php — Export Orders to Excel (.xls compatible, styled)
// รองรับ: qf=today|7d|all หรือส่งฟิลด์ date_from/time_from/date_to/time_to มาเอง

declare(strict_types=1);
session_start();
if (empty($_SESSION['uid'])) { header("Location: ../index.php"); exit; }

require __DIR__ . '/../db.php';
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$conn->set_charset('utf8mb4');

/* Timezone */
date_default_timezone_set('Asia/Bangkok');
try { $conn->query("SET time_zone = 'Asia/Bangkok'"); }
catch (\mysqli_sql_exception $e) { $conn->query("SET time_zone = '+07:00'"); }

/* Helpers */
function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function money_fmt($n){ return number_format((float)$n, 2, '.', ''); }
function thai_month_full(int $m): string {
  $map = [1=>'มกราคม',2=>'กุมภาพันธ์',3=>'มีนาคม',4=>'เมษายน',5=>'พฤษภาคม',6=>'มิถุนายน',7=>'กรกฎาคม',8=>'สิงหาคม',9=>'กันยายน',10=>'ตุลาคม',11=>'พฤศจิกายน',12=>'ธันวาคม'];
  return $map[$m] ?? '';
}
function thai_datetime_full(?string $dt, bool $with_time=true): string {
  if(!$dt) return '';
  $d = new DateTime($dt);
  $y = (int)$d->format('Y') + 543;
  $m = (int)$d->format('n');
  $day = (int)$d->format('j');
  $date = $day.' '.thai_month_full($m).' '.$y;
  if($with_time){
    $t = $d->format('H:i');
    return $date.' '.$t.' น.';
  }
  return $date;
}

/* ===== รับค่ากรอง ===== */
$status    = $_GET['status']     ?? 'all';
$q         = trim((string)($_GET['q'] ?? ''));
$date_from = trim((string)($_GET['date_from'] ?? ''));
$time_from = trim((string)($_GET['time_from'] ?? ''));
$date_to   = trim((string)($_GET['date_to'] ?? ''));
$time_to   = trim((string)($_GET['time_to'] ?? ''));
$qf        = $_GET['qf'] ?? '';  // today | 7d | all

/* แปลง qf เป็นช่วงเวลา */
if ($qf === 'today') {
  $d = new DateTime('today');
  $date_from = $d->format('Y-m-d'); $time_from = '00:00';
  $date_to   = $d->format('Y-m-d'); $time_to   = '23:59';
} elseif ($qf === '7d') {
  $to = new DateTime('today');
  $from = (clone $to)->modify('-6 days');
  $date_from = $from->format('Y-m-d'); $time_from = '00:00';
  $date_to   = $to->format('Y-m-d');   $time_to   = '23:59';
} elseif ($qf === 'all') {
  $date_from = $time_from = $date_to = $time_to = '';
}

$dt_from = $date_from ? ($date_from.' '.($time_from ?: '00:00:00')) : '';
$dt_to   = $date_to   ? ($date_to  .' '.($time_to   ?: '23:59:59')) : '';

/* ===== Query orders (เหมือน check_order.php) ===== */
$where  = '1=1'; $types=''; $params=[];
if ($status !== 'all') { $where .= ' AND o.status = ?'; $types.='s'; $params[]=$status; }
if ($dt_from !== '')   { $where .= ' AND o.order_time >= ?'; $types.='s'; $params[]=$dt_from; }
if ($dt_to !== '')     { $where .= ' AND o.order_time <= ?'; $types.='s'; $params[]=$dt_to; }
if ($q !== '') {
  $where  .= " AND EXISTS(
                 SELECT 1 FROM order_details d
                 JOIN menu m ON m.menu_id=d.menu_id
                 WHERE d.order_id=o.order_id AND m.name LIKE ?
               )";
  $types  .= 's';
  $params []= '%'.$q.'%';
}

$sql = "
  SELECT o.order_id, o.user_id, o.order_time, o.status, o.total_price,
         o.order_date, o.order_seq,
         u.username, u.name,
         COALESCE(ps.slip_count, 0) AS slip_count,
         COALESCE(items.item_count, 0) AS item_count
  FROM orders o
  JOIN users u ON u.user_id=o.user_id
  LEFT JOIN ( SELECT order_id, COUNT(*) AS slip_count FROM payment_slips GROUP BY order_id ) ps
    ON ps.order_id = o.order_id
  LEFT JOIN ( SELECT order_id, SUM(quantity) AS item_count FROM order_details GROUP BY order_id ) items
    ON items.order_id = o.order_id
  WHERE $where
  ORDER BY o.order_time DESC
";
$stmt = $conn->prepare($sql);
if ($types !== '') { $stmt->bind_param($types, ...$params); }
$stmt->execute();
$rs = $stmt->get_result();

$rows = [];
while ($r = $rs->fetch_assoc()) $rows[] = $r;
$stmt->close();

/* display id: YYMMDD-### (fallback #ID) + badge ### */
function build_display_id(array $o): array {
  $oid = (int)$o['order_id'];
  $badge = '#'.$oid; $disp = '#'.$oid;
  if (!empty($o['order_date']) && $o['order_seq'] !== null) {
    try {
      $d = new DateTime((string)$o['order_date']);
      $yy = $d->format('ymd'); $seq3 = sprintf('%03d', (int)$o['order_seq']);
      $disp = "{$yy}-{$seq3}"; $badge = $seq3;
    } catch (\Throwable $e) {}
  }
  return [$disp, $badge];
}

/* ===== KPI Summary ===== */
$total_orders = count($rows);
$total_amount = 0.0;
$cash_count = 0; $transfer_count = 0;
$st_count = ['pending'=>0,'ready'=>0,'canceled'=>0];
foreach($rows as $o){
  $total_amount += (float)$o['total_price'];
  $is_transfer = ((int)$o['slip_count'] > 0);
  if ($is_transfer) $transfer_count++; else $cash_count++;
  $st = (string)$o['status'];
  if(isset($st_count[$st])) $st_count[$st]++;
}
$avg_order = $total_orders>0 ? $total_amount/$total_orders : 0.0;

/* ===== Label: ช่วงเวลา + เงื่อนไข ===== */
if ($dt_from==='' && $dt_to==='') {
  $period_label = 'ทั้งหมด';
} else {
  $period_label = thai_datetime_full($dt_from).' – '.thai_datetime_full($dt_to);
}
$filters_label = [];
$filters_label[] = 'สถานะ: '.($status==='all'?'ทั้งหมด':ucfirst($status));
if ($q!=='') $filters_label[] = 'คำค้น: '.$q;
$filters_text = implode(' • ', $filters_label);

/* ===== ส่งออก Excel แบบ HTML Table (.xls) ===== */
$fname = 'orders_'.date('Ymd_His').'.xls';
header('Content-Type: application/vnd.ms-excel; charset=UTF-8');
header('Content-Disposition: attachment; filename="'.$fname.'"');
header('Pragma: no-cache');
header('Expires: 0');
echo "\xEF\xBB\xBF"; // UTF-8 BOM
?>
<!DOCTYPE html>
<html lang="th">
<head>
<meta charset="utf-8">
<title>Export Orders</title>
<style>
/* ====== Base ====== */
body{ font-family:Tahoma,Arial,sans-serif; color:#0b1b2b; }
h1,h2,h3{ margin:0 }
.small{ font-size:10pt; color:#5a6d7e; }
.right{ text-align:right; }
.center{ text-align:center; }

/* ====== Title Bar ====== */
.report-title{
  background:#0f2640; color:#ffffff;
  padding:10px 12px; border-radius:8px; font-weight:900; font-size:16pt;
}
.subtitle{
  background:#e9f3ff; color:#0f2640;
  padding:6px 10px; border:1px solid #bcd6ff; border-radius:8px; margin:6px 0 14px 0;
}

/* ====== KPI ====== */
.kpi-grid{ border-collapse:separate; border-spacing:10px 8px; }
.kpi{
  background:#f4fbff; border:1px solid #cfe8ff; border-radius:10px;
  padding:8px 12px; min-width:180px;
}
.kpi h4{ margin:0 0 6px 0; font-size:10pt; color:#173b63; }
.kpi .v{ font-size:14pt; font-weight:900; color:#0f2640; }

/* ====== Main table ====== */
table.data{ border-collapse:collapse; width:100%; }
.data th, .data td{ border:1px solid #9fb4c8; padding:6px 8px; }
.data th{
  background:#d8eaff; color:#0f2640; font-weight:900;
}
.data tr.row-alt td{ background:#f7fbff; }
.num{ mso-number-format:"#,##0.00"; text-align:right; }
.int{ mso-number-format:"0"; text-align:right; }
.badge{ font-weight:700; }

/* Print / page */
@page { margin: 14mm; }
</style>
</head>
<body>

<!-- Title -->
<div class="report-title">รายงานคำสั่งซื้อ (Orders) • PSU Blue Cafe</div>
<div class="subtitle">
  <strong>ช่วงเวลา:</strong> <?= h($period_label) ?>
  <?php if($filters_text!==''): ?> | <strong>เงื่อนไข:</strong> <?= h($filters_text) ?><?php endif; ?>
  | <span class="small">ออกรายงาน: <?= h(thai_datetime_full(date('Y-m-d H:i:s'))) ?></span>
</div>

<!-- KPI -->
<table class="kpi-grid">
  <tr>
    <td class="kpi">
      <h4>จำนวนออเดอร์</h4>
      <div class="v"><?= (int)$total_orders ?></div>
    </td>
    <td class="kpi">
      <h4>ยอดรวมทั้งหมด (บาท)</h4>
      <div class="v"><?= number_format($total_amount,2) ?></div>
    </td>
    <td class="kpi">
      <h4>เฉลี่ยต่อบิล (บาท)</h4>
      <div class="v"><?= number_format($avg_order,2) ?></div>
    </td>
    <td class="kpi">
      <h4>ช่องทางชำระเงิน</h4>
      <div class="v small">เงินสด: <?= (int)$cash_count ?> | โอน: <?= (int)$transfer_count ?></div>
    </td>
    <td class="kpi">
      <h4>สถานะ (Pending/Ready/Canceled)</h4>
      <div class="v small"><?= (int)$st_count['pending'] ?> / <?= (int)$st_count['ready'] ?> / <?= (int)$st_count['canceled'] ?></div>
    </td>
  </tr>
</table>

<!-- Main data table -->
<table class="data">
  <colgroup>
    <col style="width:160px">
    <col style="width:70px">
    <col style="width:80px">
    <col style="width:160px">
    <col style="width:90px">
    <col style="width:90px">
    <col style="width:90px">
    <col style="width:100px">
    <col style="width:120px">
    <col style="width:120px">
    <col style="width:auto">
  </colgroup>
  <thead>
    <tr>
      <th>เลขออเดอร์ (แสดงผล)</th>
      <th>Badge</th>
      <th>Order ID</th>
      <th>เวลาออเดอร์</th>
      <th>สถานะ</th>
      <th>การชำระเงิน</th>
      <th>จำนวนสลิป</th>
      <th>จำนวนรายการ</th>
      <th>ยอดรวม (บาท)</th>
      <th>Username</th>
      <th>ชื่อที่แสดง</th>
    </tr>
  </thead>
  <tbody>
<?php
$i = 0;
foreach($rows as $o):
  [$display_id, $badge] = build_display_id($o);
  $is_transfer = ((int)$o['slip_count'] > 0);
  $pay_text = $is_transfer ? 'โอนเงิน' : 'เงินสด';
  $rowClass = (++$i % 2 === 0) ? 'row-alt' : '';
?>
    <tr class="<?= $rowClass ?>">
      <td><?= h($display_id) ?></td>
      <td class="center badge"><?= h($badge) ?></td>
      <td class="int"><?= (int)$o['order_id'] ?></td>
      <td><?= h(thai_datetime_full($o['order_time'])) ?></td>
      <td><?= h(ucfirst((string)$o['status'])) ?></td>
      <td><?= h($pay_text) ?></td>
      <td class="int"><?= (int)$o['slip_count'] ?></td>
      <td class="int"><?= (int)$o['item_count'] ?></td>
      <td class="num"><?= money_fmt($o['total_price']) ?></td>
      <td><?= h($o['username']) ?></td>
      <td><?= h($o['name']) ?></td>
    </tr>
<?php endforeach; ?>
  </tbody>
  <?php if($total_orders>0): ?>
  <tfoot>
    <tr>
      <th colspan="8" class="right">รวมทั้งหมด</th>
      <th class="num"><?= money_fmt($total_amount) ?></th>
      <th colspan="2"></th>
    </tr>
  </tfoot>
  <?php endif; ?>
</table>

</body>
</html>
