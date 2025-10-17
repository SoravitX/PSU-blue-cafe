<?php
// admin/user_detail.php — โปรไฟล์ชั่วโมงทำงาน (ธีมเดียวกับ user_profile.php)
declare(strict_types=1);
session_start();
if (empty($_SESSION['uid'])) { header("Location: ../index.php"); exit; }

require __DIR__ . '/../db.php';
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$conn->set_charset('utf8mb4');

// เวลาไทย PHP/MySQL
date_default_timezone_set('Asia/Bangkok');
$conn->query("SET time_zone = '+07:00'");

// เรทชั่วโมงปกติ
$NORMAL_RATE = 25.00; // บาท/ชม.

function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function fmtHM(int $sec): string {
  if ($sec <= 0) return '0:00 ชม.';
  $h = intdiv($sec, 3600);
  $m = intdiv($sec % 3600, 60);
  return $h . ':' . str_pad((string)$m, 2, '0', STR_PAD_LEFT) . ' ชม.';
}
function fmtMoney(float $n): string { return number_format($n, 2); }

// ===== Params =====
$uid = (int)($_GET['id'] ?? 0);
if ($uid <= 0) { header('Location: users_list.php'); exit; }

$today  = (new DateTime('today'))->format('Y-m-d');
$month1 = (new DateTime('first day of this month'))->format('Y-m-d');

$start_date = ($_GET['start'] ?? '') ?: $month1;
$end_date   = ($_GET['end']   ?? '') ?: $today;

// ===== User =====
$stmt = $conn->prepare("SELECT user_id, username, name, student_ID, role, status FROM users WHERE user_id=? LIMIT 1");
$stmt->bind_param('i', $uid);
$stmt->execute();
$user = $stmt->get_result()->fetch_assoc();
$stmt->close();
if (!$user) { header('Location: users_list.php'); exit; }

// ===== Sum by hour_type (เฉพาะปิดงานแล้ว) =====
$sqlSum = "
  SELECT hour_type,
         COALESCE(SUM(
           TIMESTAMPDIFF(SECOND,
             CONCAT(date_in,' ',time_in),
             CONCAT(date_out,' ',time_out)
           )
         ),0) AS sec_total
  FROM attendance
  WHERE user_id = ?
    AND time_out <> '00:00:00'
    AND date_in >= ?
    AND date_in <= ?
  GROUP BY hour_type
";
$stmt = $conn->prepare($sqlSum);
$stmt->bind_param('iss', $uid, $start_date, $end_date);
$stmt->execute();
$res = $stmt->get_result();

$sec_fund = 0;
$sec_normal = 0;
while ($r = $res->fetch_assoc()) {
  if ($r['hour_type'] === 'fund')   $sec_fund   = (int)$r['sec_total'];
  if ($r['hour_type'] === 'normal') $sec_normal = (int)$r['sec_total'];
}
$stmt->close();

$hrs_normal_decimal = round($sec_normal / 3600, 2);
$hrs_fund_decimal   = round($sec_fund   / 3600, 2);
$wage_normal        = $hrs_normal_decimal * $NORMAL_RATE;

// ===== Logs (ทั้งหมดในช่วงที่เลือก) =====
$sqlLogs = "
  SELECT attendance_id, date_in, time_in, date_out, time_out, hour_type
  FROM attendance
  WHERE user_id = ?
    AND date_in >= ?
    AND date_in <= ?
  ORDER BY attendance_id DESC
";
$stmt = $conn->prepare($sqlLogs);
$stmt->bind_param('iss', $uid, $start_date, $end_date);
$stmt->execute();
$logs = $stmt->get_result();
$stmt->close();
?>
<!doctype html>
<html lang="th">
<head>
<meta charset="utf-8">
<title>รายละเอียดผู้ใช้ • ชั่วโมงทำงาน</title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<link rel="stylesheet"
 href="https://stackpath.bootstrapcdn.com/bootstrap/4.5.2/css/bootstrap.min.css">
<link rel="stylesheet"
 href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css">
<style>
/* ============================
   PSU Blue Café • Minimal/Clean Dark (เหมือน user_profile.php)
   ============================ */
