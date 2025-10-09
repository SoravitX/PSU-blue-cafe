<?php
// front_store/front_store.php — POS UI + modal popup + Voice Ready Notification + แสดงโปรโมชันต่อเมนู + UI Icons
// iPad/แท็บเล็ต: 3 คอลัมน์, เดสก์ท็อป: 5 คอลัมน์
declare(strict_types=1);
session_start();
if (empty($_SESSION['uid'])) { header("Location: ../index.php"); exit; }

require __DIR__ . '/../db.php';
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$conn->set_charset('utf8mb4');

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$conn->set_charset('utf8mb4');

/* === Timezone: ให้ MySQL ใช้เวลาไทยเหมือน PHP (สำคัญต่อ CURRENT_DATE()/order_code) === */
date_default_timezone_set('Asia/Bangkok');  // เวลา PHP
$conn->query("SET time_zone = '+07:00'");   // เวลา MySQL session

/* ---------- Helpers ---------- */
function money_fmt($n){ return number_format((float)$n, 2); }
function cart_key(int $menu_id, string $note): string { return $menu_id.'::'.md5(trim($note)); }
function safe_key(string $k): string { return htmlspecialchars($k, ENT_QUOTES, 'UTF-8'); }



/** ฟอร์แมตเลขออเดอร์รายวันเป็น YYMMDD-### */
function format_order_code(string $order_date, int $order_seq): string {
  $d = date_create_from_format('Y-m-d', $order_date) ?: new DateTime($order_date);
  $ymd = $d ? $d->format('ymd') : date('ymd');
  return $ymd . '-' . str_pad((string)$order_seq, 3, '0', STR_PAD_LEFT);
}
/** แปลงเลขลำดับออเดอร์ให้เป็น 3 หลัก เช่น 2 -> 002 */
function format_order_seq(int $order_seq): string {
  return str_pad((string)$order_seq, 3, '0', STR_PAD_LEFT);
}

/**
 * 
 * ดึงราคารวมท็อปปิงและชื่อ จากตาราง toppings เท่านั้น
 * return ['extra' => float, 'names' => string[]]
 */
function toppings_info(mysqli $conn, int $menu_id, array $picked_ids): array {
  $picked_ids = array_values(array_unique(array_map('intval', $picked_ids)));
  if (!$picked_ids) return ['extra'=>0.0, 'names'=>[]];

  $in = implode(',', array_fill(0, count($picked_ids), '?'));
  $types = str_repeat('i', count($picked_ids));

  $sql = "SELECT topping_id, name, base_price AS price
          FROM toppings
          WHERE is_active = 1 AND topping_id IN ($in)";
  $st = $conn->prepare($sql);
  $st->bind_param($types, ...$picked_ids);
  $st->execute();
  $rs = $st->get_result();

  $extra = 0.0; $names = [];
  while ($r = $rs->fetch_assoc()) {
    $extra += (float)$r['price'];
    $names[] = (string)$r['name'];
  }
  $st->close();
  return ['extra'=>$extra, 'names'=>$names];
}

/**
 * คืนโปรโมชัน (แบบ ITEM) ที่ลดเป็น "จำนวนเงิน" ได้มากสุดสำหรับเมนูนี้ ณ ขณะนี้
 * return: ['promo_id'=>int,'name'=>string,'type'=>PERCENT|FIXED,'value'=>float,'amount'=>float] หรือ null
 * หมายเหตุ: amount = เงินที่ลดได้ต่อ 1 หน่วย (คำนวณจากราคา "เมนูฐาน" ไม่รวมท็อปปิง)
 */
function best_item_promo(mysqli $conn, int $menu_id, float $base_price): ?array {
  $sql = "
    SELECT p.promo_id, p.name, p.discount_type, p.discount_value, p.max_discount
    FROM promotion_items pi
    JOIN promotions p ON p.promo_id = pi.promo_id
    WHERE pi.menu_id = ?
      AND p.scope='ITEM'
      AND p.is_active = 1
      AND NOW() BETWEEN p.start_at AND p.end_at
    ORDER BY LEAST(
      CASE WHEN p.discount_type='PERCENT'
           THEN (p.discount_value/100.0)*?
           ELSE p.discount_value
      END,
      COALESCE(p.max_discount, 999999999)
    ) DESC
    LIMIT 1
  ";
  $st = $conn->prepare($sql);
  $st->bind_param('id', $menu_id, $base_price);
  $st->execute();
  $r = $st->get_result()->fetch_assoc();
  $st->close();

  if (!$r) return null;

  $raw = ($r['discount_type']==='PERCENT')
          ? ($r['discount_value']/100.0)*$base_price
          : (float)$r['discount_value'];

  $amount = min($raw, (float)($r['max_discount'] ?? 999999999));
  if ($amount <= 0) return null;

  return [
    'promo_id' => (int)$r['promo_id'],
    'name'     => (string)$r['name'],
    'type'     => (string)$r['discount_type'],
    'value'    => (float)$r['discount_value'],
    'amount'   => (float)$amount,
  ];
}

/* ---------- ฟังก์ชันเรนเดอร์ตะกร้า (ใช้ทั้งหน้า + AJAX) ---------- */
function render_cart_box(): string {
  global $conn; // ใช้เชื่อมต่อฐานข้อมูลเพื่อดึง "ราคาเมนูฐาน"
  ob_start();
  ?>
  <div class="pos-card cart">
    <div class="d-flex align-items-center justify-content-between p-3 pt-3 pb-0">
      <div class="h5 mb-0 font-weight-bold"><i class="bi bi-basket2"></i> ออเดอร์</div>
      <a class="btn btn-sm btn-outline-light" href="front_store.php?action=clear"
         onclick="return confirm('ล้างออเดอร์ทั้งหมด?');"><i class="bi bi-trash3"></i> ล้าง</a>
    </div>
    <hr class="my-2" style="border-color:rgba(255,255,255,.25)">
    <div class="p-2 pt-0">
    <?php if(!empty($_SESSION['cart'])): ?>
      <form method="post" id="frmCart">
        <input type="hidden" name="action" value="update">
        <div class="table-responsive">
          <table class="table table-sm table-cart">
            <thead>
              <tr>
                <th>รายการ</th>
                <th class="text-right">ราคา</th>
                <th class="text-center" style="width:86px;">จำนวน</th>
                <th class="text-right">รวม</th>
                <th></th>
              </tr>
            </thead>
            <tbody>
            <?php
              $gross_total    = 0.0;   // รวมหลังหักโปรแล้ว (สุทธิ)
              $discount_total = 0.0;   // ส่วนลดรวม
              $before_total   = 0.0;   // รวมก่อนหักโปร

              // เตรียม statement ดึงราคาเมนูฐาน
              $stmtBase = $conn->prepare("SELECT price FROM menu WHERE menu_id = ?");
              foreach($_SESSION['cart'] as $key=>$it):
                $qty = (int)($it['qty'] ?? 0);
                $unit_price = (float)($it['price'] ?? 0.0); // หลังหักโปร/หน่วย
                $promo_name = (string)($it['promo_name'] ?? '');
                $unit_discount = (float)($it['unit_discount'] ?? 0.0); // ส่วนลด/หน่วย

                // ราคาเต็ม/หน่วยก่อนหักโปร (ราคาเมนูฐาน + ท็อปปิง)
                $unit_before = $unit_price + $unit_discount;

                // ดึง "ราคาเมนูฐาน" เพื่อคำนวณ "ท็อปปิง"
                $base_price = 0.0;
                $mid = (int)($it['menu_id'] ?? 0);
                if ($mid > 0) {
                  $stmtBase->bind_param("i", $mid);
                  $stmtBase->execute();
                  $res = $stmtBase->get_result();
                  if ($row = $res->fetch_row()) $base_price = (float)$row[0];
                  $res->free();
                }

                // ท็อปปิงต่อหน่วย = (ราคาเต็มก่อนหักโปร/หน่วย) - (ราคาเมนูฐาน)
                $topping_unit = max(0.0, $unit_before - $base_price);
                $topping_line = $topping_unit * $qty;

                $line_after  = $unit_price  * $qty;       // รวมสุทธิแถวนี้
                $line_disc   = $unit_discount * $qty;     // ส่วนลดรวมแถวนี้
                $line_before = $unit_before * $qty;       // รวมก่อนหักโปรแถวนี้

                $gross_total    += $line_after;
                $discount_total += $line_disc;
                $before_total   += $line_before;
            ?>
              <tr>
                <td class="align-middle">
                  <div class="font-weight-bold item-title">
                    <i class="bi bi-cup-hot"></i>
                    <?= htmlspecialchars($it['name'],ENT_QUOTES,'UTF-8') ?>
                  </div>

                  <?php if (!empty($it['note'])): ?>
                    <?php $parts = array_filter(array_map('trim', explode('|', $it['note']))); ?>
                    <div class="note-list">
                      <?php foreach ($parts as $p):
                        $k=''; $v=$p; if (strpos($p, ':')!==false) { [$k,$v] = array_map('trim', explode(':',$p,2)); } ?>
                        <span class="note-pill">
                          <?php if ($k!==''): ?><span class="k"><?= htmlspecialchars($k,ENT_QUOTES,'UTF-8') ?>:</span><?php endif; ?>
                          <span class="v"><?= htmlspecialchars($v,ENT_QUOTES,'UTF-8') ?></span>
                        </span>
                      <?php endforeach; ?>
                    </div>
                  <?php endif; ?>

                  <!-- แสดงราคาท็อปปิง: ต่อหน่วย และรวมตามจำนวน -->
                  <?php if ($topping_unit > 0): ?>
                    <div class="mt-1">
                      <span class="note-pill" title="คิดจาก (ราคาเต็มก่อนหักโปร) - (ราคาเมนูฐาน)">
  <span class="k">ท็อปปิง:</span>
  <span class="v">+<?= number_format($topping_unit,2) ?> ฿/หน่วย</span>
</span>

                    </div>
                  <?php endif; ?>

                  <?php if ($promo_name !== '' && $unit_discount > 0): ?>
                    <div class="mt-1">
                     <span class="note-pill" title="ส่วนลดโปรโมชันถูกหักแล้วในราคาต่อหน่วย">
  <span class="k">โปรฯ:</span>
  <span class="v">
    <?= htmlspecialchars($promo_name,ENT_QUOTES,'UTF-8') ?> — ลด <?= number_format($unit_discount,2) ?> ฿/หน่วย
  </span>
</span>

                    </div>
                  <?php endif; ?>
                </td>

                <td class="text-right align-middle"><?= number_format($unit_price,2) ?></td>
                <td class="text-center align-middle">
                  <input class="form-control form-control-sm" type="number"
                         name="qty[<?= htmlspecialchars($key,ENT_QUOTES,'UTF-8') ?>]"
                         value="<?= $qty ?>" min="0" aria-label="จำนวน">
                </td>
                <td class="text-right align-middle"><?= number_format($line_after,2) ?></td>
                <td class="text-right align-middle">
                  <div class="btn-group btn-group-sm">
                    <a class="btn btn-outline-primary js-edit" title="แก้ไข"
                       data-menu-id="<?= (int)$it['menu_id'] ?>"
                       data-key="<?= htmlspecialchars($key,ENT_QUOTES,'UTF-8') ?>"
                       href="menu_detail.php?id=<?= (int)$it['menu_id'] ?>&edit=1&key=<?= urlencode($key) ?>">
                      <i class="bi bi-pencil-square"></i>
                    </a>
                    <a class="btn btn-outline-danger" title="ลบ"
   href="front_store.php?action=remove&key=<?= urlencode($key) ?>"
   onclick="return confirm('ลบรายการนี้?');">
  <i class="bi bi-trash"></i>
</a>

                  </div>
                </td>
              </tr>
            <?php endforeach; $stmtBase->close(); ?>
            </tbody>
          </table>
        </div>
      </form>

      <?php
        $before_total   = $before_total   ?? 0.0;
        $discount_total = $discount_total ?? 0.0;
        $gross_total    = $gross_total    ?? 0.0;
        $net_total = $gross_total; // สุทธิหลังหักโปร
      ?>
      </div>

      <!-- สรุปยอด -->
      <div class="summary-card p-3 pt-0">
  <div class="summary-row">
    <div class="tag"><i class="bi bi-cash-coin"></i> ยอดรวมก่อนหักโปร</div>
    <div class="font-weight-bold"><?= number_format($before_total,2) ?> ฿</div>
  </div>

  <div class="summary-row" style="margin-top:6px">
    <div class="tag">โปรนี้ช่วยประหยัด</div>
   <div class="font-weight-bold">-<?= number_format($discount_total,2) ?> ฿</div>
  </div>

  <hr class="my-2">

  <div class="summary-row">
    <div class="tag" style="font-weight:900"><i class="bi bi-check2-circle"></i> ยอดสุทธิ</div>
    <div class="h5 mb-0" style="font-weight:900"><?= number_format($net_total,2) ?> ฿</div>
  </div>

  <small class="d-block mt-1">* ยอดสุทธิคือราคาหลังหักโปรแล้ว</small>
</div>


      <div class="p-3">
        <div class="d-flex">
          <button class="btn btn-light mr-2" form="frmCart" style="font-weight:800"><i class="bi bi-arrow-repeat"></i> อัปเดตจำนวน</button>
          <form method="post" class="m-0 flex-fill">
            <input type="hidden" name="action" value="checkout">
            <button class="btn btn-success btn-block" id="btnCheckout" style="font-weight:900; letter-spacing:.2px">
              <i class="bi bi-bag-check"></i> สั่งออเดอร์ (F2)
            </button>
          </form>
        </div>
      </div>
    <?php else: ?>
      <div class="px-3 pb-3 text-light" style="opacity:.9"><i class="bi bi-emoji-neutral"></i> ยังไม่มีสินค้าในออเดอร์</div>
    <?php endif; ?>
  </div>
  <?php
  return ob_get_clean();
}


/* ---------- Cart session ---------- */
if (!isset($_SESSION['cart'])) $_SESSION['cart'] = [];

/* ---------- Actions ---------- */
$action = $_POST['action'] ?? $_GET['action'] ?? '';
$success_msg = '';

