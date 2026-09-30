<?php
// ISO weekdays; NULL on older services means available every day.
function service_weekdays(array $service): array {
    $days=json_decode($service['available_weekdays']??'null',true);
    return is_array($days)?array_map('intval',$days):range(1,7);
}
function service_day_allowed(array $service,string $date): bool {
    return in_array((int)(new DateTimeImmutable($date))->format('N'),service_weekdays($service),true);
}