:root{
  /* พื้นหลังหลัก */
  --bg-grad1:#11161B;
  --bg-grad2:#141B22;

  /* พื้นผิว */
  --surface:#1A2230;
  --surface-2:#192231;
  --surface-3:#202A3A;

  /* ตัวอักษร */
  --ink:#E9EEF6;
  --ink-muted:#B9C6D6;
  --text-strong:#FFFFFF;

  /* แบรนด์ฟ้า */
  --brand-500:#3AA3FF;
  --brand-400:#7CBCFD;
  --brand-300:#A9CFFD;
  --brand-border:#1E6ACC;

  /* ชั่วโมงทุน (เขียว) */
  --fund-500:#22C55E;
  --fund-border:#15803D;

  /* ชั่วโมงปกติ (ฟ้า gradient) */
  --normal-500:#2EA7FF;
  --normal-600:#1F7EE8;
  --normal-border:#1669C9;

  --warn:#f0ad4e;
  --radius:10px;
}

/* พื้นหลัง/ฟอนต์รวม */
html,body{height:100%}
body{
  background: linear-gradient(180deg,var(--bg-grad1),var(--bg-grad2)) !important;
  color: var(--ink) !important;
  font-family:"Segoe UI",Tahoma,Arial,sans-serif;
  letter-spacing:.1px;
}

/* คอนเทนเนอร์ */
.wrap{ max-width:1100px; margin:26px auto; padding:0 14px; }

/* Topbar */
.topbar{
  position:sticky; top:0; z-index:20;
  background: var(--surface) !important;
  border: 1px solid rgba(255,255,255,.08) !important;
  border-radius: 14px !important;
  box-shadow: none !important;
  padding:12px 16px;
}
.brand{font-weight:900; letter-spacing:.3px; color:var(--text-strong)}
.badge-chip{
  background: #2EA7FF !important;
  color: #082238 !important;
  border: 1px solid var(--normal-border) !important;
  border-radius: 999px !important;
  padding:.25rem .6rem; font-weight:800 !important;
}
.topbar small .badge-chip.status{
  background: linear-gradient(180deg, var(--normal-500), var(--normal-600)) !important;
  border-color: var(--normal-border) !important;
  color:#fff !important;
}

/* นาฬิกาแคปซูล */
.clock{
  display:inline-flex;align-items:center;gap:8px;
  background: var(--surface-3) !important;
  border: 1px solid rgba(255,255,255,.12) !important;
  border-radius:999px; padding:6px 10px; font-weight:800;
  color: var(--ink);
}
.clock .bi{opacity:.9}

/* ปุ่ม */
.btn-primary{
  background: var(--brand-500) !important;
  border: 1px solid var(--brand-border) !important;
  color:#fff !important;
  font-weight:800 !important;
  box-shadow:none !important;
  text-shadow:none !important;
  border-radius: var(--radius) !important;
}
.btn-primary:hover{ filter:brightness(1.06) }
.btn-outline-light{
  background: transparent !important;
  color: var(--ink) !important;
  border: 1px solid rgba(255,255,255,.18) !important;
  box-shadow:none !important;
  border-radius: var(--radius) !important;
}

/* การ์ด/หัวท้าย */
.cardx{
  background: var(--surface) !important;
  border: 1px solid rgba(255,255,255,.08) !important;
  border-radius: var(--radius) !important;
  box-shadow: none !important;
}
.co-head,.co-foot{ background: var(--surface-2) !important; }

/* อินพุตวันที่ */
input[type="date"]{
  background: var(--surface-2) !important;
  color: var(--ink) !important;
  border: 1px solid rgba(255,255,255,.10) !important;
  border-radius: 10px !important;
}

