<?php
// admin/add_category.php — PSU Blue Dark Theme
declare(strict_types=1);
require __DIR__ . '/../db.php';
$conn->set_charset('utf8mb4');

$msg = ''; $ok = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $name = trim($_POST['category_name'] ?? '');
  if ($name === '') {
    $msg = 'กรุณากรอกชื่อหมวดหมู่';
  } else {
    $stmt = $conn->prepare("INSERT INTO categories (category_name) VALUES (?)");
    $stmt->bind_param("s", $name);
    $ok = $stmt->execute();
    $stmt->close();
    if ($ok) { header("Location: adminmenu.php?msg=added_cat"); exit; }
    $msg = 'บันทึกไม่สำเร็จ';
  }
}
?>
<!doctype html>
<html lang="th">
<head>
<meta charset="utf-8">
<title>เพิ่มหมวดหมู่ • PSU Blue Café</title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<link rel="stylesheet"
 href="https://stackpath.bootstrapcdn.com/bootstrap/4.5.2/css/bootstrap.min.css">
<link rel="stylesheet"
 href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css">
<style>
/* ============ PSU Blue Café – Dark Minimal Theme ============ */
:root{
  --bg-grad1:#0b1220;
  --bg-grad2:#101a27;
  --surface:#1a2230;
  --surface-2:#192231;
  --surface-3:#202a3a;
  --text-strong:#ffffff;
  --text-normal:#e6ebee;
  --text-muted:#9fb1c5;
  --brand-500:#2ea7ff;
  --brand-400:#1f7ee8;
  --border-blue:#1669c9;
  --success:#22c55e;
  --danger:#ef4444;
  --radius:10px;
}
html,body{height:100%}
body{
  margin:0;
  font-family:"Segoe UI",Tahoma,Arial,sans-serif;
  background:linear-gradient(180deg,var(--bg-grad1),var(--bg-grad2));
  color:var(--text-normal);
}
.wrap{max-width:640px;margin:40px auto;padding:0 16px}

/* ---------- Topbar ---------- */
.topbar{
  display:flex;align-items:center;justify-content:space-between;
  background:var(--surface-2);
  border:1px solid rgba(255,255,255,.08);
  border-radius:10px;
  padding:12px 16px;
}
.brand{
  margin:0;font-weight:900;color:var(--text-strong);
}
.btn-back{
  border-radius:8px;
  background:transparent;
  border:1px solid rgba(255,255,255,.15);
  color:var(--text-normal)!important;
  font-weight:700;
}
.btn-back:hover{filter:brightness(1.1)}

/* ---------- Card ---------- */
.cardx{
  background:var(--surface);
  border:1px solid rgba(255,255,255,.06);
  border-radius:10px;
  padding:24px;
  color:var(--text-normal);
}

/* ---------- Form ---------- */
label{font-weight:800;color:var(--text-strong)}
.form-control{
  background:var(--surface-2);
  border:1px solid rgba(255,255,255,.12);
  color:#fff;
  border-radius:8px;
}
.form-control::placeholder{color:#a0b2c2}
.form-control:focus{
  border-color:var(--brand-500);
  box-shadow:0 0 0 3px rgba(46,167,255,.25);
}

/* ---------- Buttons ---------- */
.btn-primary,.btn-ghost{
  background:linear-gradient(180deg,var(--brand-500),var(--brand-400));
  border:1px solid var(--border-blue);
  color:#fff!important;
  font-weight:900;
  border-radius:8px;
  box-shadow:none;
}
.btn-primary:hover,.btn-ghost:hover{filter:brightness(1.08)}
.btn-outline-light{
  background:transparent;
  color:#fff;
  border:1px solid rgba(255,255,255,.2);
  font-weight:800;
  border-radius:8px;
}

/* ---------- Alerts ---------- */
.alert{
  border-radius:10px;
  font-weight:700;
  border:none;
}
.alert-success{
  background:rgba(34,197,94,.18);
  color:#22c55e;
  border:1px solid rgba(34,197,94,.4);
}
.alert-danger{
  background:rgba(239,68,68,.18);
  color:#ff6b6b;
  border:1px solid rgba(239,68,68,.4);
}

/* ---------- Misc ---------- */
.small-hint{color:var(--text-muted)}
:focus-visible{outline:3px solid rgba(46,167,255,.55); outline-offset:2px; border-radius:8px}

/* Scrollbar */
*::-webkit-scrollbar{width:10px;height:10px}
*::-webkit-scrollbar-thumb{background:#2c3545;border-radius:10px}
*::-webkit-scrollbar-thumb:hover{background:#3d4a5a}
*::-webkit-scrollbar-track{background:#131a22}
</style>
</head>
<body>
<div class="wrap">

  <div class="topbar mb-3">
    <h5 class="brand"><i class="bi bi-tags"></i> เพิ่มหมวดหมู่</h5>
    <a href="adminmenu.php" class="btn btn-sm btn-back">
      <i class="bi bi-arrow-left-circle"></i> กลับหน้าจัดการเมนู
    </a>
  </div>

  <div class="cardx">
    <h4 class="mb-2 font-weight-bold"><i class="bi bi-plus-circle"></i> เพิ่มหมวดหมู่ใหม่</h4>
    <div class="small small-hint mb-3"><i class="bi bi-info-circle"></i> ใส่ชื่อหมวดหมู่ให้ชัดเจน เช่น “เครื่องดื่มร้อน”, “ของหวาน”</div>

    <?php if($msg): ?>
      <div class="alert alert-<?= $ok?'success':'danger' ?> mb-3" role="alert">
        <?= htmlspecialchars($msg,ENT_QUOTES,'UTF-8') ?>
      </div>
    <?php endif; ?>

    <form method="post" novalidate>
      <div class="form-group">
        <label for="category_name"><i class="bi bi-tag"></i> ชื่อหมวดหมู่</label>
        <input type="text" name="category_name" id="category_name" class="form-control"
               placeholder="เช่น เครื่องดื่มเย็น" required autofocus>
      </div>

      <div class="d-flex align-items-center mt-3" style="gap:10px">
        <button class="btn btn-primary" type="submit"><i class="bi bi-check2-circle"></i> บันทึก</button>
        <a href="adminmenu.php" class="btn btn-outline-light"><i class="bi bi-x-circle"></i> ยกเลิก</a>
      </div>
    </form>
  </div>
</div>
</body>
</html>
