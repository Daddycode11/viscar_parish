<?php
require_once __DIR__ . '/workflows.php';
require_once __DIR__ . '/report_data.php';
require_once __DIR__ . '/accounting_rules.php';

const ACCOUNTING_TYPES = [
    'journal_voucher'=>'Journal Voucher', 'receipt' => 'Acknowledgement Receipt', 'check_voucher' => 'Check Voucher',
    'petty_cash_voucher' => 'Petty Cash Voucher', 'disbursement' => 'Disbursement', 'deposit' => 'Deposits',
];

const ACCOUNTING_REPORT_TYPES = ['revenue'=>'Revenue','refund'=>'Refund'] + ACCOUNTING_TYPES;

function accounting_actor(array $actor): void
{
    must($actor['role'] === 'bookkeeper' && !empty($actor['parish_id']), 'Bookkeeper access required.');
}

function money_cents(string $amount): int
{
    must((bool) preg_match('/^\d{1,10}(?:\.\d{1,2})?$/', $amount), 'Enter a valid amount with at most two decimal places.');
    [$whole, $fraction] = array_pad(explode('.', $amount), 2, '');
    return (int) $whole * 100 + (int) str_pad($fraction, 2, '0');
}

function create_accounting_document(array $actor, array $input): int
{
    global $conn;
    accounting_actor($actor);
    $type = input_text($input, 'document_type');
    must(isset(ACCOUNTING_TYPES[$type]) && $type !== 'receipt', 'Choose a voucher, disbursement or deposit.');
    $date = input_text($input, 'document_date');
    report_range($date, $date);
    $party = input_text($input, 'party_name');
    $description = input_text($input, 'description', 5000);
    $reference = input_text($input, 'reference', 100);
    $amount = input_text($input, 'amount');
    must($type==='journal_voucher' || ($party !== '' && $description !== '' && money_cents($amount) > 0), 'Payee/payer, description and positive amount are required.');
    $requestKey = input_text($input, 'request_key');
    must((bool) preg_match('/^[a-f0-9]{64}$/', $requestKey), 'Reload the document form.');
    $key = hash('sha256', $actor['id'] . ':' . $requestKey);
    sqlrow('SELECT id FROM parishes WHERE id=? FOR UPDATE',[$actor['parish_id']]);
    $existing=sqlrow('SELECT id FROM accounting_documents WHERE request_key=?',[$key]);
    if ($existing) return (int)$existing['id'];
    if ($type==='journal_voucher') return create_journal_correction($actor,$input,$key);
    must($type!=='check_voucher' || $reference!=='','Check Number is required.');
    $extra=accounting_extra_fields($actor,$input,$type,$amount);
    $files=save_uploaded_files(validate_uploaded_files(['attachment'=>['required'=>false,'label'=>'Receipt / Attachment']],$_FILES));
    if(isset($files['attachment']))$extra['attachment']=$files['attachment'];
    $conn->execute_query('INSERT INTO accounting_documents(parish_id,document_type,document_date,party_name,amount,description,reference,created_by,request_key) VALUES(?,?,?,?,?,?,?,?,?) ON DUPLICATE KEY UPDATE id=LAST_INSERT_ID(id)', [$actor['parish_id'], $type, $date, $party, $amount, $description, $reference, $actor['id'], $key]);
    $row = sqlrow('SELECT id,document_number FROM accounting_documents WHERE request_key=? FOR UPDATE', [$key]);
    if (!$row['document_number']) {
        foreach($extra as $column=>$value) $conn->execute_query("UPDATE accounting_documents SET `$column`=? WHERE id=?",[$value,$row['id']]);
        $prefix = ['check_voucher'=>'CV','petty_cash_voucher'=>'PCV','disbursement'=>'DIS','deposit'=>'DEP'][$type];
        $number = $prefix . '-' . $actor['parish_id'] . '-' . substr($date, 0, 4) . '-' . str_pad((string) $row['id'], 7, '0', STR_PAD_LEFT);
        $conn->execute_query('UPDATE accounting_documents SET document_number=? WHERE id=?', [$number, $row['id']]);
        auditLog($actor['id'], 'create_accounting', 'accounting_document', $row['id']);
    }
    return (int) $row['id'];
}