/* ---------- Pay by CASH (ไม่ต้องอัปโหลดสลิป) ---------- */
if ($action === 'pay_cash') {
  header('Content-Type: application/json; charset=utf-8');

  $order_id = (int)($_POST['order_id'] ?? 0);
  if ($order_id <= 0) { echo json_encode(['ok'=>false,'msg'=>'ข้อมูลออเดอร์ไม่ถูกต้อง']); exit; }

  $stmt = $conn->prepare("SELECT order_id FROM orders WHERE order_id=?");
  $stmt->bind_param("i", $order_id);
  $stmt->execute();
  $has = (bool)$stmt->get_result()->fetch_row();
  $stmt->close();
  if (!$has) { echo json_encode(['ok'=>false,'msg'=>'ไม่พบออเดอร์']); exit; }

  $poke = $conn->prepare("UPDATE orders SET updated_at = NOW() WHERE order_id = ?");
  $poke->bind_param("i", $order_id);
  $poke->execute();
  $ok = $poke->affected_rows > 0;
  $poke->close();




  if ($ok) { $_SESSION['cart'] = []; }

  echo json_encode(['ok'=>$ok,'msg'=>$ok?'บันทึกชำระเงินสดแล้ว':'บันทึกไม่สำเร็จ','order_id'=>$order_id]);
  exit;
}

