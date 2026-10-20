<?php
require __DIR__.'/../vendor/autoload.php';
require __DIR__.'/../ZugferdInvoiceService.php';
use horstoeko\zugferd\ZugferdDocumentPdfReader;
use horstoeko\zugferd\ZugferdXsdValidator;
function check($ok, $message) { if (!$ok) throw new RuntimeException($message); }
$invoice = ['invoice_type'=>'invoice', 'invoice_number'=>'TEST-533', 'invoice_date'=>'2026-10-01', 'company_name'=>'Buyer', 'street'=>'Testweg 2', 'zip'=>'10115', 'city'=>'Berlin', 'country'=>'DE'];
$issuer = ['company_name'=>'Seller', 'street'=>'Testweg 1', 'zip'=>'10115', 'city'=>'Berlin', 'country'=>'DE', 'vat_id'=>'DE123456789'];
$base = ['quantity'=>20, 'price'=>55, 'vat'=>19, 'description'=>"Support 01.10.2026 31.12.2026\nUpdates", 'unit'=>'Hour'];
$pdf = new FPDF(); $pdf->AddPage(); $pdf->SetFont('Arial', '', 12); $pdf->Cell(40,10,'Test invoice');
$input = $pdf->Output('S');
function generate($invoice, $issuer, $items, $input, $term, $options=[]) {
    $pdf = ZugferdInvoiceService::mergePdf($invoice, $issuer, $items, $input, 'EUR', $term, $options);
    $reader = ZugferdDocumentPdfReader::readAndGuessFromContent($pdf);
    $validator = new ZugferdXsdValidator($reader);
    $validator->validate();
    check(!$validator->hasValidationErrors(), 'XSD validation failed');
    $xml = $reader->serializeAsXml();
    $doc = new DOMDocument(); $doc->loadXML($xml);
    $xp = new DOMXPath($doc); $xp->registerNamespace('ram','urn:un:unece:uncefact:data:standard:ReusableAggregateBusinessInformationEntity:100');
    return [$xml, $xp];
}
[$xml,$xp]=generate($invoice,$issuer,[$base],$input,['description'=>'30 Tage', 'due_days'=>30]);
check($xp->evaluate('string(//ram:SpecifiedTradePaymentTerms/ram:DueDateDateTime/*)')==='20261031', '30-day due date');
check($xp->evaluate('string(//ram:BillingSpecifiedPeriod/ram:StartDateTime/*)')==='20261001', 'Period start');
check($xp->evaluate('string(//ram:BillingSpecifiedPeriod/ram:EndDateTime/*)')==='20261231', 'Period end');
check($xp->evaluate('string(//ram:ShipToTradeParty/ram:PostalTradeAddress/ram:CountryID)')==='DE', 'BT-80 delivery country');
check($xp->evaluate('string(//ram:BilledQuantity/@unitCode)')==='HUR', 'Hour unit');
$cases = ['Item'=>['H87','20.00','55.00'], 'Hour'=>['HUR','20.00','55.00'], 'Half-hour'=>['HUR','10.00','110.00']];
foreach ($cases as $unit=>$expected) {
    [$ignored,$x]=generate($invoice,$issuer,[array_merge($base,['unit'=>$unit])],$input,['description'=>'7 Tage', 'due_days'=>7]);
    check($x->evaluate('string(//ram:BilledQuantity/@unitCode)')===$expected[0], $unit.' code');
    check($x->evaluate('string(//ram:BilledQuantity)')===$expected[1], $unit.' quantity');
    check($x->evaluate('string(//ram:NetPriceProductTradePrice/ram:ChargeAmount)')===$expected[2], $unit.' unit price');
    check($x->evaluate('string(//ram:LineTotalAmount)')==='1100.00', 'Unchanged line net');
}
foreach ([['Sofort',2],['Sofort',1],['je Quartalsanfang 25%',30],['per Lastschrift',7],['2% Skonto bei 7 Tage Zahlung',7],['50% nach Beauftragung 50% nach Lieferung',14]] as $term) {
    [, $x]=generate($invoice,$issuer,[$base],$input,['description'=>$term[0], 'due_days'=>$term[1]]);
    check($x->evaluate('string(//ram:SpecifiedTradePaymentTerms/ram:Description)')===$term[0], 'Preserve payment text');
    check($x->evaluate('string(//ram:SpecifiedTradePaymentTerms/ram:DueDateDateTime/*)')===(new DateTimeImmutable('2026-10-01'))->modify('+'.$term[1].' days')->format('Ymd'), 'Use configured days, not term name');
}
foreach (['missing-term','unknown-unit','missing-period','reversed-period','ambiguous-zero','fractional-half-hour'] as $failure) {
    $item=$base; $term=['description'=>'30 Tage','due_days'=>30];
    if ($failure==='missing-term') $term=[];
    if ($failure==='fractional-half-hour') { $item['unit']='Half-hour'; $item['quantity']=0.25; }
    if ($failure==='unknown-unit') $item['unit']='Unknown';
    if ($failure==='missing-period') $item['description']='Support';
    if ($failure==='reversed-period') $item['description']='Support 31.12.2026 01.10.2026';
    if ($failure==='ambiguous-zero') { $item['vat']=0; $item['invoice_vat_rate_id']=32; }
    try { generate($invoice,$issuer,[$item],$input,$term); throw new LogicException('Accepted '.$failure); }
    catch (RuntimeException $e) { /* Expected fail-closed mapping errors. */ }
}

