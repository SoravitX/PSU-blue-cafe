<?php
// admin/add_menu.php — PSU Blue Dark Theme (Flat)
declare(strict_types=1);
require __DIR__ . '/../db.php';

$message = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $name        = trim($_POST['name'] ?? '');
  $price       = (float)($_POST['price'] ?? 0);
  $category_id = (int)($_POST['category_id'] ?? 0);

  $target_dir = __DIR__ . "/images/";
  if (!is_dir($target_dir)) mkdir($target_dir, 0755, true);

  if (!isset($_FILES['image']) || $_FILES['image']['error'] !== UPLOAD_ERR_OK) {
    $message = "อัปโหลดไฟล์ไม่สำเร็จ";
  } else {
    $f = $_FILES['image'];
    $allowedExt = ['jpg','jpeg','png','gif','webp'];
    $ext = strtolower(pathinfo($f['name'], PATHINFO_EXTENSION));
    if (!in_array($ext, $allowedExt, true)) {
      $message = "รองรับเฉพาะไฟล์รูป: ".implode(', ',$allowedExt);
    } elseif ($f['size'] > 5*1024*1024) {
      $message = "ไฟล์ใหญ่เกินไป (สูงสุด 5MB)";
    } else {
      $imgInfo = @getimagesize($f['tmp_name']);
      if ($imgInfo === false) {
        $message = "ไฟล์ที่อัปโหลดไม่ใช่ภาพ";
      } else {
        $filename = sprintf("%s_%s.%s", time(), bin2hex(random_bytes(5)), $ext);
        $target_file = $target_dir . $filename;
        if (!move_uploaded_file($f['tmp_name'], $target_file)) {
          $message = "ไม่สามารถบันทึกรูปภาพได้";
        } else {
          $stmt = $conn->prepare("INSERT INTO menu (name, price, image, category_id) VALUES (?,?,?,?)");
          $stmt->bind_param("sdsi", $name, $price, $filename, $category_id);
          if ($stmt->execute()) {
            header("Location: adminmenu.php?msg=added");
            exit;
          }
          $message = "บันทึกข้อมูลล้มเหลว: ".$stmt->error;
          $stmt->close();
        }
      }
    }
  }
}
$categories = $conn->query("SELECT category_id, category_name FROM categories ORDER BY category_id");
?>
<!doctype html>
<html lang="th">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>เพิ่มเมนูใหม่ • PSU Blue Café (Admin)</title>
<link rel="stylesheet" href="https://stackpath.bootstrapcdn.com/bootstrap/4.5.2/css/bootstrap.min.css">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css">
<style>
/* ========= PSU Blue Dark Minimal ========= */
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
  --danger:#e53935;
  --success:#22c55e;
  --radius:10px;
}
html,body{height:100%}
body{
  margin:0;
  font-family:"Segoe UI",Tahoma,Arial,sans-serif;
  background:linear-gradient(180deg,var(--bg-grad1),var(--bg-grad2));
  color:var(--text-normal);
  padding:24px 16px;
  display:flex;justify-content:center;align-items:flex-start;
}
.shell{width:min(860px,96vw)}

/* ---------- Header ---------- */
.header-chip{
  display:flex;align-items:center;gap:10px;
  padding:10px 14px;
  background:var(--surface-2);
  border:1px solid rgba(255,255,255,.08);
  border-radius:10px;
}
.header-chip strong{color:var(--text-strong)}
.header-chip .btn-linkx{
  margin-left:auto;
  border:1px solid rgba(255,255,255,.18);
  border-radius:999px;
  padding:.4rem .8rem;
  color:var(--text-normal);
  text-decoration:none;
  background:var(--surface-3);
}
.header-chip .btn-linkx:hover{filter:brightness(1.05)}

