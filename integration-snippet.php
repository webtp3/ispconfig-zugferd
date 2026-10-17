<?php
// Insert inside finalize_invoice(), replacing only its original file_put_contents
// call after $invoice_filename and $pdf_content have been computed.
// The $app object and the $invoice / $company variables exist in the uploaded script.
$zugferdAutoload = '/usr/local/lib/ispconfig-zugferd/vendor/autoload.php';
$zugferdService = '/usr/local/lib/ispconfig-zugferd/ZugferdInvoiceService.php';
if (!is_file($zugferdAutoload) || !is_file($zugferdService)) {
    throw new RuntimeException('ZUGFeRD service not installed.');
}
require_once $zugferdAutoload;
require_once $zugferdService;
$invoice = $app->db->queryOneRecord('SELECT * FROM invoice WHERE invoice_id = ?', $invoice_id);
$items = $app->db->queryAllRecords('SELECT * FROM invoice_item WHERE invoice_id = ? ORDER BY invoice_item_id', $invoice_id);
$settings = $app->db->queryOneRecord('SELECT * FROM invoice_settings WHERE invoice_settings_id = 1');
$combinedPdf = ZugferdInvoiceService::mergePdf(
    $invoice,
    $company,
    $items ?: [],
    $pdf_content,
    (string)($settings['currency'] ?? 'EUR')
);
// TODO: insert a dedicated PAdES signing stage here, after ZUGFeRD merge.
if (file_put_contents($invoice_filename, $combinedPdf, LOCK_EX) === false) {
    throw new RuntimeException('Unable to store invoice PDF: '.$invoice_filename);
}
