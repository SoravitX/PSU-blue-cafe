<?php declare(strict_types=1);
// admin/add_user.php — สร้างผู้ใช้ใหม่ • โทนเดียวกับหน้าอื่น (front_store) + input ตัวอักษรสีขาว
session_start();
require __DIR__ . '/../db.php';
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$conn->set_charset('utf8mb4');

/* (แนะนำ) จำกัดสิทธิ์เฉพาะ admin */
$allow_roles = ['admin'];
if (!empty($_SESSION['role']) && !in_array($_SESSION['role'], $allow_roles, true)) {
  header('Location: ../index.php'); exit;
}

$err = '';

// ตัวเลือกบทบาท
$roles = [
  
  'front store'=> 'หน้าร้าน',
  'back store'    => 'หลังร้าน',
  
];

// ตัวเลือกสถานะชั่วโมง
$status_options = ['ชั่วโมงทุน','ชั่วโมงปกติ'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $student_ID = trim((string)($_POST['student_id'] ?? ''));
  $username   = trim((string)($_POST['username'] ?? ''));
  $name       = trim((string)($_POST['name'] ?? ''));
  $role       = (string)($_POST['role'] ?? 'employee');
  $status     = (string)($_POST['status'] ?? 'ชั่วโมงปกติ');
  $pass       = (string)($_POST['password'] ?? '');
  $pass2      = (string)($_POST['password2'] ?? '');

  // validate
  if ($student_ID === '' || $username === '' || $name === '' || $pass === '' || $pass2 === '') {
    $err = 'กรอกข้อมูลให้ครบ';
  } elseif ($pass !== $pass2) {
    $err = 'รหัสผ่านไม่ตรงกัน';
  } elseif (!isset($roles[$role])) {
    $err = 'บทบาทไม่ถูกต้อง';
  } elseif (!in_array($status, $status_options, true)) {
    $err = 'สถานะไม่ถูกต้อง';
  } else {
    // ตรวจซ้ำ username
    $stmt = $conn->prepare("SELECT 1 FROM users WHERE username=? LIMIT 1");
    $stmt->bind_param("s", $username);
    $stmt->execute();
    $dupUser = (bool)$stmt->get_result()->fetch_row();
    $stmt->close();

    // ตรวจซ้ำ student_ID
    $stmt = $conn->prepare("SELECT 1 FROM users WHERE student_ID=? LIMIT 1");
    $stmt->bind_param("s", $student_ID);
    $stmt->execute();
    $dupSID = (bool)$stmt->get_result()->fetch_row();
    $stmt->close();

    if ($dupUser) {
      $err = 'มีชื่อผู้ใช้นี้อยู่แล้ว';
    } elseif ($dupSID) {
      $err = 'มีรหัสนักศึกษานี้อยู่แล้ว';
    } else {
      $hash = password_hash($pass, PASSWORD_DEFAULT);
      $stmt = $conn->prepare("
        INSERT INTO users (username, password, student_ID, name, role, status)
        VALUES (?, ?, ?, ?, ?, ?)
      ");
      $stmt->bind_param("ssssss", $username, $hash, $student_ID, $name, $role, $status);
      $stmt->execute();
      $stmt->close();
      header("Location: users_list.php?msg=added");
      exit;
    }
  }
}
?>
<!doctype html>
<html lang="th">
<head>
<meta charset="utf-8">
<title>สร้างผู้ใช้ใหม่ • PSU Blue Cafe</title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<link rel="stylesheet" href="https://stackpath.bootstrapcdn.com/bootstrap/4.5.2/css/bootstrap.min.css">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css">
<style>
/* ================= FRONT_STORE THEME (เหมือนหน้าอื่น) ================= */
:root{
  /* พื้นหลัง */
  --bg1:#11161b; --bg2:#141b22;

  /* พื้นผิว */
  --surface:#1a2230; --surface-2:#192231; --surface-3:#202a3a;

  /* ตัวอักษร */
  --text-strong:#ffffff;
  --text-normal:#e9eef6;
  --text-muted:#b9c6d6;

  /* ฟ้าแบรนด์ */
  --brand-500:#3aa3ff;
  --brand-400:#7cbcfd;
  --brand-300:#a9cffd;

  /* สถานะ */
  --ok:#22c55e; --danger:#e53935;

  --radius:12px;
}

html,body{height:100%}
body{
  margin:0;
  background: linear-gradient(180deg,var(--bg1),var(--bg2));
  color:var(--text-normal);
  font-family:"Segoe UI",Tahoma,Arial,sans-serif;
}
.container-narrow{max-width:960px;margin:20px auto;padding:0 14px}

/* ---------- Topbar ---------- */
.topbar{
  position:sticky; top:0; z-index:50; padding:12px 16px; border-radius:var(--radius);
  background:var(--surface); border:1px solid rgba(255,255,255,.08);
}
.brand{font-weight:900; letter-spacing:.3px; color:var(--text-strong)}
.badge-tag{
  background: var(--surface-3);
  color: var(--text-strong);
  font-weight:800; border-radius:999px; border:1px solid rgba(255,255,255,.12);
  padding:.35rem .7rem;
}
.topbar .btn{
  border-radius:12px; font-weight:800;
  background: var(--surface-3); color:var(--text-strong);
  border:1px solid rgba(255,255,255,.14);
}
.topbar .btn:hover{ filter:brightness(1.05) }

/* ---------- Card ---------- */
.cardx{
  background: var(--surface);
  color:var(--text-normal);
  border:1px solid rgba(255,255,255,.08);
  border-radius:var(--radius);
}

/* ---------- Form ---------- */
label{ font-weight:800; color:var(--text-strong) }
.form-control, .custom-select{
  background: var(--surface-3) !important;
  color: var(--text-strong) !important;     /* ตัวอักษรตอนพิมพ์เป็นสีขาว */
  border:1px solid rgba(255,255,255,.12) !important;
  border-radius:12px !important;
}
.form-control::placeholder{ color:#90a3b6 !important; opacity:1 }
.form-control:focus, .custom-select:focus{
  box-shadow:none !important;
  border-color:#1e6acc !important;
}
select option{
  background: var(--surface-3);
  color: var(--text-strong);
}
input, select, textarea{
  color: var(--text-strong) !important;     /* ย้ำอีกชั้น */
  caret-color:#fff;
}

/* รองรับ autofill ให้ตัวอักษรยังเป็นสีขาว */
input:-webkit-autofill,
select:-webkit-autofill,
textarea:-webkit-autofill{
  -webkit-text-fill-color: var(--text-strong) !important;
  box-shadow: 0 0 0px 1000px var(--surface-3) inset !important;
  -webkit-box-shadow: 0 0 0px 1000px var(--surface-3) inset !important;
  transition: background-color 9999s ease-in-out 0s;
}

/* ---------- Buttons ---------- */
.btn-primary{
  background: linear-gradient(180deg, #56b2ff, var(--brand-500)) !important;
  border:1px solid #1e6acc !important;
  color:#fff !important; font-weight:900 !important; border-radius:12px !important;
  box-shadow:0 8px 22px rgba(58,163,255,.28);
}
.btn-primary:hover{ filter:brightness(1.05) }
.btn-light{
  background: transparent !important; color: var(--text-strong) !important;
  border:1px solid rgba(255,255,255,.18) !important; border-radius:12px !important; font-weight:800 !important;
}
.btn-outline-light{
  color:#eaf2ff !important; border-color: rgba(255,255,255,.18) !important; border-radius:12px !important; font-weight:800 !important;
}
.btn-outline-light:hover{ background: var(--surface-3) !important; color:#fff !important }

/* ---------- Alert ---------- */
.alert{
  border-radius:12px; font-weight:700;
  background:rgba(229,57,53,.12); color:#ffc9c9; border-color:rgba(229,57,53,.35)
}

/* ---------- Helpers ---------- */
.hr-soft{ border-top:1px solid rgba(255,255,255,.12) }
:focus-visible{ outline:3px solid rgba(58,163,255,.35); outline-offset:3px; border-radius:10px }

/* Scrollbar */
*::-webkit-scrollbar{width:10px;height:10px}
*::-webkit-scrollbar-thumb{background:#2a3442;border-radius:10px}
*::-webkit-scrollbar-thumb:hover{background:#334559}
*::-webkit-scrollbar-track{background:#131923}
/* ---- ปิดเงาทุกปุ่ม (ตามธีม no-shadow) ---- */
.btn,
.btn:hover,
.btn:focus,
.btn:active,
.btn-primary,
.btn-primary:hover,
.btn-primary:focus {
  box-shadow: none !important;
  text-shadow: none !important;
}

/* ตัด outline เรืองแสงตอนโฟกัส (ถ้าไม่ต้องการ) */
:focus-visible {
  outline: none !important;
}

/* ลบเงาที่ตั้งไว้ใน .btn-primary เดิม */
.btn-primary { 
  /* เคยมี: box-shadow:0 8px 22px rgba(58,163,255,.28); */
  box-shadow: none !important;
}

</style>
</head>
<body>
<div class="container-narrow">

  <!-- Topbar -->
  <div class="topbar d-flex align-items-center justify-content-between mb-3">
    <div class="d-flex align-items-center">
      <h4 class="brand mb-0 mr-3"><i class="bi bi-shield-lock"></i> สร้างผู้ใช้ใหม่</h4>
      
    </div>
    <div class="d-flex align-items-center" style="gap:8px">
      <a href="users_list.php" class="btn btn-sm"><i class="bi bi-people"></i> ดูผู้ใช้ทั้งหมด</a>
      <a href="adminmenu.php" class="btn btn-sm"><i class="bi bi-gear"></i> กลับหน้า Admin</a>
      <a href="../logout.php" class="btn btn-outline-light btn-sm"><i class="bi bi-box-arrow-right"></i> ออกจากระบบ</a>
    </div>
  </div>

  <!-- Card -->
  <div class="cardx p-3 p-md-4">
    <?php if ($err): ?>
      <div class="alert alert-danger mb-3"><?= htmlspecialchars($err,ENT_QUOTES,'UTF-8') ?></div>
    <?php endif; ?>

    <form method="post" novalidate>
      <div class="form-row">
        <div class="form-group col-md-6">
          <label>รหัสนักศึกษา (Student ID) <span class="text-danger">*</span></label>
          <input type="text" name="student_id" class="form-control" required
                 placeholder="เช่น 66xxxxxxx"
                 value="<?= htmlspecialchars($_POST['student_id'] ?? '', ENT_QUOTES,'UTF-8') ?>">
          <small class="text-muted">ใช้สำหรับระบุผู้ใช้ภายในระบบ</small>
        </div>

        <div class="form-group col-md-6">
          <label>ชื่อผู้ใช้ (username) <span class="text-danger">*</span></label>
          <input type="text" name="username" class="form-control" required
                 placeholder="ตัวอย่าง: panit123"
                 value="<?= htmlspecialchars($_POST['username'] ?? '', ENT_QUOTES,'UTF-8') ?>">
        </div>

        <div class="form-group col-md-6">
          <label>ชื่อ - สกุล ที่แสดง <span class="text-danger">*</span></label>
          <input type="text" name="name" class="form-control" required
                 placeholder="ตัวอย่าง: สมหญิง ใจดี"
                 value="<?= htmlspecialchars($_POST['name'] ?? '', ENT_QUOTES,'UTF-8') ?>">
        </div>

        <div class="form-group col-md-6">
          <label>บทบาท (role)</label>
          <select name="role" class="custom-select">
            <?php foreach($roles as $k=>$v): ?>
              <option value="<?= htmlspecialchars($k,ENT_QUOTES) ?>"
                <?= (($_POST['role'] ?? 'employee')===$k)?'selected':'' ?>>
                <?= htmlspecialchars($v,ENT_QUOTES) ?> (<?= htmlspecialchars($k,ENT_QUOTES) ?>)
              </option>
            <?php endforeach; ?>
          </select>
        </div>

        <div class="form-group col-md-6">
          <label>สถานะชั่วโมง</label>
          <select name="status" class="custom-select">
            <?php foreach ($status_options as $opt): ?>
              <option value="<?= htmlspecialchars($opt,ENT_QUOTES,'UTF-8') ?>"
                <?= (($_POST['status'] ?? 'ชั่วโมงปกติ')===$opt)?'selected':'' ?>>
                <?= htmlspecialchars($opt,ENT_QUOTES,'UTF-8') ?>
              </option>
            <?php endforeach; ?>
          </select>
        </div>
      </div>

      <hr class="hr-soft">

      <div class="form-row">
        <div class="form-group col-md-6">
          <label>รหัสผ่าน <span class="text-danger">*</span></label>
          <input type="password" name="password" class="form-control" required minlength="4" placeholder="อย่างน้อย 4 ตัวอักษร">
        </div>
        <div class="form-group col-md-6">
          <label>ยืนยันรหัสผ่าน <span class="text-danger">*</span></label>
          <input type="password" name="password2" class="form-control" required minlength="4" placeholder="พิมพ์รหัสผ่านอีกครั้ง">
        </div>
      </div>

      <div class="d-flex justify-content-between mt-2">
        <a href="users_list.php" class="btn btn-light"><i class="bi bi-x-circle"></i> ยกเลิก</a>
        <button class="btn btn-primary"><i class="bi bi-save"></i> บันทึกผู้ใช้</button>
      </div>
    </form>
  </div>
</div>

<script>
document.querySelector('form')?.addEventListener('submit', function(e){
  const pw  = this.querySelector('input[name="password"]').value;
  const pw2 = this.querySelector('input[name="password2"]').value;
  if (pw !== pw2) { e.preventDefault(); alert('รหัสผ่านไม่ตรงกัน'); }
});
</script>
</body>
</html>