/* ---------- Upload Slip (AJAX) ---------- */
if ($action === 'upload_slip') {
  header('Content-Type: application/json; charset=utf-8');

  $order_id = (int)($_POST['order_id'] ?? 0);
  if ($order_id <= 0) {
    echo json_encode(['ok'=>false,'msg'=>'ข้อมูลออเดอร์ไม่ถูกต้อง']); exit;
  }

  // 1) ดึงข้อมูลออเดอร์ให้ได้ก่อน แล้วค่อยคำนวณรหัส
  $stmt = $conn->prepare("SELECT order_id, user_id, total_price, status, order_date, order_seq FROM orders WHERE order_id=?");
  $stmt->bind_param("i", $order_id);
  $stmt->execute();
  $order = $stmt->get_result()->fetch_assoc();
  $stmt->close();

  if (!$order) {
    echo json_encode(['ok'=>false,'msg'=>'ไม่พบออเดอร์']); exit;
  }

  // 2) สร้างรหัส (เต็ม) และเลขลำดับ (3 หลัก)
  $order_code = format_order_code((string)$order['order_date'], (int)$order['order_seq']);
  $order_seq  = format_order_seq((int)$order['order_seq']);

  // 3) ต้องมีไฟล์สลิปจริง ๆ
  if (!isset($_FILES['slip']) || $_FILES['slip']['error'] !== UPLOAD_ERR_OK) {
    echo json_encode(['ok'=>false,'msg'=>'กรุณาเลือกไฟล์สลิปก่อนอัปโหลด','order_id'=>$order_id]); exit;
  }

  // 4) เตรียมตาราง (ครั้งเดียว)
  $conn->query("
    CREATE TABLE IF NOT EXISTS payment_slips (
      id INT AUTO_INCREMENT PRIMARY KEY,
      order_id INT NOT NULL,
      user_id INT NOT NULL,
      file_path VARCHAR(255) NOT NULL,
      mime VARCHAR(64) NOT NULL,
      size_bytes INT NOT NULL,
      uploaded_at DATETIME NOT NULL,
      note VARCHAR(255) DEFAULT NULL,
      INDEX(order_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
  ");

  // 5) ตรวจไฟล์ + เซฟ
  $base = realpath(__DIR__ . '/../uploads') ?: (__DIR__ . '/../uploads');
  $dir  = $base . '/slips';
  if (!is_dir($dir)) { @mkdir($dir, 0775, true); }

  $tmp  = $_FILES['slip']['tmp_name'];
  $size = (int)$_FILES['slip']['size'];
  if ($size > 5 * 1024 * 1024) { echo json_encode(['ok'=>false,'msg'=>'ไฟล์เกิน 5MB']); exit; }

  $fi   = new finfo(FILEINFO_MIME_TYPE);
  $mime = $fi->file($tmp);
  $allow = ['image/jpeg','image/png','image/webp','image/heic','image/heif'];
  if (!in_array($mime, $allow, true)) {
    echo json_encode(['ok'=>false,'msg'=>'รองรับ JPG/PNG/WebP/HEIC เท่านั้น']); exit;
  }

  // ชื่อไฟล์ปลายทาง
  $target = $dir . '/slip_'.$order_id.'_'.date('Ymd_His').'_'.bin2hex(random_bytes(3)).'.jpg';

  $ok = false;
  try {
    switch ($mime) {
      case 'image/jpeg': $im = imagecreatefromjpeg($tmp); break;
      case 'image/png':  $im = imagecreatefrompng($tmp); imagepalettetotruecolor($im); imagealphablending($im,true); imagesavealpha($im,false); break;
      case 'image/webp': $im = function_exists('imagecreatefromwebp') ? imagecreatefromwebp($tmp) : null; break;
      default: $im = null; // HEIC/HEIF → ย้ายไฟล์ดิบ
    }
    if ($im) {
      $w = imagesx($im); $h = imagesy($im);
      $maxSide = 1500;
      if (max($w,$h) > $maxSide) {
        $ratio = min($maxSide/$w, $maxSide/$h);
        $nw = (int)round($w*$ratio); $nh = (int)round($h*$ratio);
        $dst = imagecreatetruecolor($nw, $nh);
        imagecopyresampled($dst, $im, 0,0,0,0, $nw,$nh, $w,$h);
        imagedestroy($im); $im = $dst;
      }
      imagejpeg($im, $target, 82); imagedestroy($im);
      $ok = file_exists($target);
    } else {
      // HEIC/HEIF: เก็บแบบเดิม (เปลี่ยนนามสกุลให้ถูกก็ได้ แต่ที่นี่เก็บเป็น .jpg ชั่วคราว)
      $ok = move_uploaded_file($tmp, $target);
    }
  } catch (Throwable $e) {
    $ok = move_uploaded_file($tmp, $target);
  }

  if (!$ok) { echo json_encode(['ok'=>false,'msg'=>'บันทึกไฟล์ไม่สำเร็จ']); exit; }

  $rel = 'uploads/slips/'.basename($target);
  $note = trim((string)($_POST['note'] ?? ''));
  $uid  = (int)$_SESSION['uid'];
  $sz   = filesize($target) ?: $size;

  // 6) บันทึกข้อมูลไฟล์ + poke updated_at
  $ins = $conn->prepare("INSERT INTO payment_slips (order_id,user_id,file_path,mime,size_bytes,uploaded_at,note)
                         VALUES (?,?,?,?,?,NOW(),?)");
  $mimeSave = 'image/jpeg';
  $ins->bind_param("iissis", $order_id, $uid, $rel, $mimeSave, $sz, $note);
  $ins->execute(); $ins->close();

  $poke = $conn->prepare("UPDATE orders SET updated_at = NOW() WHERE order_id = ?");
  $poke->bind_param("i", $order_id);
  $poke->execute();
  $poke->close();

  // 7) เคลียร์ตะกร้า + ตอบกลับเป็น JSON จริง ๆ
  $_SESSION['cart'] = [];

  echo json_encode([
    'ok'         => true,
    'msg'        => 'อัปโหลดสำเร็จ',
    'path'       => $rel,
    'order_id'   => $order_id,
    'order_code' => $order_code,
    'order_seq'  => $order_seq
  ]);
  exit;
}


/* ---------- Add/Update/Remove/Checkout ---------- */
if ($action === 'add') {
  $menu_id = (int)($_POST['menu_id'] ?? 0);
  $qty     = max(1, (int)($_POST['qty'] ?? 1));
  $note    = trim((string)($_POST['note'] ?? ''));
  $isEdit  = isset($_POST['edit']) && (int)$_POST['edit'] === 1;
  $old_key = (string)($_POST['old_key'] ?? '');

  $addon_total = isset($_POST['addon_total']) ? (float)$_POST['addon_total'] : 0.0;
  if ($addon_total < 0) $addon_total = 0.0;

  $stmt = $conn->prepare("SELECT menu_id, name, price, image FROM menu WHERE menu_id=?");
  $stmt->bind_param("i", $menu_id);
  $stmt->execute();
  $item = $stmt->get_result()->fetch_assoc();
  $stmt->close();

  if ($item) {
    $picked = isset($_POST['toppings']) ? (array)$_POST['toppings'] : [];
    $picked_ids = array_values(array_filter(array_map('intval', $picked)));
    $tp = toppings_info($conn, $menu_id, $picked_ids);

    if (!empty($tp['names'])) {
      if (mb_stripos($note, 'ท็อปปิง:') === false) {
        $note = trim($note);
        $note = $note !== '' ? ($note.' | ท็อปปิง: '.implode(', ', $tp['names'])) : ('ท็อปปิง: '.implode(', ', $tp['names']));
      }
    }

    $addon_total = isset($_POST['addon_total']) ? (float)$_POST['addon_total'] : 0.0;
    if ($addon_total < 0) $addon_total = 0.0;
    $addon_effective = ($tp['extra'] > 0) ? $tp['extra'] : $addon_total;

    // ==== คิดส่วนลดโปรจาก "ราคาเมนูฐาน" ====
    $base_price = (float)$item['price'];
    $appliedPromo = best_item_promo($conn, (int)$menu_id, $base_price);
    $unit_discount = $appliedPromo ? (float)$appliedPromo['amount'] : 0.0;

    // ราคาต่อหน่วยจริง = (ราคาเมนูฐาน + ท็อปปิง) - ส่วนลด
    $unit_price = max(0.0, $base_price + $addon_effective - $unit_discount);

    $new_key = cart_key($menu_id, $note);

    if ($isEdit) {
      if ($old_key !== '' && isset($_SESSION['cart'][$old_key])) unset($_SESSION['cart'][$old_key]);
      if (isset($_SESSION['cart'][$new_key])) {
        $_SESSION['cart'][$new_key]['qty']  += $qty;
        $_SESSION['cart'][$new_key]['note']  = $note;
        $_SESSION['cart'][$new_key]['price'] = $unit_price;

        $_SESSION['cart'][$new_key]['promo_id']      = $appliedPromo ? (int)$appliedPromo['promo_id'] : null;
        $_SESSION['cart'][$new_key]['promo_name']    = $appliedPromo ? (string)$appliedPromo['name']  : '';
        $_SESSION['cart'][$new_key]['unit_discount'] = $unit_discount;
      } else {
        $_SESSION['cart'][$new_key] = [
          'menu_id' => $menu_id,
          'name'    => $item['name'],
          'price'   => $unit_price,
          'qty'     => $qty,
          'image'   => (string)$item['image'],
          'note'    => $note,
          'promo_id'      => $appliedPromo ? (int)$appliedPromo['promo_id'] : null,
          'promo_name'    => $appliedPromo ? (string)$appliedPromo['name']  : '',
          'unit_discount' => $unit_discount,
        ];
      }
    } else {
      if (isset($_SESSION['cart'][$new_key])) {
        $_SESSION['cart'][$new_key]['qty']  += $qty;
        $_SESSION['cart'][$new_key]['price'] = $unit_price;

        $_SESSION['cart'][$new_key]['promo_id']      = $appliedPromo ? (int)$appliedPromo['promo_id'] : null;
        $_SESSION['cart'][$new_key]['promo_name']    = $appliedPromo ? (string)$appliedPromo['name']  : '';
        $_SESSION['cart'][$new_key]['unit_discount'] = $unit_discount;
      } else {
        $_SESSION['cart'][$new_key] = [
          'menu_id' => $menu_id,
          'name'    => $item['name'],
          'price'   => $unit_price,
          'qty'     => $qty,
          'image'   => (string)$item['image'],
          'note'    => $note,
          'promo_id'      => $appliedPromo ? (int)$appliedPromo['promo_id'] : null,
          'promo_name'    => $appliedPromo ? (string)$appliedPromo['name']  : '',
          'unit_discount' => $unit_discount,
        ];
      }
    }
  }

  if (!empty($_SERVER['HTTP_X_REQUESTED_WITH'])) {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
      'ok'    => true,
      'count' => isset($_SESSION['cart']) ? count($_SESSION['cart']) : 0,
    ]);
    exit;
  }
}

if ($action === 'update') {
  foreach ($_POST['qty'] ?? [] as $key=>$q) {
    $q = max(0, (int)$q);
    if (isset($_SESSION['cart'][$key])) {
      if ($q===0) unset($_SESSION['cart'][$key]);
      else $_SESSION['cart'][$key]['qty'] = $q;
    }
  }
}

if ($action === 'remove') {
  $key = (string)($_GET['key'] ?? '');
  if ($key !== '' && isset($_SESSION['cart'][$key])) unset($_SESSION['cart'][$key]);
}

if ($action === 'clear') { $_SESSION['cart'] = []; }

/* ----- CHECKOUT ----- */
$new_order_id = 0; $new_total = 0.00; $new_order_code = '';
if ($action === 'checkout' && !empty($_SESSION['cart'])) {
  $total = 0.00;
  foreach ($_SESSION['cart'] as $row) $total += ((float)$row['price']) * ((int)$row['qty']);

  $stmt = $conn->prepare("INSERT INTO orders (user_id, order_time, status, total_price)
                          VALUES (?, NOW(), 'pending', ?)");
  $stmt->bind_param("id", $_SESSION['uid'], $total);
  $stmt->execute();
  $order_id = $stmt->insert_id;
  $stmt->close();

  foreach ($_SESSION['cart'] as $row) {
    $line = ((int)$row['qty']) * ((float)$row['price']);
    $promoId = $row['promo_id'] ?? null;

    if ($promoId === null) {
      $stmt = $conn->prepare("INSERT INTO order_details (order_id, menu_id, promo_id, quantity, note, total_price)
                              VALUES (?, ?, NULL, ?, ?, ?)");
      $stmt->bind_param("iiisd", $order_id, $row['menu_id'], $row['qty'], $row['note'], $line);
    } else {
      $stmt = $conn->prepare("INSERT INTO order_details (order_id, menu_id, promo_id, quantity, note, total_price)
                              VALUES (?, ?, ?, ?, ?, ?)");
      $stmt->bind_param("iiiisd", $order_id, $row['menu_id'], $promoId, $row['qty'], $row['note'], $line);
    }
    $stmt->execute();
    $stmt->close();
  }

  // 👇 ดึงรหัสรายวัน (YYMMDD-###) ออกมาใช้แสดงผล

// ดึง order_date + order_seq แล้วเตรียมทั้งแบบเต็ม และแบบ seq-only
$stmt = $conn->prepare("SELECT order_date, order_seq FROM orders WHERE order_id=?");
$stmt->bind_param("i", $order_id);
$stmt->execute();
$stmt->bind_result($od, $os);
$stmt->fetch();
$stmt->close();

$new_order_id     = $order_id;
$new_total        = $total;
$new_order_code   = ($od && $os) ? format_order_code((string)$od, (int)$os) : ('#'.$order_id); // ยังเก็บไว้ใช้ภายใน
$new_order_seq    = ($os) ? format_order_seq((int)$os) : '';                                  // <<< ใช้แสดงผลแทน


}


/* ---------- AJAX: กล่องตะกร้า ---------- */
if ($action === 'cart_html') {
  header('Content-Type: text/html; charset=utf-8');
  echo render_cart_box();
  exit;
}

/* ---------- Data ---------- */
$cat_raw     = $_GET['category_id'] ?? '0';
$isTop       = ($cat_raw === 'top');
$category_id = $isTop ? 0 : (int)$cat_raw;
$keyword     = trim((string)($_GET['q'] ?? ''));
$paid_flag = isset($_GET['paid']) ? (int)$_GET['paid'] : 0;
$paid_oid  = isset($_GET['oid'])  ? (int)$_GET['oid']  : 0;
$paid_oseq = isset($_GET['oseq']) ? trim((string)$_GET['oseq']) : ''; // <<< seq-only จาก query
if ($paid_flag === 1 && $paid_oid > 0) {
  if ($paid_oseq === '') {
    $g = $conn->prepare("SELECT order_seq FROM orders WHERE order_id=?");
    $g->bind_param("i", $paid_oid);
    $g->execute();
    $g->bind_result($os);
    if ($g->fetch()) { $paid_oseq = format_order_seq((int)$os); }
    $g->close();
  }
  $label = $paid_oseq !== '' ? $paid_oseq : ('#'.$paid_oid);
  $success_msg = "สั่งออเดอร์แล้ว! เลขที่ออเดอร์ {$label}"; // <<< แสดงเฉพาะ seq
}




$cats = $conn->query("SELECT category_id, category_name FROM categories ORDER BY category_id");

/* ===== Active item promotions per menu (เลือกโปรที่ลด 'จำนวนเงิน' สูงสุด) ===== */
$promoJoin = "
LEFT JOIN (
  SELECT
    pi.menu_id,
    SUBSTRING_INDEX(
      GROUP_CONCAT(
        p.promo_id ORDER BY
          LEAST(
            CASE WHEN p.discount_type='PERCENT' THEN (p.discount_value/100.0)*m.price ELSE p.discount_value END,
            COALESCE(p.max_discount, 999999999)
          ) DESC
        SEPARATOR ','
      ),
      ',', 1
    ) AS best_promo_id,
    SUBSTRING_INDEX(
      GROUP_CONCAT(
        p.name ORDER BY
          LEAST(
            CASE WHEN p.discount_type='PERCENT' THEN (p.discount_value/100.0)*m.price ELSE p.discount_value END,
            COALESCE(p.max_discount, 999999999)
          ) DESC
        SEPARATOR ','
      ),
      ',', 1
    ) AS promo_name,
    SUBSTRING_INDEX(
      GROUP_CONCAT(
        p.discount_type ORDER BY
          LEAST(
            CASE WHEN p.discount_type='PERCENT' THEN (p.discount_value/100.0)*m.price ELSE p.discount_value END,
            COALESCE(p.max_discount, 999999999)
          ) DESC
        SEPARATOR ','
      ),
      ',', 1
    ) AS discount_type,
    SUBSTRING_INDEX(
      GROUP_CONCAT(
        p.discount_value ORDER BY
          LEAST(
            CASE WHEN p.discount_type='PERCENT' THEN (p.discount_value/100.0)*m.price ELSE p.discount_value END,
            COALESCE(p.max_discount, 999999999)
          ) DESC
        SEPARATOR ','
      ),
      ',', 1
    ) AS discount_value,
    MAX(
      LEAST(
        CASE WHEN p.discount_type='PERCENT' THEN (p.discount_value/100.0)*m.price ELSE p.discount_value END,
        COALESCE(p.max_discount, 999999999)
      )
    ) AS discount_amount,
    SUBSTRING_INDEX(
      GROUP_CONCAT(
        p.scope ORDER BY
          LEAST(
            CASE WHEN p.discount_type='PERCENT' THEN (p.discount_value/100.0)*m.price ELSE p.discount_value END,
            COALESCE(p.max_discount, 999999999)
          ) DESC
        SEPARATOR ','
      ),
      ',', 1
    ) AS promo_scope,
    SUBSTRING_INDEX(
      GROUP_CONCAT(
        DATE_FORMAT(p.start_at, '%Y-%m-%d %H:%i:%s') ORDER BY
          LEAST(
            CASE WHEN p.discount_type='PERCENT' THEN (p.discount_value/100.0)*m.price ELSE p.discount_value END,
            COALESCE(p.max_discount, 999999999)
          ) DESC
        SEPARATOR ','
      ),
      ',', 1
    ) AS promo_start_at,
    SUBSTRING_INDEX(
      GROUP_CONCAT(
        DATE_FORMAT(p.end_at, '%Y-%m-%d %H:%i:%s') ORDER BY
          LEAST(
            CASE WHEN p.discount_type='PERCENT' THEN (p.discount_value/100.0)*m.price ELSE p.discount_value END,
            COALESCE(p.max_discount, 999999999)
          ) DESC
        SEPARATOR ','
      ),
      ',', 1
    ) AS promo_end_at,
    SUBSTRING_INDEX(
      GROUP_CONCAT(
        p.is_active ORDER BY
          LEAST(
            CASE WHEN p.discount_type='PERCENT' THEN (p.discount_value/100.0)*m.price ELSE p.discount_value END,
            COALESCE(p.max_discount, 999999999)
          ) DESC
        SEPARATOR ','
      ),
      ',', 1
    ) AS promo_is_active
  FROM promotion_items pi
  JOIN promotions p ON p.promo_id = pi.promo_id
  JOIN menu m       ON m.menu_id = pi.menu_id
  WHERE p.is_active = 1 AND p.scope='ITEM' AND NOW() BETWEEN p.start_at AND p.end_at
  GROUP BY pi.menu_id
) ap ON ap.menu_id = m.menu_id
";

/* ----- เมนู (ยอดนิยม/ปกติ) พร้อมคอลัมน์โปรโมชัน ----- */
if ($isTop) {
  $sql = "SELECT 
            m.menu_id, m.name, m.price, m.image, c.category_name,
            (SELECT COALESCE(SUM(d.quantity),0)
               FROM order_details d
              WHERE d.menu_id = m.menu_id
            ) AS total_sold,
            ap.best_promo_id, ap.promo_name, ap.discount_type, ap.discount_value, ap.discount_amount,
            ap.promo_scope, ap.promo_start_at, ap.promo_end_at, ap.promo_is_active
          FROM menu m
          LEFT JOIN categories c ON m.category_id = c.category_id
          $promoJoin
          WHERE m.is_active = 1";
  $types = ''; $params = [];

  if ($keyword !== '') { 
    $sql .= " AND m.name LIKE ?"; 
    $types .= 's'; 
    $params[] = '%'.$keyword.'%'; 
  }

  $sql .= " ORDER BY total_sold DESC, m.menu_id ASC LIMIT 12";

  if ($types !== '') {
    $stmt = $conn->prepare($sql);
    $stmt->bind_param($types, ...$params);
    $stmt->execute(); 
    $menus = $stmt->get_result(); 
    $stmt->close();
  } else {
    $menus = $conn->query($sql);
  }

  if ($menus && $menus->num_rows === 0) {
    $sqlAll = "SELECT 
                 m.menu_id, m.name, m.price, m.image, c.category_name,
                 ap.best_promo_id, ap.promo_name, ap.discount_type, ap.discount_value, ap.discount_amount,
                 ap.promo_scope, ap.promo_start_at, ap.promo_end_at, ap.promo_is_active
               FROM menu m
               LEFT JOIN categories c ON m.category_id=c.category_id
               $promoJoin
               WHERE m.is_active = 1";
    $typesAll = ''; 
    $paramsAll = [];
    if ($keyword !== '') {
      $sqlAll   .= " AND m.name LIKE ?";
      $typesAll .= 's';
      $paramsAll[] = '%'.$keyword.'%';
    }
    $sqlAll .= " ORDER BY m.menu_id";
    if ($typesAll !== '') {
      $stmt = $conn->prepare($sqlAll);
      $stmt->bind_param($typesAll, ...$paramsAll);
      $stmt->execute();
      $menus = $stmt->get_result();
      $stmt->close();
    } else {
      $menus = $conn->query($sqlAll);
    }
  }

} else {
  $sql = "SELECT 
            m.menu_id, m.name, m.price, m.image, c.category_name,
            ap.best_promo_id, ap.promo_name, ap.discount_type, ap.discount_value, ap.discount_amount,
            ap.promo_scope, ap.promo_start_at, ap.promo_end_at, ap.promo_is_active
          FROM menu m 
          LEFT JOIN categories c ON m.category_id=c.category_id
          $promoJoin
          WHERE m.is_active = 1";
  $types=''; $params=[];
  if ($category_id>0) { $sql.=" AND m.category_id=?"; $types.='i'; $params[]=$category_id; }
  if ($keyword!=='')  { $sql.=" AND m.name LIKE ?";   $types.='s'; $params[]='%'.$keyword.'%'; }
  $sql .= " ORDER BY m.menu_id";
  if ($types!=='') {
    $stmt = $conn->prepare($sql);
    $stmt->bind_param($types, ...$params);
    $stmt->execute(); 
    $menus = $stmt->get_result(); 
    $stmt->close();
  } else {
    $menus = $conn->query($sql);
  }
}
?>
<!doctype html>
<html lang="th">
<head>
<meta charset="utf-8">
<title>PSU Blue Cafe • Menu</title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<link rel="stylesheet"
 href="https://stackpath.bootstrapcdn.com/bootstrap/4.5.2/css/bootstrap.min.css">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css">

<style>
  /* ริบบอนโปรโมชัน: teal เด่นบนพื้นกราไฟต์ */
.product-mini .ribbon{
  background:linear-gradient(180deg,#00ADB5,#0A8A90);
}

/* ปุ่ม “เลือก”: teal → aqua ไล่เฉด */
.product-mini .quick{
  background:linear-gradient(180deg,var(--brand-500),var(--brand-400));
  border:1px solid color-mix(in oklab, var(--brand-700), black 12%);
}

/* ตัวหนังสือราคาพิเศษให้ตัดกับพื้น */
.product-mini .pprice{ color: var(--brand-500); }

/* เส้นตาราง/เส้นคั่นให้เข้ากับโทน */
.table-cart thead th{ border-bottom:2px solid color-mix(in oklab, var(--brand-500), white 45%) }
.table-cart td,.table-cart th{ border-color: color-mix(in oklab, var(--brand-700), white 65%) !important }

/* =============================
   Blue Cafe – Refined UI Theme
   - ไม่ใช้พื้นหลัง "ขาวล้วน"
   - เพิ่ม Light/Dark Theme
   - เน้นการอ่าน + ปุ่มกดเด่น
   ============================= */

/* ---------- Design Tokens ---------- */
:root{
  /* ===== EXISTING ===== */
  --text-strong:#e8f1ff;
  --text-normal:#d2e4ff;
  --text-muted:#9cb6d8;

  --bg-grad1:#0a1a30;
  --bg-grad2:#0D4071;

  --surface:#0f223a;
  --surface-2:#0a1f36;
  --surface-3:#122a47;
  --ink:#eaf3ff;
  --ink-muted:#b2c5e3;

  --shadow-lg:0 22px 66px rgba(0,0,0,.55);
  --shadow:   0 14px 32px rgba(0,0,0,.42);

  /* ===== NEW: brand palette for the base (light) theme ===== */
  --brand-900:#eaf3ff;
  --brand-700:#b2c5e3;
  --brand-500:#2c8bd6;   /* ฟ้าไฮไลต์ที่ใช้ในยอดสุทธิ */
  --brand-400:#5cb0ff;
  --brand-300:#a6d3ff;

  /* mapping สำหรับปุ่ม gradient ที่อิง aqua-* */
  --aqua-500: var(--brand-500);
  --aqua-400: var(--brand-400);

  /* controls */
  --radius:14px;
  --ring: color-mix(in oklab, var(--brand-400), white 40%);
}




/* ---------- Dark Theme ---------- */
:root[data-theme="dark"]{
  /* Teal-Graphite Dark */
  --text-strong:#F4F7F8;
  --text-normal:#E6EBEE;
  --text-muted:#B9C2C9;

  --bg-grad1:#222831;    /* graphite deep */
  --bg-grad2:#393E46;    /* graphite */

  --surface:#1C2228;     /* not pure black */
  --surface-2:#232A31;
  --surface-3:#2B323A;
  --ink:#F4F7F8;
  --ink-muted:#CFEAED;

  --brand-900:#EEEEEE;   /* for badges on dark */
  --brand-700:#BFC6CC;
  --brand-500:#00ADB5;   /* accent unchanged */
  --brand-400:#27C8CF;
  --brand-300:#73E2E6;

  --aqua-500:#00ADB5;
  --aqua-400:#5ED8DD;
  --mint-300:#223037;
  --violet-200:#5C6A74;

  --shadow-lg:0 22px 66px rgba(0,0,0,.55);
  --shadow:   0 14px 32px rgba(0,0,0,.42);
}


/* ---------- Page Base ---------- */
html,body{height:100%}
body{
  background:linear-gradient(135deg,var(--bg-grad1),var(--bg-grad2));
  color:var(--ink);
  font-family: "Segoe UI", Tahoma, Arial, sans-serif;
}

/* ---------- Layout Shell ---------- */
.pos-shell{padding:12px;max-width:1600px;margin:0 auto;}
.topbar{
  position:sticky; top:0; z-index:50;
  padding:12px 16px; border-radius:14px;
  background:color-mix(in oklab, var(--surface), black 6%);
  backdrop-filter:blur(6px);
  border:1px solid color-mix(in oklab, var(--violet-200), black 10%);
  box-shadow:0 8px 20px rgba(0,0,0,.18)
}
.brand{color:var(--text-strong); font-weight:900; letter-spacing:.3px}
.brand i{opacity:.95; margin-right:6px}

/* ---------- Buttons ---------- */
.btn-ghost{
  background:linear-gradient(180deg,var(--aqua-400),var(--aqua-500));
  border:1px solid color-mix(in oklab, var(--brand-700), black 10%);
  color:#fff; font-weight:800
}
.btn-ghost:hover{filter:brightness(1.05)}
.btn-primary,.btn-success{font-weight:800}

.topbar .btn-primary{
  background:linear-gradient(180deg,#3aa3ff,#1f7ee8);
  border-color:#1669c9;
}

/* ---------- Chips / Filters ---------- */
.pos-card{
  background:color-mix(in oklab, var(--surface), white 6%);
  border:1px solid color-mix(in oklab, var(--violet-200), black 12%);
  border-radius:var(--radius); box-shadow:var(--shadow)
}
.chips a{
  display:inline-flex; align-items:center; gap:8px;
  padding:7px 14px; margin:0 8px 10px 0; border-radius:999px;
  border:1px solid color-mix(in oklab, var(--brand-500), black 8%);
  color:var(--text-strong); text-decoration:none; font-weight:800;
  background:color-mix(in oklab, var(--surface), white 8%);
  transition:transform .12s, box-shadow .12s
}
.chips a .bi{font-size:1.05rem}
.chips a.active{
  background:linear-gradient(180deg,var(--brand-400),var(--mint-300));
  color:#03203e; border-color:#073c62;
  box-shadow:0 8px 18px rgba(0,0,0,.15)
}
.chips a:hover{transform:translateY(-1px)}

/* ---------- Search ---------- */
.search-wrap{ position:relative; display:inline-block; }
.search-wrap .bi{ position:absolute; left:12px; top:50%; transform:translateY(-50%); color:var(--text-muted) }
.search-wrap .searchbox{
  background:var(--surface);
  border:2px solid color-mix(in oklab, var(--brand-500), white 10%);
  color:var(--ink);
  border-radius:999px; padding:.42rem .9rem .42rem 36px; min-width:260px
}
.searchbox:focus{box-shadow:0 0 0 .18rem color-mix(in oklab, var(--aqua-500), white 45%)}

/* ---------- Grid ---------- */
.menu-grid{
  display:grid; grid-template-columns:repeat(5,1fr); gap:12px; padding:12px
}
@media (min-width:768px) and (max-width:1399px){.menu-grid{grid-template-columns:repeat(3,1fr)}}
@media (max-width:767px){.menu-grid{grid-template-columns:repeat(2,1fr)}}

/* ---------- Product Card ---------- */
.product-mini{
  position:relative; background:var(--surface);
  border:1px solid color-mix(in oklab, var(--violet-200), black 8%);
  border-radius:14px; overflow:hidden; color:inherit; text-decoration:none;
  display:flex; flex-direction:column; height:100%;
  transition:transform .12s, box-shadow .12s, border-color .12s
}
.product-mini:focus,.product-mini:hover{
  transform:translateY(-2px);
  border-color:color-mix(in oklab, var(--brand-300), black 8%);
  box-shadow:0 10px 20px rgba(0,0,0,.16); outline:none
}
.product-mini .thumb{
  width:100%; height:120px; object-fit:cover;
  background:color-mix(in oklab, var(--surface-2), white 8%)
}
.product-mini .ribbon{
  position:absolute; left:-6px; top:10px;
  background:linear-gradient(180deg,#2ecc71,#1b9c4a);
  color:#fff; padding:6px 10px; font-weight:900; font-size:.8rem; letter-spacing:.2px;
  border-radius:0 10px 10px 0; box-shadow:0 6px 14px rgba(0,0,0,.18)
}
.product-mini .ribbon i{ margin-right:6px; }
.product-mini .meta{padding:10px 12px 12px}
.product-mini .pname{
  font-weight:900; color:var(--text-strong); line-height:1.15; font-size:1.0rem;
  display:-webkit-box; -webkit-line-clamp:2; -webkit-box-orient:vertical; overflow:hidden; min-height:2.3em
}
.product-mini .row2{display:flex; align-items:center; justify-content:space-between; margin-top:8px}
.product-mini .pprice{font-weight:900; color:var(--brand-400); font-size:1.05rem; letter-spacing:.2px}
.product-mini .quick{
  font-size:.85rem; font-weight:800; padding:6px 12px; border-radius:999px;
  background:linear-gradient(180deg,var(--aqua-400),var(--aqua-500));
  border:1px solid color-mix(in oklab, var(--brand-700), black 10%); color:#fff;
  transition:transform .08s, filter .12s
}
.product-mini:hover .quick{ transform:translateY(-1px); }
.product-mini .text-muted{ color:var(--text-muted)!important }

/* ---------- Cart ---------- */
.cart{position:sticky; top:82px}
.table-cart{
  color:var(--text-normal); background:var(--surface);
  border-radius:12px; overflow:hidden; table-layout:auto
}
.table-cart thead th{
  background:var(--surface-2); color:var(--text-strong);
  border-bottom:2px solid color-mix(in oklab, var(--brand-400), white 40%); font-weight:800
}
.table-cart td,.table-cart th{
  border-color:color-mix(in oklab, var(--brand-400), white 60%)!important
}
.table-cart thead th:first-child,.table-cart tbody td:first-child{width:58%}
.table-cart tbody tr:hover td{ background:color-mix(in oklab, var(--surface-3), white 8%); }
.table-cart tbody tr:not(:last-child) td{border-bottom:2px dashed color-mix(in oklab, var(--brand-500), white 55%)!important}

.note-list{display:flex; flex-wrap:wrap; gap:6px; margin-top:6px}
.note-pill{
  display:inline-flex; align-items:center; background:var(--surface-2);
  border:1px solid color-mix(in oklab, var(--brand-300), white 40%);
  border-radius:999px; padding:4px 10px; font-size:.82rem; font-weight:800
}
.note-pill .k{color:var(--brand-400); margin-right:6px}
.note-pill .v{color:var(--text-strong)}

.cart-footer{
  background:linear-gradient(180deg,var(--brand-500),var(--brand-900));
  color:#fff; border-top:1px solid var(--brand-900);
  padding:12px 14px; border-radius:0 0 14px 14px
}

.summary-row{ display:flex; justify-content:space-between; align-items:center; gap:10px }
.summary-row .tag{ display:inline-flex; align-items:center; gap:8px; font-weight:800 }
.summary-row .tag i{ opacity:.9 }

/* ===== Payment method pills: clearer selected state ===== */
.psu-radio{
  display:inline-flex; align-items:center; border-radius:999px;
  background: var(--surface-2);
  border:1.5px solid color-mix(in oklab, var(--brand-700), black 10%);
  padding:6px 10px; color:var(--text-strong); font-weight:800; cursor:pointer;
  transition:filter .15s, border-color .15s, transform .05s;
}
.psu-radio:hover{ filter:brightness(1.04); }
.psu-radio:active{ transform:scale(.99); }

.psu-radio input{ display:none; }

/* ตัวแคปซูลข้อความด้านใน */
.psu-radio span{
  display:inline-flex; align-items:center; gap:8px;
  padding:8px 14px; border-radius:999px;
  color:var(--ink); line-height:1;
  border:1px solid transparent;
}

/* ========== SELECTED ========== */
/* โทนแบน/ชัดเจน: พื้นเทียล, ตัวอักษรขาว, เส้นขอบสว่าง */
.psu-radio input:checked + span{
  background: var(--aqua-500);           /* #00ADB5 */
  color:#fff;
  border-color: color-mix(in oklab, var(--aqua-400), white 15%);
  box-shadow:
    0 0 0 2px color-mix(in oklab, var(--aqua-500), black 25%) inset,
    0 0 0 3px color-mix(in oklab, var(--aqua-500), white 65%); /* วงแหวนอ่อนรอบๆ ให้เด่นขึ้น */
}

/* คีย์บอร์ดโฟกัสให้เห็นชัด */
.psu-radio:has(input:focus-visible){
  outline:3px solid color-mix(in oklab, var(--aqua-500), white 35%);
  outline-offset:3px; border-radius:14px;
}

/* ---------- Payment / Dropzone ---------- */
#uploadZone,#cashZone{ color:var(--text-strong) }
#uploadZone label,#cashZone label{ font-weight:800; color:var(--brand-700) }
#dropzone{
  border:2px dashed var(--brand-400);
  background:var(--surface-2);
  color:var(--text-normal);
  transition:all .2s; border-radius:12px; padding:16px; text-align:center; cursor:pointer
}
#dropzone:hover{ filter:brightness(1.02) }
#dropzone .lead{color:var(--text-strong); font-weight:900}
#dropzone .small{color:var(--text-muted)}
#btnUpload,#btnCashConfirm{
  background:linear-gradient(180deg,var(--brand-500),var(--brand-900));
  border:none; font-weight:900; letter-spacing:.5px
}
#btnUpload:hover,#btnCashConfirm:hover{ filter:brightness(1.05) }
#btnSlipCancel,#btnSlipCancel2{
  border:1px solid var(--violet-200); color:var(--text-strong); font-weight:800; background:var(--surface)
}
#btnSlipCancel:hover,#btnSlipCancel2:hover{ filter:brightness(1.03) }
#cashZone .alert-info{
  background:var(--surface-2); border:1px solid var(--brand-400);
  color:var(--text-strong); font-weight:800; border-radius:12px
}

/* ---------- Modal ---------- */
.psu-modal{position:fixed; inset:0; display:none; z-index:1050}
.psu-modal.is-open{display:block}
.psu-modal__backdrop{position:absolute; inset:0; background:rgba(3,12,24,.55); backdrop-filter:blur(2px)}
.psu-modal__dialog{
  position:absolute; left:50%; top:50%; transform:translate(-50%,-50%);
  width:min(1020px,96vw); max-height:92vh; overflow:auto; background:var(--surface);
  border-radius:20px; box-shadow:var(--shadow-lg);
  border:1px solid color-mix(in oklab, var(--violet-200), black 12%)
}
.psu-modal__body{padding:0}
.psu-modal__close{
  position:absolute; right:12px; top:8px; border:0; background:transparent;
  font-size:32px; font-weight:900; line-height:1; cursor:pointer; color:var(--text-strong)
}

/* ---------- Misc / A11y ---------- */
.alert-ok{background:#2e7d32; color:#fff; border:none}
.badge-user{
  background:linear-gradient(180deg,var(--brand-400),var(--brand-700));
  color:#fff; font-weight:800; border-radius:999px
}
.voice-toggle{
  display:inline-flex; align-items:center; gap:6px; padding:4px 10px; border-radius:999px;
  background:color-mix(in oklab, var(--surface), white 5%);
  border:1px solid color-mix(in oklab, var(--violet-200), black 18%); font-weight:800
}

:focus-visible{outline:3px solid var(--ring); outline-offset:2px; border-radius:10px}
*::-webkit-scrollbar{width:10px;height:10px}
*::-webkit-scrollbar-thumb{background:color-mix(in oklab, var(--brand-700), black 8%); border-radius:10px}
*::-webkit-scrollbar-thumb:hover{background:color-mix(in oklab, var(--brand-500), black 10%)}
*::-webkit-scrollbar-track{background:color-mix(in oklab, var(--surface-2), white 4%)}

/* Toast */
#toast-zone > div{ border:1px solid color-mix(in oklab, var(--violet-200), black 20%) }
.summary-card{
  background: var(--surface-2);
  border-top:1px solid color-mix(in oklab, var(--brand-400), white 35%);
  border-bottom-left-radius:14px;
  border-bottom-right-radius:14px;
}
.summary-card .amount{ font-weight:800; color:var(--text-strong) }
.summary-card .net{ font-weight:900; color:var(--brand-500) }
/* Summary block: dark bg + white text */
.summary-card{
  background: var(--surface-2);
  border-top:1px solid color-mix(in oklab, var(--brand-400), white 35%);
  border-bottom-left-radius:14px;
  border-bottom-right-radius:14px;
}

/* ทำให้ตัวอักษรในบล็อคนี้ “ขาว” ทั้งหมด */
.summary-card,
.summary-card *{
  color:#fff !important;
}

/* เส้นคั่นให้กลืนกับพื้นหลังเข้ม */
.summary-card hr{
  border-color: rgba(255,255,255,.25);
}
/* ชื่อเมนูในตะกร้าให้ออกขาว */
.table-cart .item-title{
  color:#fff !important;             /* หรือใช้ var(--text-strong) ก็ได้ */
  /* color:var(--text-strong) !important; */
}
/* === Reduce corner radius for cards/components === */
:root{
  --radius: 8px;              /* ใช้กับ .pos-card (เผื่อมีการอ้างถึงตัวแปร) */
}

/* กล่องการ์ดหลัก, การ์ดสินค้า, ตารางตะกร้า, ท็อปบาร์, โมดัล */
.pos-card,
.product-mini,
.table-cart,
.topbar,
.psu-modal__dialog{
  border-radius: 8px !important;
}

/* ส่วนหัว/ภาพในการ์ดสินค้า (เผื่ออยากให้รับกับมุมการ์ดใหม่) */
.product-mini .thumb{
  border-top-left-radius: 8px;
  border-top-right-radius: 8px;
}

/* ปุ่ม/ชิป/พิลล์ถ้าต้องการลดความโค้งลงด้วย (เลือกใช้ได้) */
.note-pill{
  border-radius: 8px !important;
}
.chips a{
  border-radius: 12px !important; /* จาก 999px ลดลงหน่อย แต่ยังดูเป็นชิป */
}
/* Fix: header ของตารางตะกร้าเป็นเส้นเดียว ไม่ซ้อน */
.table-cart{
  border-collapse: separate;     /* ปลอดภัยกับเงา/มุมโค้ง */
  border: 0;                     /* ไม่ให้กรอบตารางหลักโผล่ */
}
.table-cart thead th{
  border-top: 0 !important;      /* ปิดเส้นบนที่ Bootstrap ใส่ให้ */
  border-bottom: 0 !important;   /* ไม่ใช้เส้นที่ระดับเซลล์ */
}
.table-cart thead tr{
  background: var(--surface-2);  /* พื้นหัวตารางเหมือนเดิม */
  border-bottom: 2px solid color-mix(in oklab, var(--brand-400), white 40%);
}

/* กันเส้นซ้อนในบอดี้ขึ้นมาชนหัวตาราง */
.table-cart tbody td{
  border-top: 0 !important;
}

/* (ถ้าอยากให้แถวคั่นเป็นเส้นประต่อไป ก็เก็บอันนี้ไว้ได้) */
.table-cart tbody tr:not(:last-child) td{
  border-bottom: 2px dashed color-mix(in oklab, var(--brand-500), white 55%) !important;
}
.table-cart tbody tr{
  position:relative;
}
.table-cart tbody tr:not(:last-child)::after{
  content:"";
  position:absolute; left:50%; transform:translateX(-50%);
  bottom:-7px; width:60%; height:6px; border-radius:999px;
  background: color-mix(in oklab, var(--brand-500), white 80%);
  box-shadow: 0 1px 0 color-mix(in oklab, var(--brand-500), black 40%) inset;
  opacity:.45;
}
.table-cart tbody td{ border-bottom:0 !important; }
/* ซีบร้าลายแถว */
.table-cart tbody tr:nth-child(odd) td{
  background: color-mix(in oklab, var(--surface-3), white 6%);
}
.table-cart tbody tr:nth-child(even) td{
  background: color-mix(in oklab, var(--surface-2), white 6%);
}
/* เส้นคั่นบางสีเทียลอ่อน */
.table-cart tbody tr:not(:last-child) td{
  border-bottom: 1.5px solid color-mix(in oklab, var(--brand-500), white 60%) !important;
}
/* ===== Upload button — Emerald high contrast ===== */
#btnUpload{
  background: linear-gradient(180deg, #22C55E, #16A34A); /* emerald -> darker emerald */
  color:#fff;
  border:1.5px solid #15803D;
  box-shadow:
    0 1px 0 rgba(0,0,0,.25) inset,
    0 8px 18px rgba(0,0,0,.22);
  font-weight:900; letter-spacing:.3px;
  border-radius:12px;
  transition: filter .15s, transform .05s, box-shadow .15s;
}
#btnUpload:hover{
  filter:brightness(1.06);
  box-shadow:
    0 1px 0 rgba(0,0,0,.25) inset,
    0 10px 22px rgba(0,0,0,.26);
}
#btnUpload:active{ transform: translateY(1px); }
#btnUpload:focus-visible{
  outline:3px solid rgba(34,197,94,.55); /* emerald focus ring */
  outline-offset:2px;
}
#btnUpload:disabled{
  opacity:.65; cursor:not-allowed; filter:none;
  box-shadow:0 1px 0 rgba(0,0,0,.25) inset;
}
/* ==== Strong Accent Buttons (Upload/Cash) ==== */
#btnUpload,
#btnCashConfirm{
  /* โทนฟ้าเด่น ตัดกับพื้นเข้ม */
  background: linear-gradient(180deg, #2ea7ff 0%, #1f7ee8 100%);
  border: 1px solid #1669c9;
  color: #fff !important;
  font-weight: 900;
  letter-spacing: .2px;
  box-shadow: 0 8px 20px rgba(31,126,232,.35), inset 0 -2px 0 rgba(0,0,0,.15);
  text-shadow: 0 1px 0 rgba(0,0,0,.25);
}

#btnUpload:hover,
#btnCashConfirm:hover{
  filter: brightness(1.05);
  box-shadow: 0 10px 24px rgba(31,126,232,.45), inset 0 -2px 0 rgba(0,0,0,.18);
}

#btnUpload:active,
#btnCashConfirm:active{
  transform: translateY(1px);
  box-shadow: 0 6px 14px rgba(31,126,232,.35) inset;
}