function complete_accounting_document(array $actor, int $id, array $input=[]): void
{
    global $conn;
    accounting_actor($actor);
    $parishLock=sqlrow('SELECT id FROM parishes WHERE id=? FOR UPDATE',[$actor['parish_id']]);
    $row = sqlrow('SELECT * FROM accounting_documents WHERE id=? AND parish_id=? FOR UPDATE', [$id, $actor['parish_id']]);
    must($row !== null && $row['status'] === 'draft', 'Only an existing draft can be completed.');
    if ($row['document_type']==='check_voucher') must(money_cents($row['amount'])<=2000000,'Check Voucher maximum is 20,000.00.');
    if ($row['document_type']==='petty_cash_voucher') must(money_cents($row['amount'])<=50000,'Petty Cash maximum per entry is 500.00.');
    if($row['document_type']==='petty_cash_voucher') {
        if(!$row['petty_cash_set']) {
            $extra=accounting_extra_fields($actor,$row,'petty_cash_voucher',$row['amount']);
            $row['petty_cash_set']=$extra['petty_cash_set'];
            $conn->execute_query('UPDATE accounting_documents SET petty_cash_set=? WHERE id=?',[$row['petty_cash_set'],$id]);
        }
        $setTotal=sqlrow("SELECT COALESCE(SUM(amount),0) amount FROM accounting_documents WHERE petty_cash_set=? AND status IN ('draft','completed')",[$row['petty_cash_set']]);
        must(money_cents($setTotal['amount'])<=1000000,'This petty cash set exceeds the 10,000.00 limit.');
    }
    if(in_array($row['document_type'],['check_voucher','deposit'],true)) {
        foreach(['bank_name','bank_account_number',...($row['document_type']==='check_voucher'?['secretary_signatory','finance_signatory','priest_signatory']:[])] as $column) {
            if(empty($row[$column])) {
                $value=input_text($input,$column,$column==='bank_account_number'?100:255);
                must($value!=='','Complete the bank and signatory details before completing this draft.');
                $conn->execute_query("UPDATE accounting_documents SET `$column`=? WHERE id=?",[$value,$id]);
            }
        }
    }
    must($row['party_name'] !== '' && $row['description'] !== '' && money_cents($row['amount']) > 0, 'Complete all required document fields.');
    if(in_array($row['document_type'],['check_voucher','deposit'],true) && $row['reference']==='') {
        $reference=input_text($input,'reference',100);
        must($reference!=='','A check or deposit reference is required.');
        $conn->execute_query('UPDATE accounting_documents SET reference=? WHERE id=?',[$reference,$id]);
    }
    $conn->execute_query("UPDATE accounting_documents SET status='completed',approved_by=?,approved_at=NOW() WHERE id=?", [$actor['id'], $id]);
    auditLog($actor['id'], 'complete_accounting', 'accounting_document', $id);
}

function accounting_rows(array $actor, array $filters): array
{
    global $conn;
    if ($actor['role']==='admin') {
        // Read-only audit reports select an explicit parish; write helpers still require Bookkeeper.
        $actor['parish_id']=(int)($filters['parish_id']??0);
        must($actor['parish_id']>0,'Select a parish for the audit report.');
    } else { accounting_actor($actor); }
    $from = input_text($filters, 'date_from') ?: date('Y-m-01');
    $to = input_text($filters, 'date_to') ?: date('Y-m-d');
    [$start, $end] = report_range($from, $to);
    $type = input_text($filters, 'document_type');
    $status = input_text($filters, 'status');
    must($type === '' || isset(ACCOUNTING_REPORT_TYPES[$type]), 'Invalid document type.');
    must(in_array($status, ['', 'draft','completed','refunded'], true), 'Invalid status.');
    must(empty($filters['parish_id']) || (int) $filters['parish_id'] === (int) $actor['parish_id'], 'Another parish cannot be selected.');
    if(in_array($type,['revenue','refund'],true)) {
        $date=$type==='refund'?'p.refunded_at':'COALESCE(p.verified_at,p.paid_at)';
        $state=$type==='refund'?"p.status='refunded'":"p.status IN ('completed','refunded')";
        return $conn->execute_query("SELECT p.id,? document_type,COALESCE(p.receipt_number,p.reference_number,CONCAT('PAY-',p.id)) document_number,DATE($date) document_date,u.name party_name,p.amount,s.name service_name,s.name description,COALESCE(p.reference_number,'') reference,p.status,a.parish_id FROM payments p JOIN applications a ON a.id=p.application_id JOIN users u ON u.id=a.user_id JOIN services s ON s.id=a.service_id WHERE a.parish_id=? AND $state AND $date BETWEEN ? AND ? AND (?='' OR p.status=?) ORDER BY $date DESC,p.id DESC",[$type,$actor['parish_id'],$start,$end,$status,$status])->fetch_all(MYSQLI_ASSOC);
    }
    $rows = [];
    if ($type === '' || $type === 'receipt') {
        $rows = $conn->execute_query("SELECT r.id,'receipt' document_type,r.receipt_number document_number,DATE(r.issued_at) document_date,r.parishioner_name party_name,r.amount,r.service_name,r.service_name description,COALESCE(p.reference_number,'') reference,p.status,a.parish_id FROM receipts r JOIN payments p ON p.id=r.payment_id JOIN applications a ON a.id=p.application_id WHERE a.parish_id=? AND r.issued_at BETWEEN ? AND ? AND (?='' OR p.status=?)", [$actor['parish_id'],$start,$end,$status,$status])->fetch_all(MYSQLI_ASSOC);
    }
    if ($type !== 'receipt') {
        $rows = array_merge($rows, $conn->execute_query("SELECT id,document_type,document_number,document_date,party_name,amount,description,reference,status,parish_id,bank_name,bank_account_number,secretary_signatory,finance_signatory,priest_signatory FROM accounting_documents WHERE parish_id=? AND document_date BETWEEN ? AND ? AND (?='' OR document_type=?) AND (?='' OR status=?)", [$actor['parish_id'],$from,$to,$type,$type,$status,$status])->fetch_all(MYSQLI_ASSOC));
    }
    usort($rows, fn($a,$b) => strcmp($b['document_date'], $a['document_date']) ?: $b['id'] <=> $a['id']);
    return $rows;
}
