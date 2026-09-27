<?php
require_once __DIR__ . '/navigation.php';
/**
 * PDF / Print Helper — Apostolic Vicariate of San Jose
 * Generates printable HTML documents that can be converted to PDF via browser print.
 * No external library needed — uses browser's built-in print-to-PDF.
 */

/**
 * Generate a printable certificate HTML
 */
function generateCertificateHTML($record, $parish, $qr_url = '') {
    $cert_no = $record['certificate_number'] ?? 'N/A';
    $type = htmlspecialchars(ucwords(str_replace('_', ' ', $record['record_type'])), ENT_QUOTES);
    $name = htmlspecialchars($record['parishioner_name']);
    $date = date('F j, Y', strtotime($record['date_of_sacrament']));
    $minister = htmlspecialchars($record['minister_name'] ?? 'Parish Priest');
    $parish_name = htmlspecialchars($parish['name'] ?? 'Parish');
    $parish_addr = htmlspecialchars($parish['address'] ?? '');
    $sponsors = htmlspecialchars($record['sponsors'] ?? '');
    $remarks = htmlspecialchars($record['remarks'] ?? '');
    $qr_img = $qr_url ? '<img src="' . htmlspecialchars($qr_url) . '" width="100" height="100">' : '';

    return '<!DOCTYPE html>
<html><head><meta charset="UTF-8">
<title>Certificate of ' . $type . ' — ' . $name . '</title>
<style>
@import url("https://fonts.googleapis.com/css2?family=Cormorant+Garamond:ital,wght@0,400;0,600;1,400&family=DM+Sans:wght@300;400;500&display=swap");
@page { size: A4 landscape; margin: 0; }
* { margin:0; padding:0; box-sizing:border-box; }
body { font-family: "DM Sans", sans-serif; background: #fff; }
.cert { width: 297mm; min-height: 210mm; margin: auto; padding: 20mm 25mm; position: relative; border: 3px double #C9A84C; }
.cert::before { content:""; position:absolute; inset:8mm; border:1px solid #C9A84C; pointer-events:none; }
.header { text-align: center; margin-bottom: 12mm; }
.header h4 { font-family: "DM Sans", sans-serif; font-size: 10pt; letter-spacing: 3px; text-transform: uppercase; color: #666; margin-bottom: 2mm; }
.header h1 { font-family: "Cormorant Garamond", serif; font-size: 20pt; color: #1B2A4A; margin-bottom: 1mm; }
.header h2 { font-family: "Cormorant Garamond", serif; font-size: 28pt; color: #C9A84C; font-weight: 600; }
.header .parish { font-size: 11pt; color: #555; margin-top: 2mm; }
.body-text { text-align: center; font-size: 12pt; line-height: 2.2; margin: 10mm 15mm; color: #333; }
.body-text .name { font-family: "Cormorant Garamond", serif; font-size: 22pt; font-weight: 600; color: #1B2A4A; border-bottom: 1px solid #C9A84C; padding: 0 10mm; display: inline-block; }
.body-text .detail { font-weight: 500; color: #1B2A4A; }
.footer { display: flex; justify-content: space-between; align-items: flex-end; margin-top: 15mm; padding: 0 10mm; }
.sign-block { text-align: center; }
.sign-line { width: 60mm; border-bottom: 1px solid #333; margin-bottom: 2mm; }
.sign-label { font-size: 9pt; color: #666; }
.sign-name { font-size: 10pt; font-weight: 500; }
.qr-block { text-align: center; }
.qr-block img { margin-bottom: 2mm; }
.qr-block small { font-size: 7pt; color: #999; display: block; }
.cert-no { position: absolute; top: 12mm; right: 15mm; font-size: 8pt; color: #999; }
@media print { body { -webkit-print-color-adjust: exact; print-color-adjust: exact; } }
</style>
</head><body>' . navigation_controls() . '
<div class="cert">
  <div class="cert-no">Certificate No: ' . htmlspecialchars($cert_no) . '</div>
  <div class="header">
    <h4>Apostolic Vicariate of San Jose</h4>
    <h1>' . $parish_name . '</h1>
    <h2>Certificate of ' . $type . '</h2>
    <div class="parish">' . $parish_addr . '</div>
  </div>
  <div class="body-text">
    This is to certify that<br>
    <span class="name">' . $name . '</span><br>
    has received the Sacrament of <span class="detail">' . $type . '</span><br>
    on <span class="detail">' . $date . '</span>' .
    ($sponsors ? '<br>Sponsor(s): <span class="detail">' . $sponsors . '</span>' : '') .
    ($remarks ? '<br><em>' . $remarks . '</em>' : '') . '
  </div>
  <div class="footer">
    <div class="sign-block">
      <div class="sign-line"></div>
      <div class="sign-name">' . $minister . '</div>
      <div class="sign-label">Parish Priest / Minister</div>
    </div>
    <div class="qr-block">' . $qr_img . '
      <small>Scan to verify authenticity</small>
    </div>
    <div class="sign-block">
      <div class="sign-line"></div>
      <div class="sign-label">Date Issued: ' . date('F j, Y') . '</div>
    </div>
  </div>
</div>
<script>window.onload=function(){window.print();}</script>
</body></html>';
}

/**
 * Generate a printable receipt HTML
 */
function generateReceiptHTML($receipt, $parish_name = '', $qr_url = '') {
    $qr_img = $qr_url ? '<img src="' . htmlspecialchars($qr_url) . '" width="80" height="80">' : '';

    return '<!DOCTYPE html>
<html><head><meta charset="UTF-8">
<title>Official Receipt #' . htmlspecialchars($receipt['receipt_number']) . '</title>
<style>
@import url("https://fonts.googleapis.com/css2?family=Cormorant+Garamond:wght@400;600&family=DM+Sans:wght@300;400;500&display=swap");
@page { size: A5; margin: 10mm; }
* { margin:0; padding:0; box-sizing:border-box; }
body { font-family: "DM Sans", sans-serif; font-size: 10pt; color: #333; }
.receipt { max-width: 148mm; margin: auto; padding: 8mm; border: 2px solid #C9A84C; }
.receipt-header { text-align: center; border-bottom: 2px solid #1B2A4A; padding-bottom: 5mm; margin-bottom: 5mm; }
.receipt-header h3 { font-family: "Cormorant Garamond", serif; font-size: 14pt; color: #1B2A4A; }
.receipt-header h4 { font-size: 8pt; letter-spacing: 2px; text-transform: uppercase; color: #666; }
.receipt-header .rnum { font-size: 11pt; font-weight: 600; color: #C9A84C; margin-top: 2mm; }
.receipt-body { margin: 5mm 0; }
.receipt-row { display: flex; justify-content: space-between; padding: 2mm 0; border-bottom: 1px dotted #ddd; }
.receipt-row .label { color: #666; font-size: 9pt; }
.receipt-row .value { font-weight: 500; }
.receipt-total { display: flex; justify-content: space-between; padding: 4mm 0; margin-top: 3mm; border-top: 2px solid #1B2A4A; font-size: 13pt; font-weight: 600; color: #1B2A4A; }
.receipt-footer { display: flex; justify-content: space-between; align-items: flex-end; margin-top: 8mm; padding-top: 5mm; border-top: 1px solid #ddd; }
.receipt-footer .sign { text-align: center; }
.receipt-footer .sign-line { width: 40mm; border-bottom: 1px solid #333; margin-bottom: 1mm; }
.receipt-footer .sign small { font-size: 7pt; color: #999; }
@media print { body { -webkit-print-color-adjust: exact; print-color-adjust: exact; } }
</style>
</head><body>' . navigation_controls() . '
<div class="receipt">
  <div class="receipt-header">
    <h4>Apostolic Vicariate of San Jose</h4>
    <h3>' . htmlspecialchars($parish_name ?: 'Parish') . '</h3>
    <div class="rnum">Official Receipt #' . htmlspecialchars($receipt['receipt_number']) . '</div>
  </div>
  <div class="receipt-body">
    <div class="receipt-row"><span class="label">Date</span><span class="value">' . date('F j, Y', strtotime($receipt['issued_at'])) . '</span></div>
    <div class="receipt-row"><span class="label">Received From</span><span class="value">' . htmlspecialchars($receipt['parishioner_name']) . '</span></div>
    <div class="receipt-row"><span class="label">Service</span><span class="value">' . htmlspecialchars($receipt['service_name'] ?? 'N/A') . '</span></div>
    <div class="receipt-row"><span class="label">Payment Method</span><span class="value">' . htmlspecialchars($receipt['payment_method'] ?? 'N/A') . '</span></div>
    <div class="receipt-row"><span class="label">Reference #</span><span class="value">' . htmlspecialchars($receipt['reference_number'] ?? 'N/A') . '</span></div>' .
    ($receipt['notes'] ? '<div class="receipt-row"><span class="label">Notes</span><span class="value">' . htmlspecialchars($receipt['notes']) . '</span></div>' : '') . '
  </div>
  <div class="receipt-total">
    <span>Total Amount</span>
    <span>₱' . number_format($receipt['amount'], 2) . '</span>
  </div>
  <div class="receipt-footer">
    <div class="sign">
      <div class="sign-line"></div>
      <small>Received By</small>
    </div>
    <div>' . $qr_img . '</div>
  </div>
</div>
<script>window.onload=function(){window.print();}</script>
</body></html>';
}
