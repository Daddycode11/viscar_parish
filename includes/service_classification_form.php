<section class="card"><div class="card-body"><h2><?= h(t('Sacrament classification')) ?></h2>
<p><?= h(t('Choose a sacrament type to create a record automatically on approval. Leave ordinary services unclassified.')) ?></p>
<?php foreach($conn->execute_query('SELECT id,name,sacrament_type FROM services WHERE parish_id=?',[$user['parish_id']]) as $service): ?>
<form method="post"><?= csrf_field() ?><input type="hidden" name="_action" value="classify_service"><input type="hidden" name="service_id" value="<?= (int)$service['id'] ?>"><label><?= h($service['name']) ?><select name="sacrament_type"><option value=""><?= h(t('Non-sacramental service')) ?></option><?php foreach(SACRAMENT_TYPES as $type): ?><option <?= $service['sacrament_type']===$type?'selected':'' ?>><?= h($type) ?></option><?php endforeach; ?></select></label><button><?= h(t('Save')) ?></button></form>
<?php endforeach; ?></div></section>
