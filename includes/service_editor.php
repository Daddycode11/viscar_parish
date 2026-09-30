<?php
require_once __DIR__.'/service_types.php';
require_once __DIR__.'/workflows.php';
if (($_SERVER['REQUEST_METHOD']??'GET')==='POST' && in_array($action,['create','update','delete','update_field','add_field'],true)) {
    try {
        $result=revision_transaction(function() use($conn,$user,$action) {
            must($user['role']==='secretary','Secretary access required.');
            $id=(int)($_POST['id']??0);
            if (in_array($action,['add_field','update_field'],true)) {
                $old=$action==='update_field'?sqlrow('SELECT f.* FROM service_fields f JOIN services s ON s.id=f.service_id WHERE f.id=? AND s.parish_id=? FOR UPDATE',[$id,$user['parish_id']]):null;
                $sid=$old['service_id']??(int)($_POST['service_id']??0);
                must(sqlrow('SELECT id FROM services WHERE id=? AND parish_id=? FOR UPDATE',[$sid,$user['parish_id']])!==null,'Service not found.');
                must($action!=='update_field'||$old!==null,'Field not found.');
                $label=input_text($_POST,'field_label');$name=input_text($_POST,'field_name');$type=input_text($_POST,'field_type');
                $name=$name?:trim(strtolower(preg_replace('/[^a-zA-Z0-9]+/','_',$label)),'_');
                must($label!=='' && (bool)preg_match('/^[a-zA-Z][a-zA-Z0-9_]{0,99}$/',$name),'Enter a label and a valid field name.');
                must(in_array($type,['text','number','date','select','textarea','file','email','phone'],true),'Invalid field type.');
                must(!sqlrow('SELECT id FROM service_fields WHERE service_id=? AND field_name=? AND id<>?',[$sid,$name,$id]),'Field names must be unique within a service.');
                $options=input_text($_POST,'field_options',5000);
                must($type!=='select'||$options!=='','Enter choices for the select field.');
                $args=[$name,$label,$type,$options,empty($_POST['is_required'])?0:1,max(0,(int)($_POST['sort_order']??0))];
                if ($old) $conn->execute_query('UPDATE service_fields SET field_name=?,field_label=?,field_type=?,field_options=?,is_required=?,sort_order=? WHERE id=?',[...$args,$id]);
                else $conn->execute_query('INSERT INTO service_fields(field_name,field_label,field_type,field_options,is_required,sort_order,service_id) VALUES(?,?,?,?,?,?,?)',[...$args,$sid]);
                auditLog($user['id'],$action,'service',$sid);
                return ['message'=>'Form field saved. Historical responses preserved.'];
            }
            $existing=$id?sqlrow('SELECT * FROM services WHERE id=? AND parish_id=? FOR UPDATE',[$id,$user['parish_id']]):null;
            must($action==='create'||$existing!==null,'Service not found.');
            if ($action==='delete') {
                // Retire instead of deleting referenced forms and applications.
                $conn->execute_query("UPDATE services SET status='inactive' WHERE id=?",[$id]);
                auditLog($user['id'],'retire_service','service',$id);
                return ['message'=>'Service retired. Existing records preserved.'];
            }
            $name=input_text($_POST,'name');
            $general=input_text($_POST,'general_type') ?: canonical_service_type($name);
            must(in_array($general,[...GENERAL_SERVICE_TYPES,'Other / Custom'],true),'Choose a valid service type.');
            must($name!=='','Enter a service name.');
            $classification=input_text($_POST,'classification') ?: ($existing['classification']??'Non-Sacramental');
            must(in_array($classification,['Sacramental','Non-Sacramental'],true),'Choose a classification.');
            $mode=input_text($_POST,'amount_mode') ?: 'fixed';
            must(in_array($mode,['fixed','user_defined'],true),'Choose an amount mode.');
            $fee=service_money(input_text($_POST,'fee')?:'0');
            $limit=filter_var($_POST['max_daily_limit']??0,FILTER_VALIDATE_INT);
            must($limit!==false && $limit>=0,'Daily limit must be a nonnegative integer.');
            $status=input_text($_POST,'status')?:'active';must(in_array($status,['active','inactive'],true),'Invalid status.');
            $sacrament=$classification==='Sacramental'?($general==='Matrimony'?'Marriage':$general):null;
            if ($general==='Other / Custom') $sacrament=$classification==='Sacramental'?($existing['sacrament_type']??null):null;
            $args=[$name,input_text($_POST,'description',10000),$fee,$limit,input_text($_POST,'requirements_note',10000),$status,$general,$classification,$mode,$sacrament];
            if ($existing) $conn->execute_query('UPDATE services SET name=?,description=?,fee=?,max_daily_limit=?,requirements_note=?,status=?,general_type=?,classification=?,amount_mode=?,sacrament_type=? WHERE id=?',[...$args,$id]);
            else { $conn->execute_query('INSERT INTO services(name,description,fee,max_daily_limit,requirements_note,status,general_type,classification,amount_mode,sacrament_type,parish_id) VALUES(?,?,?,?,?,?,?,?,?,?,?)',[...$args,$user['parish_id']]);$id=$conn->insert_id; }
            if (isset($_POST['weekdays_present'])) {
                $days=$_POST['available_weekdays']??[];
                must(is_array($days)&&count($days)>0,'Select at least one available weekday.');
                foreach($days as $day)must(is_scalar($day)&&preg_match('/^[1-7]$/',(string)$day)===1,'Invalid weekday.');
                $days=array_values(array_unique(array_map('intval',$days)));sort($days);
                $conn->execute_query('UPDATE services SET available_weekdays=? WHERE id=?',[json_encode($days),$id]);
            }
            auditLog($user['id'],$action.'_service','service',$id);
            return ['id'=>$id,'message'=>'Service saved.'];
        });
        header('Content-Type: application/json');echo json_encode(['success'=>true]+$result);exit;
    } catch (Throwable $e) {
        if (!$e instanceof DomainException) error_log('Service editor: '.$e->getMessage());
        fail_request($e instanceof DomainException?$e->getMessage():'Unable to save service.',422);
    }
}