#btnUpload:focus-visible,
#btnCashConfirm:focus-visible{
  outline: 3px solid rgba(46,167,255,.55);
  outline-offset: 2px;
  border-radius: 10px;
}

/* Disabled */
#btnUpload:disabled,
#btnCashConfirm:disabled{
  opacity: .65;
  cursor: not-allowed;
  box-shadow: none;
}

/* ปุ่ม “ยกเลิก” ให้คงคอนทราสต์ชัดในดาร์ค */
#btnSlipCancel,
#btnSlipCancel2{
  border: 1px solid #8fb9ff;
  color: #eaf3ff;
  background: #203145;
}
#btnSlipCancel:hover,
#btnSlipCancel2:hover{
  filter: brightness(1.05);
}
/* ==== Category chip – ACTIVE (ตามภาพ) ==== */
.chips a{
  display:inline-flex; align-items:center; gap:8px;
  padding:7px 14px; margin:0 8px 10px 0; border-radius:999px;
  background:color-mix(in oklab, var(--surface), white 8%);
  color:var(--text-strong); text-decoration:none; font-weight:800;
  border:1px solid color-mix(in oklab, var(--brand-700), black 18%);
  transition:filter .12s, transform .08s;
}
.chips a:hover{ transform:translateY(-1px); }

.chips a.active{
  background: linear-gradient(180deg, #1fd1d6, #0aa9ae);   /* เทียลสว่าง -> เข้ม */
  color: #062b33;                                          /* ตัวอักษรเข้มอ่านง่าย */
  border: 1.5px solid #0d8f94;                             /* ขอบเทียลเข้ม */
  box-shadow:
    inset 0 -2px 0 rgba(0,0,0,.15),                        /* เงาด้านล่างด้านใน */
    0 6px 14px rgba(0,173,181,.30);                        /* เงาด้านนอกนุ่มๆ */
}
.chips a.active .bi{ filter: drop-shadow(0 0 0 rgba(0,0,0,0)); opacity:.95; }

.chips a:focus-visible{
  outline: 3px solid rgba(31,209,214,.45);                 /* วงแหวนโฟกัส */
  outline-offset: 2px;
  border-radius: 999px;
}
/* === Compact size for CTA 'เลือก' === */
.product-mini .row2 .quick{
  min-width: 84px;          /* เดิม ~92px */
  padding: 6px 12px;        /* เดิม 8px 14px */
  font-size: .86rem;        /* เดิม .92rem */
  border-radius: 999px;     /* รักษาทรงแคปซูล */
}

/* เล็กลงอีกนิดเฉพาะจอเล็ก */
@media (max-width: 575.98px){
  .product-mini .row2 .quick{
    min-width: 78px;
    padding: 5px 10px;
    font-size: .84rem;
  }
}
/* === Compact size for CTA 'เลือก' === */
.product-mini .row2 .quick{
  min-width: 84px;          /* เดิม ~92px */
  padding: 6px 12px;        /* เดิม 8px 14px */
  font-size: .86rem;        /* เดิม .92rem */
  border-radius: 999px;     /* รักษาทรงแคปซูล */
}

/* เล็กลงอีกนิดเฉพาะจอเล็ก */
@media (max-width: 575.98px){
  .product-mini .row2 .quick{
    min-width: 78px;
    padding: 5px 10px;
    font-size: .84rem;
  }
}
/* === CTA 'เลือก' : Blue on Dark, White text === */
:root{
  --cta-blue-500:#2ea7ff;   /* ฟ้าน้ำเงินสด */
  --cta-blue-600:#1f7ee8;   /* ฟ้าเข้มลงสำหรับไล่เฉด */
}

.product-mini .row2 .quick{
  /* ขนาดกะทัดรัด */
  min-width: 84px;
  padding: 6px 12px;
  font-size: .86rem;
  border-radius: 999px;

  /* โทนสี */
  background: linear-gradient(180deg, var(--cta-blue-500), var(--cta-blue-600));
  color:#fff;                              /* ตัวอักษรสีขาว */
  border: 2px solid #1669c9;               /* เส้นขอบน้ำเงินเข้ม */
  text-shadow: 0 1px 0 rgba(0,0,0,.25);    /* ช่วยให้อ่านชัดขึ้นบนพื้นฟ้า */
  box-shadow: 0 10px 22px rgba(0,0,0,.25), inset 0 -2px 0 rgba(0,0,0,.12);
  transform: translateY(0);
  transition: transform .08s, filter .12s, box-shadow .12s;
}

.product-mini:hover .row2 .quick{
  transform: translateY(-2px);
  filter: brightness(1.05);
  box-shadow: 0 12px 26px rgba(0,0,0,.30), inset 0 -2px 0 rgba(0,0,0,.16);
}

/* โฟกัสจากคีย์บอร์ดให้เห็นชัด */
.product-mini .row2 .quick:focus-visible{
  outline: 3px solid rgba(46,167,255,.55);
  outline-offset: 2px;
  border-radius: 10px;
}

/* Disabled */
.product-mini .row2 .quick[disabled]{
  opacity:.6; cursor:not-allowed; filter:none; transform:none; box-shadow:none;
}

/* จอเล็ก: ย่ออีกนิด */
@media (max-width: 575.98px){
  .product-mini .row2 .quick{
    min-width: 78px;
    padding: 5px 10px;
    font-size: .84rem;
  }
}
:root{ --pay-blue:#1f7ee8; } /* ปรับเฉดน้ำเงินได้ */

#slipModal .psu-radio:has(input:checked){
  background: transparent !important;   /* ซ่อนกรอบนอก */
  border-color: transparent !important;
  box-shadow: none !important;
  outline: none !important;
}

/* เลือกแล้ว = น้ำเงินล้วน + ขอบขาวชัด */
#slipModal .psu-radio input:checked + span{
  background: var(--pay-blue) !important;
  color: #fff !important;
  border: 2px solid #fff !important;    /* <<< ขอบเส้นสีขาว */
  box-shadow: none !important;
  text-shadow: none !important;
}

