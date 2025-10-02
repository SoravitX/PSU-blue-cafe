<?php
// export_excel.php — Export รายงานยอดขายเป็น Excel
// ✅ แยกก่อนลด/ส่วนลด/หลังลด + รายได้ท็อปปิง + ยอดสุทธิ (ต่อเมนู/รวม)
// ✅ ใช้เดือนภาษาไทยแบบเต็มในหัวรายงาน
// ✅ รองรับ .xlsx (PhpSpreadsheet) และ CSV fallback
declare(strict_types=1);
session_start();
if (empty($_SESSION['uid'])) { header("Location: ../index.php"); exit; }

require __DIR__ . '/../db.php';
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$conn->set_charset('utf8mb4');

/* ===== Timezone ให้ตรงกัน (PHP + MySQL) ===== */
date_default_timezone_set('Asia/Bangkok');
try {
  $conn->query("SET time_zone = 'Asia/Bangkok'");
} catch (\mysqli_sql_exception $e) {
  $conn->query("SET time_zone = '+07:00'");
}

/* ===== Autoload PhpSpreadsheet (ถ้ามี) ===== */
if (!class_exists('\PhpOffice\PhpSpreadsheet\Spreadsheet')) {
  $vendor = __DIR__ . '/../vendor/autoload.php';
  if (file_exists($vendor)) { require_once $vendor; }
}

/* ===== Utils ===== */
function money_fmt($n){ return number_format((float)$n, 2); }
/** วันที่ไทยแบบเต็ม: 1 ตุลาคม 2025 */
function th_full_date(\DateTime $dt): string {
  if (class_exists('IntlDateFormatter')) {
    $fmt = new \IntlDateFormatter(
      'th_TH@calendar=gregorian',
      \IntlDateFormatter::LONG, \IntlDateFormatter::NONE,
      'Asia/Bangkok', \IntlDateFormatter::GREGORIAN,
      'd MMMM y' // เดือนเต็ม
    );
    $s = $fmt->format($dt);
    if ($s !== false) return $s;
  }
  // fallback manual
  $months = [1=>'มกราคม','กุมภาพันธ์','มีนาคม','เมษายน','พฤษภาคม','มิถุนายน','กรกฎาคม','สิงหาคม','กันยายน','ตุลาคม','พฤศจิกายน','ธันวาคม'];
  $m = (int)$dt->format('n');
  return (int)$dt->format('j') . ' ' . ($months[$m]??$dt->format('F')) . ' ' . $dt->format('Y');
}
/** คำนวณช่วงวันจาก period */
function dt_range_from_period(string $period, string $start='', string $end=''): array {
  $now = new DateTime('now');
  $d0  = (clone $now)->setTime(0,0,0);

  if ($period==='today') {
    $rs = $d0; $re = (clone $rs)->modify('+1 day');
  } elseif ($period==='week') {
    $rs = (clone $d0)->modify('monday this week');
    $re = (clone $rs)->modify('+7 days');
  } elseif ($period==='month') {
    $rs = (clone $d0)->modify('first day of this month');
    $re = (clone $rs)->modify('first day of next month');
  } else {
    $rs = $start ? new DateTime($start.' 00:00:00') : $d0;
    $re = $end   ? new DateTime($end  .' 23:59:59') : (clone $d0)->modify('+1 day');
  }
  return [$rs->format('Y-m-d H:i:s'), $re->format('Y-m-d H:i:s'), $rs, $re];
}

/* ===== รับช่วงเวลา ===== */
$period = $_GET['period'] ?? 'today';
$start  = trim((string)($_GET['start'] ?? ''));
$end    = trim((string)($_GET['end']   ?? ''));
[$rangeStartStr, $rangeEndStr, $rsObj, $reObj] = dt_range_from_period($period, $start, $end);

/* สถานะที่นับยอดขายจริง */
$OK_STATUSES = ["ready","completed","paid","served"];
$ph = implode(',', array_fill(0, count($OK_STATUSES), '?'));
$types = 'ss' . str_repeat('s', count($OK_STATUSES));

