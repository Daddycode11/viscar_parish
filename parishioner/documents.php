<?php
require_once __DIR__.'/../includes/access.php';
require_once __DIR__.'/../includes/workflow_routes.php';
$rows=$conn->execute_query('SELECT r.*,s.name service_name FROM application_document_requests r JOIN applications a ON a.id=r.application_id JOIN services s ON s.id=a.service_id WHERE a.user_id=? ORDER BY r.id DESC',[$user['id']])->fetch_all(MYSQLI_ASSOC);
$page_id='documents';$page_title='Requested Documents';require __DIR__.'/includes/layout.php';
?><div class="card"><div class="card-body"><h2>Requested documents</h2><p>You may submit multiple PDF, JPG or PNG files per request (5 MB per file, 20 files per submission). Staff can review it with your application.</p><p id="documentNotice" role="status"></p>
<?php if(!$rows):?><p>No additional documents requested.</p><?php endif;?>
<?php foreach($rows as $r):?><div class="card" style="padding:16px;margin:16px 0"><h3>Booking #<?=(int)$r['application_id']?> — <?=htmlspecialchars($r['service_name'])?></h3><p><?=nl2br(htmlspecialchars($r['documents']??''))?></p><p><?=nl2br(htmlspecialchars($r['message']??''))?></p>
<?php if($r['submitted_at']):?><p>Submitted for review: <?=htmlspecialchars($r['submitted_at'])?></p><?php render_requirement_attachments(owned_application((int)$r['application_id'],$user),['additional_'.$r['id']=>$r['documents']],'additional_'.$r['id']); ?>
<?php else:?><form enctype="multipart/form-data" method="post" action="documents.php?ajax=submit"><input type="hidden" name="request_id" value="<?=(int)$r['id']?>"><input type="file" name="document[]" multiple data-multiple-attachments accept=".pdf,.jpg,.jpeg,.png" required><button type="submit" class="btn-sm btn-navy">Submit documents</button></form><?php endif;?></div><?php endforeach;?></div></div>
<script>document.querySelectorAll('form[action="documents.php?ajax=submit"]').forEach(form=>form.addEventListener('submit',async e=>{e.preventDefault();const button=form.querySelector('button[type=submit]');button.disabled=true;try{const body=new FormData(form);body.set('attachment_count',form.querySelector('input[type=file]').files.length);const r=await fetch(form.action,{method:'POST',body});const d=await r.json();if(d.ok)location.reload();else document.getElementById('documentNotice').textContent=d.message;}catch(e){document.getElementById('documentNotice').textContent='Unable to submit. Please retry.';}button.disabled=false;}));</script>
<script defer src="<?= h(app_url('assets/js/multiple-attachments.js')) ?>"></script>
<?php require __DIR__.'/includes/layout_footer.php'; ?>