/* (ไม่บังคับ) ถ้าอยากให้ปุ่มที่ยังไม่ถูกเลือกมีเส้นขาวจาง ๆ ด้วย */
#slipModal .psu-radio input:not(:checked) + span{
  border: 1.5px solid rgba(255,255,255,.28);
}
/* ===========================
   Minimal • Clean • Readable
   วางไว้ท้ายไฟล์เพื่อ override
   =========================== */

/* โทนสีเรียบ */
:root{
  --bg-grad1:#11161b;
  --bg-grad2:#141b22;
  --surface:#1a2230;
  --surface-2:#192231;
  --surface-3:#202a3a;

  --ink:#e9eef6;           /* ตัวอักษรหลัก */
  --ink-muted:#b9c6d6;
  --text-strong:#ffffff;

  --brand-500:#3aa3ff;     /* ฟ้าเรียบ */
  --brand-400:#7cbcfd;
  --brand-300:#a9cffd;

  --radius:10px;           /* มุมโค้งพอดีๆ */
  --shadow:   0 6px 16px rgba(0,0,0,.25);
  --shadow-lg:0 10px 24px rgba(0,0,0,.3);
}

/* พื้นหลังหน้า = แบน เรียบ */
body{
  background: linear-gradient(180deg,var(--bg-grad1),var(--bg-grad2));
  color: var(--ink);
  letter-spacing:.1px;
}

/* ตัดเงาหนัก / เกลี่ยขอบให้บางลง */
.pos-card,
.product-mini,
.topbar,
.psu-modal__dialog{
  background: var(--surface);
  border: 1px solid rgba(255,255,255,.06);
  border-radius: var(--radius);
  box-shadow: none;          /* เอาเงาออกให้ดูสะอาด */
}

/* Topbar เรียบ + ระยะหายใจ */
.topbar{
  background: var(--surface-2);
  border: 1px solid rgba(255,255,255,.08);
  padding: 10px 14px;
  box-shadow: none;
}
.brand{ color:#fff; font-weight:800 }

/* Search box แบน */
.search-wrap .searchbox{
  background: var(--surface-3);
  border: 1px solid rgba(255,255,255,.10);
  border-radius: 12px;
  padding:.44rem 0.9rem .44rem 36px;
}
.searchbox:focus{ box-shadow: 0 0 0 3px rgba(58,163,255,.18) }

/* ปุ่มหลัก = สีเดียว แบน */
.btn-ghost,
.btn-primary,
#btnUpload,
#btnCashConfirm{
  background: var(--brand-500) !important;
  border: 1px solid #1e6acc !important;
  color:#fff !important;
  font-weight:800;
  box-shadow:none !important;
  text-shadow:none !important;
}
.btn-ghost:hover,
.btn-primary:hover,
#btnUpload:hover,
#btnCashConfirm:hover{ filter:brightness(1.06) }

/* ปุ่มรอง (ขอบบาง) */
.btn-outline-light,
#btnSlipCancel,
#btnSlipCancel2{
  background: transparent !important;
  color: var(--ink) !important;
  border: 1px solid rgba(255,255,255,.18) !important;
}

/* หมวดหมู่ (chips) แบน + ชัด */
.chips a{
  background: var(--surface-3);
  border: 1px solid rgba(255,255,255,.10);
  color: var(--ink);
  padding: 8px 12px;
  border-radius: 12px;
  box-shadow:none;
}
.chips a.active{
  background: var(--brand-500);
  color:#08121f;
  border-color:#1e6acc;
  box-shadow:none;
}

/* การ์ดสินค้าเรียบ */
.product-mini{
  border-radius: 10px;
}
.product-mini .thumb{
  height: 120px;
  background: var(--surface-2);
}
.product-mini .pname{
  font-size: .98rem;
  line-height: 1.25;
  color:#fff;
}
.product-mini .pprice{
  color:#fff;
  font-weight:900;
}
/* ป้ายโปรเล็ก อ่านง่าย */
.product-mini .ribbon{
  top:12px; left:12px;
  border-radius: 6px;
  padding: 4px 8px;
  background: #18b37e;
  box-shadow:none;
}

/* ปุ่ม “เลือก” = แคปซูลเรียบ */
.product-mini .quick{
  background: var(--brand-500);
  border: 1px solid #1e6acc;
  color:#fff;
  border-radius: 999px;
  padding: 6px 12px;
  font-weight:800;
  box-shadow:none;
}

/* ตารางตะกร้า = เส้นบาง สีจาง */
.table-cart{
  background: var(--surface);
  color: var(--ink);
  border: 0;
}
.table-cart thead th{
  background: var(--surface-2);
  border-bottom: 1px solid rgba(255,255,255,.10) !important;
}
.table-cart td, .table-cart th{
  border-color: rgba(255,255,255,.08) !important;
}
.table-cart tbody tr:hover td{
  background: var(--surface-2);
}

