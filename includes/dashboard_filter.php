<?php
require_once __DIR__.'/report_data.php';
// Empty selection retains the existing all-time view. Recurring Mass times are lifetime data.
try {
    $dashboardFrom=input_text($_GET,'date_from')?:'1900-01-01';
    $dashboardTo=input_text($_GET,'date_to')?:'9999-12-31';
    [$dashboardStart,$dashboardEnd]=report_range($dashboardFrom,$dashboardTo);
}catch(DomainException $e){fail_request($e->getMessage(),422);}

function dashboard_date_sql(string $column): string
{
    global $dashboardStart,$dashboardEnd;
    $allowedExpressions=[
        'effective_payment_date'=>'COALESCE(pay.verified_at,pay.paid_at,pay.created_at)',
    ];
    if(isset($allowedExpressions[$column]))$column=$allowedExpressions[$column];
    elseif(!preg_match('/^[a-z_.]+$/i',$column))throw new LogicException('Invalid date column.');
    // Both timestamps originate exclusively from strict date parsing above.
    return "$column BETWEEN '$dashboardStart' AND '$dashboardEnd'";
}

function render_dashboard_filter(): void
{
    global $dashboardFrom,$dashboardTo;
    echo '<form method="get" class="filter-bar"><label>From<input type="date" name="date_from" value="'.h($dashboardFrom==='1900-01-01'?'':$dashboardFrom).'"></label><label>To<input type="date" name="date_to" value="'.h($dashboardTo==='9999-12-31'?'':$dashboardTo).'"></label><button class="btn-sm btn-navy">Apply period</button><a class="btn-sm btn-outline" href="dashboard.php">All time</a></form>';
}
