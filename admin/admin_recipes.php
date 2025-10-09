<?php
// admin_recipes.php — แสดงสูตร (ใช้ตาราง recipes เดียว) + โทนเดียวกับ admin + ปุ่ม .btn-admin

declare(strict_types=1);
session_start();
if (empty($_SESSION['uid'])) { header("Location: ../index.php"); exit; }

require __DIR__ . '/../db.php';
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$conn->set_charset('utf8mb4');

if (($_SESSION['role'] ?? '') !== 'admin') {
  $_SESSION['flash'] = 'ต้องเป็นแอดมินเท่านั้น';
  header("Location: ../SelectRole/role.php"); exit;
}

function e(string $s): string { return htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); }

$viewing_id = isset($_GET['view']) ? (int)$_GET['view'] : 0;
$search = trim((string)($_GET['q'] ?? ''));

if ($viewing_id) {
  $st = $conn->prepare("SELECT r.*, m.name AS menu_name FROM recipes r JOIN menu m ON m.menu_id=r.menu_id WHERE r.recipe_id=?");
  $st->bind_param('i',$viewing_id);
  $st->execute();
  $recipe = $st->get_result()->fetch_assoc();
  $st->close();
} else {
  $sql = "SELECT r.recipe_id,r.title,m.name AS menu_name,r.created_at
          FROM recipes r JOIN menu m ON m.menu_id=r.menu_id
          WHERE (?='' OR m.name LIKE ?)
          ORDER BY r.recipe_id DESC";
  $st=$conn->prepare($sql);
  $param='%'.$search.'%';
  $st->bind_param('ss',$search,$param);
  $st->execute();
  $recipes=$st->get_result()->fetch_all(MYSQLI_ASSOC);
  $st->close();
}
?>
<!doctype html>
<html lang="th">
<head>
<meta charset="utf-8">
<title>PSU Blue Cafe • สูตรเมนู</title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<link rel="stylesheet" href="https://stackpath.bootstrapcdn.com/bootstrap/4.5.2/css/bootstrap.min.css">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css">
<style>
:root{
  --bg1:#11161b; --bg2:#141b22;
  --surface:#1a2230; --surface-2:#192231; --surface-3:#202a3a;
  --ink:#e9eef6; --ink-muted:#b9c6d6;
  --brand-500:#3aa3ff; --brand-400:#7cbcfd; --brand-300:#a9cffd;
  --radius:12px;
}
html,body{height:100%}
body{
  background:linear-gradient(180deg,var(--bg1),var(--bg2));
  color:var(--ink);
  font-family:"Segoe UI",Tahoma,Arial,sans-serif;
}
.container-fluid{max-width:1300px;padding:14px;margin:auto;}
.topbar{
  background:var(--surface);
  border:1px solid rgba(255,255,255,.08);
  border-radius:14px;
  padding:12px 16px;
  margin-bottom:16px;
  display:flex;justify-content:space-between;align-items:center;
}
.cardx{
  background:var(--surface);
  border:1px solid rgba(255,255,255,.08);
  border-radius:var(--radius);
  padding:18px;
}
.table thead th{
  background:var(--surface-2);
  color:#fff;
  border-bottom:1px solid rgba(255,255,255,.10);
  font-weight:800;
}
.table tbody td{color:#eaf2ff;}
.table-hover tbody tr:hover td{background:#223044;}
pre{
  white-space:pre-wrap;
  line-height:1.7;
  color:#e9eef6;
  background:var(--surface-3);
  border-radius:12px;
  padding:14px;
  font-size:15px;
  border:1px solid rgba(255,255,255,.12);
}

/* ===== ปุ่มโทนเดียวกับหน้า admin/users_list (.btn-admin) ===== */
.btn-admin{
  background: linear-gradient(180deg, #56b2ff, #3aa3ff);
  border: 1px solid #1e6acc;
  color:#fff !important;
  font-weight:900;
  border-radius:12px;
  padding:.4rem .85rem;
  box-shadow:0 8px 22px rgba(58,163,255,.28);
}
.btn-admin .bi{ margin-right:.35rem; }
.btn-admin:hover{ filter:brightness(1.06); transform:translateY(-1px); color:#fff !important; }
.btn-admin:focus{ outline:3px solid rgba(58,163,255,.35); outline-offset:2px; }

/* ปุ่มทั่วไปให้คุมโทน */
.btn-info{ background:var(--brand-500); border:1px solid #1e6acc; font-weight:800; }
.btn-warning{ background:#f6c453; border:1px solid #d79a00; font-weight:800; color:#1a2230 !important; }
.btn-danger{ font-weight:800; border-radius:10px; }
.btn-light{ border:1px solid rgba(255,255,255,.14); }

/* ช่องค้นหาโทนเข้ม */
input.form-control{
  background:var(--surface-3);
  color:#eaf2ff;
  border:1px solid rgba(255,255,255,.12);
  border-radius:10px;
}
input.form-control::placeholder{ color:#90a3b6 }
</style>
</head>
<body>
<div class="container-fluid">
  <div class="topbar">
    <h4 class="m-0"><i class="bi bi-journal-text"></i> สูตรเมนู</h4>
    <div class="d-flex align-items-center">
      <a href="admin_recipe_create.php" class="btn btn-admin btn-sm mr-2">
        <i class="bi bi-plus-circle"></i> เพิ่มสูตร
      </a>
      <a href="adminmenu.php" class="btn btn-admin btn-sm mr-2">
        <i class="bi bi-gear"></i> เมนูแอดมิน
      </a>
      <a href="../logout.php" class="btn btn-sm btn-outline-light">
        <i class="bi bi-box-arrow-right"></i> ออกจากระบบ
      </a>
    </div>
  </div>

  <div class="cardx">
  <?php if(!$viewing_id): ?>
    <form class="form-inline mb-3">
      <label class="mr-2"><i class="bi bi-search"></i> ค้นหา</label>
      <input type="text" name="q" class="form-control mr-2" value="<?=e($search)?>" placeholder="ชื่อเมนู">
      <button class="btn btn-admin btn-sm">ค้นหา</button>
      <a href="admin_recipes.php" class="btn btn-light btn-sm ml-2">ล้าง</a>
    </form>

    <div class="table-responsive">
      <table class="table table-sm table-hover">
        <thead>
          <tr><th>ID</th><th>เมนู</th><th>หัวสูตร</th><th class="text-right">จัดการ</th></tr>
        </thead>
        <tbody>
        <?php if(empty($recipes)): ?>
          <tr><td colspan="4" class="text-muted">ยังไม่มีสูตร</td></tr>
        <?php else: foreach($recipes as $r): ?>
          <tr>
            <td>#<?= (int)$r['recipe_id'] ?></td>
            <td><?= e($r['menu_name']) ?></td>
            <td><?= e($r['title']) ?></td>
            <td class="text-right">
              <a href="?view=<?= (int)$r['recipe_id'] ?>" class="btn btn-info btn-sm text-white">
                <i class="bi bi-eye"></i> ดู
              </a>
              <a href="admin_recipe_edit.php?id=<?= (int)$r['recipe_id'] ?>" class="btn btn-warning btn-sm text-dark">
                <i class="bi bi-pencil-square"></i> แก้ไข
              </a>
              <a href="admin_recipe_delete.php?id=<?= (int)$r['recipe_id'] ?>"
                 onclick="return confirm('ลบสูตรนี้?');"
                 class="btn btn-danger btn-sm">
                 <i class="bi bi-trash"></i> ลบ
              </a>
            </td>
          </tr>
        <?php endforeach; endif; ?>
        </tbody>
      </table>
    </div>
  <?php else: ?>
    <?php if(empty($recipe)): ?>
      <div class="text-muted">ไม่พบสูตร</div>
    <?php else: ?>
      <a href="admin_recipes.php" class="btn btn-light mb-3">
        <i class="bi bi-arrow-left"></i> กลับ
      </a>
      <h4 class="text-light">
        <?= e($recipe['title']) ?>
        <small class="text-muted">(<?= e($recipe['menu_name']) ?>)</small>
      </h4>
      <div class="text-muted mb-3">
        <i class="bi bi-clock-history"></i> สร้างเมื่อ <?= e($recipe['created_at']) ?>
      </div>
      <pre><?= e($recipe['recipe_text']) ?></pre>
    <?php endif; ?>
  <?php endif; ?>
  </div>
</div>
</body>
</html>
