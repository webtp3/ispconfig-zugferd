<?php
// Parser regression tests intentionally run without Composer or PDF dependencies.
require __DIR__.'/../ZugferdInvoiceService.php';
$method = new ReflectionMethod('ZugferdInvoiceService', 'period');
$method->setAccessible(true);
$count = 0;
foreach (["\n", "\r\n"] as $newline) {
    foreach (['-', ' - ', '–', '—'] as $separator) {
        $period = $method->invoke(null, [], 'Annual support'.$newline.'01.11.2026'.$separator.'31.10.2027');
        if ($period[0]->format('Y-m-d') !== '2026-11-01' || $period[1]->format('Y-m-d') !== '2027-10-31') {
            throw new RuntimeException('Recurring cron period mismatch');
        }
        $count++;
    }
}
foreach ([
    'Support 01.11.2026 31.10.2027',
    'Support 01.11.2026-31.10.2027',
    "Support\n01.11.2026-31.10.2027\n01.11.2026-31.10.2027"
] as $description) {
    $period = $method->invoke(null, [], $description);
    if ($period[0]->format('Y-m-d') !== '2026-11-01' || $period[1]->format('Y-m-d') !== '2027-10-31') throw new RuntimeException('Legacy/duplicate period mismatch');
    $count++;
}
foreach ([
    "Support\n31.10.2027-01.11.2026",
    "Support\n31.02.2026-31.10.2027",
    "Support\n01.11.2026-31.10.2027\n01.11.2027-31.10.2028"
] as $description) {
    try {
        $method->invoke(null, [], $description);
        throw new LogicException('Accepted invalid or ambiguous service period');
    } catch (RuntimeException $expected) { $count++; }
}
$period = $method->invoke(null, ['service_period_start'=>'2026-01-01','service_period_end'=>'2026-01-31'], "Support\n01.11.2026-31.10.2027");
if ($period[0]->format('Y-m-d') !== '2026-01-01') throw new RuntimeException('Explicit period must take precedence');
$count++;
if ($method->invoke(null, [], "Support\nOffer dated 01.11.2026 for delivery 31.10.2027") !== null) throw new RuntimeException('Do not infer dates from arbitrary prose');
$count++;
echo "PASS: $count service period parser cases\n";
