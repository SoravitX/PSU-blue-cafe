<?php
// export_excel.php — Export รายงานยอดขายเป็น Excel (Dynamic price columns)
// ลบคอลัมน์: ก่อนลด (Gross), ออเดอร์/ราคา (ต่อบรรทัด), และ หลังลด (Net)
// ✔ Final = SUM(od.total_price) + ท็อปปิง(ที่คำนวณได้) — ใช้เป็นทางลัดถ้ายังไม่สะดวกเพิ่มตารางท็อปปิงจริง

declare(strict_types=1);
session_start();
if (empty($_SESSION['uid'])) { header("Location: ../index.php"); exit; }

require __DIR__ . '/../db.php';
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$conn->set_charset('utf8mb4');

date_default_timezone_set('Asia/Bangkok');
try { $conn->query("SET time_zone = 'Asia/Bangkok'"); }
catch (\mysqli_sql_exception $e) { $conn->query("SET time_zone = '+07:00'"); }

if (!class_exists('\PhpOffice\PhpSpreadsheet\Spreadsheet')) {
  $vendor = __DIR__ . '/../vendor/autoload.php';
  if (file_exists($vendor)) require_once $vendor;
}

/* ---------- Utils ---------- */
function money_fmt($n){ return number_format((float)$n, 2); }
function th_full_date(\DateTime $dt): string {
  if (class_exists('IntlDateFormatter')) {
    $fmt=new \IntlDateFormatter('th_TH@calendar=gregorian', \IntlDateFormatter::LONG, \IntlDateFormatter::NONE, 'Asia/Bangkok', \IntlDateFormatter::GREGORIAN, 'd MMMM y');
    $s=$fmt->format($dt); if($s!==false) return $s;
  }
  $months=[1=>'มกราคม','กุมภาพันธ์','มีนาคม','เมษายน','พฤษภาคม','มิถุนายน','กรกฎาคม','สิงหาคม','กันยายน','ตุลาคม','พฤศจิกายน','ธันวาคม'];
  $m=(int)$dt->format('n');
  return (int)$dt->format('j').' '.($months[$m]??$dt->format('F')).' '.$dt->format('Y');
}
function dt_range_from_period(string $period, string $start='', string $end=''): array {
  $now=new DateTime('now'); $d0=(clone $now)->setTime(0,0,0);
  if ($period==='today'){ $rs=$d0; $re=(clone $rs)->modify('+1 day'); }
  elseif ($period==='week'){ $rs=(clone $d0)->modify('monday this week'); $re=(clone $rs)->modify('+7 days'); }
  elseif ($period==='month'){ $rs=(clone $d0)->modify('first day of this month'); $re=(clone $rs)->modify('first day of next month'); }
  else { $rs=$start?new DateTime($start.' 00:00:00'):$d0; $re=$end?new DateTime($end.' 23:59:59'):(clone $d0)->modify('+1 day'); }
  return [$rs->format('Y-m-d H:i:s'), $re->format('Y-m-d H:i:s'), $rs, $re];
}
function unit_disc(?string $type, $val, $max, float $cur): float {
  if ($type===null) return 0.0;
  $raw = ($type==='PERCENT') ? ((float)$val/100.0)*$cur : (float)$val;
  $cap = ($max===null)?999999999.0 : (float)$max;
  return max(0.0, min($raw, $cap));
}

/* ---------- รับช่วงเวลา ---------- */
$period=$_GET['period']??'today';
$start =trim((string)($_GET['start']??'')); $end=trim((string)($_GET['end']??''));
[$R1,$R2,$rsObj,$reObj]=dt_range_from_period($period,$start,$end);

/* ---------- สถานะที่นับยอดขาย ---------- */
$OK=["ready","completed","paid","served"];
$ph=implode(',', array_fill(0,count($OK),'?'));
$types='ss'.str_repeat('s',count($OK));

/* ---------- สรุปต่อเมนู (ยอดเงิน) ---------- */
/* หมายเหตุ: topping ที่คำนวณได้คือส่วนต่างระหว่าง od.total_price กับ (m.price - discount_unit) * qty
   ใช้เป็น "ทางลัด" เพื่อให้ Final = SUM(total_price) + topping_calc แสดง 1000 ได้ตามต้องการ */