/* KPI tiles */
.kpi{ display:grid; grid-template-columns: repeat(3,minmax(220px,1fr)); gap:12px; }
.tile{
  background: var(--surface-2) !important;
  border: 1px solid rgba(255,255,255,.10) !important;
  border-radius: 14px !important;
  box-shadow: none !important;
  padding:14px 16px;
}
.tile .l{ font-weight:800; color:var(--text-strong); display:flex; align-items:center; gap:8px }
.tile .n{ font-size:1.7rem; font-weight:900; color:#fff !important; line-height:1.2 }

/* สีพิเศษให้สอดคล้องกับ user_profile */
.tile-fund{
  background: linear-gradient(180deg, var(--fund-500), #16A34A) !important;
  border-color: var(--fund-border) !important;
}
.tile-normal{
  background: linear-gradient(180deg, var(--normal-500), var(--normal-600)) !important;
  border-color: var(--normal-border) !important;
}
.tile-fund .l, .tile-fund .n, .tile-normal .l, .tile-normal .n { color:#fff !important; }

/* pill */
.pill{display:inline-flex;align-items:center;gap:6px;border-radius:999px;padding:.15rem .6rem;font-weight:800}
.pill-fund{
  background: var(--fund-500) !important;
  border: 1.5px solid var(--fund-border) !important;
  color:#fff !important;
}
.pill-normal{
  background: var(--brand-500) !important;
  border: 1.5px solid var(--brand-border) !important;
  color:#fff !important;
}

/* ตาราง */
.table thead th{
  background: var(--surface-2) !important;
  color:#fff !important;
  border-bottom: 1px solid rgba(255,255,255,.10) !important;
  font-weight:800;
}
.table td,.table th{
  border-color: rgba(255,255,255,.08) !important;
  color: var(--ink) !important;
}

/* ขนาดตัวอักษรให้เสมอ ๆ */
body, .table, .btn, input, label, .badge, .pill{ font-size:14.5px !important; }

/* Toggle บันทึก (ซ่อนเริ่มต้น) */
#logsCard.is-hidden #logsBody{ display:none; }
#toggleLogsBtn .bi{ margin-right:4px; }
</style>
</head>
<body>
<div class="wrap">

  <!-- Topbar -->
  <div class="topbar d-flex align-items-center justify-content-between mb-3">
    <div>
      <div class="h5 m-0 brand"><i class="bi bi-person-badge"></i> ชั่วโมงทำงาน</div>
      <small class="text-light">
        <i class="bi bi-person"></i> ผู้ใช้:
        <span class="badge-chip"><?= h($user['username']) ?></span>
        &nbsp;•&nbsp;<i class="bi bi-pass"></i> รหัสนักศึกษา:
        <span class="badge-chip"><?= h($user['student_ID'] ?: '-') ?></span>
        &nbsp;•&nbsp;<i class=""></i> สถานะ:
        <span class="badge-chip status"><?= h($user['status'] ?: '-') ?></span>
      </small>
    </div>
    <div class="d-flex align-items-center" style="gap:8px">
      <div class="clock"><i class="bi bi-clock"></i><span id="nowClock">--:--:--</span></div>
      <a href="users_list.php" class="btn btn-sm btn-outline-light"><i class="bi bi-people"></i> รายชื่อผู้ใช้</a>
      <a href="adminmenu.php" class="btn btn-sm btn-outline-light"><i class="bi bi-gear"></i> หน้า Admin</a>
    </div>
  </div>

  <!-- Filter -->
  <div class="cardx p-3 mb-3">
    <form class="form-inline" method="get">
      <input type="hidden" name="id" value="<?= (int)$uid ?>">
      <label class="mr-2" style="color:var(--text-strong)"><i class="bi bi-calendar2-week"></i> ช่วงวันที่</label>
      <input type="date" name="start" class="form-control mr-2" value="<?= h($start_date) ?>">
      <span class="mr-2">ถึง</span>
      <input type="date" name="end" class="form-control mr-2" value="<?= h($end_date) ?>">
      <button class="btn btn-primary"><i class="bi bi-search"></i> แสดง</button>
      
    </form>
  </div>

  <!-- KPI tiles -->
  <div class="kpi mb-3">
    <div class="tile tile-fund">
      <div class="l"><i class="bi bi-mortarboard"></i> ชั่วโมงทุน (เฉพาะที่ปิดงานแล้ว)</div>
      <div class="n"><?= fmtHM($sec_fund) ?></div>
    </div>

    <div class="tile tile-normal">
      <div class="l"><i class="bi bi-briefcase"></i> ชั่วโมงปกติ (เฉพาะที่ปิดงานแล้ว)</div>
      <div class="n"><?= fmtHM($sec_normal) ?></div>
    </div>

    <div class="tile">
      <div class="l"><i class="bi bi-cash-coin"></i> ค่าตอบแทนชั่วโมงปกติ</div>
      <div class="n"><?= fmtMoney($wage_normal) ?> ฿</div>
      <div class="small mt-1" style="color:var(--ink-muted)"><i class="bi bi-info-circle"></i> อัตรา: <?= fmtMoney($NORMAL_RATE) ?> ฿/ชม.</div>
    </div>
  </div>

  <!-- Logs -->
  <div class="cardx p-3 mb-4" id="logsCard">
    <div class="d-flex align-items-center justify-content-between mb-2">
      <h6 class="m-0" style="color:var(--text-strong); font-weight:800"><i class="bi bi-journal-text"></i> บันทึกเวลาในช่วงที่เลือก</h6>
      <div class="d-flex align-items-center" style="gap:8px">
        <button id="toggleLogsBtn" type="button" class="btn btn-sm btn-outline-light" style="font-weight:800">
          <i class="bi bi-eye"></i> แสดงบันทึก
        </button>
      </div>
    </div>

    <div id="logsBody">
      <div class="table-responsive">
        <table class="table table-sm mb-0">
          <thead>
            <tr>
              <th style="width:140px"><i class="bi bi-calendar-event"></i> วันที่เข้า</th>
              <th style="width:110px"><i class="bi bi-alarm"></i> เวลาเข้า</th>
              <th style="width:140px"><i class="bi bi-calendar2-event"></i> วันที่ออก</th>
              <th style="width:110px"><i class="bi bi-alarm"></i> เวลาออก</th>
              <th style="width:130px"><i class="bi bi-tag"></i> ชนิดชั่วโมง</th>
              <th class="text-right" style="width:140px"><i class="bi bi-stopwatch"></i> ชั่วโมงรวม</th>
            </tr>
          </thead>
          <tbody>
            <?php if($logs->num_rows === 0): ?>
              <tr><td colspan="6" class="text-center" style="color:var(--ink-muted)"><i class="bi bi-emoji-neutral"></i> ไม่มีข้อมูลในช่วงที่เลือก</td></tr>
            <?php else: ?>
              <?php while($r = $logs->fetch_assoc()):
                $dur = '-';
                if (trim($r['time_out']) !== '00:00:00') {
                  $s = strtotime($r['date_in'].' '.$r['time_in']);
                  $e = strtotime($r['date_out'].' '.$r['time_out']);
                  if ($s && $e && $e >= $s) { $dur = fmtHM($e - $s); }
                }
                $pill = $r['hour_type']==='fund' ? 'pill pill-fund' : 'pill pill-normal';
                $lbl  = $r['hour_type']==='fund' ? 'ชั่วโมงทุน'   : 'ชั่วโมงปกติ';
              ?>
                <tr>
                  <td><?= h($r['date_in']) ?></td>
                  <td><?= h($r['time_in']) ?></td>
                  <td><?= h($r['date_out']) ?></td>
                  <td><?= h($r['time_out']) ?></td>
                  <td><span class="<?= $pill ?>"><i class="bi bi-tags"></i> <?= h($lbl) ?></span></td>
                  <td class="text-right"><?= h($dur) ?></td>
                </tr>
              <?php endwhile; ?>
            <?php endif; ?>
          </tbody>
        </table>
      </div>
    </div> <!-- /#logsBody -->
  </div>   <!-- /#logsCard -->

</div>

<!-- นาฬิกาแบบสด -->
<script>
(function(){
  const el = document.getElementById('nowClock');
  if(!el) return;
  function pad(n){return (n<10?'0':'')+n}
  function tick(){
    const d = new Date();
    el.textContent = [pad(d.getHours()),pad(d.getMinutes()),pad(d.getSeconds())].join(':');
  }
  tick(); setInterval(tick, 1000);
})();
</script>

<!-- Toggle แสดง/ซ่อนบันทึก (ค่าเริ่มต้น = ซ่อน) -->
<script>
(function(){
  const KEY = 'psu.userdetail.logs.hidden'; // แยกคีย์จาก user_profile
  const card = document.getElementById('logsCard');
  const body = document.getElementById('logsBody');
  const btn  = document.getElementById('toggleLogsBtn');
  if(!card || !body || !btn) return;

  function apply(hidden){
    card.classList.toggle('is-hidden', hidden);
    btn.innerHTML = hidden
      ? '<i class="bi bi-eye"></i> แสดงบันทึก'
      : '<i class="bi bi-eye-slash"></i> ซ่อนบันทึก';
    btn.setAttribute('aria-expanded', String(!hidden));
  }

  let saved = null;
  try { saved = localStorage.getItem(KEY); } catch(e){}
  const initialHidden = (saved === null) ? true : (saved === '1');
  apply(initialHidden);

  btn.addEventListener('click', () => {
    const willHide = !card.classList.contains('is-hidden');
    apply(willHide);
    try { localStorage.setItem(KEY, willHide ? '1' : '0'); } catch(e){}
  });
})();
</script>
</body>
</html>
