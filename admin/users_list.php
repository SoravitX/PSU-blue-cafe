<?php
// admin/users_list.php — รายชื่อผู้ใช้ทั้งหมด + ค้นหา/กรอง + รวมชั่วโมง
// โทนสีตรงกับหน้า front_store (น้ำเงินเข้ม + ฟ้าหลัก)
// * ไม่เปลี่ยน logic/SQL *

session_start();
if (empty($_SESSION['uid'])) { header("Location: ../index.php"); exit; }

require __DIR__ . '/../db.php';
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$conn->set_charset('utf8mb4');

function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function fmtHM(int $sec): string {
  if ($sec <= 0) return '0:00 ชม.';
  $h = intdiv($sec, 3600);
  $m = intdiv($sec % 3600, 60);
  return $h . ':' . str_pad((string)$m, 2, '0', STR_PAD_LEFT) . ' ชม.';
}

// ===== รับตัวกรอง =====
$q    = trim((string)($_GET['q'] ?? ''));     // ค้นหา ชื่อ/username/student_ID
$role = trim((string)($_GET['role'] ?? ''));  // เลือกบทบาท

// ===== เงื่อนไขค้นหา =====
$where  = '1=1';
$types  = '';
$params = [];

if ($q !== '') {
  $where .= " AND (u.name LIKE ? OR u.username LIKE ? OR u.student_ID LIKE ?)";
  $types .= 'sss';
  $kw = '%'.$q.'%';
  $params[] = $kw; $params[] = $kw; $params[] = $kw;
}
if ($role !== '') {
  $where .= " AND u.role = ?";
  $types .= 's';
  $params[] = $role;
}

// ===== ดึงผู้ใช้ + รวมชั่วโมงจาก attendance (เฉพาะบันทึกที่ปิดงานแล้ว) =====
$sql = "
  SELECT
    u.user_id, u.username, u.student_ID, u.name, u.role, u.status,
    COALESCE(SUM(CASE
      WHEN a.time_out <> '00:00:00' AND a.hour_type = 'fund'
      THEN TIMESTAMPDIFF(SECOND, CONCAT(a.date_in,' ',a.time_in), CONCAT(a.date_out,' ',a.time_out))
      ELSE 0 END
    ),0) AS sec_fund,
    COALESCE(SUM(CASE
      WHEN a.time_out <> '00:00:00' AND a.hour_type = 'normal'
      THEN TIMESTAMPDIFF(SECOND, CONCAT(a.date_in,' ',a.time_in), CONCAT(a.date_out,' ',a.time_out))
      ELSE 0 END
    ),0) AS sec_normal
  FROM users u
  LEFT JOIN attendance a ON a.user_id = u.user_id
  WHERE $where
  GROUP BY u.user_id, u.username, u.student_ID, u.name, u.role, u.status
  ORDER BY u.name ASC, u.student_ID ASC, u.username ASC
";

if ($types !== '') {
  $stmt = $conn->prepare($sql);
  $stmt->bind_param($types, ...$params);
  $stmt->execute();
  $users = $stmt->get_result();
  $stmt->close();
} else {
  $users = $conn->query($sql);
}
?>
<!doctype html>
<html lang="th">
<head>
<meta charset="utf-8">
<title>PSU Blue Cafe • รายชื่อผู้ใช้ทั้งหมด</title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<link rel="stylesheet" href="https://stackpath.bootstrapcdn.com/bootstrap/4.5.2/css/bootstrap.min.css">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css">
<style>
/* ================= FRONT_STORE TONE ================= */
:root{
  --bg1:#11161b; --bg2:#141b22;
  --surface:#1a2230; --surface-2:#192231; --surface-3:#202a3a;
  --text-strong:#ffffff; --text-normal:#e9eef6; --text-muted:#b9c6d6;
  --brand-500:#3aa3ff; --brand-400:#7cbcfd; --brand-300:#a9cffd;
  --ok:#22c55e; --danger:#e53935;
  --radius:10px;
}

