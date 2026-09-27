<?php
function current_petty_cash_set(array $actor): int
{
    global $conn;
    // Every writer locks the parish first, including replenishment, to serialize sets.
    sqlrow('SELECT id FROM parishes WHERE id=? FOR UPDATE',[$actor['parish_id']]);
    $set=sqlrow("SELECT id FROM petty_cash_sets WHERE parish_id=? AND status='open' ORDER BY id DESC LIMIT 1 FOR UPDATE",[$actor['parish_id']]);
    if (!$set) {
        $conn->execute_query('INSERT INTO petty_cash_sets(parish_id,created_by) VALUES(?,?)',[$actor['parish_id'],$actor['id']]);
        return (int)$conn->insert_id;
    }
    return (int)$set['id'];
}

function replenish_petty_cash(array $actor): void
{
    global $conn;
    must($actor['role']==='secretary' && !empty($actor['parish_id']),'Secretary access required for replenishment.');
    $id=current_petty_cash_set($actor);
    must(!sqlrow("SELECT id FROM accounting_documents WHERE petty_cash_set=? AND status='draft' LIMIT 1",[$id]),'Complete outstanding petty cash drafts before replenishment.');
    must(sqlrow('SELECT id FROM accounting_documents WHERE petty_cash_set=? LIMIT 1',[$id])!==null,'The current set is empty.');
    $conn->execute_query("UPDATE petty_cash_sets SET status='closed',closed_at=NOW() WHERE id=?",[$id]);
    auditLog($actor['id'],'replenish_petty_cash','petty_cash_set',$id);
    current_petty_cash_set($actor);
}

function accounting_extra_fields(array $actor,array $input,string $type,string $amount): array
{
    $extra=[];$cents=money_cents($amount);
    if ($type==='check_voucher') must($cents<=2000000,'Check Voucher maximum is 20,000.00.');
    if ($type==='petty_cash_voucher') {
        must($cents<=50000,'Petty Cash maximum per entry is 500.00.');
        $extra['petty_cash_set']=current_petty_cash_set($actor);
        $total=sqlrow("SELECT COALESCE(SUM(amount),0) amount FROM accounting_documents WHERE petty_cash_set=? AND status IN ('draft','completed')",[$extra['petty_cash_set']]);
        must(money_cents($total['amount'])+$cents<=1000000,'This petty cash set has a 10,000.00 limit. Replenishment is required.');
    }
    if (in_array($type,['check_voucher','deposit'],true)) {
        foreach (['bank_name','bank_account_number'] as $key) {
            $extra[$key]=input_text($input,$key,$key==='bank_name'?255:100);
            must($extra[$key]!=='','Bank name and bank account number are required.');
        }
    }
    if ($type==='check_voucher') {
        foreach (['secretary_signatory','finance_signatory','priest_signatory'] as $key) {
            $extra[$key]=input_text($input,$key);must($extra[$key]!=='','Secretary, Finance VP and Parish Priest signatories are required.');
        }
    }
    return $extra;
}

function create_journal_correction(array $actor,array $input,string $key): int
{
    global $conn;
    $ref=input_text($input,'original_reference');
    must((bool)preg_match('/^(document|receipt):([1-9][0-9]*)$/',$ref,$m),'Choose the original transaction.');
    $receipt=$m[1]==='receipt';$id=(int)$m[2];
    $original=$receipt
        ?sqlrow('SELECT r.id,r.service_name description,r.receipt_number document_number FROM receipts r JOIN payments p ON p.id=r.payment_id JOIN applications a ON a.id=p.application_id WHERE r.id=? AND a.parish_id=? FOR UPDATE',[$id,$actor['parish_id']])
        :sqlrow("SELECT id,description,document_number FROM accounting_documents WHERE id=? AND parish_id=? AND document_type='check_voucher' FOR UPDATE",[$id,$actor['parish_id']]);
    must($original!==null,'Original transaction not found.');
    $corrected=input_text($input,'description',5000);$reason=input_text($input,'correction_reason',2000);
    must($corrected!==''&&strlen($reason)>=5,'Enter corrected particulars and a reason.');
    $date=input_text($input,'document_date');report_range($date,$date);
    $conn->execute_query("INSERT INTO accounting_documents(parish_id,document_type,document_date,party_name,amount,description,reference,created_by,request_key,status,original_document_id,original_receipt_id,original_particulars,correction_reason,approved_by,approved_at) VALUES(?,'journal_voucher',?,'Particulars correction',0,?,?,?,?, 'completed',?,?,?,?,?,NOW())",[$actor['parish_id'],$date,$corrected,$original['document_number'],$actor['id'],$key,$receipt?null:$id,$receipt?$id:null,$original['description'],$reason,$actor['id']]);
    $new=(int)$conn->insert_id;
    $conn->execute_query('UPDATE accounting_documents SET document_number=? WHERE id=?',['JV-'.$actor['parish_id'].'-'.$new,$new]);
    auditLog($actor['id'],'correct_particulars','accounting_document',$new);
    return $new;
}
