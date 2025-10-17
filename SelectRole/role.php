<?php
session_start();
if (empty($_SESSION['uid'])) { header("Location: ../index.php"); exit(); }
$username = $_SESSION['username'] ?? '';
$name     = $_SESSION['name'] ?? '';
$display  = $username ?: $name;
?>
<!DOCTYPE html>
<html lang="th">
<head>
<meta charset="UTF-8">
<title>เลือกสิทธิการใช้งาน • PSU Blue Café</title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<link rel="stylesheet"
      href="https://stackpath.bootstrapcdn.com/bootstrap/4.5.2/css/bootstrap.min.css">
<link rel="stylesheet"
      href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css">
<style>
/* ===============================
   PSU BLUE CAFE — Dark Blue Theme
   =============================== */
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
  --radius:10px;
}

/* ---------- Layout ---------- */
html,body{height:100%}
body{
  background:linear-gradient(180deg,var(--bg-grad1),var(--bg-grad2));
  font-family:"Segoe UI",Tahoma,Arial,sans-serif;
  color:var(--text-normal);
  display:flex;align-items:center;justify-content:center;
  padding:20px;
}

/* ---------- Container ---------- */
.container-role{width:100%;max-width:960px}

/* ---------- Top bar ---------- */
.top-chip{
  display:flex;align-items:center;gap:10px;
  background:var(--surface-2);
  border:1px solid rgba(255,255,255,.08);
  border-radius:999px;
  padding:10px 16px;margin-bottom:20px;
}
.brand{font-weight:900;color:var(--brand-500)}
.user strong{color:#fff}
.sp{flex:1}
.btn-logout{
  background:#e53935;border:1px solid #b91c1c;color:#fff!important;
  font-weight:800;border-radius:8px;padding:.4rem .9rem;
  text-decoration:none!important;
}
.btn-logout:hover{filter:brightness(1.08)}

/* ---------- Title ---------- */
h3{
  text-align:center;
  font-weight:900;
  color:#fff;
  margin-bottom:20px;
}

/* ---------- Tiles ---------- */
.row.gx-12{margin-left:-6px;margin-right:-6px}
.row.gx-12>[class^="col"]{padding-left:6px;padding-right:6px}

.tile{
  background:var(--surface);
  border:1px solid rgba(255,255,255,.08);
  border-radius:var(--radius);
  text-align:center;
  color:#fff;
  text-decoration:none!important;
  padding:26px 16px;
  display:flex;flex-direction:column;align-items:center;justify-content:center;
  transition:transform .1s ease,filter .1s ease;
}
.tile:hover{transform:translateY(-2px);filter:brightness(1.05)}
.tile img{width:84px;height:84px;object-fit:contain;margin-bottom:10px}
.tile h5{font-weight:900;margin:0;color:#fff}
.tile .tag{
  margin-top:8px;font-size:.9rem;font-weight:800;
  background:linear-gradient(180deg,#1fd1d6,#0aa9ae);
  border:1px solid #0d8f94;
  color:#062b33;
  border-radius:999px;
  padding:6px 12px;
}

/* ---------- Button base ---------- */
.btn-blue{
  background:linear-gradient(180deg,var(--brand-500),var(--brand-400));
  border:1px solid var(--border-blue);
  color:#fff!important;
  font-weight:800;
  border-radius:8px;
}
.btn-blue:hover{filter:brightness(1.08)}

.muted{color:var(--text-muted)}
</style>
</head>
<body>
<div class="container container-role">

  <!-- Top -->
  <div class="top-chip">
    <div class="brand"><i class="bi bi-cup-hot"></i> PSU Blue Café</div>
    <span class="muted">•</span>
    <div class="user">ผู้ใช้: <strong><?= htmlspecialchars($display,ENT_QUOTES,'UTF-8') ?></strong></div>
    <div class="sp"></div>
    <a href="../logout.php" class="btn-logout">
      <i class="bi bi-box-arrow-right"></i> ออกจากระบบ
    </a>
  </div>

  <h3>เลือกสิทธิการใช้งาน</h3>

  <div class="row gx-12">
    <div class="col-md-4 mb-3">
      <a class="tile" href="../front_store/front_store.php">
        <img src="icons/store.png" alt="หน้าร้าน">
        <h5>หน้าร้าน</h5>
        <div class="tag">สั่ง-คิดเงิน</div>
      </a>
    </div>
    <div class="col-md-4 mb-3">
      <a class="tile" href="../back_store/back_store.php">
        <img src="icons/coffee-machine.png" alt="หลังร้าน">
        <h5>หลังร้าน</h5>
        <div class="tag">จัดเตรียม/อัปเดตสถานะ</div>
      </a>
    </div>
    <div class="col-md-4 mb-3">
      <a class="tile" href="../attendance/check_in.php">
        <img src="icons/register.png" alt="ลงเวลา">
        <h5>ลงเวลาทำงาน</h5>
        <div class="tag">เช็คอิน/เอาท์</div>
      </a>
    </div>
  </div>

</div>
</body>
</html>