/* ===== ดึงข้อมูล (ต่อเมนู) พร้อมแยก: qty, gross, discount, net_after_promo, topping, final =====
   - gross = SUM(m.price * qty)             (ก่อนลด, ไม่รวมท็อปปิง)
   - discount = SUM(ส่วนลดต่อหน่วย(คงเพดาน) * qty)
   - net_after_promo = gross - discount     (หลังลด, ไม่รวมท็อปปิง)
   - topping = SUM(max(od.total_price - (m.price - discount_unit)*qty, 0))
   - final = SUM(od.total_price)            (สุทธิหลังลด + ท็อปปิง) 
*/
$sql = "
  SELECT 
    COALESCE(c.category_name, 'Uncategorized') AS category_name,
    m.menu_id, m.name AS menu_name,
    m.price AS unit_price,
    COALESCE(SUM(od.quantity),0) AS qty,
    COALESCE(SUM(m.price * od.quantity),0) AS gross_amount,

    COALESCE(SUM(
      COALESCE(
        CASE
          WHEN p.promo_id IS NULL THEN 0
          WHEN p.discount_type='PERCENT'
            THEN LEAST((p.discount_value/100.0)*m.price, COALESCE(p.max_discount, 999999999))
          ELSE LEAST(p.discount_value, COALESCE(p.max_discount, 999999999))
        END, 0
      ) * od.quantity
    ),0) AS discount_amount,

    COALESCE(SUM(
      GREATEST(
        od.total_price - (
          (m.price - COALESCE(
            CASE
              WHEN p.promo_id IS NULL THEN 0
              WHEN p.discount_type='PERCENT'
                THEN LEAST((p.discount_value/100.0)*m.price, COALESCE(p.max_discount, 999999999))
              ELSE LEAST(p.discount_value, COALESCE(p.max_discount, 999999999))
            END,0)
          ) * od.quantity
        ), 0)
    ),0) AS topping_amount,

    COALESCE(SUM(od.total_price),0) AS final_amount

  FROM order_details od
  JOIN orders o ON o.order_id = od.order_id
  JOIN menu   m ON m.menu_id   = od.menu_id
  LEFT JOIN categories c ON m.category_id = c.category_id
  LEFT JOIN promotions p ON p.promo_id = od.promo_id

  WHERE o.order_time >= ? AND o.order_time < ?
    AND o.status IN ($ph)
  GROUP BY COALESCE(c.category_name,'Uncategorized'), m.menu_id, m.name, m.price
  ORDER BY category_name ASC, menu_name ASC
";
$stmt = $conn->prepare($sql);
$stmt->bind_param($types, $rangeStartStr, $rangeEndStr, ...$OK_STATUSES);
$stmt->execute();
$res = $stmt->get_result();

$byCat = [];
$tot = [
  'qty'=>0, 'gross'=>0.0, 'discount'=>0.0, 'net_after'=>0.0, 'topping'=>0.0, 'final'=>0.0
];
while ($r = $res->fetch_assoc()) {
  $cat   = (string)$r['category_name'];
  $qty   = (int)$r['qty'];
  $gross = (float)$r['gross_amount'];
  $disc  = (float)$r['discount_amount'];
  $top   = (float)$r['topping_amount'];
  $final = (float)$r['final_amount'];
  $net   = $gross - $disc;

  if (!isset($byCat[$cat])) $byCat[$cat] = [];
  $byCat[$cat][] = [
    'name'   => $r['menu_name'],
    'qty'    => $qty,
    'price'  => (float)$r['unit_price'],
    'gross'  => $gross,
    'discount'=> $disc,
    'net'    => $net,
    'topping'=> $top,
    'final'  => $final,
  ];
  $tot['qty']     += $qty;
  $tot['gross']   += $gross;
  $tot['discount']+= $disc;
  $tot['net_after'] += $net;
  $tot['topping'] += $top;
  $tot['final']   += $final;
}
$stmt->close();

/* ===== KPI รวม (คงไว้ ตามสูตรเดิมเพื่อความชัดเจน) ===== */
$sqlExtra = "
  SELECT
    COALESCE(SUM(
      GREATEST(
        od.total_price - (
          (m.price - COALESCE(
            CASE
              WHEN p.promo_id IS NULL THEN 0
              WHEN p.discount_type='PERCENT'
                THEN LEAST((p.discount_value/100.0)*m.price, COALESCE(p.max_discount, 999999999))
              ELSE LEAST(p.discount_value, COALESCE(p.max_discount, 999999999))
            END, 0)
          ) * od.quantity
        )
      , 0)
    ), 0) AS topping_total,

    COALESCE(SUM(
      COALESCE(
        CASE
          WHEN p.promo_id IS NULL THEN 0
          WHEN p.discount_type='PERCENT'
            THEN LEAST((p.discount_value/100.0)*m.price, COALESCE(p.max_discount, 999999999))
          ELSE LEAST(p.discount_value, COALESCE(p.max_discount, 999999999))
        END, 0
      ) * od.quantity
    ), 0) AS discount_total
  FROM order_details od
  JOIN orders o ON o.order_id = od.order_id
  JOIN menu   m ON m.menu_id  = od.menu_id
  LEFT JOIN promotions p ON p.promo_id = od.promo_id
  WHERE o.order_time >= ? AND o.order_time < ?
    AND o.status IN ($ph)
";
$stmt = $conn->prepare($sqlExtra);
$stmt->bind_param($types, $rangeStartStr, $rangeEndStr, ...$OK_STATUSES);
$stmt->execute();
$extra = $stmt->get_result()->fetch_assoc() ?: ['topping_total'=>0.0, 'discount_total'=>0.0];
$stmt->close();

