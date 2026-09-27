<?php
require_once __DIR__.'/report_data.php';

/** Income remains verified after a refund; the return is deducted exactly once. */
function financial_totals(string $from, string $to, int $parish=0): array
{
    global $conn;
    [$start,$end]=report_range($from,$to);
    $income=$conn->execute_query("SELECT COALESCE(SUM(p.amount),0) amount FROM payments p JOIN applications a ON a.id=p.application_id WHERE p.status IN ('completed','refunded') AND COALESCE(p.verified_at,p.paid_at) BETWEEN ? AND ? AND (?=0 OR a.parish_id=?)",[$start,$end,$parish,$parish])->fetch_assoc()['amount'];
    $refund=$conn->execute_query("SELECT COALESCE(SUM(p.amount),0) amount FROM payments p JOIN applications a ON a.id=p.application_id WHERE p.status='refunded' AND p.refunded_at BETWEEN ? AND ? AND (?=0 OR a.parish_id=?)",[$start,$end,$parish,$parish])->fetch_assoc()['amount'];
    $expenses=$conn->execute_query("SELECT document_type,COALESCE(SUM(amount),0) amount FROM accounting_documents WHERE status='completed' AND document_type IN ('check_voucher','petty_cash_voucher','disbursement') AND document_date BETWEEN ? AND ? AND (?=0 OR parish_id=?) GROUP BY document_type",[$from,$to,$parish,$parish])->fetch_all(MYSQLI_ASSOC);
    $result=['verified_revenue'=>(float)$income,'refunds'=>(float)$refund,'check_voucher'=>0.0,'petty_cash_voucher'=>0.0,'disbursement'=>0.0];
    foreach($expenses as $row) $result[$row['document_type']]=(float)$row['amount'];
    $result['net_revenue']=round($result['verified_revenue']-$result['refunds']-$result['check_voucher']-$result['petty_cash_voucher']-$result['disbursement'],2);
    return $result;
}

function refund_totals_by_parish(string $from,string $to): array
{
    global $conn;[$start,$end]=report_range($from,$to);
    return $conn->execute_query("SELECT pa.name parish,COUNT(p.id) transactions,COALESCE(SUM(p.amount),0) amount FROM parishes pa LEFT JOIN applications a ON a.parish_id=pa.id LEFT JOIN payments p ON p.application_id=a.id AND p.status='refunded' AND p.refunded_at BETWEEN ? AND ? GROUP BY pa.id,pa.name ORDER BY amount DESC,pa.name",[$start,$end])->fetch_all(MYSQLI_ASSOC);
}
