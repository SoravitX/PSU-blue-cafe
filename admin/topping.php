<?php
// admin/topping.php — จัดการท็อปปิง (แสดง/เพิ่ม/แก้ไข/ลบ) • PSU Blue Café
declare(strict_types=1);
session_start();
if (empty($_SESSION['uid'])) { header("Location: ../index.php"); exit; }

require __DIR__ . '/../db.php';
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$conn->set_charset('utf8mb4');

if (($_SESSION['role'] ?? '') !== 'admin') { header("Location: ../SelectRole/role.php"); exit; }

if (empty($_SESSION['csrf'])) $_SESSION['csrf']=bin2hex(random_bytes(16));
$CSRF=$_SESSION['csrf'];

function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function back_to_self(array $q=[]){
  $base = basename(__FILE__);
  $qs = http_build_query($q);
  return $base . ($qs?('?'.$qs):'');
}

/* ---------- Handle Actions ---------- */
$msg = '';
if ($_SERVER['REQUEST_METHOD']==='POST') {
  if (!hash_equals($_SESSION['csrf'], $_POST['csrf'] ?? '')) { die('Bad CSRF'); }
  $act = $_POST['act'] ?? '';

  if ($act === 'add') {
    $name = trim($_POST['name'] ?? '');
    $price = (float)($_POST['base_price'] ?? 0);
    $is_active = isset($_POST['is_active']) ? 1 : 0;

    if ($name==='') {
      $msg='กรุณากรอกชื่อท็อปปิง';
    } else {
      $st=$conn->prepare("INSERT INTO toppings(name, base_price, is_active) VALUES (?,?,?)");
      $st->bind_param('sdi',$name,$price,$is_active);
      $st->execute(); $st->close();
      header('Location: '.back_to_self(['msg'=>'added'])); exit;
    }
  }

  if ($act === 'update') {
    $id=(int)($_POST['topping_id'] ?? 0);
    $name = trim($_POST['name'] ?? '');
    $price = (float)($_POST['base_price'] ?? 0);
    $is_active = isset($_POST['is_active']) ? 1 : 0;

    if ($id<=0 || $name==='') {
      $msg='ข้อมูลไม่ครบ';
    } else {
      $st=$conn->prepare("UPDATE toppings SET name=?, base_price=?, is_active=? WHERE topping_id=?");
      $st->bind_param('sdii',$name,$price,$is_active,$id);
      $st->execute(); $st->close();
      header('Location: '.back_to_self(['msg'=>'updated'])); exit;
    }
  }

  if ($act === 'delete') {
    $id=(int)($_POST['topping_id'] ?? 0);
    if ($id>0) {
      $st=$conn->prepare("DELETE FROM toppings WHERE topping_id=?");
      $st->bind_param('i',$id);
      $st->execute(); $st->close();
      header('Location: '.back_to_self(['msg'=>'deleted'])); exit;
    } else { $msg='ไม่พบรายการที่จะลบ'; }
  }

  if ($act === 'toggle') {
    $id=(int)($_POST['topping_id'] ?? 0);
    $st=$conn->prepare("UPDATE toppings SET is_active=1-is_active WHERE topping_id=?");
    $st->bind_param('i',$id);
    $st->execute(); $st->close();
    header('Location: '.back_to_self(['msg'=>'toggled'])); exit;
  }
}

/* ---------- Edit mode (prefill) ---------- */
$edit_id = (int)($_GET['edit'] ?? 0);
$edit_row = null;
if ($edit_id>0) {
  $st=$conn->prepare("SELECT * FROM toppings WHERE topping_id=?");
  $st->bind_param('i',$edit_id); $st->execute();
  $edit_row = $st->get_result()->fetch_assoc();
  $st->close();
}