/* pill หมายเหตุ/ท็อปปิง */
.note-pill{
  background: var(--surface-3);
  border: 1px solid rgba(255,255,255,.10);
  color: var(--ink);
  border-radius: 10px;
}
.note-pill .k{ color: var(--brand-300) }
.note-pill .v{ color: #fff }

/* สรุปยอด = แบ็กกราวด์เข้ม + ตัวเลขเด่น */
.summary-card{
  background: var(--surface-2);
  border: 0;
}
.summary-card .h5,
.summary-card .amount,
.summary-card .net{ color:#fff !important; }
.summary-row .tag{ color:var(--ink-muted) }

/* ตัวเลือกวิธีชำระแบบแคปซูลเรียบ */
.psu-radio{
  background: var(--surface-3);
  border: 1px solid rgba(255,255,255,.12);
  box-shadow:none;
}
.psu-radio input:checked + span{
  background: var(--brand-500);
  color:#071627;
  border: 1px solid #1e6acc;
  box-shadow:none;
}

/* โมดัลเรียบ */
.psu-modal__backdrop{ background: rgba(0,0,0,.55) }
.psu-modal__dialog{ border: 1px solid rgba(255,255,255,.08) }

/* เลิกใช้ drop-shadow หนัก ๆ บนไอคอน/ป้าย */
.badge, .ribbon, .quick, .chips a{ text-shadow:none !important; box-shadow:none !important; }

/* ขนาดตัวอักษรเล็กน้อยให้เสมอ ๆ */
body, .table, .btn, input, label, .badge{ font-size: 14.5px; }
/* เปลี่ยนสีพื้นหลังป้ายผู้ใช้ */
.badge-user{
  background: linear-gradient(180deg, #1fd1d6, #0aa9ae); /* เทียลไล่เฉดนุ่มๆ */
  color: #062b33;                  /* ตัวอักษรเข้ม อ่านชัดบนพื้นสว่าง */
  border: 1.5px solid #0d8f94;     /* เส้นขอบให้คมขึ้น */
  border-radius: 999px;
  font-weight: 800;
}

/* ถ้าอยากเรียบไม่ไล่เฉด ใช้แบบแบน */
.badge-user{ /* เลือกใช้แทนบล็อกบนได้ */
  background: #2ea7ff;             /* ฟ้านุ่ม */
  color: #082238;
  border: 1px solid #1669c9;
}
/* ===== Cart pills: ลบไอคอน + ปรับสีให้เหมือน "น้ำแข็ง" ===== */
/* ซ่อนไอคอนเฉพาะบรรทัดท็อปปิง/โปรโมชัน */
.note-pill > i.bi-egg-fried,
.note-pill > i.bi-tags-fill{
  display: none !important;
}

/* ให้ข้อความในสองบรรทัดนี้เป็นสีเดียวกับค่า (เช่น "น้ำแข็ง: ...") */
.note-pill:has(> i.bi-egg-fried),
.note-pill:has(> i.bi-tags-fill){
  color: var(--text-strong) !important;   /* ขาว/สว่างเหมือน .note-pill .v */
  font-weight: 800;
}

/* ===== สรุปยอด: "โปรนี้ช่วยประหยัด" เป็นสีเขียว ===== */
/* แถวที่ 2 ของ summary-card คือบรรทัด "โปรนี้ช่วยประหยัด" */
.summary-card .summary-row + .summary-row .tag,
.summary-card .summary-row + .summary-row .font-weight-bold{
  color: #22c55e !important;             /* emerald green */
}

/* (ถ้าอยากให้ตัวเลขขึ้นสีเขียวด้วยแต่คงเครื่องหมายลบไว้) */
.summary-card .summary-row + .summary-row .font-weight-bold {
  font-weight: 900;
}
/* ซ่อนไอคอนสองรายการ (คง DOM ไว้เพื่อเลือกด้วย :has) */
.note-pill > i.bi-egg-fried,
.note-pill > i.bi-tags-fill{
  display:none !important;
}

/* ให้ทั้งบรรทัดท็อปปิง/โปรฯ เป็นสีเดียวกับ "น้ำแข็ง" (var(--text-strong)) */
.note-pill:has(> i.bi-egg-fried),
.note-pill:has(> i.bi-tags-fill){
  color: var(--text-strong) !important;  /* เหมือน .note-pill .v */
  font-weight: 800;
}
/* === Tweak: smaller product cards + wider order panel === */

/* เพิ่มจำนวนคอลัมน์และลดช่องว่างเล็กน้อย */
.menu-grid{
  gap:10px !important;
}

/* จอใหญ่ (≥1400px): จาก 5 → 6 คอลัมน์ ให้การ์ดเล็กลงโดยรวม */
@media (min-width:1400px){
  .menu-grid{ grid-template-columns: repeat(6,1fr) !important; }
}

/* แท็บเล็ต/แล็ปท็อประดับกลาง (768–1399px): จาก 3 → 4 คอลัมน์ */
@media (min-width:768px) and (max-width:1399px){
  .menu-grid{ grid-template-columns: repeat(4,1fr) !important; }
}

/* ย่อองค์ประกอบภในการ์ด */
.product-mini .thumb{ height:100px !important; }          /* เดิม 120px */
.product-mini .meta{  padding:8px 10px 10px !important; }
.product-mini .pname{
  font-size:.90rem !important; line-height:1.2;
  -webkit-line-clamp:2;
}
.product-mini .pprice{ font-size:1.0rem !important; }

/* ปุ่ม เลือก ให้เล็กลง */
.product-mini .row2 .quick{
  min-width:72px !important;
  padding:5px 10px !important;
  font-size:.82rem !important;
}

/* ลดมาร์จิน/เงาเพื่อให้ภาพรวมแน่นขึ้นเล็กน้อย */
.product-mini{
  box-shadow:none !important;
}
/* --- Stack: ราคาอยู่บน ปุ่มอยู่ล่าง --- */
.product-mini .row2{
  display:flex !important;
  flex-direction: column !important;   /* เรียงบน→ล่าง */
  align-items: flex-start !important;   /* ชิดซ้าย */
  gap: 6px;                             /* ระยะห่างเล็กน้อย */
}

/* ไม่ให้สัญลักษณ์ ฿ หลุดลงบรรทัดใหม่ */
.product-mini .pprice{
  white-space: nowrap;                  /* ราคา + ฿ อยู่บรรทัดเดียว */
  line-height: 1.1;
}

/* ให้ปุ่มชิดซ้ายและเป็นบล็อกใต้ราคา */
.product-mini .row2 .quick{
  align-self: flex-start;               /* ชิดซ้ายใต้ราคา */
  display: inline-block;
}
/* ——— ย่อฟอนต์โปรโมชันในหน้าเมนู ——— */

/* 1) ป้ายริบบอนมุมการ์ด (คำว่า โปร) */
.product-mini .ribbon{
  font-size: .72rem;     /* เดิม ~.80rem */
  padding: 4px 8px;      /* ลด padding ให้สมส่วน */
  letter-spacing: .15px; /* อ่านง่ายขึ้นตอนฟอนต์เล็ก */
}

/* 2) ป้ายเขียวใต้ชื่อเมนู (โปร: xxx -xx%) */
.product-mini .meta .badge-success{
  font-size: .70rem;     /* ย่อข้อความโปร */
  padding: 2px 6px;      /* ให้ขนาดป้ายไม่เทอะทะ */
  border-radius: 6px;
  line-height: 1.05;
}
.product-mini .meta .badge-success .bi{
  font-size: .9em;       /* ย่อไอคอนดาวให้เข้าฟอนต์ */
  margin-right: 4px;
}

/* (ไม่บังคับ) ย่อเพิ่มในจอเล็ก */
@media (max-width: 575.98px){
  .product-mini .ribbon{ font-size: .68rem; padding: 3px 7px; }
  .product-mini .meta .badge-success{ font-size: .66rem; padding: 2px 6px; }
}
/* Menu popup smaller on desktop/tablet */
#menuModal .psu-modal__dialog{
  width: min(820px, 88vw) !important;   /* เดิม min(1020px,96vw) */
  max-height: 88vh !important;          /* ไม่ให้ยาวเกินจอ */
}
#menuModal .psu-modal__body{ padding: 0 !important; }
/* ==== Slip upload zone: gray bg + white text ==== */
#dropzone{
  background: #3a3a3a !important;                 /* พื้นหลังเทา */
  border: 2px dashed rgba(255,255,255,.45) !important;
  color: #fff !important;
}
#dropzone .lead,
#dropzone .small,
#dropzone .text-muted{                             /* "รองรับ JPG, PNG, WebP, HEIC..." */
  color: #fff !important;
  opacity: .95;
}

/* ==== Amount line in slip modal: white ==== */
#slipBody .text-muted{                             /* "ยอดที่ต้องชำระ:" */
  color: #fff !important;
}
#slipAmount{                                       /* ตัวเลข 38.00 ฿ */
  color: #fff !important;
  font-weight: 900;
}

</style>
</head>
<body>
<div class="container-fluid pos-shell">

  <!-- Top bar -->
  <div class="topbar d-flex align-items-center justify-content-between mb-3">
    <div class="d-flex align-items-center">
      <h4 class="brand mb-0 mr-3"><i class="bi bi-cup-hot"></i>Menu</h4>

      <form class="form-inline" method="get" action="front_store.php">
        <div class="search-wrap mr-2">
          <i class="bi bi-search"></i>
          <input name="q" value="<?= htmlspecialchars($keyword,ENT_QUOTES,'UTF-8') ?>" class="form-control form-control-sm searchbox" type="search" placeholder="ค้นหารายการ (กด / เพื่อค้นหา)">
        </div>
        <?php if($category_id>0){ ?><input type="hidden" name="category_id" value="<?= (int)$category_id ?>"><?php } ?>
        <?php if($isTop){ ?><input type="hidden" name="category_id" value="top"><?php } ?>
        <button class="btn btn-sm btn-ghost"><i class="bi bi-arrow-right-circle"></i> ค้นหา</button>
      </form>
    </div>

    <div class="d-flex align-items-center topbar-actions">
     

      <a href="order.php" class="btn btn-primary btn-sm mr-2" style="font-weight:800"><i class="bi bi-receipt"></i> ออเดอร์</a>
      <a href="../SelectRole/role.php" class="btn btn-primary btn-sm mr-2" style="font-weight:800"><i class="bi bi-person-badge"></i> บทบาท</a>
      <a href="user_profile.php" class="btn btn-primary btn-sm mr-2" style="font-weight:800"><i class="bi bi-person-circle"></i> ข้อมูลส่วนตัว</a>
      <span class="badge badge-user px-3 py-2 mr-2"><i class="bi bi-person"></i> ผู้ใช้: <?= htmlspecialchars($_SESSION['username'] ?? '', ENT_QUOTES,'UTF-8') ?></span>

      <!-- NEW: Theme toggle -->
      <button id="themeToggle" class="btn btn-sm btn-ghost mr-2" type="button" title="สลับธีม (Light/Dark)">
        <i class="bi bi-moon-stars"></i>
      </button>

      <a class="btn btn-sm btn-outline-light" href="../logout.php"><i class="bi bi-box-arrow-right"></i> ออกจากระบบ</a>
    </div>
  </div>

  <?php if ($success_msg): ?>
    <div class="alert alert-ok pos-card p-3 mb-3">
      <i class="bi bi-check2-circle"></i>
      <?= htmlspecialchars($success_msg, ENT_QUOTES, 'UTF-8') ?>
      &nbsp;&nbsp;<a class="btn btn-light btn-sm" href="order.php"><i class="bi bi-arrow-up-right-square"></i> ไปหน้า Order</a>
    </div>
  <?php endif; ?>
<?php if (!empty($new_order_id)): ?>
<script>
  document.addEventListener('DOMContentLoaded', () => {
    const oid  = <?= (int)$new_order_id ?>;
    const amt  = "<?= number_format((float)$new_total, 2) ?>";
    const oseq = <?= json_encode($new_order_seq ?? '', JSON_UNESCAPED_UNICODE) ?>; // <<< seq-only
    if (typeof openSlipModal === 'function') {
      openSlipModal(oid, amt, oseq);  // <<< ส่ง seq-only
    } else {
      setTimeout(() => { try { openSlipModal(oid, amt, oseq); } catch(_) {} }, 0);
    }
  });
</script>


<?php endif; ?>


  <!-- CHIPS -->
  <div class="pos-card p-3 mb-3">
    <div class="d-flex align-items-center flex-wrap chips">
      <div class="mr-2 text-white-50 font-weight-bold"><i class="bi bi-columns-gap"></i> หมวดหมู่:</div>

      <?php $topLink = 'front_store.php?category_id=top' . ($keyword!==''?('&q='.urlencode($keyword)):''); ?>
      <a href="<?= htmlspecialchars($topLink,ENT_QUOTES,'UTF-8') ?>" class="<?= $isTop ? 'active' : '' ?>"><i class="bi bi-bar-chart-fill"></i> ยอดนิยม</a>

      <a href="front_store.php<?= $keyword!==''?('?q='.urlencode($keyword)) : '' ?>" class="<?= (!$isTop && $category_id===0)?'active':'' ?>"><i class="bi bi-grid-3x3-gap"></i> ทั้งหมด</a>

      <?php while($c=$cats->fetch_assoc()):
        $link = "front_store.php?category_id=".(int)$c['category_id'].($keyword!==''?('&q='.urlencode($keyword)):''); ?>
        <a href="<?= htmlspecialchars($link,ENT_QUOTES,'UTF-8') ?>" class="<?= (!$isTop && $category_id===(int)$c['category_id'])?'active':'' ?>">
          <i class="bi bi-tag"></i> <?= htmlspecialchars($c['category_name'],ENT_QUOTES,'UTF-8') ?>
        </a>
      <?php endwhile; ?>
    </div>
  </div>

  <div class="row">
    <!-- เมนู -->
    <div class="col-xl-7 col-lg-7 col-md-6 mb-3">
      <div class="pos-card">
        <?php if($menus && $menus->num_rows>0): ?>
          <div class="menu-grid">
            <?php while($m=$menus->fetch_assoc()):
              $img = trim((string)$m['image']);
              $imgPathFs = __DIR__ . "/../admin/images/" . ($img !== '' ? $img : "default.png");
              $imgSrc    = "../admin/images/" . ($img !== '' ? $img : "default.png");
              if (!file_exists($imgPathFs)) $imgSrc = "https://via.placeholder.com/600x400?text=No+Image";

              $hasPromo  = isset($m['discount_amount']) && (float)$m['discount_amount'] > 0;
              $final     = $hasPromo ? max(0, (float)$m['price'] - (float)$m['discount_amount']) : (float)$m['price'];

              $promoTag = '';
              if ($hasPromo) {
                if ((string)$m['discount_type'] === 'PERCENT') {
                  $pct = rtrim(rtrim(number_format((float)$m['discount_value'], 2, '.', ''), '0'), '.');
                  $promoTag = ($m['promo_name'] ? $m['promo_name'].' ' : '')."-{$pct}%";
                } else {
                  $promoTag = ($m['promo_name'] ? $m['promo_name'].' ' : '').'-'.number_format((float)$m['discount_amount'], 2).'฿';
                }
              }
            ?>
              <a class="product-mini" href="menu_detail.php?id=<?= (int)$m['menu_id'] ?>" data-id="<?= (int)$m['menu_id'] ?>" tabindex="0">
                <?php if ($hasPromo): ?>
                  <div class="ribbon"><i class="bi bi-tags"></i> โปร</div>
                <?php endif; ?>
                <img class="thumb" src="<?= htmlspecialchars($imgSrc,ENT_QUOTES,'UTF-8') ?>" alt="">
                <div class="meta">
                  <div class="pname"><?= htmlspecialchars($m['name'],ENT_QUOTES,'UTF-8') ?></div>

                  <?php if ($hasPromo): ?>
                    <div class="mt-1">
                      <span class="badge badge-success" style="font-weight:800">
                        <i class="bi bi-stars"></i>
                        โปร: <?= htmlspecialchars($promoTag, ENT_QUOTES, 'UTF-8') ?>
                      </span>
                    </div>
                  <?php endif; ?>

