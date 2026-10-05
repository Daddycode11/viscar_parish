<?php
require __DIR__.'/../includes/time_format.php';
$passed=0;
foreach(['12:00 AM'=>'00:00','12:01 AM'=>'00:01','11:59 AM'=>'11:59','12:00 PM'=>'12:00','12:01 PM'=>'12:01','1:00 PM'=>'13:00','11:59 PM'=>'23:59','1:00 AM'=>'01:00'] as $display=>$stored){
    if(parse_parish_time($display,false)!==$stored||display_time($stored)!==$display||display_datetime('2038-01-02 '.$stored.':00')!=='2038-01-02 '.$display)throw new RuntimeException('Time round trip failed: '.$display);
    $passed++;
}
foreach(['00:00 AM','13:00 PM','12:60 PM','9:00','12:00','09:00','9 AM','9:00 XM'] as $invalid){
    $rejected=false;try{parse_parish_time($invalid,false);}catch(DomainException $e){$rejected=true;}
    if(!$rejected)throw new RuntimeException('Invalid display time accepted.');$passed++;
}
if(parse_parish_time('13:00')!=='13:00')throw new RuntimeException('Legacy storage parsing changed.');
echo ($passed+1)." time parsing/display checks passed. No database writes.\n";
