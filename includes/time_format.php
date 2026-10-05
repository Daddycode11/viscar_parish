<?php
// Parish wall-clock values: never apply timezone conversion to stored schedules.
function parse_parish_time(string $value, bool $allowStorage = true): string
{
    $value=trim($value);
    if(preg_match('/^(0?[1-9]|1[0-2]):([0-5][0-9])\s*(AM|PM)$/i',$value,$parts)) {
        $hour=(int)$parts[1]%12+(strtoupper($parts[3])==='PM'?12:0);
        return sprintf('%02d:%s',$hour,$parts[2]);
    }
    if($allowStorage&&preg_match('/^(?:[01][0-9]|2[0-3]):[0-5][0-9]$/',$value))return $value;
    throw new DomainException('Use a time with AM or PM, for example 9:00 AM, 12:00 PM or 1:30 PM.');
}
function display_time(?string $value): string
{
    if(!$value)return '';
    if(!preg_match('/^(\d{2}):([0-5][0-9])(?::[0-5][0-9])?$/',$value,$parts))return $value;
    $hour=(int)$parts[1];if($hour>23)return $value;
    return ($hour%12?:12).':'.$parts[2].($hour<12?' AM':' PM');
}
function display_datetime(?string $value): string
{
    if(!$value)return '';
    if(preg_match('/^(\d{4}-\d{2}-\d{2})[ T](\d{2}:[0-5][0-9])(?::[0-5][0-9])?$/',$value,$parts))return $parts[1].' '.display_time($parts[2]);
    return $value;
}