html,body{height:100%}
body{
  margin:0;
  background: linear-gradient(180deg,var(--bg1),var(--bg2));
  color:var(--text-normal);
  font-family:"Segoe UI",Tahoma,Arial,sans-serif;
}
.container-xl{max-width:1400px}

/* ---------- Topbar ---------- */
.topbar{
  position:sticky; top:0; z-index:50; padding:12px 16px; border-radius:var(--radius);
  background:var(--surface); border:1px solid rgba(255,255,255,.08);
}
.brand{font-weight:900; letter-spacing:.3px; color:var(--text-strong)}
.badge-user{
  background: var(--surface-3);
  color: var(--text-strong);
  font-weight:800; border-radius:999px; border:1px solid rgba(255,255,255,.12)
}
.searchbox{
  background: var(--surface-3);
  border:1px solid rgba(255,255,255,.12);
  color:var(--text-normal);
  border-radius:999px; padding:.45rem .9rem; min-width:260px
}
.searchbox::placeholder{ color:#90a3b6 }
.searchbox:focus{ box-shadow:none; border-color:#6aaeff; color:var(--text-strong) }

.btn-ghost{
  background: var(--brand-500);
  border:1px solid #1e6acc; color:#fff; font-weight:800; border-radius:12px;
}
.topbar .btn-primary{
  background: var(--brand-500);
  border:1px solid #1e6acc; font-weight:800; color:#fff;
}
.topbar .btn-light{
  background: var(--surface-3); color:var(--text-strong); border:1px solid rgba(255,255,255,.14); font-weight:800;
}
.btn-outline-light{ color:#eaf2ff; border-color: rgba(255,255,255,.18) }
.btn-outline-light:hover{ background: var(--surface-3); color:#fff }

/* ---------- Card & Table ---------- */
.cardx{
  background: var(--surface);
  color:var(--text-normal);
  border:1px solid rgba(255,255,255,.08);
  border-radius:var(--radius);
}
.table-wrap{ max-height: 68vh; overflow:auto; border-radius:12px }

/* table header sticky + dark */
.table thead th{
  position: sticky; top: 0; z-index: 1;
  background: var(--surface-2);
  color: var(--text-strong);
  border-bottom:1px solid rgba(255,255,255,.10);
  font-weight:800;
}
.table{ color:var(--text-normal); }
.table td, .table th{
  border-color: rgba(255,255,255,.10) !important;
  vertical-align: middle !important;
}
.table tbody tr:nth-child(odd){ background: #1c2432; }
.table tbody tr:nth-child(even){ background: #19212d; }
.table tbody tr:hover td{ background:#223044; color:var(--text-strong) }

/* ---------- Badges ---------- */
.badge-role{
  padding:.35rem .6rem; border-radius:999px; font-weight:800;
  background: var(--surface-3); color:#eaf6ff; border:1px solid rgba(255,255,255,.14)
}
.badge-status{padding:.35rem .6rem; border-radius:999px; font-weight:800}
/* ✅ สีตามที่ขอ */
.badge-status-fund{
  background:#22c55e; color:#ffffff; border:1px solid #16a34a;
}
.badge-status-norm{
  background:#3aa3ff; color:#ffffff; border:1px solid #1e6acc;
}

/* ---------- Helper row ---------- */
.legend{
  display:flex; align-items:center; gap:10px; flex-wrap:wrap;
  color:var(--text-strong);
  background: var(--surface-2);
  border:1px solid rgba(255,255,255,.10); border-radius:12px; padding:8px 12px; font-weight:700
}
.legend .text-muted{ color:var(--text-muted) !important }
.dot{width:10px; height:10px; border-radius:50%}
.dot-fund{background:#22c55e} .dot-norm{background:#3aa3ff}

/* ---------- Small utilities ---------- */
.kpi-chip{
  display:inline-flex; align-items:center; gap:6px;
  background: var(--surface-3); color:#eaf6ff; border:1px solid rgba(255,255,255,.14);
  border-radius:999px; padding:6px 10px; font-weight:800
}

/* Focus ring */
:focus-visible{ outline:3px solid rgba(58,163,255,.55); outline-offset:3px; border-radius:10px }

/* Scrollbar */
*::-webkit-scrollbar{width:10px;height:10px}
*::-webkit-scrollbar-thumb{background:#2a3442;border-radius:10px}
*::-webkit-scrollbar-thumb:hover{background:#334559}
*::-webkit-scrollbar-track{background:#131923}

/* === Admin button: Front_Store blue === */
.btn-admin{
  background: linear-gradient(180deg, #56b2ff, #3aa3ff);
  border: 1px solid #1e6acc;
  color:#fff !important;
  font-weight:900;
  border-radius:12px;
  padding:.35rem .8rem;
  box-shadow:0 8px 22px rgba(58,163,255,.28);
}
.btn-admin .bi{ margin-right:.35rem; }
.btn-admin:hover{ filter:brightness(1.05); transform:translateY(-1px); color:#fff !important; }
.btn-admin:focus{ outline:3px solid rgba(58,163,255,.35); outline-offset:2px; }

/* ❌ ลบ avatar ออก — ไม่ใช้แล้ว */
/* === Action buttons: ชัดเจนบนพื้นเข้ม === */
.btn-edit{
  background: linear-gradient(180deg,#f6ad55,#f59e0b);
  border:1px solid #d97706;
  color:#0b0f16 !important;          /* ตัวอักษรเข้ม อ่านง่ายบนปุ่มสีส้ม */
  font-weight:900;
  border-radius:10px;
  padding:.35rem .7rem;
  box-shadow:0 8px 18px rgba(0,0,0,.25);
}
.btn-edit:hover{ filter:brightness(1.05); transform:translateY(-1px); }
.btn-edit:focus{ outline:3px solid rgba(245,158,11,.45); outline-offset:2px; }

.btn-hours{
  background: linear-gradient(180deg,#a78bfa,#8b5cf6);
  border:1px solid #6d28d9;
  color:#ffffff !important;          /* ตัวอักษรขาวบนพื้นม่วง */
  font-weight:900;
  border-radius:10px;
  padding:.35rem .7rem;
  box-shadow:0 8px 18px rgba(0,0,0,.25);
}
.btn-hours:hover{ filter:brightness(1.05); transform:translateY(-1px); }
.btn-hours:focus{ outline:3px solid rgba(139,92,246,.45); outline-offset:2px; }

</style>
</head>
<body>
<div class="container-xl py-3">

  <!-- Topbar -->
  <div class="topbar d-flex align-items-center justify-content-between mb-3">
    <div class="d-flex align-items-center">
      <h4 class="brand mb-0 mr-3"><i class="bi bi-people"></i> ผู้ใช้ทั้งหมด</h4>
      <form class="form-inline" method="get">
        <input name="q" class="form-control form-control-sm searchbox mr-2"
               value="<?= h($q) ?>" type="search"
               placeholder="ค้นหา: ชื่อ / username / student_ID" aria-label="ค้นหา">
        <select name="role" class="form-control form-control-sm mr-2" style="background:var(--surface-3);color:var(--text-normal);border:1px solid rgba(255,255,255,.12)">
          <option value="">ทุกบทบาท</option>
          <?php
            $roles = ['admin','employee'];
            foreach($roles as $r){
              $sel = ($role===$r)?'selected':''; ?>
              <option value="<?= h($r) ?>" <?= $sel ?>><?= h($r) ?></option>
          <?php } ?>
        </select>
        <button class="btn btn-sm btn-ghost"><i class="bi bi-search"></i> ค้นหา</button>
      </form>
    </div>
    <div class="d-flex align-items-center">
      <a href="add_user.php" class="btn btn-primary btn-sm mr-2"><i class="bi bi-person-plus"></i> เพิ่มผู้ใช้</a>
      <a href="adminmenu.php" class="btn btn-admin btn-sm mr-2">
        <i class="bi bi-gear"></i> เมนูเเอดมิน
      </a>
      <a class="btn btn-sm btn-outline-light" href="../logout.php"><i class="bi bi-box-arrow-right"></i> ออกจากระบบ</a>
    </div>
  </div>

  <!-- Legend + Quick KPI -->
  <div class="cardx p-2 mb-2">
    <div class="d-flex align-items-center justify-content-between flex-wrap">
      <div class="legend mb-2 mb-sm-0">
        <span>สถานะเวลา:</span>
        <span class="dot dot-norm"></span> ชั่วโมงปกติ
        <span class="dot dot-fund ml-2"></span> ชั่วโมงทุน
        <span class="ml-3 text-muted" style="font-weight:600">*รวมเฉพาะรายการที่ปิดงานแล้ว</span>
      </div>
      <div class="kpi-chip">
        <i class="bi bi-people"></i>
        รวมผู้ใช้ที่ตรงเงื่อนไข:
        <strong class="ml-1">
          <?= isset($users) && $users instanceof mysqli_result ? (int)$users->num_rows : 0 ?>
        </strong>
      </div>
    </div>
  </div>

  <!-- Table -->
  <div class="cardx p-3">
    <div class="table-wrap">
      <table class="table table-sm mb-0">
        <thead>
          <tr>
            <!-- ✅ ย้าย Username มาอยู่หน้าสุด และลบ avatar ออก -->
            <th style="min-width:150px">Username</th>
            <th style="min-width:220px">ชื่อ-นามสกุล</th>
            <th style="min-width:130px">Student ID</th>
            <th style="min-width:110px">Role</th>
            <th style="min-width:120px">Status</th>
            <th class="text-right" style="min-width:130px">ชั่วโมงปกติ</th>
            <th class="text-right" style="min-width:130px">ชั่วโมงทุน</th>
            <th class="text-right" style="min-width:130px">รวมทั้งหมด</th>
            
          
          </tr>
        </thead>
        <tbody>
        <?php if ($users && $users->num_rows > 0): ?>
          <?php while($u = $users->fetch_assoc()):
            $sec_fund   = (int)($u['sec_fund'] ?? 0);
            $sec_normal = (int)($u['sec_normal'] ?? 0);
            $sec_total  = $sec_fund + $sec_normal;
          ?>
            <tr>
              <!-- username first -->
              <td><?= h($u['username']) ?></td>
              <!-- แสดงเฉพาะชื่อ (ไม่มี avatar ตัวอักษรเดี่ยวแล้ว) -->
              <td><?= h($u['name']) ?></td>
              <td><?= h($u['student_ID'] ?? '') ?></td>
              <td><span class="badge-role"><?= h($u['role'] ?: '-') ?></span></td>
              <td>
                <?php if($u['status']==='ชั่วโมงทุน'): ?>
                  <span class="badge-status badge-status-fund">ชั่วโมงทุน</span>
                <?php else: ?>
                  <span class="badge-status badge-status-norm">ชั่วโมงปกติ</span>
                <?php endif; ?>
              </td>
              <!-- ✅ สีตามที่ขอ: ปกติ=ฟ้า, ทุน=เขียว (สีอยู่ใน CSS ของ badge status แล้ว) -->
              <td class="text-right"><?= fmtHM($sec_normal) ?></td>
              <td class="text-right"><?= fmtHM($sec_fund) ?></td>
              <td class="text-right"><strong><?= fmtHM($sec_total) ?></strong></td>
              
              <td class="text-right">
<a class="btn btn-edit btn-sm mr-1" href="edit_user.php?id=<?= (int)$u['user_id'] ?>">
  <i class="bi bi-pencil-square"></i> แก้ไข
</a>
<a class="btn btn-hours btn-sm" href="user_detail.php?id=<?= (int)$u['user_id'] ?>">
  <i class="bi bi-clock-history"></i> ชั่วโมง
</a>

              </td>
            </tr>
          <?php endwhile; ?>
        <?php else: ?>
          <tr><td colspan="9" class="text-center" style="color:var(--text-muted)">ไม่พบผู้ใช้</td></tr>
        <?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>

</div>

<!-- UX: โฟกัสช่องค้นหาเร็วด้วยปุ่ม / -->
<script>
document.addEventListener('keydown', e=>{
  if(e.key === '/'){
    const q = document.querySelector('input[name="q"]');
    if(q){ e.preventDefault(); q.focus(); q.select(); }
  }
});
</script>
</body>
</html>
