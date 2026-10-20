<?php

declare(strict_types=1);

use horstoeko\zugferd\ZugferdDocumentBuilder;
use horstoeko\zugferd\ZugferdDocumentPdfBuilder;
use horstoeko\zugferd\ZugferdProfiles;
use horstoeko\zugferd\codelists\ZugferdInvoiceType;
use horstoeko\zugferd\codelists\ZugferdUnitCodes;
use horstoeko\zugferd\codelists\ZugferdVatCategoryCodes;
use horstoeko\zugferd\codelists\ZugferdVatTypeCodes;
use horstoeko\zugferd\codelists\ZugferdElectronicAddressScheme;

/**
 * Prototype integration for ISPConfig Billing, EN 16931 profile.
 * Does not sign PDFs. Add an independently tested PDF/A-compatible signing
 * stage after this step if legally/operationally required.
 */
final class ZugferdInvoiceService
{
    /** @return string Final PDF bytes (not written to disk) */
    public static function mergePdf(
        array $invoice,
        array $issuer,
        array $items,
        string $existingPdf,
        string $currency = 'EUR',
        array $paymentTerms = [],
        array $options = []
    ): string {
        if (($invoice['invoice_type'] ?? '') !== 'invoice') {
            throw new RuntimeException('Only finalized normal invoices are supported.');
        }
        if (empty($invoice['invoice_number']) || empty($invoice['invoice_date'])) {
            throw new RuntimeException('Missing invoice number/date.');
        }
        if (substr($existingPdf, 0, 5) !== '%PDF-') {
            throw new RuntimeException('Input does not look like a PDF.');
        }
        if (!$items) {
            throw new RuntimeException('Invoice contains no items.');
        }
        $sellerCountry = self::country((string)($issuer['country'] ?? ''));
        $buyerCountry = self::country((string)($invoice['country'] ?? ''));
        if (empty($issuer['company_name']) || empty($invoice['street']) || empty($invoice['zip']) || empty($invoice['city']) || !$sellerCountry || !$buyerCountry) {
            throw new RuntimeException('Missing mandatory issuer/customer address data.');
        }
        if (empty($issuer['street']) || empty($issuer['zip']) || empty($issuer['city'])) {
            throw new RuntimeException('Missing issuer address.');
        }
        if (empty($issuer['vat_id']) && empty($issuer['tax_id'])) {
            throw new RuntimeException('Issuer needs tax number or VAT ID.');
        }
        $buyerName = trim((string)(($invoice['company_name'] ?? '') ?: ($invoice['contact_name'] ?? '')));
        if ($buyerName === '') {
            throw new RuntimeException('Missing buyer name.');
        }
        $doc = ZugferdDocumentBuilder::createNew(ZugferdProfiles::PROFILE_EN16931);
        $doc->setDocumentInformation(
            (string)$invoice['invoice_number'],
            ZugferdInvoiceType::INVOICE,
            new DateTimeImmutable((string)$invoice['invoice_date']),
            strtoupper($currency)
        );
        $doc->setDocumentSeller((string)$issuer['company_name']);
        $doc->setDocumentSellerAddress((string)$issuer['street'], '', '', (string)$issuer['zip'], (string)$issuer['city'], $sellerCountry);
        if (!empty($issuer['vat_id'])) {
            $doc->addDocumentSellerVATRegistrationNumber((string)$issuer['vat_id']);
        }
        if (!empty($issuer['tax_id'])) {
            $doc->addDocumentSellerTaxNumber((string)$issuer['tax_id']);
        }
        if (!empty($issuer['email'])) {
            $doc->setDocumentSellerCommunication(ZugferdElectronicAddressScheme::UNECE3155_EM, (string)$issuer['email']);
        }
        $doc->setDocumentBuyer($buyerName);
        $doc->setDocumentBuyerAddress((string)$invoice['street'], '', '', (string)$invoice['zip'], (string)$invoice['city'], $buyerCountry);
        if (!empty($invoice['vat_id'])) $doc->addDocumentBuyerVATRegistrationNumber((string)$invoice['vat_id']);
        if (!empty($invoice['email'])) {
            $doc->setDocumentBuyerCommunication(ZugferdElectronicAddressScheme::UNECE3155_EM, (string)$invoice['email']);
        }
        if (!empty($issuer['bank_account_iban'])) {
            // Payment terms / means must be mapped from your installed library version
            // and ISPConfig payment gateway; do not assume direct debit.
            $doc->setDocumentGeneralPaymentInformation(null, (string)$invoice['invoice_number']);
        }

        // BT-9/BT-20: use the actual configured term, not its numeric ID or name alone.
        $termDescription = trim((string)($paymentTerms['description'] ?? $paymentTerms['name'] ?? ''));
        $dueDate = null;
        if (isset($paymentTerms['due_days'])) {
            $days = filter_var($paymentTerms['due_days'], FILTER_VALIDATE_INT);
            if ($days === false || $days < 0) throw new RuntimeException('Invalid payment due_days.');
            $dueDate = self::date((string)$invoice['invoice_date'])->modify('+'.$days.' days');
        }
        if ($termDescription === '' && $dueDate === null) {
            throw new RuntimeException('Payment term description or due_days is required (BR-CO-25).');
        }
        $doc->addDocumentPaymentTerm($termDescription !== '' ? $termDescription : null, $dueDate);

        $deliveryDate = !empty($invoice['delivery_date']) ? self::date((string)$invoice['delivery_date']) : null;
        $defaultServiceDate = !empty($options['use_invoice_date_as_delivery_date'])
            ? self::date((string)$invoice['invoice_date']) : null;
        if ($deliveryDate !== null) $doc->setDocumentSupplyChainEvent($deliveryDate);
        // Invoice address is the delivery default; callers can supply an explicit delivery country.
        $deliveryCountry = self::country((string)($invoice['delivery_country'] ?? $buyerCountry));
        if (!$deliveryCountry) throw new RuntimeException('Missing delivery country.');
        $doc->setDocumentShipTo($buyerName);
        $doc->setDocumentShipToAddress(null, null, null, null, null, $deliveryCountry);

        $taxGroups = [];
        $netTotal = 0.0;
        foreach ($items as $index => $item) {
            $qty = (float)($item['quantity'] ?? 0);
            $unitPrice = (float)($item['price'] ?? 0);
            $vat = (float)($item['vat'] ?? 0);
            if ($qty <= 0 || $vat < 0 || $vat > 100) {
                throw new RuntimeException('Invalid quantity/VAT in invoice item '.($index + 1));
            }
            if (!in_array(round($vat, 2), [0.0, 7.0, 12.0, 19.0, 21.0], true)) {
                throw new RuntimeException('Nonstandard VAT requires explicit legal tax category mapping.');
            }
            $lineNet = round($qty * $unitPrice, 2);
            if ($lineNet < 0) {
                throw new RuntimeException('Credit/discount lines require additional mapping.');
            }
            $description = trim((string)($item['description'] ?? ''));
            if ($description === '') {
                throw new RuntimeException('Missing invoice line description.');
            }
            // Zero percent is not sufficient to determine a legal tax category.
            if ($vat == 0.0) {
                if ((int)($item['invoice_vat_rate_id'] ?? 0) !== 2) {
                    throw new RuntimeException('Zero VAT requires an explicit mapping; only rate ID 2 is reverse charge.');
                }
                if (empty($issuer['vat_id']) || empty($invoice['vat_id'])) {
                    throw new RuntimeException('Reverse charge requires seller and buyer VAT IDs.');
                }
                $category = 'AE';
            } else {
                $category = ZugferdVatCategoryCodes::STAN_RATE;
            }
            $doc->addNewPosition((string)($index + 1));
            $doc->setDocumentPositionProductDetails(mb_substr($description, 0, 200));
            [$xmlQty, $xmlPrice, $unitCode] = self::unit($item, $qty, $unitPrice);
            $doc->setDocumentPositionNetPrice($xmlPrice);
            $doc->setDocumentPositionQuantity($xmlQty, $unitCode);
            $period = self::period($item, $description);
            if ($period !== null) {
                $doc->setDocumentPositionBillingPeriod($period[0], $period[1]);
            } elseif ($deliveryDate === null) {
                if ($defaultServiceDate === null || preg_match('/\d{2}\.\d{2}\.\d{4}|\d{4}-\d{2}-\d{2}/', $description)) {
                    throw new RuntimeException('Missing/unrecognized delivery date or service period for line '.($index + 1).' (BR-FX-EN-04).');
                }
                $doc->setDocumentPositionBillingPeriod($defaultServiceDate, $defaultServiceDate);
            }
            $doc->addDocumentPositionTax($category, ZugferdVatTypeCodes::VALUE_ADDED_TAX, $vat);
            $doc->setDocumentPositionLineSummation($lineNet);
            $key = $category . ':' . number_format($vat, 2, '.', '');
            if (!isset($taxGroups[$key])) {
                $taxGroups[$key] = ['category' => $category, 'rate' => $vat, 'base' => 0.0];
            }
            $taxGroups[$key]['base'] += $lineNet;
            $netTotal += $lineNet;
        }
        $netTotal = round($netTotal, 2);
        $vatTotal = 0.0;
        foreach ($taxGroups as $taxGroup) {
            $base = round($taxGroup['base'], 2);
            $tax = round($base * $taxGroup['rate'] / 100, 2);
            $doc->addDocumentTax($taxGroup['category'], ZugferdVatTypeCodes::VALUE_ADDED_TAX, $base, $tax, $taxGroup['rate'], $taxGroup['category'] === 'AE' ? 'Reverse charge' : null, $taxGroup['category'] === 'AE' ? 'VATEX-EU-AE' : null);
            $vatTotal += $tax;
        }
        $vatTotal = round($vatTotal, 2);
        $gross = round($netTotal + $vatTotal, 2);
        // Fail closed on rounding mismatch against ISPConfig's own original calculation.
        $expectedGross = 0.0;
        foreach ($items as $item) {
            $p = (float)$item['quantity'] * (float)$item['price'];
            $expectedGross += $p + $p * (float)$item['vat'] / 100;
        }
        if (abs(round($expectedGross, 2) - $gross) > 0.001) {
            throw new RuntimeException('VAT rounding differs from ISPConfig. Explicit rounding policy needed.');
        }
        $doc->setDocumentSummation($gross, $gross, $netTotal, 0.0, 0.0, $netTotal, $vatTotal);
        $pdf = ZugferdDocumentPdfBuilder::fromPdfString($doc, $existingPdf);
        $pdf->generateDocument();
        return $pdf->downloadString();
    }

