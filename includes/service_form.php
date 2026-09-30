<?php foreach($conn->execute_query('SELECT * FROM service_fields WHERE service_id=? ORDER BY sort_order,id',[$serviceId]) as $field): if(!empty($scalarFieldsOnly)&&$field['field_type']==='file')continue; $key=$field['field_name']?:'field_'.$field['id']; ?>
<div class="form-group"><label><?= h($field['field_label']) ?>
<?php if($field['field_type']==='select'): ?><select name="fields[<?= h($key) ?>]" <?= $field['is_required']?'required':'' ?>><option value=""></option><?php foreach(explode(',',$field['field_options']??'') as $option): ?><option><?= h(trim($option)) ?></option><?php endforeach; ?></select>
<?php elseif($field['field_type']==='textarea'): ?><textarea name="fields[<?= h($key) ?>]" <?= $field['is_required']?'required':'' ?>></textarea>
<?php else: ?><input name="<?= $field['field_type']==='file'?'field_'.$field['id'].'[]':'fields['.h($key).']' ?>" <?= $field['field_type']==='file'?'multiple data-multiple-attachments accept=".pdf,.png,.jpg,.jpeg"':'' ?> type="<?= h(in_array($field['field_type'],['date','number','email','file'])?$field['field_type']:'text') ?>" <?= $field['is_required']?'required':'' ?>><?php endif; ?></label></div>
<?php endforeach; ?>