/* ---------- Fetch list ---------- */
$rows = $conn->query("SELECT topping_id, name, base_price, is_active
                      FROM toppings ORDER BY is_active DESC, name ASC");
?>
<!doctype html>
<html lang="th">
<head>
<meta charset="utf-8">
<title>จัดการท็อปปิง • PSU Blue Café</title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<link rel="stylesheet"
  href="https://stackpath.bootstrapcdn.com/bootstrap/4.5.2/css/bootstrap.min.css">
<link rel="stylesheet"
  href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css">
<style>
:root{
  --bg1:#0b1220; --bg2:#101a27;
  --surface:#1a2230; --surface-2:#192231; --surface-3:#202a3a;
  --text-strong:#ffffff; --text-normal:#e6ebee; --text-muted:#9fb1c5;
  --brand-500:#2ea7ff; --brand-400:#1f7ee8; --brand-border:#1669c9;
  --ok:#22c55e; --danger:#ef4444; --radius:12px;
}
html,body{height:100%}
body{
  margin:0; font-family:"Segoe UI",Tahoma,Arial,sans-serif;
  background:linear-gradient(180deg,var(--bg1),var(--bg2));
  color:var(--text-normal);
}
.container-xl{max-width:1200px}

/* Top bar */
.topbar{
  position:sticky; top:0; z-index:5;
  display:flex; align-items:center; justify-content:space-between;
  padding:12px 16px; border-radius:var(--radius);
  background:var(--surface); border:1px solid rgba(255,255,255,.08);
  margin:14px 0;
}
.brand{margin:0; font-weight:900; color:var(--text-strong)}
.btn-back{
  border-radius:10px; padding:.4rem .8rem;
  background:var(--surface-3); color:#fff; text-decoration:none;
  border:1px solid rgba(255,255,255,.14); font-weight:800;
}
.btn-back:hover{filter:brightness(1.05)}

/* Cards */
.cardx{ background:var(--surface); border:1px solid rgba(255,255,255,.08);
  border-radius:var(--radius); color:var(--text-normal); }
.cardx .head{ padding:14px 16px; border-bottom:1px solid rgba(255,255,255,.08);
  background:var(--surface-2); border-radius:var(--radius) var(--radius) 0 0; }
.cardx .body{ padding:16px }

/* Inputs */
label{font-weight:800; color:var(--text-strong)}
.form-control{
  background:var(--surface-3); border:1px solid rgba(255,255,255,.18);
  color:#fff; border-radius:10px;
}
.form-control::placeholder{color:#a0b2c2}
.form-control:focus{border-color:var(--brand-500); box-shadow:0 0 0 .2rem rgba(46,167,255,.25)}

/* Buttons */
.btn-main{
  background:linear-gradient(180deg,var(--brand-500),var(--brand-400));
  color:#fff; border:1px solid var(--brand-border); font-weight:900; border-radius:10px;
}
.btn-line{ background:var(--surface-3); color:#fff; border:1px solid rgba(255,255,255,.18); font-weight:800; border-radius:10px }

.btn-edit{ background:linear-gradient(180deg,#f6ad55,#f59e0b); border:1px solid #d97706; color:#0b0f16; font-weight:900; border-radius:10px }
.btn-del { background:rgba(239,68,68,.18); border:1px solid rgba(239,68,68,.55); color:#ff9b9b; font-weight:900; border-radius:10px }
.btn-act { background:#273449; border:1px solid rgba(255,255,255,.18); color:#dbe8ff; border-radius:10px; font-weight:800 }

/* Table */
.table-wrap{ overflow:auto }
.table thead th{
  position:sticky; top:0; z-index:1; background:var(--surface-2); color:var(--text-strong);
  border-bottom:1px solid rgba(255,255,255,.08); font-weight:800;
}
.table{ color:var(--text-normal) }
.table td, .table th{ border-color: rgba(255,255,255,.10) !important; vertical-align:middle !important; }
.table tbody tr:nth-child(odd){ background:#1c2432 } .table tbody tr:nth-child(even){ background:#19212d }
.badge-on{ background:#22c55e; color:#fff; padding:.35rem .6rem; border-radius:999px; font-weight:800 }
.badge-off{ background:#8893a5; color:#0b1220; padding:.35rem .6rem; border-radius:999px; font-weight:800 }

/* iPad & medium screens: สองคอลัมน์พอดี ไม่ต้องเลื่อน */
@media (min-width: 768px){
  .grid-2 { display:grid; grid-template-columns: 380px 1fr; gap:16px; }
}
@media (max-width: 767.98px){
  .grid-2 { display:block }
}
:focus-visible{ outline:3px solid rgba(46,167,255,.55); outline-offset:3px; border-radius:10px }
</style>
</head>
<body>
<div class="container-xl">

  <div class="topbar">
    <h5 class="brand"><i class="bi bi-egg-fried"></i> จัดการท็อปปิง</h5>
    <div class="d-flex align-items-center" style="gap:8px">
      <a href="adminmenu.php" class="btn-back"><i class="bi bi-gear"></i> เมนูแอดมิน</a>
      
    </div>
  </div>

  <?php if(isset($_GET['msg'])): ?>
    <?php
      $map = ['added'=>'เพิ่มท็อปปิงเรียบร้อย','updated'=>'บันทึกการแก้ไขแล้ว','deleted'=>'ลบเรียบร้อย','toggled'=>'อัพเดตสถานะแล้ว'];
      $txt = $map[$_GET['msg']] ?? '';
    ?>
    <?php if($txt): ?>
      <div class="alert alert-success"><?= h($txt) ?></div>
    <?php endif; ?>
  <?php endif; ?>
  <?php if($msg): ?>
    <div class="alert alert-danger"><?= h($msg) ?></div>
  <?php endif; ?>

  <div class="grid-2">

    <!-- Form Add / Edit -->
    <div class="cardx mb-3">
      <div class="head d-flex align-items-center justify-content-between">
        <strong><?= $edit_row? 'แก้ไขท็อปปิง' : 'เพิ่มท็อปปิงใหม่' ?></strong>
        <?php if($edit_row): ?>
          <a class="btn btn-sm btn-line" href="<?= h(back_to_self()) ?>"><i class="bi bi-x-circle"></i> ยกเลิกแก้ไข</a>
        <?php endif; ?>
      </div>
      <div class="body">
        <form method="post" novalidate>
          <input type="hidden" name="csrf" value="<?= h($CSRF) ?>">
          <?php if($edit_row): ?>
            <input type="hidden" name="act" value="update">
            <input type="hidden" name="topping_id" value="<?= (int)$edit_row['topping_id'] ?>">
          <?php else: ?>
            <input type="hidden" name="act" value="add">
          <?php endif; ?>

          <div class="form-group">
            <label>ชื่อท็อปปิง</label>
            <input type="text" name="name" class="form-control" required placeholder="เช่น ไข่มุก / วิปครีม"
                   value="<?= h($edit_row['name'] ?? '') ?>">
          </div>

          <div class="form-group">
            <label>ราคาพื้นฐาน (บาท)</label>
            <input type="number" name="base_price" class="form-control" step="0.01" min="0" required
                   placeholder="เช่น 5.00" value="<?= h($edit_row['base_price'] ?? '0.00') ?>">
          </div>

          <div class="form-group form-check">
            <?php $checked = ($edit_row ? ((int)$edit_row['is_active']===1) : true); ?>
            <input type="checkbox" class="form-check-input" id="is_active" name="is_active" <?= $checked?'checked':'' ?>>
            <label class="form-check-label" for="is_active">เปิดใช้งานท็อปปิงนี้</label>
          </div>

          <div class="d-flex align-items-center" style="gap:8px">
            <button class="btn btn-main" type="submit"><i class="bi bi-check2-circle"></i> <?= $edit_row? 'บันทึกการแก้ไข' : 'เพิ่มท็อปปิง' ?></button>
            
          </div>
        </form>
      </div>
    </div>

    <!-- List -->
    <div class="cardx mb-3">
      <div class="head d-flex align-items-center justify-content-between">
  <strong>รายการท็อปปิงที่มีอยู่</strong>
  <span class="small" style="color:var(--text-strong);font-weight:700;">
    ทั้งหมด: <?= (int)$rows->num_rows ?> รายการ
  </span>
</div>

      <div class="body table-wrap">
        <table class="table table-sm mb-0">
          <thead>
            <tr>
              <th style="min-width:70px">ID</th>
              <th style="min-width:220px">ชื่อท็อปปิง</th>
              <th style="min-width:120px" class="text-right">ราคา (บาท)</th>
              <th style="min-width:120px">สถานะ</th>
              <th style="min-width:220px" class="text-right">จัดการ</th>
            </tr>
          </thead>
          <tbody>
            <?php if($rows->num_rows>0): ?>
              <?php while($r=$rows->fetch_assoc()): ?>
                <tr>
                  <td>#<?= (int)$r['topping_id'] ?></td>
                  <td><?= h($r['name']) ?></td>
                  <td class="text-right"><?= number_format((float)$r['base_price'],2) ?></td>
                  <td>
                    <?php if((int)$r['is_active']===1): ?>
                      <span class="badge-on">เปิดใช้งาน</span>
                    <?php else: ?>
                      <span class="badge-off">ปิดไว้</span>
                    <?php endif; ?>
                  </td>
                  <td class="text-right">
                    <a class="btn btn-sm btn-edit mr-1" href="<?= h(back_to_self(['edit'=>(int)$r['topping_id']])) ?>">
                      <i class="bi bi-pencil-square"></i> แก้ไข
                    </a>

                    <!-- toggle -->
                   <!-- toggle -->
<form method="post" class="d-inline">
  <input type="hidden" name="csrf" value="<?= h($CSRF) ?>">
  <input type="hidden" name="act" value="toggle">
  <input type="hidden" name="topping_id" value="<?= (int)$r['topping_id'] ?>">
  <button class="btn btn-sm btn-act mr-1" type="submit">
    <i class="bi bi-power"></i> เปิดปิด
  </button>
</form>


                    <!-- delete -->
                    <form method="post" class="d-inline" onsubmit="return confirm('ยืนยันการลบท็อปปิงนี้?');">
                      <input type="hidden" name="csrf" value="<?= h($CSRF) ?>">
                      <input type="hidden" name="act" value="delete">
                      <input type="hidden" name="topping_id" value="<?= (int)$r['topping_id'] ?>">
                      <button class="btn btn-sm btn-del" type="submit">
                        <i class="bi bi-trash3"></i> ลบ
                      </button>
                    </form>
                  </td>
                </tr>
              <?php endwhile; ?>
            <?php else: ?>
              <tr><td colspan="5" class="text-center text-muted">ยังไม่มีท็อปปิง</td></tr>
            <?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>

  </div><!-- /grid-2 -->

</div>

<!-- ยืนยันลบในมือถือ: ปุ่ม Delete จะขึ้น confirm ของเบราว์เซอร์ -->
</body>
</html>
