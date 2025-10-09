<?php
// admin_recipe_edit.php — แก้ไขสูตร (PSU Blue Cafe Theme)

declare(strict_types=1);
session_start();
if(empty($_SESSION['uid'])){header("Location: ../index.php");exit;}
require __DIR__.'/../db.php';
$conn->set_charset('utf8mb4');
if(($_SESSION['role']??'')!=='admin'){header("Location: ../SelectRole/role.php");exit;}

$id=(int)($_GET['id']??0);
if(empty($_SESSION['csrf']))$_SESSION['csrf']=bin2hex(random_bytes(16));
$CSRF=$_SESSION['csrf'];
function e(string $s):string{return htmlspecialchars($s,ENT_QUOTES,'UTF-8');}

if($_SERVER['REQUEST_METHOD']==='POST'){
  if(!hash_equals($_SESSION['csrf'],$_POST['csrf']??''))die('Bad CSRF');
  $title=trim($_POST['title']??'');
  $text=trim($_POST['recipe_text']??'');
  if($id>0 && $title!=='' && $text!==''){
    $st=$conn->prepare("UPDATE recipes SET title=?,recipe_text=?,updated_at=NOW() WHERE recipe_id=?");
    $st->bind_param('ssi',$title,$text,$id);
    $st->execute();
    header("Location: admin_recipes.php?view=$id&msg=updated");exit;
  }
}

$st=$conn->prepare("SELECT r.*,m.name AS menu_name FROM recipes r JOIN menu m ON m.menu_id=r.menu_id WHERE r.recipe_id=?");
$st->bind_param('i',$id);$st->execute();
$recipe=$st->get_result()->fetch_assoc();$st->close();
if(!$recipe){die('ไม่พบสูตร');}
?>
<!doctype html>
<html lang="th">
<head>
<meta charset="utf-8">
<title>PSU Blue Cafe • แก้ไขสูตร</title>
<link rel="stylesheet" href="https://stackpath.bootstrapcdn.com/bootstrap/4.5.2/css/bootstrap.min.css">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css">
<style>
/* ======== PSU Blue Café Theme ======== */
:root{
  --bg1:#11161b; --bg2:#141b22;
  --surface:#1a2230; --surface-2:#192231; --surface-3:#202a3a;
  --ink:#e9eef6; --ink-muted:#b9c6d6;
  --brand-500:#3aa3ff; --brand-400:#7cbcfd;
  --radius:12px;
}
body{
  background:linear-gradient(180deg,var(--bg1),var(--bg2));
  color:var(--ink);
  font-family:"Segoe UI",Tahoma,Arial,sans-serif;
  min-height:100vh;
  padding:30px 10px;
}
.container{max-width:900px;}
h4{font-weight:900;color:#eaf2ff;}
.cardx{
  background:var(--surface);
  border:1px solid rgba(255,255,255,.08);
  border-radius:var(--radius);
  padding:24px;
  color:var(--ink);
  box-shadow:none;
}

/* ===== ปุ่มหลัก (ฟ้า PSU) ===== */
.btn-admin{
  background: linear-gradient(180deg,#56b2ff,#3aa3ff);
  border: 1px solid #1e6acc;
  color:#fff !important;
  font-weight:900;
  border-radius:12px;
  padding:.5rem .9rem;
  box-shadow:0 8px 22px rgba(58,163,255,.28);
}
.btn-admin:hover{ filter:brightness(1.06); transform:translateY(-1px); color:#fff !important; }
.btn-admin:focus{ outline:3px solid rgba(58,163,255,.35); outline-offset:2px; }

/* ปุ่มรอง (light) */
.btn-light{
  background: var(--surface-3);
  color:#eaf2ff;
  border:1px solid rgba(255,255,255,.12);
  border-radius:10px;
  font-weight:800;
}

/* ฟอร์ม */
label{font-weight:800;color:#dce6f5;}
.form-control{
  background:var(--surface-3);
  border:1px solid rgba(255,255,255,.12);
  color:#eaf2ff;
  border-radius:10px;
}
.form-control:focus{
  background:var(--surface-3);
  border-color:#3aa3ff;
  box-shadow:0 0 0 .18rem rgba(58,163,255,.25);
  color:#fff;
}
textarea.form-control{min-height:300px;line-height:1.7;}
</style>
</head>
<body>
<div class="container">
  <h4><i class="bi bi-pencil-square"></i> แก้ไขสูตร</h4>
  <div class="cardx mt-3">
    <form method="post">
      <input type="hidden" name="csrf" value="<?=e($CSRF)?>">
      <div class="form-group">
        <label>ชื่อสูตร</label>
        <input type="text" name="title" class="form-control" value="<?=e($recipe['title'])?>" required>
      </div>
      <div class="form-group">
        <label>เนื้อหาสูตรทั้งหมด</label>
        <textarea name="recipe_text" class="form-control" rows="15" required><?=e($recipe['recipe_text'])?></textarea>
      </div>
      <div class="text-right mt-3">
        <a href="admin_recipes.php?view=<?= (int)$id ?>" class="btn btn-light mr-2">
          <i class="bi bi-arrow-left"></i> ย้อนกลับ
        </a>
        <button class="btn btn-admin">
          <i class="bi bi-save2"></i> บันทึกการแก้ไข
        </button>
      </div>
    </form>
  </div>
</div>
</body>
</html>