$topping_total   = (float)($extra['topping_total']  ?? 0.0);
$discount_total  = (float)($extra['discount_total'] ?? 0.0);
$gross_total     = (float)$tot['gross'];
$net_after_promo = $gross_total - $discount_total; // คงความหมาย: หลังหักโปรฯ (ยังไม่รวมท็อปปิง)
$final_total     = (float)$tot['final'];           // ยอดสุทธิสุดท้าย (หลังโปรฯ + ท็อปปิง)

/* ===== สร้าง XLSX ถ้ามี PhpSpreadsheet ===== */
if (class_exists('\PhpOffice\PhpSpreadsheet\Spreadsheet')) {
  $wb = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
  $ws = $wb->getActiveSheet();
  $ws->setTitle('Sales Report');

  // หัวรายงาน (ไทย)
  $title = "รายงานขายเครื่องดื่ม PSU Blue Café";
  $periodText = "วันที่ " . th_full_date($rsObj) . " ถึง " . th_full_date((clone $reObj)->modify('-1 second'));
  $ws->setCellValue('E1', $title);
  $ws->setCellValue('E2', $periodText);
  $ws->mergeCells('E1:L1');
  $ws->mergeCells('E2:L2');
  $ws->getStyle('E1')->getFont()->setBold(true)->setSize(14);
  $ws->getStyle('E2')->getFont()->setBold(true);

  // KPI กล่องซ้าย
  $ws->setCellValue('C1', 'รายได้จากท็อปปิง (THB)');     $ws->setCellValue('D1', $topping_total);
  $ws->setCellValue('C2', 'ส่วนลดที่ให้ไป (โปรโมชัน)');   $ws->setCellValue('D2', $discount_total);
  $ws->setCellValue('C3', 'Net หลังโปรฯ (ไม่รวมท็อปปิง)'); $ws->setCellValue('D3', $net_after_promo);
  $ws->setCellValue('C4', 'ยอดสุทธิสุดท้าย');             $ws->setCellValue('D4', $final_total);

  $ws->getStyle('C1:D4')->getFont()->setBold(true);
  $ws->getStyle('D1:D4')->getNumberFormat()->setFormatCode(\PhpOffice\PhpSpreadsheet\Style\NumberFormat::FORMAT_NUMBER_COMMA_SEPARATED1);
  $ws->getStyle('C1:D4')->getFill()->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)->getStartColor()->setARGB('FFF6FBFF');
  $ws->getColumnDimension('C')->setWidth(33);
  $ws->getColumnDimension('D')->setWidth(18);

  // Header columns (เพิ่มคอลัมน์แยกราคา)
  $ws->setCellValue('E4', 'รายการ');
  $ws->setCellValue('F4', 'จำนวน');
  $ws->setCellValue('G4', 'ราคาต่อหน่วย');
  $ws->setCellValue('H4', 'ก่อนลด (Gross)');
  $ws->setCellValue('I4', 'ส่วนลดโปรฯ');
  $ws->setCellValue('J4', 'หลังลด (Net)');
  $ws->setCellValue('K4', 'ท็อปปิง');
  $ws->setCellValue('L4', 'ยอดสุทธิ (Final)');
  $ws->getStyle('E4:L4')->getFont()->setBold(true);
  $ws->getStyle('E4:L4')->getFill()->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)->getStartColor()->setARGB('FFEEF5FF');

  foreach (['E'=>34,'F'=>10,'G'=>14,'H'=>15,'I'=>14,'J'=>14,'K'=>14,'L'=>16] as $col=>$w) {
    $ws->getColumnDimension($col)->setWidth($w);
  }

  $row = 5;
  foreach ($byCat as $catName => $items) {
    // หัวหมวด
    $ws->mergeCells("E{$row}:L{$row}");
    $ws->setCellValue("E{$row}", strtoupper($catName));
    $ws->getStyle("E{$row}:L{$row}")->getFont()->setBold(true);
    $ws->getStyle("E{$row}:L{$row}")->getFill()->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)->getStartColor()->setARGB('FFDDEBFF');
    $row++;

    $startDataRow = $row;
    $isOdd = false;
    foreach ($items as $it) {
      // zebra shading
      if ($isOdd) {
        $ws->getStyle("E{$row}:L{$row}")->getFill()
          ->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)
          ->getStartColor()->setARGB('FFF7FAFF');
      }
      $isOdd = !$isOdd;

      $ws->setCellValue("E{$row}", $it['name']);
      $ws->setCellValue("F{$row}", (int)$it['qty']);
      $ws->setCellValue("G{$row}", (float)$it['price']);
      $ws->setCellValue("H{$row}", (float)$it['gross']);
      $ws->setCellValue("I{$row}", (float)$it['discount']);
      $ws->setCellValue("J{$row}", (float)$it['net']);
      $ws->setCellValue("K{$row}", (float)$it['topping']);
      $ws->setCellValue("L{$row}", (float)$it['final']);
      $row++;
    }
    // spacer
    $ws->getStyle("E{$row}:L{$row}")->getFill()->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)->getStartColor()->setARGB('FFEFF6FF');
    $row++;
  }

  // รวมท้ายตาราง
  $ws->setCellValue("G{$row}", 'รวมทั้งหมด');
  $ws->setCellValue("H{$row}", $tot['gross']);
  $ws->setCellValue("I{$row}", $tot['discount']);
  $ws->setCellValue("J{$row}", $tot['net_after']);
  $ws->setCellValue("K{$row}", $tot['topping']);
  $ws->setCellValue("L{$row}", $tot['final']);
  $ws->getStyle("G{$row}:L{$row}")->getFont()->setBold(true);
  $ws->getStyle("G{$row}:L{$row}")->getFill()->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)->getStartColor()->setARGB('FFE6F2FF');

  // Number formats & borders
  $lastDataRow = $row;
  $ws->getStyle("F5:F{$lastDataRow}")->getAlignment()->setHorizontal(\PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER);
  $ws->getStyle("G5:L{$lastDataRow}")->getNumberFormat()->setFormatCode(\PhpOffice\PhpSpreadsheet\Style\NumberFormat::FORMAT_NUMBER_COMMA_SEPARATED1);
  $ws->getStyle("E4:L{$lastDataRow}")
     ->getBorders()->getAllBorders()
     ->setBorderStyle(\PhpOffice\PhpSpreadsheet\Style\Border::BORDER_THIN)
     ->getColor()->setARGB('FFBFD4FF');

  // usability
  $ws->freezePane('E5'); // freeze header
  $ws->setAutoFilter("E4:L{$lastDataRow}");

  // Output XLSX
  header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
  $fname = 'PSU_Blue_Cafe_Sales_'.date('Ymd_His').'.xlsx';
  header('Content-Disposition: attachment; filename="'.$fname.'"');
  header('Cache-Control: max-age=0');
  $writer = new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($wb);
  $writer->save('php://output');
  exit;
}