<?php
$promoScope   = $m['promo_scope'] ?? '';
$promoType    = $m['discount_type'] ?? '';
$promoValue   = isset($m['discount_value']) ? (float)$m['discount_value'] : 0.0;
$promoStart   = $m['promo_start_at'] ?? '';
$promoEnd     = $m['promo_end_at'] ?? '';
$promoActive  = isset($m['promo_is_active']) ? ((int)$m['promo_is_active'] === 1) : false;
$valText = ($promoType === 'PERCENT')
  ? rtrim(rtrim(number_format($promoValue, 2, '.', ''), '0'), '.') . '%'
  : number_format($promoValue, 2) . ' ฿';
$saveText = number_format((float)$m['discount_amount'], 2);
?>
                  <div class="row2">
                    <div class="pprice">
                      <?php if ($hasPromo): ?>
                        <div style="line-height:1">
                          <div class="text-muted" style="text-decoration:line-through; font-weight:700;">
                            <?= money_fmt($m['price']) ?> ฿
                          </div>
                          <div style="font-weight:900;">
                            <?= money_fmt($final) ?> ฿
                          </div>
                        </div>
                      <?php else: ?>
                        <?= money_fmt($final) ?> ฿
                      <?php endif; ?>
                    </div>
                    <span class="quick"><i class="bi bi-plus-circle"></i> เลือก</span>
                  </div>
                </div>
              </a>
            <?php endwhile; ?>
          </div>
        <?php else: ?>
          <div class="p-3"><div class="alert alert-warning m-0"><i class="bi bi-exclamation-triangle"></i> ไม่พบสินค้า</div></div>
        <?php endif; ?>
      </div>
    </div>

    <!-- ตะกร้า -->
    <div class="col-xl-5 col-lg-5 col-md-6 mb-3"> 
      <div id="cartBox">
        <?= render_cart_box(); ?>
      </div>
    </div>
  </div>
</div>

<!-- ===== Menu Detail Modal ===== -->
<div id="menuModal" class="psu-modal" aria-hidden="true">
  <div class="psu-modal__backdrop"></div>
  <div class="psu-modal__dialog">
    <button type="button" class="psu-modal__close" id="menuModalClose" aria-label="Close">&times;</button>
    <div class="psu-modal__body" id="menuModalBody">
      <div class="text-center py-5">กำลังโหลด…</div>
    </div>
  </div>
</div>

<!-- ===== Slip Upload / Payment Modal ===== -->
<div id="slipModal" class="psu-modal" aria-hidden="true">
  <div class="psu-modal__backdrop"></div>
  <div class="psu-modal__dialog" style="max-width:720px">
    <button type="button" class="psu-modal__close" id="slipClose" aria-label="Close">&times;</button>
    <div class="psu-modal__body" id="slipBody">
      <div class="p-3 p-md-4">
        <div class="d-flex justify-content-between align-items-center mb-2">
          <div class="h5 mb-0"><i class="bi bi-cash-stack"></i> ยืนยันการชำระเงิน</div>
          <span class="badge badge-primary" id="slipBadge" style="font-size:.95rem"></span>
        </div>
        <div class="text-muted mb-3">ยอดที่ต้องชำระ: <strong id="slipAmount">0.00</strong> ฿</div>

        <div class="form-group mb-2">
          <label class="font-weight-bold d-block mb-2">วิธีชำระ</label>
          <div class="d-flex">
            <label class="psu-radio mr-3">
              <input type="radio" name="pmethod" value="transfer" checked>
              <span>💳 โอนเงิน (อัปโหลดสลิป)</span>
            </label>
            <label class="psu-radio">
              <input type="radio" name="pmethod" value="cash">
              <span>💵 เงินสด (ไม่ต้องแนบสลิป)</span>
            </label>
          </div>
        </div>

        <!-- UPLOAD ZONE -->
        <div id="uploadZone">
          <form id="frmSlip" enctype="multipart/form-data">
            <input type="hidden" name="action" value="upload_slip">
            <input type="hidden" name="order_id" id="slipOrderId" value="">
            <div class="mb-2" id="dropzone" style="border:2px dashed #8bb6ff; border-radius:12px; padding:16px; background:#f6faff; text-align:center; cursor:pointer">
              <div class="lead mb-1"><i class="bi bi-cloud-arrow-up"></i> ลากไฟล์มาวางที่นี่ หรือใช้ปุ่มด้านล่าง</div>
              <div class="small text-muted">รองรับ JPG, PNG, WebP, HEIC ขนาดไม่เกิน 5MB</div>

              <input type="file" name="slip" id="slipFile" accept="image/*" class="d-none">

              <div class="mt-2 d-flex justify-content-center" style="gap:10px; flex-wrap:wrap">
                <button type="button" class="btn btn-outline-primary" id="btnChooseFile"><i class="bi bi-file-earmark-image"></i> เลือกไฟล์</button>
                <button type="button" class="btn btn-info" id="btnTakePhoto"><i class="bi bi-camera"></i> ถ่ายภาพ</button>
              </div>
            </div>

            <div class="form-group">
              <label><i class="bi bi-pencil-square"></i> หมายเหตุ (ถ้ามี)</label>
              <input type="text" name="note" class="form-control" placeholder="เช่น โอนจากธนาคาร... เวลา ...">
            </div>

            <div id="slipPreview" style="display:flex; gap:10px; flex-wrap:wrap; margin:10px 0"></div>

            <div class="d-flex">
              <button class="btn btn-success mr-2" type="submit" id="btnUpload"><i class="bi bi-upload"></i> อัปโหลดสลิป</button>
              <button class="btn btn-outline-secondary" type="button" id="btnSlipCancel"><i class="bi bi-x-circle"></i> ยกเลิก</button>
            </div>
            <div id="slipMsg" class="mt-3"></div>
          </form>
        </div>

       <!-- CASH ZONE -->
<div id="cashZone" style="display:none">
  <div class="alert alert-info">
    <i class="bi bi-info-circle"></i>
    รับชำระเป็น <strong>เงินสด</strong> – ไม่ต้องแนบสลิป
  </div>

  <!-- ใส่จำนวนเงินที่ลูกค้าให้มา + ปุ่มลัด -->
  <div class="pos-card p-3 mb-3">
    <label class="font-weight-bold d-block mb-1"><i class="bi bi-wallet2"></i> เงินที่ลูกค้าให้มา (฿)</label>
    <div class="d-flex align-items-center" style="gap:10px; flex-wrap:wrap">
      <input id="cashGiven" type="number" min="0" step="1" class="form-control" style="max-width:200px"
             placeholder="เช่น 100" inputmode="numeric">
      <div class="btn-group btn-group-sm" role="group" aria-label="quick-cash">
      </div>
    </div>

    <div class="mt-2 small text-muted">
      ยอดที่ต้องชำระ: <strong id="cashDue">0.00</strong> ฿
    </div>
    <div class="mt-1 h5 mb-0">
      เงินทอน: <strong id="cashChange">0.00</strong> ฿
    </div>
  </div>

  <div class="d-flex">
    <button class="btn btn-success mr-2" id="btnCashConfirm" type="button" disabled>
      <i class="bi bi-cash"></i> ยืนยันรับเงินสด
    </button>
    <button class="btn btn-outline-secondary" type="button" id="btnSlipCancel2">
      <i class="bi bi-x-circle"></i> ยกเลิก
    </button>
  </div>
  <div id="cashMsg" class="mt-3"></div>
</div>


      </div>
    </div>
  </div>
</div>

<!-- Hotkeys -->
<script>
document.addEventListener('keydown', function(e){
  if (e.key === '/') {
    const q = document.querySelector('input[name="q"]');
    if (q) { q.focus(); q.select(); e.preventDefault(); }
  }
  if (e.key === 'F2') {
    const btn = document.getElementById('btnCheckout');
    if (btn) { e.preventDefault(); btn.click(); }
  }
});
</script>

<!-- Toast zone + เสียงแจ้งเตือน -->
<div id="toast-zone" style="position:fixed; right:16px; bottom:16px; z-index:9999;"></div>
<audio id="ding" preload="auto">
  <source src="https://actions.google.com/sounds/v1/alarms/beep_short.ogg" type="audio/ogg">
</audio>

<script>
/* ===== Theme Toggle (จำค่าไว้ใน localStorage) ===== */
(function(){
  const KEY='psu.theme.pref';
  const root=document.documentElement;
  const current = localStorage.getItem(KEY);
  if (current) {
    root.setAttribute('data-theme', current);
  } else {
    const prefersDark = window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches;
    root.setAttribute('data-theme', prefersDark ? 'dark' : 'light');
  }
  const btn=document.getElementById('themeToggle');
  if(btn){
    const syncIcon = ()=>{
      const now = root.getAttribute('data-theme');
      btn.innerHTML = now==='dark' ? '<i class="bi bi-brightness-high"></i>' : '<i class="bi bi-moon-stars"></i>';
    };
    syncIcon();
    btn.addEventListener('click', ()=>{
      const now = root.getAttribute('data-theme')==='dark' ? 'light' : 'dark';
      root.setAttribute('data-theme', now);
      localStorage.setItem(KEY, now);
      syncIcon();
    });
  }
})();
</script>

<script>
/* ===== Voice helper ===== */
const voiceSwitch = document.getElementById('voiceSwitch');
const VOICE_FLAG_KEY = 'psu.voice.enabled';
try{ voiceSwitch.checked = localStorage.getItem(VOICE_FLAG_KEY) === '1'; }catch(_){}
voiceSwitch?.addEventListener('change', () => {
  try{ localStorage.setItem(VOICE_FLAG_KEY, voiceSwitch.checked ? '1':'0'); }catch(_){}
  if (voiceSwitch.checked) speakOnceWarmup();
});
function speak(text, lang='th-TH'){
  if (!('speechSynthesis' in window)) return false;
  if (!voiceSwitch?.checked) return false;
  const u = new SpeechSynthesisUtterance(text);
  u.lang = lang; u.rate = 1.0; u.pitch = 1.0;
  const pickThai = (window.speechSynthesis.getVoices() || []).find(v => /th(-|_|$)/i.test(v.lang));
  if (pickThai) u.voice = pickThai;
  window.speechSynthesis.cancel(); window.speechSynthesis.speak(u);
  return true;
}
function speakOnceWarmup(){
  try{
    const t = new SpeechSynthesisUtterance('พร้อมใช้งานเสียงแจ้งเตือนแล้ว');
    t.lang = 'th-TH'; window.speechSynthesis.cancel(); window.speechSynthesis.speak(t);
  }catch(_){}
}
</script>

<script>
/* ===== Poll order status + Voice on READY ===== */
let lastSince = ''; let knownStatus = {};
function showToast(text, style='info'){
  const id = 't' + Date.now();
  const bg = style==='success' ? '#28a745' : (style==='danger' ? '#dc3545' : '#007bff');
  const el = document.createElement('div');
  el.id = id; el.style.cssText = `min-width:260px;margin-top:8px;background:${bg};color:#fff;padding:12px 14px;border-radius:10px;box-shadow:0 8px 20px rgba(0,0,0,.2);font-weight:700`;
  el.textContent = text; document.getElementById('toast-zone').appendChild(el);
  setTimeout(()=> el.remove(), 4000);
}
async function poll(){
  try{
    const base = '../api/orders_feed.php?mine=1';
    const qs   = lastSince ? ('&since='+encodeURIComponent(lastSince)) : '';
    const r = await fetch(base+qs, {cache:'no-store'}); if(!r.ok) throw new Error('HTTP '+r.status);
    const data = await r.json(); if(!data.ok) return;
    if (!lastSince && data.now) lastSince = data.now;
    if(data.orders && data.orders.length){
      lastSince = data.orders[data.orders.length - 1].updated_at;
      for(const o of data.orders){
        const id = o.order_id, st = o.status;
        const prev = knownStatus[id]; knownStatus[id] = st;
        if (prev && prev !== st){
          if (st === 'ready'){
            const msg = `ออเดอร์ของคุณ หมายเลข ${id} เสร็จแล้ว!`;
            showToast(msg, 'success'); document.getElementById('ding')?.play().catch(()=>{}); speak(msg, 'th-TH');
          } else if (st === 'canceled'){
            showToast(`ออเดอร์ของคุณ #${id} ถูกยกเลิก`, 'danger');
          } else { showToast(`ออเดอร์ของคุณ #${id} → ${st}`); }
        } else if (!prev) { knownStatus[id] = st; }
      }
    }
  }catch(e){ }finally{ setTimeout(poll, 1500); }
}
window.addEventListener('load', () => {
  if ('speechSynthesis' in window) { window.speechSynthesis.onvoiceschanged = () => {}; }
  poll();
});
</script>

<script>
/* ===== Modal logic (เพิ่ม/แก้ไขผ่านโมดัลเมนู) ===== */
const modal = document.getElementById('menuModal');
const modalBody = document.getElementById('menuModalBody');
const closeBtn = document.getElementById('menuModalClose');
function openModal(){ modal.classList.add('is-open'); document.body.style.overflow='hidden'; }
function closeModal(){ modal.classList.remove('is-open'); document.body.style.overflow=''; }
closeBtn.onclick = closeModal;
document.querySelector('#menuModal .psu-modal__backdrop').addEventListener('click', closeModal);
document.addEventListener('keydown', e=>{ if(e.key==='Escape') closeModal(); });

