<?php
const GENERAL_SERVICE_TYPES = ['Baptism','Matrimony','Anointing of the Sick','Confirmation','Mass Request','Mass Intention','Funeral Mass','Blessing Request','Non-Sacramental'];

function canonical_service_type(string $name): string
{
    $name = strtolower(trim(preg_replace('/\s+/', ' ', $name)));
    $aliases = ['wedding'=>'Matrimony','marriage'=>'Matrimony','matrimony'=>'Matrimony',
        'mass intension'=>'Mass Intention','mass intentions'=>'Mass Intention',
        'baptismal'=>'Baptism','baptismal certificate'=>'Baptism','funeral'=>'Funeral Mass',
        'blessing'=>'Blessing Request'];
    foreach (GENERAL_SERVICE_TYPES as $type) $aliases[strtolower($type)] = $type;
    if (isset($aliases[$name])) return $aliases[$name];
    $matches=[];
    foreach($aliases as $alias=>$type) if(preg_match('/(?<![a-z])'.preg_quote($alias,'/').'(?![a-z])/',$name))$matches[$type]=true;
    return count($matches)===1?array_key_first($matches):'Other / Custom';
}

function service_money(string $value): string
{
    if (!preg_match('/^\d{1,8}(?:\.\d{1,2})?$/', $value)) {
        throw new DomainException('Enter a nonnegative amount with at most two decimal places.');
    }
    return $value;
}
