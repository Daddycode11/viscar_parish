<?php
function site_settings(): array
{
    global $conn;
    $defaults = [
        'site_name' => 'Apostolic Vicariate of San Jose',
        'hero_badge' => 'Parish Service Platform',
        'hero_headline' => 'Faith, Community & Sacred Service',
        'hero_subtitle' => 'Apply for sacraments, track requests and connect with your parish.',
        'homepage_text' => '', 'contact_details' => '',
        'color_gold' => '#C9A84C', 'color_navy' => '#43658b', 'color_wine' => '#6B2737',
        'site_logo' => 'assets/img/logo-homepage.png', 'favicon' => '',
        'hero_bg_image' => 'assets/img/rightimage.png',
    ];
    try {
        foreach ($conn->query('SELECT setting_key,setting_value FROM site_settings') as $row) {
            if (isset($defaults[$row['setting_key']]) && trim($row['setting_value']) !== '') {
                $defaults[$row['setting_key']] = $row['setting_value'];
            }
        }
    } catch (mysqli_sql_exception $error) {
        if ($error->getCode() !== 1146) { throw $error; }
    }
    foreach (['color_gold', 'color_navy', 'color_wine'] as $key) {
        if (!preg_match('/^#[a-f0-9]{6}$/i', $defaults[$key])) { $defaults[$key] = '#43658b'; }
    }
    foreach (['site_logo', 'favicon', 'hero_bg_image'] as $key) {
        if (!preg_match('~^(?:assets/img|uploads/site)/[a-zA-Z0-9_.-]+$~', $defaults[$key])) {
            $defaults[$key] = $key === 'site_logo' ? 'assets/img/logo-homepage.png' : '';
        }
    }
    return $defaults;
}

function save_site_settings(array $actor, array $input): void
{
    global $conn;
    if ($actor['role'] !== 'admin') { throw new DomainException('Administrator access required.'); }
    $values = [];
    foreach (['site_name', 'hero_headline', 'hero_subtitle', 'homepage_text', 'contact_details', 'hero_badge'] as $key) {
        $values[$key] = input_text($input, $key, 5000);
    }
    foreach (['color_gold', 'color_navy', 'color_wine'] as $key) {
        $values[$key] = input_text($input, $key);
        if (!preg_match('/^#[a-f0-9]{6}$/i', $values[$key])) { throw new DomainException('Choose valid theme colors.'); }
    }
    foreach (['site_logo', 'favicon', 'hero_bg_image'] as $key) {
        $image = save_validated_image($key, 'site');
        if ($image) { $values[$key] = $image; }
    }
    revision_transaction(function () use ($conn, $values, $actor) {
        foreach ($values as $key => $value) {
            $conn->execute_query('INSERT INTO site_settings(setting_key,setting_value) VALUES(?,?) ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value)', [$key, $value]);
        }
        auditLog($actor['id'], 'update_homepage', 'site_settings');
    });
}