async function showMenuPopup(menuId, oldKey=null){
  if(!menuId) return;
  openModal();
  modalBody.innerHTML = '<div class="text-center py-5">กำลังโหลด…</div>';
  try{
    const url = 'menu_detail.php?popup=1&id=' + encodeURIComponent(menuId) + (oldKey ? ('&edit=1&key='+encodeURIComponent(oldKey)) : '');
    const r = await fetch(url, {cache:'no-store', credentials:'same-origin'});
    const html = await r.text();
    modalBody.innerHTML = html;

    const form = modalBody.querySelector('#menuForm');
    if(form){
      const onSubmit = async (ev)=>{
        ev.preventDefault(); ev.stopPropagation();
        const fd = new FormData(form);
        fd.set('action','add');
        if(oldKey){ fd.set('edit','1'); fd.set('old_key', oldKey); }

        if(!fd.get('qty')) fd.set('qty','1');
        const mid = (fd.get('menu_id') || menuId);
        if(!fd.get('menu_id')) fd.set('menu_id', String(mid));

        const pick = (name)=> (modalBody.querySelector(`input[name="${name}"]:checked`) || {}).value || '';
        const parts = [];
        const size = pick('size'), sweet = pick('sweet'), ice = pick('ice');
        if(size)  parts.push('ขนาด: '+size);
        if(sweet) parts.push('หวาน: '+sweet);
        if(ice)   parts.push('น้ำแข็ง: '+ice);
        const tops = Array.from(modalBody.querySelectorAll('input[name="toppings[]"]:checked')).map(x=> (x.dataset?.title || x.value));
        const free = (modalBody.querySelector('textarea[name="note_free"]')?.value || '').trim();
        if(tops.length) parts.push('ท็อปปิง: '+tops.join(', '));
        if(free) parts.push('หมายเหตุ: '+free);
        if(!fd.get('note')) fd.set('note', parts.join(' | '));

        try{
          const res = await fetch('front_store.php', {
            method:'POST', body:fd, credentials:'same-origin', cache:'no-store',
            headers:{ 'X-Requested-With': 'XMLHttpRequest' }
          });
          try{ await res.json(); }catch(_){}
          closeModal();
          await refreshCart();
        }catch(err){ alert(oldKey ? 'แก้ไขรายการไม่สำเร็จ' : 'เพิ่มลงตะกร้าไม่สำเร็จ'); }
      };
      form.addEventListener('submit', onSubmit, { once:true });
    }
  }catch(err){
    modalBody.innerHTML = '<div class="p-4 text-danger">โหลดไม่สำเร็จ</div>';
  }
}

document.addEventListener('click', (e)=>{
  const card = e.target.closest('.product-mini');
  if(!card) return;
  e.preventDefault();
  const idFromData = card.dataset.id;
  const idFromHref = (()=>{ try{ return new URL(card.getAttribute('href'), location.href).searchParams.get('id'); }catch(_){ return null } })();
  const menuId = idFromData || idFromHref;
  showMenuPopup(menuId);
});

document.addEventListener('click', (e)=>{
  const editBtn = e.target.closest('.js-edit');
  if(!editBtn) return;
  e.preventDefault();
  const menuId = editBtn.getAttribute('data-menu-id');
  const oldKey = editBtn.getAttribute('data-key');
  showMenuPopup(menuId, oldKey);
});

window.addEventListener('load', ()=>{
  const mid = sessionStorage.getItem('psu.openMenuId');
  if (mid) {
    sessionStorage.removeItem('psu.openMenuId');
    showMenuPopup(mid);
  }
});

async function refreshCart(){
  try{
    const r = await fetch('front_store.php?action=cart_html', { cache:'no-store', credentials:'same-origin', headers:{ 'X-Requested-With':'XMLHttpRequest' }});
    if(!r.ok){ throw new Error('HTTP '+r.status); }
    const html = await r.text();
    const box = document.getElementById('cartBox');
    if (box) box.innerHTML = html;
  }catch(_){}
}
</script>

<script>
/* ===== Slip Modal logic ===== */
const slipModal   = document.getElementById('slipModal');
const slipBody    = document.getElementById('slipBody');
const slipClose   = document.getElementById('slipClose');
const slipOrderId = document.getElementById('slipOrderId');
const slipAmount  = document.getElementById('slipAmount');
const slipBadge   = document.getElementById('slipBadge');
const slipFile    = document.getElementById('slipFile');
const slipPrev    = document.getElementById('slipPreview');
const slipMsg     = document.getElementById('slipMsg');
const dropzone    = document.getElementById('dropzone');
const btnUpload   = document.getElementById('btnUpload');
const btnSlipCancel = document.getElementById('btnSlipCancel');
const frmSlip     = document.getElementById('frmSlip');

const btnChooseFile = document.getElementById('btnChooseFile');
const btnTakePhoto  = document.getElementById('btnTakePhoto');

const btnSlipCancel2 = document.getElementById('btnSlipCancel2');
const cashZone   = document.getElementById('cashZone');
const uploadZone = document.getElementById('uploadZone');
const btnCashConfirm = document.getElementById('btnCashConfirm');
const cashMsg    = document.getElementById('cashMsg');

function openSlipModal(orderId, amountText, orderCode){
  if (!slipOrderId) return;
  slipOrderId.value = String(orderId);
  slipAmount.textContent = amountText;
  slipBadge.textContent  = orderCode ? orderCode : ('#' + orderId);
  slipPrev.innerHTML = '';
  if (slipFile) slipFile.value = '';
  slipMsg.innerHTML = '';

  const pmTransfer = document.querySelector('input[name="pmethod"][value="transfer"]');
  if (pmTransfer) pmTransfer.checked = true;
  if (uploadZone) uploadZone.style.display = '';
  if (cashZone)   cashZone.style.display   = 'none';

  slipModal.classList.add('is-open');
  document.body.style.overflow='hidden';
}
// ===== Utilities: แปลง/แสดงตัวเลขเงิน =====
function parseMoney(text){
  if (typeof text === 'number') return text;
  if (!text) return 0;
  // เอาเครื่องหมายคั่นหลัก/สัญลักษณ์ที่ไม่ใช่ตัวเลขออก
  const t = String(text).replace(/[,\s฿]/g,'').trim();
  const n = Number(t);
  return isNaN(n) ? 0 : n;
}
function fmtMoney(n){
  return (Number(n)||0).toLocaleString('th-TH', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
}

// ===== Cash change calculator =====
const cashDueEl    = document.getElementById('cashDue');    // แสดงยอดที่ต้องชำระ
const cashGivenEl  = document.getElementById('cashGiven');  // input จำนวนเงินที่ลูกค้าให้มา
const cashChangeEl = document.getElementById('cashChange'); // แสดงเงินทอน

function getDueAmount(){
  // slipAmount แสดงยอดรวมในโมดัล (ตัวแปรที่มีอยู่แล้ว)
  return parseMoney(slipAmount.textContent || slipAmount.innerText || slipAmount.value || 0);
}
function calcChange(){
  const due   = getDueAmount();
  const given = parseMoney(cashGivenEl?.value || 0);
  const chg   = Math.max(0, given - due);
  if (cashDueEl)    cashDueEl.textContent    = fmtMoney(due);
  if (cashChangeEl) cashChangeEl.textContent = fmtMoney(chg);

  // เปิดปุ่มก็ต่อเมื่อ "เงินที่ให้มา >= ยอดที่ต้องชำระ"
  if (btnCashConfirm){
    btnCashConfirm.disabled = !(given >= due && due > 0);
  }
}
function addCash(delta){
  if (!cashGivenEl) return;
  const cur = parseMoney(cashGivenEl.value || 0);
  cashGivenEl.value = String(cur + delta);
  calcChange();
}
function setExact(){
  if (!cashGivenEl) return;
  cashGivenEl.value = String(getDueAmount());
  calcChange();
}

// ติดตั้ง event
cashGivenEl?.addEventListener('input', calcChange);

// ปุ่มลัด
document.querySelectorAll('.js-cash-quick').forEach(btn=>{
  btn.addEventListener('click', ()=>{
    const add = btn.getAttribute('data-add');
    const act = btn.getAttribute('data-act');
    if (act === 'exact'){ setExact(); return; }
    if (add){ addCash(Number(add)); }
  });
});


// เวลาเปิดโมดัล (ตอนเรียก openSlipModal) ให้ sync ค่า due/change
(function(){
  const _open = window.openSlipModal;
  window.openSlipModal = function(orderId, amountText, orderCode){
    _open(orderId, amountText, orderCode);
    // sync ตัวเลขครั้งแรก
    setTimeout(()=> {
      if (cashDueEl)    cashDueEl.textContent    = fmtMoney(getDueAmount());
      if (cashGivenEl)  cashGivenEl.value        = '';     // เคลียร์ช่อง
      if (cashChangeEl) cashChangeEl.textContent = fmtMoney(0);
      calcChange();
    }, 0);
  };
})();

function closeSlipModal(){
  slipModal.classList.remove('is-open');
  document.body.style.overflow='';
}
slipClose.addEventListener('click', closeSlipModal);
document.querySelector('#slipModal .psu-modal__backdrop').addEventListener('click', closeSlipModal);
document.addEventListener('keydown', e=>{ if(e.key==='Escape') closeSlipModal(); });

btnSlipCancel.addEventListener('click', closeSlipModal);
btnSlipCancel2?.addEventListener('click', closeSlipModal);

// ปุ่มเลือกไฟล์/ถ่ายภาพ
btnChooseFile?.addEventListener('click', () => { try { slipFile.removeAttribute('capture'); } catch(e) {} slipFile.click(); });
btnTakePhoto?.addEventListener('click', () => { try { slipFile.setAttribute('capture','environment'); } catch(e) {} slipFile.click(); });

// คลิกพื้นหลัง dropzone = เลือกไฟล์
dropzone.addEventListener('click', (e) => {
  if (e.target.closest('#btnChooseFile') || e.target.closest('#btnTakePhoto')) return;
  try { slipFile.removeAttribute('capture'); } catch(e) {}
  slipFile.click();
});

// Drag & drop
;['dragenter','dragover'].forEach(ev => dropzone.addEventListener(ev, e=>{ e.preventDefault(); e.stopPropagation(); dropzone.style.background='#e9f3ff'; }));
;['dragleave','drop'].forEach(ev => dropzone.addEventListener(ev, e=>{ e.preventDefault(); e.stopPropagation(); dropzone.style.background='#f6faff'; }));
dropzone.addEventListener('drop', e=>{
  if (e.dataTransfer.files && e.dataTransfer.files[0]) {
    slipFile.files = e.dataTransfer.files;
    renderSlipPreview();
  }
});
slipFile.addEventListener('change', renderSlipPreview);

function renderSlipPreview(){
  slipPrev.innerHTML = '';
  const f = slipFile.files && slipFile.files[0];
  if (!f) return;
  const ok = /image\/(jpeg|png|webp|heic|heif)/i.test(f.type);
  if (!ok) { alert('ไฟล์ต้องเป็นรูปภาพเท่านั้น'); slipFile.value=''; return; }
  const url = URL.createObjectURL(f);
  const img = new Image(); img.src = url; img.onload = ()=>{
    slipPrev.innerHTML = '';
    img.style.maxWidth = '260px';
    img.style.borderRadius = '10px';
    img.style.border = '1px solid #dce8ff';
    slipPrev.appendChild(img);
    URL.revokeObjectURL(url);
  };
}

document.addEventListener('change', (e)=>{
  if (e.target && e.target.name === 'pmethod') {
    const v = e.target.value;
    if (uploadZone) uploadZone.style.display = (v === 'transfer') ? '' : 'none';
    if (cashZone)   cashZone.style.display   = (v === 'cash')     ? '' : 'none';
  }
});

// ส่งฟอร์มอัปโหลด
frmSlip.addEventListener('submit', async (e)=>{
  e.preventDefault();
  slipMsg.innerHTML = '';
  if (!slipFile.files || !slipFile.files[0]) { alert('กรุณาเลือก/ถ่ายภาพสลิปก่อน'); return; }
  if (slipFile.files[0].size > 5*1024*1024) { alert('ไฟล์เกิน 5MB'); return; }
  btnUpload.disabled = true;

  try{
    const fd = new FormData(frmSlip);
    const res = await fetch('front_store.php', { method:'POST', body:fd, credentials:'same-origin' });

    // พยายามอ่านเป็น JSON ถ้าไม่ใช่ ให้ fallback เป็น text
    let j = null, txt = '';
    const ct = res.headers.get('content-type') || '';
    if (ct.includes('application/json')) {
      j = await res.json();
    } else {
      txt = await res.text();
    }

    if (!res.ok) {
      const msg = j?.msg || (txt ? ('อัปโหลดไม่สำเร็จ: ' + txt.slice(0,120)) : ('HTTP ' + res.status));
      throw new Error(msg);
    }

    if (j && j.ok) {
      slipMsg.innerHTML = '<div class="alert alert-success mb-2"><i class="bi bi-check2-circle"></i> '+ (j.msg||'อัปโหลดสำเร็จ') +'</div>';
      const oseq = (j.order_seq || '');  // ใช้ seq-only ให้ตรงกับฝั่ง PHP
      setTimeout(()=>{
        closeSlipModal();
        const url = 'front_store.php?paid=1&oid='+(j.order_id||'')+(oseq?('&oseq='+encodeURIComponent(oseq)):'');
        window.location.href = url;
      }, 1000);
    } else {
      throw new Error(j?.msg || 'อัปโหลดไม่สำเร็จ');
    }
  }catch(err){
    slipMsg.innerHTML = '<div class="alert alert-danger mb-2"><i class="bi bi-x-octagon"></i> ' + (err.message || 'เชื่อมต่อไม่สำเร็จ') + '</div>';
    btnUpload.disabled = false;
  }
});


// เงินสด
btnCashConfirm?.addEventListener('click', async ()=>{
  const oid = slipOrderId.value;
  if (!oid) return;
  btnCashConfirm.disabled = true;
  cashMsg.innerHTML = '';
  try{
    const fd = new FormData();
    fd.set('action','pay_cash');
    fd.set('order_id', oid);
    const res = await fetch('front_store.php', { method:'POST', body:fd, credentials:'same-origin' });
    const j = await res.json();
    if (j && j.ok) {
      cashMsg.innerHTML = '<div class="alert alert-success"><i class="bi bi-cash-coin"></i> บันทึกชำระเงินสดแล้ว</div>';
      const oc = (j.order_code || '');
      setTimeout(()=>{
        closeSlipModal();
        const due   = getDueAmount();
        const given = parseMoney(cashGivenEl?.value || 0);
        const chg   = Math.max(0, given - due);
        const url = 'front_store.php'
          + '?paid=1'
          + '&oid=' + encodeURIComponent(oid)
          + (given ? ('&cr=' + encodeURIComponent(given)) : '')
          + (chg   ? ('&chg=' + encodeURIComponent(chg))   : '');
        window.location.href = url;
      }, 800);


    } else {
      cashMsg.innerHTML = '<div class="alert alert-danger"><i class="bi bi-x-octagon"></i> '+(j?.msg || 'บันทึกไม่สำเร็จ')+'</div>';
      btnCashConfirm.disabled = false;
    }
  }catch(_){
    cashMsg.innerHTML = '<div class="alert alert-danger"><i class="bi bi-x-octagon"></i> เชื่อมต่อไม่สำเร็จ</div>';
    btnCashConfirm.disabled = false;
  }
});

window.openSlipModal = openSlipModal;
</script>

</body>
</html>