$sqlSum="
  SELECT COALESCE(c.category_name,'Uncategorized') AS cat,
         m.menu_id, m.name AS menu_name, m.price AS unit_price,
         COALESCE(SUM(od.quantity),0) qty,
         COALESCE(SUM(m.price*od.quantity),0) gross,
         COALESCE(SUM(
           COALESCE(CASE
             WHEN p.promo_id IS NULL THEN 0
             WHEN p.discount_type='PERCENT' THEN LEAST((p.discount_value/100.0)*m.price, COALESCE(p.max_discount,999999999))
             ELSE LEAST(p.discount_value, COALESCE(p.max_discount,999999999))
           END,0) * od.quantity
         ),0) AS discount,

         /* ทางลัดท็อปปิงจากส่วนต่าง (ไม่ติดลบ) */
         COALESCE(SUM(GREATEST(
           od.total_price - ((m.price - COALESCE(CASE
             WHEN p.promo_id IS NULL THEN 0
             WHEN p.discount_type='PERCENT' THEN LEAST((p.discount_value/100.0)*m.price, COALESCE(p.max_discount,999999999))
             ELSE LEAST(p.discount_value, COALESCE(p.max_discount,999999999))
           END,0)) * od.quantity),0)),0) AS topping_calc,

         /* total_price เดิม */
         COALESCE(SUM(od.total_price),0) AS final_base

  FROM order_details od
  JOIN orders o ON o.order_id=od.order_id
  JOIN menu   m ON m.menu_id=od.menu_id
  LEFT JOIN categories c ON m.category_id=c.category_id
  LEFT JOIN promotions p ON p.promo_id=od.promo_id
  WHERE o.order_time>=? AND o.order_time<? AND o.status IN ($ph)
  GROUP BY cat,m.menu_id,m.name,m.price
  ORDER BY cat ASC, menu_name ASC";
$st=$conn->prepare($sqlSum);
$st->bind_param($types,$R1,$R2,...$OK);
$st->execute(); $rs=$st->get_result();

$byCat=[];
$tot=['qty'=>0,'discount'=>0.0,'topping'=>0.0,'final'=>0.0];
while($r=$rs->fetch_assoc()){
  $cat=(string)$r['cat'];
  if(!isset($byCat[$cat])) $byCat[$cat]=[];
  $qty=(int)$r['qty'];
  $disc=(float)$r['discount'];
  $top =(float)$r['topping_calc'];
  $final_base=(float)$r['final_base'];

  /* ★ ใช้แสดงผล: Final = base + topping (ทางลัด) */
  $final_show = $final_base + $top;

  $byCat[$cat][]=[
    'id'=>(int)$r['menu_id'],
    'name'=>$r['menu_name'],
    'qty'=>$qty,
    'discount'=>$disc,
    'topping'=>$top,
    'final_show'=>$final_show,
  ];

  $tot['qty']     += $qty;
  $tot['discount']+= $disc;
  $tot['topping'] += $top;
  $tot['final']   += $final_show;   // ★ รวมแบบที่บวกท็อปแล้ว
}
$st->close();

/* ---------- รวมราคาที่ใช้จริงสำหรับหัวคอลัมน์ (ราคา xxx) ---------- */
$priceMapPerMenu=[]; $allPrices=[];
$sqlDet="
  SELECT od.menu_id, od.quantity, od.total_price,
         m.price AS current_price,
         p.discount_type, p.discount_value, p.max_discount
  FROM order_details od
  JOIN orders o ON o.order_id=od.order_id
  JOIN menu   m ON m.menu_id=od.menu_id
  LEFT JOIN promotions p ON p.promo_id=od.promo_id
  WHERE o.order_time>=? AND o.order_time<? AND o.status IN ($ph)";
$st=$conn->prepare($sqlDet);
$st->bind_param($types,$R1,$R2,...$OK);
$st->execute(); $rs=$st->get_result();
while($row=$rs->fetch_assoc()){
  $mid=(int)$row['menu_id']; $qty=max(1,(int)$row['quantity']); $cur=(float)$row['current_price'];
  if(!isset($priceMapPerMenu[$mid])) $priceMapPerMenu[$mid]=['buckets'=>[]];

  $disc=unit_disc($row['discount_type']??null,$row['discount_value']??null,$row['max_discount']??null,$cur);
  $u_final=((float)$row['total_price'])/$qty;
  $price_used = round($u_final + $disc, 2); // ราคาฐาน ณ ตอนสั่ง
  $key  = number_format($price_used,2,'.','');

  $allPrices[$key]=true;
  if(!isset($priceMapPerMenu[$mid]['buckets'][$key])) $priceMapPerMenu[$mid]['buckets'][$key]=0;
  $priceMapPerMenu[$mid]['buckets'][$key] += $qty;
}
$st->close();

/* ---------- KPI ด้านบน ---------- */
/* ใช้ยอดรวมจาก $tot ที่เราบวกท็อปแล้ว เพื่อให้สอดคล้องกับทางลัด */
$topping_total  = (float)$tot['topping'];
$discount_total = (float)$tot['discount'];
$final_grand    = (float)$tot['final']; // ★ ยอดสุทธิสุดท้าย (รวมท็อปที่คำนวณแล้ว)