/* ---------- Card ---------- */
.cardx{
  margin-top:14px;
  border-radius:10px;
  background:var(--surface);
  border:1px solid rgba(255,255,255,.08);
  color:var(--text-normal);
}
.cardx .head{
  display:flex;align-items:center;justify-content:space-between;
  background:var(--surface-2);
  border-bottom:1px solid rgba(255,255,255,.1);
  padding:16px 18px;
}
.cardx h3{margin:0;font-weight:900;color:#fff}
.cardx .body{padding:20px}

/* ---------- Form ---------- */
label{font-weight:800;color:#fff}
.form-control,.custom-select{
  background:var(--surface-3);
  border:1px solid rgba(255,255,255,.12);
  color:#fff;
  border-radius:8px;
}
.form-control::placeholder{color:#9fb0bb}
.form-control:focus,.custom-select:focus{
  border-color:var(--brand-500);
  box-shadow:0 0 0 3px rgba(46,167,255,.25);
}
.helper{color:var(--text-muted);font-size:.85rem}

/* ---------- Buttons ---------- */
.btn-main{
  font-weight:900;
  border-radius:999px;
  background:linear-gradient(180deg,var(--brand-500),var(--brand-400));
  border:1px solid var(--border-blue);
  color:#fff!important;
  padding:.55rem 1.1rem;
}
.btn-main:hover{filter:brightness(1.08)}
.btn-ghost{
  font-weight:800;
  border-radius:999px;
  background:transparent;
  border:1px solid rgba(255,255,255,.2);
  color:#fff;
  padding:.5rem 1rem;
}
.btn-ghost:hover{filter:brightness(1.1)}
.alert-error{
  background:rgba(229,57,53,.14);
  color:#ffb4b1;
  border:1px solid rgba(229,57,53,.35);
  border-radius:10px;
  font-weight:800;
}

/* ---------- Upload Zone ---------- */
.drop{
  margin-top:6px;
  border:2px dashed rgba(255,255,255,.15);
  border-radius:10px;
  background:var(--surface-2);
  padding:14px;
  text-align:center;
  transition:border-color .15s,background .15s;
}
.drop.dragover{border-color:var(--brand-500);background:var(--surface-3)}
.drop small{color:var(--text-muted)}
.preview{
  display:flex;align-items:center;gap:12px;margin-top:12px;
}
.preview img{
  width:140px;height:140px;object-fit:cover;
  border-radius:8px;
  border:1px solid rgba(255,255,255,.1);
  background:#0f151d;
}
.badge-name{
  display:inline-flex;align-items:center;gap:6px;
  padding:.25rem .6rem;
  border-radius:999px;
  background:var(--surface-3);
  border:1px solid rgba(255,255,255,.14);
  color:var(--text-normal);
  font-weight:800;
}
.btn-danger-soft{
  border-radius:999px;
  padding:.35rem .8rem;
  font-weight:800;
  border:1px solid rgba(229,57,53,.45);
  background:rgba(229,57,53,.15);
  color:#ffd6d5;
}
:focus-visible{outline:3px solid rgba(46,167,255,.45);outline-offset:2px;border-radius:8px}

/* ---------- Scrollbar ---------- */
*::-webkit-scrollbar{width:10px;height:10px}
*::-webkit-scrollbar-thumb{background:#2e3a44;border-radius:10px}
*::-webkit-scrollbar-thumb:hover{background:#3a4752}
*::-webkit-scrollbar-track{background:#151a20}
/* ปุ่มย้อนกลับ — PSU Blue Flat */
.btn-back-blue {
  background: linear-gradient(180deg, var(--brand-500), var(--brand-400));
  border: 1px solid var(--border-blue);
  color: #fff !important;
  font-weight: 900;
  border-radius: 999px;
  padding: .45rem 1rem;
  transition: filter .12s ease, transform .12s ease;
}
.btn-back-blue:hover {
  filter: brightness(1.08);
  transform: translateY(-1px);
}
.btn-back-blue i {
  margin-right: 4px;
}

</style>
</head>
<body>
<div class="shell">

  <div class="header-chip">
    <strong>เพิ่มเมนูใหม่</strong>

    <a href="adminmenu.php" class="btn-linkx">← กลับหน้าจัดการเมนู</a>
  </div>

  <div class="cardx">
    <div class="head">
      <h3>เพิ่มเมนูใหม่</h3>
      <a href="adminmenu.php" class="btn-back-blue btn-sm">
  <i class="bi bi-arrow-left-circle"></i> ย้อนกลับ
</a>

    </div>

    <div class="body">
      <?php if (!empty($message)): ?>
        <div class="alert alert-error mb-3"><?= htmlspecialchars($message,ENT_QUOTES,'UTF-8') ?></div>
      <?php endif; ?>

      <form method="POST" enctype="multipart/form-data" novalidate>
        <div class="form-group">
          <label for="name">ชื่อเมนู</label>
          <input type="text" id="name" name="name" class="form-control" required autofocus placeholder="เช่น ชาเขียวนม">
        </div>

        <div class="form-row">
          <div class="form-group col-md-6">
            <label for="price">ราคา (บาท)</label>
            <input type="number" id="price" name="price" class="form-control" step="0.01" min="0" required placeholder="เช่น 40.00">
            <small id="priceHint" class="helper"></small>
          </div>
          <div class="form-group col-md-6">
            <label for="category_id">หมวดหมู่</label>
            <select id="category_id" name="category_id" class="custom-select" required>
              <option value="">— เลือกหมวดหมู่ —</option>
              <?php while($cat = $categories->fetch_assoc()): ?>
                <option value="<?= (int)$cat['category_id'] ?>"><?= htmlspecialchars($cat['category_name'],ENT_QUOTES,'UTF-8') ?></option>
              <?php endwhile; ?>
            </select>
          </div>
        </div>

        <div class="form-group">
          <label for="image">รูปภาพเมนู</label>
          <div id="drop" class="drop">
            <div><strong>ลาก-วาง</strong> ไฟล์รูปมาวางที่นี่ หรือ <u>คลิกเพื่อเลือกไฟล์</u></div>
            <small>รองรับ: JPG, PNG, GIF, WEBP (สูงสุด 5MB)</small>
            <input type="file" id="image" name="image" accept="image/*" required hidden>
          </div>

          <div class="preview" id="preview" hidden>
            <img id="previewImg" alt="ตัวอย่างรูป">
            <div>
              <div class="badge-name" id="fileName">—</div>
              <div class="mt-2">
                <button type="button" id="removePreview" class="btn-danger-soft btn-sm">ลบรูปออก</button>
              </div>
              <small class="helper d-block mt-1">ตรวจสอบรูปก่อนบันทึก</small>
            </div>
          </div>
        </div>

        <div class="d-flex justify-content-between align-items-center flex-wrap">
          <div class="helper mb-2">ตรวจสอบชื่อ / ราคา / หมวดหมู่ และรูปก่อนกด “บันทึกเมนู”</div>
          <button type="submit" class="btn-main">บันทึกเมนู</button>
        </div>
      </form>
    </div>
  </div>
</div>

<script>
const price=document.getElementById('price'),priceHint=document.getElementById('priceHint');
function fmt(n){return (Number(n)||0).toLocaleString('th-TH',{minimumFractionDigits:2,maximumFractionDigits:2});}
price.addEventListener('input',()=>priceHint.textContent='ตัวอย่าง: '+fmt(price.value)+' บาท');price.dispatchEvent(new Event('input'));
const drop=document.getElementById('drop'),fileInput=document.getElementById('image'),
previewBox=document.getElementById('preview'),previewImg=document.getElementById('previewImg'),
fileName=document.getElementById('fileName'),removeBtn=document.getElementById('removePreview');
drop.addEventListener('click',()=>fileInput.click());
['dragenter','dragover'].forEach(ev=>drop.addEventListener(ev,e=>{e.preventDefault();drop.classList.add('dragover');}));
['dragleave','drop'].forEach(ev=>drop.addEventListener(ev,e=>{e.preventDefault();drop.classList.remove('dragover');}));
drop.addEventListener('drop',e=>{const f=e.dataTransfer.files&&e.dataTransfer.files[0];if(f){fileInput.files=e.dataTransfer.files;showPreview(f);}});
fileInput.addEventListener('change',e=>{const f=e.target.files&&e.target.files[0];if(f)showPreview(f);});
function showPreview(file){const url=URL.createObjectURL(file);previewImg.src=url;fileName.textContent=file.name+' • '+Math.round(file.size/1024)+' KB';previewBox.hidden=false;}
removeBtn.addEventListener('click',()=>{fileInput.value='';previewBox.hidden=true;previewImg.src='';fileName.textContent='—';});
</script>
</body>
</html>