    private static function date(string $value): DateTimeImmutable
    {
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        if (!$date || $date->format('Y-m-d') !== $value) throw new RuntimeException('Invalid invoice/service date: '.$value);
        return $date;
    }

    private static function unit(array $item, float $quantity, float $price): array
    {
        $unit = strtolower(trim((string)($item['unit'] ?? 'Item')));
        if (in_array($unit, ['item', 'items', 'h87'], true)) return [$quantity, $price, 'H87'];
        if (in_array($unit, ['hour', 'hours', 'hur'], true)) return [$quantity, $price, 'HUR'];
        // There is no invented "half hour" code: convert both quantity and price.
        if (in_array($unit, ['half-hour', 'halfhour', 'halfhours'], true)) {
            $hours = $quantity / 2;
            $precision = \horstoeko\zugferd\ZugferdSettings::getQuantityDecimals();
            if (abs(round($hours, $precision) - $hours) > 0.0000001) {
                throw new RuntimeException('Half-hour quantity exceeds XML quantity precision.');
            }
            return [$hours, $price * 2, 'HUR'];
        }
        throw new RuntimeException('Unknown invoice unit: '.$unit);
    }

    private static function period(array $item, string $description): ?array
    {
        $start = $item['service_period_start'] ?? null;
        $end = $item['service_period_end'] ?? null;
        // Legacy ISPConfig stores expanded FROMDATE/TODATE at the end of the first line.
        // Read only that precise form, never dates from arbitrary free-text lines.
        if (!$start && !$end) {
            $firstLine = preg_split('/\R/u', $description)[0];
            if (preg_match('/(?:^|\s)(\d{2}\.\d{2}\.\d{4})\s+(?:[-–]\s*)?(\d{2}\.\d{2}\.\d{4})\s*$/u', $firstLine, $match)) {
                $start = implode('-', array_reverse(explode('.', $match[1])));
                $end = implode('-', array_reverse(explode('.', $match[2])));
            } else {
                return null;
            }
        }
        if (!$start || !$end) throw new RuntimeException('Incomplete service period.');
        $from = self::date((string)$start);
        $to = self::date((string)$end);
        if ($from > $to) throw new RuntimeException('Service period starts after its end.');
        return [$from, $to];
    }

    private static function country(string $value): string
    {
        $country = strtoupper(trim($value));
        $countries = ['DE' => 'DE', 'GERMANY' => 'DE', 'DEUTSCHLAND' => 'DE', 'AT' => 'AT', 'AUSTRIA' => 'AT', 'ÖSTERREICH' => 'AT', 'CH' => 'CH', 'SWITZERLAND' => 'CH', 'SCHWEIZ' => 'CH', 'CZ' => 'CZ', 'CZECH REPUBLIC' => 'CZ', 'TSCHECHIEN' => 'CZ'];
        return $countries[$country] ?? (preg_match('/^[A-Z]{2}$/', $country) ? $country : '');
    }
}