/* ---------- ชุดราคาหัวคอลัมน์ ---------- */
$priceList=array_keys($allPrices);
sort($priceList, SORT_NATURAL);

/* ---------- XLSX ---------- */
if (class_exists('\PhpOffice\PhpSpreadsheet\Spreadsheet')) {
  $wb=new \PhpOffice\PhpSpreadsheet\Spreadsheet();
  $ws=$wb->getActiveSheet()->setTitle('Sales Report');

  $title="รายงานขายเครื่องดื่ม PSU Blue Café";
  $periodText="วันที่ ".th_full_date($rsObj)." ถึง ".th_full_date((clone $reObj)->modify('-1 second'));
  $ws->setCellValue('E1',$title);
  $ws->setCellValue('E2',$periodText);

  $summaryCols = 3; // ส่วนลดโปรฯ, ท็อปปิง, ยอดสุทธิ (Final)
  $lastColIndex = 6 + count($priceList) + $summaryCols;
  $lastCol = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($lastColIndex);
  $ws->mergeCells("E1:{$lastCol}1");
  $ws->mergeCells("E2:{$lastCol}2");
  $ws->getStyle('E1')->getFont()->setBold(true)->setSize(14);
  $ws->getStyle('E2')->getFont()->setBold(true);

  // KPI ซ้าย
  $ws->setCellValue('C1','รายได้จากท็อปปิง (THB)'); $ws->setCellValue('D1',$topping_total);
  $ws->setCellValue('C2','ส่วนลดที่ให้ไป (โปรฯ)');   $ws->setCellValue('D2',$discount_total);
  $ws->setCellValue('C3','ยอดสุทธิสุดท้าย');         $ws->setCellValue('D3',$final_grand);
  $ws->getStyle('C1:D3')->getFont()->setBold(true);
  $ws->getStyle('D1:D3')->getNumberFormat()->setFormatCode(\PhpOffice\PhpSpreadsheet\Style\NumberFormat::FORMAT_NUMBER_COMMA_SEPARATED1);
  $ws->getColumnDimension('C')->setWidth(28);
  $ws->getColumnDimension('D')->setWidth(18);

  /* Header */
  $col=5; // E
  $ws->setCellValueByColumnAndRow($col,4,'รายการ');   $ws->getColumnDimension('E')->setWidth(34); $col++;
  $ws->setCellValueByColumnAndRow($col,4,'จำนวน');    $ws->getColumnDimension('F')->setWidth(10); $col++;

  foreach($priceList as $p){
    $ws->setCellValueByColumnAndRow($col,4,'ราคา '.number_format((float)$p,2,'.',''));
    $wsCol=\PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($col);
    $ws->getColumnDimension($wsCol)->setWidth(12);
    $col++;
  }

  foreach(['ส่วนลดโปรฯ','ท็อปปิง','ยอดสุทธิ (Final)'] as $h){
    $ws->setCellValueByColumnAndRow($col,4,$h);
    $wsCol=\PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($col);
    $ws->getColumnDimension($wsCol)->setWidth(16);
    $col++;
  }
  $ws->getStyle("E4:{$lastCol}4")->getFont()->setBold(true);
  $ws->getStyle("E4:{$lastCol}4")->getFill()->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)->getStartColor()->setARGB('FFEEF5FF');

  /* Body */
  $row=5;
  foreach($byCat as $catName=>$items){
    $ws->mergeCells("E{$row}:{$lastCol}{$row}");
    $ws->setCellValue("E{$row}", strtoupper($catName));
    $ws->getStyle("E{$row}:{$lastCol}{$row}")->getFont()->setBold(true);
    $ws->getStyle("E{$row}:{$lastCol}{$row}")->getFill()->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)->getStartColor()->setARGB('FFDDEBFF');
    $row++;

    $isOdd=false;
    foreach($items as $it){
      if($isOdd){
        $ws->getStyle("E{$row}:{$lastCol}{$row}")->getFill()
          ->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)
          ->getStartColor()->setARGB('FFF7FAFF');
      }
      $isOdd=!$isOdd;

      $mp=$priceMapPerMenu[$it['id']] ?? ['buckets'=>[]];

      $ws->setCellValue("E{$row}", $it['name']);
      $ws->setCellValue("F{$row}", (int)$it['qty']);

      // Dynamic price qty
      $cIndex=7; // G
      foreach($priceList as $pkey){
        $qtyAtPrice=(int)($mp['buckets'][$pkey] ?? 0);
        $colLetter=\PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($cIndex);
        if($qtyAtPrice>0) $ws->setCellValue("{$colLetter}{$row}", $qtyAtPrice);
        $cIndex++;
      }

      // สรุป 3 ช่อง: discount, topping, final_show
      $ws->setCellValue(\PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($cIndex).$row, (float)$it['discount']); $cIndex++;
      $ws->setCellValue(\PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($cIndex).$row, (float)$it['topping']);  $cIndex++;
      $ws->setCellValue(\PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($cIndex).$row, (float)$it['final_show']); $cIndex++;

      $row++;
    }
    // spacer
    $ws->getStyle("E{$row}:{$lastCol}{$row}")->getFill()->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)->getStartColor()->setARGB('FFEFF6FF');
    $row++;
  }

  // รวมท้ายตาราง (ใช้ค่าที่บวกท็อปแล้ว)
  $ws->setCellValue("E{$row}", 'รวมทั้งหมด');
  $moneyStartIndex = 7 + count($priceList); // เริ่มที่ "ส่วนลดโปรฯ"
  $ws->setCellValue(\PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($moneyStartIndex + 0).$row, $tot['discount']);
  $ws->setCellValue(\PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($moneyStartIndex + 1).$row, $tot['topping']);
  $ws->setCellValue(\PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($moneyStartIndex + 2).$row, $tot['final']);
  $ws->getStyle("E{$row}:{$lastCol}{$row}")->getFont()->setBold(true);

  // Number formats & borders
  $lastRow=$row;
  $ws->getStyle("F5:F{$lastRow}")->getAlignment()->setHorizontal(\PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER);
  $mStart=\PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($moneyStartIndex);
  $mEnd  =\PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($moneyStartIndex+2);
  $ws->getStyle("{$mStart}5:{$mEnd}{$lastRow}")
     ->getNumberFormat()->setFormatCode(\PhpOffice\PhpSpreadsheet\Style\NumberFormat::FORMAT_NUMBER_COMMA_SEPARATED1);
  $ws->getStyle("E4:{$lastCol}{$lastRow}")
     ->getBorders()->getAllBorders()
     ->setBorderStyle(\PhpOffice\PhpSpreadsheet\Style\Border::BORDER_THIN)
     ->getColor()->setARGB('FFBFD4FF');

  $ws->freezePane('E5');
  $ws->setAutoFilter("E4:{$lastCol}{$lastRow}");

  header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
  $fname='PSU_Blue_Cafe_Sales_'.date('Ymd_His').'.xlsx';
  header('Content-Disposition: attachment; filename="'.$fname.'"');
  header('Cache-Control: max-age=0');
  (new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($wb))->save('php://output');
  exit;
}

