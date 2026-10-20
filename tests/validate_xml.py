"""Optional: python -m pip install saxonche lxml; python tests/validate_xml.py invoice.xml."""
import sys
from pathlib import Path
from lxml import etree
from saxonche import PySaxonProcessor

stylesheet = Path(__file__).resolve().parents[1] / 'vendor/horstoeko/zugferd/src/schema/xslt/FACTUR-X_EN16931.xslt'
ns = {'ram': 'urn:un:unece:uncefact:data:standard:ReusableAggregateBusinessInformationEntity:100'}
with PySaxonProcessor(license=False) as proc:
    validator = proc.new_xslt30_processor().compile_stylesheet(stylesheet_file=str(stylesheet))
    failures = []
    for filename in sys.argv[1:]:
        doc = etree.parse(filename)
        # The bundled Factur-X XSLT does not include BR-CO-25, so check it explicitly.
        due = float(doc.xpath('string(//ram:DuePayableAmount)', namespaces=ns) or 0)
        has_terms = doc.xpath('boolean(//ram:SpecifiedTradePaymentTerms/ram:Description[normalize-space()] | //ram:SpecifiedTradePaymentTerms/ram:DueDateDateTime/*[normalize-space()])', namespaces=ns)
        errors = []
        if due > 0 and not has_terms:
            errors.append('BR-CO-25: missing payment due date and terms')
        svrl = etree.fromstring(validator.transform_to_string(source_file=filename).encode())
        for error in svrl.xpath('//*[local-name()="failed-assert"]'):
            errors.append(''.join(error.itertext()).strip())
        print(f'{Path(filename).name}: {len(errors)} failed checks')
        for error in errors:
            print(error)
        failures.extend(errors)
if len(sys.argv) < 2:
    raise SystemExit('Supply at least one invoice XML filename')
raise SystemExit(1 if failures else 0)
