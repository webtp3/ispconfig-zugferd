# ISPConfig Billing: ZUGFeRD integration prototype

Based on uploaded `create_recurring_invoices.php` and `invoice_action.php`.

## Install

1. Back up the billing module and database. Verify PHP version, extension availability and Composer constraints.
2. In an isolated private directory (for example `/usr/local/lib/ispconfig-zugferd`):
   `composer require horstoeko/zugferd`
3. Place `ZugferdInvoiceService.php` in a private directory, not under a public document root.
4. In `create_recurring_invoices.php`, replace the original `file_put_contents($invoice_filename, $pdf_content);` only after the existing PDF bytes and the invoice number are available, as shown in `integration-snippet.php`.
5. Check all manual finalization paths (`invoice_edit.php`, billing functions, template classes), so these get the same integration. `invoice_action.php` only routes actions and emails and is not the main PDF generation path.
6. Generate test invoices including all used VAT types, zero-rated cases, different companies and payment methods. Validate both EN16931 XML (KoSIT) and PDF/A-3 (veraPDF). Do not enable production until these pass.

## Caveats / important

- **Prototype, not production ready.** Missing complete tax category treatment for exempt/reverse charge/intra-community scenarios, payment means and payment terms, service periods, discounts, credit notes, customer reference fields, and edge-case rounding. Zero VAT is NOT always zero-rated and may require VAT exemption reason. Decide based on tax records.
- Zero-VAT handling requires an explicit tax mapping. Only ISPConfig rate ID 2 is currently mapped to reverse charge; IDs 1 and 32 fail closed.
- The snippet intentionally throws on failures; this prevents sending nonconforming invoice PDFs silently. For a production system, use atomic writes/locking and a retryable state machine, and only mark the invoice `status_printed=y` after successful generation.
- Do not modify the PDF after applying a digital signature. Sign only as the last stage. Signing is a separate tested operation (e.g. PAdES), not an XML-only step; confirm PDF/A integrity afterwards. SSL/TLS is not a PDF signature.
- Preserving a visual PDF does not automatically mean PDF/A-3 conformance. Validate outputs.
- `invoice_amount` is updated *after* PDF generation in the uploaded script, so the helper derives totals from invoice items; mixed VAT rounding is strictly checked.
- The recurring billing script has independent account/date restrictions such as year <=2030; separate issue.

## Structured payment terms, periods and units

Deploy this service together with the updated ISPConfig caller. `mergePdf()`
accepts the selected `invoice_payment_term` row as its sixth argument. The caller
must resolve the row by `invoice.payment_terms`; this ID is not a number of days.
`description` is preserved as BT-20 and `due_days` produces BT-9 from invoice date.
Thus "Sofort" uses its configured one/two days. Discount, quarterly and advance
payment text is retained without inventing instalment amounts, deducting skonto,
changing document type, or inferring a payment method from the name. A direct-debit
payment means/mandate is a separate mapping and is not provided by this change.

The caller enriches each item from `invoice_item_template.unit`. `Item` maps to
H87, `Hour` to HUR. `Half-hour` maps to HUR with half the quantity and twice the
price; the net amount stays unchanged. Missing template references are errors;
manual lines without a template use Item. Current template values are used;
unit snapshotting for historical invoices requires a separate schema change.
Unknown units fail rather than silently becoming pieces.

For BR-FX-EN-04 provide `invoice.delivery_date` (YYYY-MM-DD), or explicit
`service_period_start` / `service_period_end` on every item. Legacy stored item
text ending its first line with two DD.MM.YYYY dates is also supported, matching
ISPConfig's expanded FROMDATE/TODATE convention. Dates elsewhere in free text
are not interpreted. The buyer country is the delivery-country default and can
be overridden with `invoice.delivery_country`. No periods are inferred from
mutable recurring-item schedules. Missing or invalid service dates stop creation.
An optional seventh argument `['use_invoice_date_as_delivery_date' => true]`
selects the existing PDF policy for otherwise undated lines. The updated ISPConfig
caller enables it; the service default is false. Explicit periods win; unrecognized
date-containing descriptions still require explicit mapping. The fallback is a
single-day line period, not a header delivery date overriding all lines.

ID 2 at zero VAT maps to AE / VATEX-EU-AE and requires both VAT IDs. Zero-rate IDs
1 and 32 remain intentionally unmapped. Positive 7/12/19/21 rates use category S;
this is technical support, not a determination of applicable tax rates.

Run `php tests/mapping.php` for PDF/XML round trips, XSD validation, the actual
payment-term variants, units, reverse charge and fail-closed cases. Optional
`VALIDATION_OUTPUT_DIR=/path` exports synthetic XML for Schematron checking.
PDF/A, signature, tax-policy and recipient-specific requirements need separate
validation. Existing PDFs are not retroactively changed by deploying the code.

To check business rules with the bundled Factur-X XSLT, install `saxonche` and
`lxml` in an isolated Python environment and run `python tests/validate_xml.py
/path/to/invoice.xml`. It also checks BR-CO-25 explicitly because that rule is
not present in the bundled stylesheet. The test invoice XML can be exported with
`VALIDATION_OUTPUT_DIR=/path php tests/mapping.php`. This is not PDF/A validation.