/* ===== Fallback: CSV ===== */
header('Content-Type: text/csv; charset=utf-8');
$fname = 'PSU_Blue_Cafe_Sales_'.date('Ymd_His').'.csv';
header('Content-Disposition: attachment; filename="'.$fname.'"');
echo "\xEF\xBB\xBF"; // UTF-8 BOM

$out = fopen('php://output', 'w');

// Header (ไทย)
fputcsv($out, ["รายงานขายเครื่องดื่ม PSU Blue Café"]);
fputcsv($out, ["ช่วงวันที่", th_full_date($rsObj), "ถึง", th_full_date((clone $reObj)->modify('-1 second'))]);

// KPI
fputcsv($out, ['รายได้จากท็อปปิง (THB)', number_format($topping_total, 2, '.', '')]);
fputcsv($out, ['ส่วนลดที่ให้ไป (โปรโมชัน) (THB)', number_format($discount_total, 2, '.', '')]);
fputcsv($out, ['Net หลังโปรฯ (ไม่รวมท็อปปิง)', number_format($net_after_promo, 2, '.', '')]);
fputcsv($out, ['ยอดสุทธิสุดท้าย', number_format($final_total, 2, '.', '')]);
fputcsv($out, []);

// Table header (ละเอียด)
fputcsv($out, ['หมวดหมู่', 'รายการ', 'จำนวน', 'ราคาต่อหน่วย', 'ก่อนลด (Gross)', 'ส่วนลดโปรฯ', 'หลังลด (Net)', 'ท็อปปิง', 'ยอดสุทธิ (Final)']);

foreach ($byCat as $catName => $items) {
  foreach ($items as $it) {
    fputcsv($out, [
      $catName,
      $it['name'],
      (int)$it['qty'],
      number_format((float)$it['price'], 2, '.', ''),
      number_format((float)$it['gross'], 2, '.', ''),
      number_format((float)$it['discount'], 2, '.', ''),
      number_format((float)$it['net'], 2, '.', ''),
      number_format((float)$it['topping'], 2, '.', ''),
      number_format((float)$it['final'], 2, '.', ''),
    ]);
  }
}
fputcsv($out, []);
fputcsv($out, ['รวมทั้งหมด', '', '', '',
  number_format($tot['gross'], 2, '.', ''),
  number_format($tot['discount'], 2, '.', ''),
  number_format($tot['net_after'], 2, '.', ''),
  number_format($tot['topping'], 2, '.', ''),
  number_format($tot['final'], 2, '.', ''),
]);

fclose($out);
exit;
