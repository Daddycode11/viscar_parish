<?php
require_once __DIR__ . '/request_workflows.php';
$isParishioner = $user['role'] === 'parishioner';
if (!in_array($user['role'], ['parishioner', 'secretary', 'bookkeeper'], true)) {
    fail_request('Request management is restricted to the assigned staff role.');
}
$type = $user['role'] === 'bookkeeper' ? 'refund' : 'reschedule';
if ($isParishioner) {
    $type = $_GET['type'] ?? $_POST['request_type'] ?? 'refund';
    if (!in_array($type, ['refund', 'reschedule', 'cancel'], true)) { fail_request('Invalid request type.', 422); }
}
if ($user['role']==='secretary' && ($_GET['type']??'')==='cancel') $type='cancel';
$notice = $_SESSION['request_delivery_feedback']??'';unset($_SESSION['request_delivery_feedback']);
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $GLOBALS['after_commit']=[];
    try {
        revision_transaction(function () use ($user, $isParishioner) {
            if ($isParishioner) { create_change_request($user, $_POST); }
            else { review_change_request($user, $_POST); }
        });
        $_SESSION['request_delivery_feedback']=delivery_feedback(dispatch_after_commit());
        header('Location: requests.php?type=' . $type . '&saved=1', true, 303); exit;
    } catch (Throwable $error) {
        http_response_code(422);
        error_log('Change request: '.get_class($error).' '.$error->getMessage());
        $notice = $error instanceof DomainException ? $error->getMessage() : 'Unable to save request.';
    }
}
$where = $isParishioner ? 'a.user_id=?' : 'a.parish_id=?';
$rows = $conn->execute_query("SELECT r.*,s.name service_name FROM application_requests r JOIN applications a ON a.id=r.application_id JOIN services s ON s.id=a.service_id WHERE $where AND r.request_type=? ORDER BY r.id DESC", [$isParishioner ? $user['id'] : $user['parish_id'], $type])->fetch_all(MYSQLI_ASSOC);
$apps = $isParishioner ? $conn->execute_query("SELECT a.id,s.name FROM applications a JOIN services s ON s.id=a.service_id WHERE a.user_id=? AND (a.status IN ('pending','approved') OR a.payment_status='paid')", [$user['id']])->fetch_all(MYSQLI_ASSOC) : [];
$folder = $isParishioner ? 'parishioner' : 'staff';
$page_id = 'requests'; $page_title = t($type === 'refund' ? 'Refunds' : ($type==='cancel'?'Cancellations':'Reschedules'));
require APP_ROOT . '/' . $folder . '/includes/layout.php';
?>
<h1><?= h($page_title) ?></h1>
<?php if ($isParishioner || $user['role']==='secretary'): ?><nav class="request-tabs" aria-label="Request type"><?php foreach(['refund'=>'Refunds','reschedule'=>'Reschedules','cancel'=>'Cancellations'] as $tab=>$label): if(!$isParishioner && $tab==='refund')continue; ?><a href="requests.php?type=<?= $tab ?>" <?= $type===$tab?'aria-current="page"':'' ?>><?= h(t($label)) ?></a><?php endforeach; ?></nav><?php endif; ?>
<p role="status"><?= h(t($notice ?: (isset($_GET['saved']) ? 'Request saved.' : ''))) ?></p>
<?php if ($isParishioner): ?>
<section class="card"><div class="card-body"><?php if($type==='cancel'): ?><p>Your application stays active until the Secretary confirms cancellation. Refunds follow a separate process.</p><?php endif; ?><form method="post" <?= $type==='cancel'?'onsubmit="return confirm(\'Request cancellation? The Secretary will review your reason.\')"':'' ?>><?= csrf_field() ?>
<input type="hidden" name="request_type" value="<?= h($type) ?>">
<div class="form-group"><label><?= h(t('Booking')) ?><select name="application_id" required><?php foreach($apps as $app): ?><option value="<?= (int)$app['id'] ?>">#<?= (int)$app['id'] ?> <?= h($app['name']) ?></option><?php endforeach; ?></select></label></div>
<?php if ($type === 'reschedule'): ?><div class="form-group"><label><?= h(t('New schedule')) ?><input type="datetime-local" name="proposed_schedule" required></label></div><?php endif; ?>
<div class="form-group"><label><?= h(t('Reason')) ?><textarea name="reason" required minlength="5" maxlength="2000"></textarea></label></div>
<button class="btn-sm btn-navy"><?= h(t('Submit request')) ?></button></form></div></section>
<?php endif; ?>
<section class="card"><div class="card-body"><table><tr><th>#</th><th><?= h(t('Booking')) ?></th><th><?= h(t('New schedule')) ?></th><th><?= h(t('Reason')) ?></th><th><?= h(t('Status')) ?></th><th><?= h(t('Decision note / refund reference')) ?></th></tr>
<?php foreach($rows as $row): ?><tr><td><?= (int)$row['id'] ?></td><td>#<?= (int)$row['application_id'] ?> <?= h($row['service_name']) ?></td><td><?= h($row['proposed_schedule']) ?></td><td><?= h($row['reason']) ?></td><td><?= h(t(ucfirst($row['status']))) ?></td><td><?= h($row['review_note']) ?>
<?php if(!$isParishioner && in_array($row['status'],['pending','approved'],true)): ?>
<form method="post"><?= csrf_field() ?><input type="hidden" name="id" value="<?= (int)$row['id'] ?>"><input name="review_note" maxlength="2000" aria-label="<?= h(t('Decision note / refund reference')) ?>"><select name="decision">
<?php if($row['status']==='pending'): ?><option value="approve"><?= h(t('Approve')) ?></option><option value="reject"><?= h(t('Reject')) ?></option><?php else: ?><option value="complete"><?= h(t('Record completed refund')) ?></option><?php endif; ?></select><button><?= h(t('Save')) ?></button></form>
<?php endif; ?></td></tr><?php endforeach; ?></table></div></section>
<?php require APP_ROOT . '/' . $folder . '/includes/layout_footer.php'; ?>
