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
- Verify `ZugferdVatCategoryCodes::ZERO_RATE` against your installed version, as constant names can change. If absent replace with correct library-specific category or remove 0% support until validated.
- The snippet intentionally throws on failures; this prevents sending nonconforming invoice PDFs silently. For a production system, use atomic writes/locking and a retryable state machine, and only mark the invoice `status_printed=y` after successful generation.
- Do not modify the PDF after applying a digital signature. Sign only as the last stage. Signing is a separate tested operation (e.g. PAdES), not an XML-only step; confirm PDF/A integrity afterwards. SSL/TLS is not a PDF signature.
- Preserving a visual PDF does not automatically mean PDF/A-3 conformance. Validate outputs.
- `invoice_amount` is updated *after* PDF generation in the uploaded script, so the helper derives totals from invoice items; mixed VAT rounding is strictly checked.
- The recurring billing script has independent account/date restrictions such as year <=2030; separate issue.