$datedInvoice=$invoice; $datedInvoice['delivery_date']='2026-10-01';
[, $x]=generate($datedInvoice,$issuer,[array_merge($base,['description'=>'Single service'])],$input,['description'=>'30 Tage']);
check($x->evaluate('string(//ram:ActualDeliverySupplyChainEvent/ram:OccurrenceDateTime/*)')==='20261001', 'Explicit delivery date');


[$fallbackXml, $x]=generate($invoice,$issuer,[array_merge($base,['description'=>'Single service'])],$input,['description'=>'Sofort','due_days'=>2],['use_invoice_date_as_delivery_date'=>true]);
check($x->evaluate('string(//ram:BillingSpecifiedPeriod/ram:StartDateTime/*)')==='20261001', 'Configured single-day fallback');
[, $x]=generate($invoice,$issuer,[$base],$input,['description'=>'30 Tage','due_days'=>30],['use_invoice_date_as_delivery_date'=>true]);
check($x->evaluate('string(//ram:BillingSpecifiedPeriod/ram:EndDateTime/*)')==='20261231', 'Explicit line period wins over fallback');
check($x->evaluate('count(//ram:ActualDeliverySupplyChainEvent)')==0, 'Do not override period with header delivery date');

$rcInvoice=$invoice; $rcInvoice['vat_id']='DE987654321';
[$rcXml,$x]=generate($rcInvoice,$issuer,[array_merge($base,['vat'=>0,'invoice_vat_rate_id'=>2])],$input,['description'=>'30 Tage','due_days'=>30]);
check($x->evaluate('string(//ram:ApplicableHeaderTradeSettlement/ram:ApplicableTradeTax/ram:CategoryCode)')==='AE', 'Reverse charge ID 2');
check($x->evaluate('string(//ram:ApplicableHeaderTradeSettlement/ram:ApplicableTradeTax/ram:ExemptionReasonCode)')==='VATEX-EU-AE', 'Reverse charge reason');
$output=getenv('VALIDATION_OUTPUT_DIR');
if ($output) { file_put_contents($output.'/corrected.xml',$xml); file_put_contents($output.'/reverse-charge.xml',$rcXml); file_put_contents($output.'/fallback.xml',$fallbackXml); }
echo "PASS: payment terms, service periods, unit conversions, reverse charge, XSD and failure cases\n";
