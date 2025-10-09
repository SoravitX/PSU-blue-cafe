<?php
// admin_recipe_delete.php — ลบสูตร
declare(strict_types=1);
session_start();
if(empty($_SESSION['uid'])){header("Location: ../index.php");exit;}
require __DIR__.'/../db.php';
$conn->set_charset('utf8mb4');
if(($_SESSION['role']??'')!=='admin'){header("Location: ../SelectRole/role.php");exit;}

$id=(int)($_GET['id']??0);
if($id>0){
  $st=$conn->prepare("DELETE FROM recipes WHERE recipe_id=?");
  $st->bind_param('i',$id);
  $st->execute();
}
header("Location: admin_recipes.php?msg=deleted");
exit;
