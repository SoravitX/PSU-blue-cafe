<?php
// admin_recipe_create.php — เพิ่มสูตรใหม่

declare(strict_types=1);
session_start();
if (empty($_SESSION['uid'])) { header("Location: ../index.php"); exit; }

require __DIR__ . '/../db.php';
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
<meta charset="utf-8"><title>เพิ่มสูตร</title>
<link rel="stylesheet" href="https://stackpath.bootstrapcdn.com/bootstrap/4.5.2/css/bootstrap.min.css">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css">
<style>
body{background:linear-gradient(135deg,#222831,#393E46);color:#E6EBEE;font-family:"Segoe UI",Tahoma,Arial,sans-serif;}
.cardx{background:#1C2228;border:1px solid rgba(255,255,255,.08);border-radius:16px;padding:20px;}
.btn-psu{background:linear-gradient(180deg,#27C8CF,#00ADB5);border:1px solid #00ADB5;color:#062e31;font-weight:900;border-radius:12px;}
</style>
</head>
<body>
<div class="container" style="max-width:900px;padding:20px;">
  <h4><i class="bi bi-plus-circle"></i> เพิ่มสูตรใหม่</h4>
  <div class="cardx mt-3">
    <form method="post">
      <input type="hidden" name="csrf" value="<?=e($CSRF)?>">
      <div class="form-row">
        <div class="col-md-4 mb-3">
          <label>เมนู</label>
          <select name="menu_id" class="form-control" required>
            <option value="">-- เลือก --</option>
            <?php foreach($menus as $m): ?>
              <option value="<?=$m['menu_id']?>"><?=$m['name']?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="col-md-8 mb-3">
          <label>ชื่อสูตร</label>
          <input type="text" name="title" class="form-control" required>
        </div>
      </div>
      <div class="form-group">
        <label>เนื้อหาสูตรทั้งหมด</label>
        <textarea name="recipe_text" class="form-control" rows="15" placeholder="1. ใส่ส่วนผสมลงในแก้ว..."></textarea>
      </div>
      <div class="text-right">
        <button class="btn btn-psu"><i class="bi bi-save2"></i> บันทึก</button>
      </div>
    </form>
  </div>
</div>
</body>
</html>
