"""Offline guard tests for the read-only public advisory collector."""
import unittest
from audit_dependencies import locked_python, lookup, public_json


class DependencyAuditTests(unittest.TestCase):
    def test_exact_pins(self):
        self.assertEqual([('PyHanko', '0.37.0')], locked_python('# scope\nPyHanko==0.37.0\n'))

    def test_unsafe_or_ambiguous_requirements_rejected(self):
        for text in ['--index-url https://example.test', 'x>=1', 'x @ https://example.test',
                     'x==1\nx==2', 'Some_Name==1\nsome-name==1', '']:
            with self.subTest(text=text), self.assertRaises(ValueError):
                locked_python(text)

    def test_pagination_preserves_query_mapping(self):
        queries = [{'version': '1', 'package': {'name': 'one'}}, {'version': '2', 'package': {'name': 'two'}}]
        calls = []
        def fetch(path, body):
            calls.append(body)
            if len(calls) == 1:
                return {'results': [{}, {'vulns': [{'id': 'A-1'}], 'next_page_token': 'NEXT'}]}
            self.assertEqual([dict(queries[1], page_token='NEXT')], body['queries'])
            return {'results': [{'vulns': [{'id': 'A-1'}, {'id': 'A-2'}]}]}
        self.assertEqual({1: {'A-1', 'A-2'}}, lookup(queries, fetch))

    def test_incomplete_or_error_results_never_report_clean(self):
        for payload in [{}, {'results': []}, {'results': [{'error': 'unavailable'}]},
                        {'results': [{'vulns': 'bad'}]}, {'results': [{'vulns': [{'id': '../secret'}]}]}]:
            with self.subTest(payload=payload), self.assertRaises(ValueError):
                lookup([{'version': '1'}], lambda *args: payload)

    def test_infinite_pagination_is_incomplete(self):
        with self.assertRaisesRegex(ValueError, 'pagination'):
            lookup([{}], lambda *args: {'results': [{'next_page_token': 'again'}]})

    def test_unexpected_api_path_fails_without_network(self):
        for path in ['https://example.test', '../config', 'vulns/../config', 'vulns/ID?secret=x']:
            with self.subTest(path=path), self.assertRaises(ValueError):
                public_json(path)


if __name__ == '__main__':
    unittest.main()