/* ---------- CSV fallback ---------- */
header('Content-Type: text/csv; charset=utf-8');
$fname='PSU_Blue_Cafe_Sales_'.date('Ymd_His').'.csv';
header('Content-Disposition: attachment; filename="'.$fname.'"');
echo "\xEF\xBB\xBF";
$out=fopen('php://output','w');

fputcsv($out, ["รายงานขายเครื่องดื่ม PSU Blue Café"]);
fputcsv($out, ["ช่วงวันที่", th_full_date($rsObj), "ถึง", th_full_date((clone $reObj)->modify('-1 second'))]);
fputcsv($out, ['รายได้จากท็อปปิง (THB)', number_format($topping_total,2,'.','')]);
fputcsv($out, ['ส่วนลดที่ให้ไป (โปรฯ) (THB)', number_format($discount_total,2,'.','')]);
fputcsv($out, ['ยอดสุทธิสุดท้าย', number_format($tot['final'],2,'.','')]); // ★ รวมท็อปแล้ว
fputcsv($out, []);

$hdr=['หมวดหมู่','รายการ','จำนวน'];
foreach($priceList as $p) $hdr[]='ราคา '.number_format((float)$p,2,'.','');
array_push($hdr,'ส่วนลดโปรฯ','ท็อปปิง','ยอดสุทธิ (Final)');
fputcsv($out,$hdr);

foreach($byCat as $catName=>$items){
  foreach($items as $it){
    $mp=$priceMapPerMenu[$it['id']] ?? ['buckets'=>[]];
    $rowData=[$catName,$it['name'],(int)$it['qty']];
    foreach($priceList as $pkey){ $rowData[]=(int)($mp['buckets'][$pkey]??0); }
    array_push($rowData,
      number_format((float)$it['discount'],2,'.',''),
      number_format((float)$it['topping'],2,'.',''),
      number_format((float)$it['final_show'],2,'.','') // ★ Final แถวนี้คือ base+ท็อปแล้ว
    );
    fputcsv($out,$rowData);
  }
}

$tail=['รวมทั้งหมด','',''];
foreach($priceList as $_) $tail[]='';
array_push($tail,
  number_format($tot['discount'],2,'.',''),
  number_format($tot['topping'],2,'.',''),
  number_format($tot['final'],2,'.','') // ★ รวมท็อปแล้ว
);
fputcsv($out,$tail);

fclose($out);
exit;
