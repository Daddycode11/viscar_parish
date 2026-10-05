<?php
require_once __DIR__.'/../includes/access.php';
require_once __DIR__.'/../includes/manual_payments.php';
if($user['role']!=='secretary')fail_request('Secretary access required.');
$notice='';
if($_SERVER['REQUEST_METHOD']==='POST'){
    $GLOBALS['new_uploads']=[];
    try{revision_transaction(fn()=>save_manual_method($user,$_POST,$_FILES));header('Location: payment_methods.php?saved=1',true,303);exit;}
    catch(Throwable $error){foreach($GLOBALS['new_uploads'] as $file)if(is_file($file))unlink($file);http_response_code(422);$notice=$error instanceof DomainException?$error->getMessage():'Unable to save payment method.';}
}
$methods=manual_payment_methods((int)$user['parish_id']);$page_id='settings';$page_title='Payment Methods';require __DIR__.'/includes/layout.php';
?>
<h1>Manual payment methods</h1><p>Add the parish's digital-bank or wallet QR image. All submitted payments require bookkeeper verification.</p><p role="status"><?= h($notice?: (isset($_GET['saved'])?'Payment method saved.':'')) ?></p>
<section class="card"><div class="card-body"><form method="post" enctype="multipart/form-data"><?= csrf_field() ?><div class="form-group"><label>Method name<input name="name" maxlength="100" required placeholder="Bank or wallet name"></label></div><div class="form-group"><label>Account details and instructions<textarea name="instructions" maxlength="2000" required></textarea></label></div><div class="form-group"><label>Bank-generated QR image<input type="file" name="qr" accept="image/png,image/jpeg" required></label></div><button class="btn-sm btn-navy">Add payment method</button></form></div></section>
<?php foreach($methods as $method): ?><section class="card"><div class="card-body"><h2><?= h($method['name']) ?></h2><p><?= nl2br(h($method['instructions'])) ?></p><img style="max-width:220px" src="../public/payment_file.php?method=<?= (int)$method['id'] ?>" alt="<?= h($method['name']) ?> payment QR"><form method="post"><?= csrf_field() ?><input type="hidden" name="_action" value="retire"><input type="hidden" name="id" value="<?= (int)$method['id'] ?>"><button>Remove from available methods</button></form></div></section><?php endforeach; ?>
<?php require __DIR__.'/includes/layout_footer.php'; ?>
