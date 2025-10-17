<?php
// admin_recipe_create.php — เพิ่มสูตรใหม่ (ธีมเดียวกับ user_profile.php)
declare(strict_types=1);
session_start();
if (empty($_SESSION['uid'])) { header("Location: ../index.php"); exit; }

require __DIR__ . '/../db.php';
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$conn->set_charset('utf8mb4');

if (($_SESSION['role'] ?? '') !== 'admin') { header("Location: ../SelectRole/role.php"); exit; }

if (empty($_SESSION['csrf'])) $_SESSION['csrf']=bin2hex(random_bytes(16));
$CSRF=$_SESSION['csrf'];

function e(string $s): string {return htmlspecialchars($s,ENT_QUOTES,'UTF-8');}

if($_SERVER['REQUEST_METHOD']==='POST'){
  if(!hash_equals($_SESSION['csrf'],$_POST['csrf']??'')){die('Bad CSRF');}
  $menu_id=(int)($_POST['menu_id']??0);
  $title=trim($_POST['title']??'');
  $text=trim($_POST['recipe_text']??'');
  if($menu_id>0 && $title!=='' && $text!==''){
    $st=$conn->prepare("INSERT INTO recipes(menu_id,title,recipe_text) VALUES(?,?,?)");
    $st->bind_param('iss',$menu_id,$title,$text);
    $st->execute();
    header("Location: admin_recipes.php?msg=added");
    exit;
  }
}

$menus=$conn->query("SELECT menu_id,name FROM menu WHERE is_active=1 ORDER BY name")->fetch_all(MYSQLI_ASSOC);
?>
<!doctype html>
<html lang="th">
<head>
<meta charset="utf-8">
<title>เพิ่มสูตร • PSU Blue Café</title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<link rel="stylesheet" href="https://stackpath.bootstrapcdn.com/bootstrap/4.5.2/css/bootstrap.min.css">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css">
<style>
/* ============================
   PSU Blue Café • Minimal/Clean Dark
   (ให้เหมือน user_profile.php)
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

  /* ปุ่มลบ/เตือน (สำรอง) */
  --danger-500:#EF4444;

  --radius:10px;
}

/* พื้นหลัง/ตัวอักษร */
html,body{height:100%}
body{
  background: linear-gradient(180deg,var(--bg-grad1),var(--bg-grad2)) !important;
  color: var(--ink) !important;
  font-family:"Segoe UI",Tahoma,Arial,sans-serif;
  letter-spacing:.1px;
}

/* คอนเทนเนอร์ */
.wrap{ max-width:900px; margin:26px auto; padding:0 14px; }

/* Topbar */
.topbar{
  position:sticky; top:0; z-index:10;
  background: var(--surface) !important;
  border: 1px solid rgba(255,255,255,.08) !important;
  border-radius: 14px !important;
  box-shadow: none !important;
  padding:12px 16px;
}
.brand{font-weight:900; letter-spacing:.3px; color:var(--text-strong)}
.btn-outline-light{
  background: transparent !important;
  color: var(--ink) !important;
  border: 1px solid rgba(255,255,255,.18) !important;
  box-shadow:none !important;
  border-radius: var(--radius) !important;
}

/* การ์ด */
.cardx{
  background: var(--surface) !important;
  border: 1px solid rgba(255,255,255,.08) !important;
  border-radius: 14px !important;
  box-shadow: none !important;
  padding:20px;
}

/* ปุ่มหลัก */
.btn-primary{
  background: var(--brand-500) !important;
  border: 1px solid var(--brand-border) !important;
  color:#fff !important;
  font-weight:800 !important;
  box-shadow:none !important;
  text-shadow:none !important;
  border-radius: 12px !important;
}
.btn-primary:hover{ filter:brightness(1.06) }

/* อินพุต/เซเล็กต์/เท็กซ์แอเรีย */
.form-control{
  background: var(--surface-2) !important;
  color: var(--ink) !important;
  border: 1px solid rgba(255,255,255,.10) !important;
  border-radius: 10px !important;
}
.form-control::placeholder{ color: #9FB0C7 !important; opacity:1 }
.form-control:focus{
  border-color: var(--brand-border) !important;
  box-shadow: 0 0 0 .15rem rgba(30,106,204,.25) !important;
}

/* ป้าย label */
label{ color: var(--text-strong); font-weight:700 }

/* ตัวอักษรกำหนดขนาด */
body, .table, .btn, input, label, .badge, .pill, .form-control{ font-size:14.5px !important; }

/* helper: เส้นแบ่งบาง */
.hr-light{
  border:0; height:1px; background: rgba(255,255,255,.08);
  margin:12px 0 18px 0;
}
</style>
</head>
<body>
<div class="wrap">

  <!-- Topbar -->
  <div class="topbar d-flex align-items-center justify-content-between mb-3">
    <div class="brand"><i class="bi bi-bezier2"></i> เพิ่มสูตรใหม่</div>
    <div class="d-flex align-items-center" style="gap:8px">
      <a href="admin_recipes.php" class="btn btn-sm btn-outline-light"><i class="bi bi-card-list"></i> รายการสูตร</a>
      <a href="adminmenu.php" class="btn btn-sm btn-outline-light"><i class="bi bi-gear"></i> หน้า Admin</a>
    </div>
  </div>

  <!-- Card form -->
  <div class="cardx">
    <form method="post">
      <input type="hidden" name="csrf" value="<?=e($CSRF)?>">

      <div class="form-row">
        <div class="col-md-4 mb-3">
          <label for="menu_id">เมนู</label>
          <select id="menu_id" name="menu_id" class="form-control" required>
            <option value="">-- เลือกเมนู --</option>
            <?php foreach($menus as $m): ?>
              <option value="<?=$m['menu_id']?>"><?=e($m['name'])?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="col-md-8 mb-3">
          <label for="title">ชื่อสูตร</label>
          <input id="title" type="text" name="title" class="form-control" placeholder="เช่น: สูตรลาเต้เย็น (แก้ว 16 ออนซ์)" required>
        </div>
      </div>

      <hr class="hr-light">

      <div class="form-group mb-3">
        <label for="recipe_text">เนื้อหาสูตรทั้งหมด</label>
        <textarea id="recipe_text" name="recipe_text" class="form-control" rows="14"
          placeholder="ตัวอย่าง:
1) ใส่น้ำเชื่อม 20 มล.
2) ชงเอสเปรสโซ่ 1 ช็อต เติมนม 140 มล.
3) คนให้เข้ากัน ใส่น้ำแข็งจนเต็มแก้ว"></textarea>
        
      </div>

      <div class="d-flex align-items-center justify-content-end" style="gap:8px">
        <a href="admin_recipes.php" class="btn btn-outline-light"><i class="bi bi-arrow-left"></i> ยกเลิก</a>
        <button class="btn btn-primary"><i class="bi bi-save2"></i> บันทึก</button>
      </div>
    </form>
  </div>

</div>
</body>
</html>
