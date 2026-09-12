"""Manifest verification tests using invented bytes; no registry calls."""
import hashlib
import json
import unittest
from resolve_linux_images import digest, manifest, select_amd64, resolve


class ImageManifestTests(unittest.TestCase):
    def test_digest_is_immutable_and_strict(self):
        self.assertEqual('sha256:'+'a'*64, digest('sha256:'+'a'*64))
        for value in ['latest','php:8.2','sha256:'+'A'*64,'sha256:abc','../manifest',None]:
            with self.assertRaises(ValueError):
                digest(value)

    def test_manifest_bytes_must_match_registry_digest(self):
        body=b'{"schemaVersion":2,"synthetic":true}'
        expected='sha256:'+hashlib.sha256(body).hexdigest()
        self.assertTrue(manifest(body,expected)['synthetic'])
        with self.assertRaisesRegex(ValueError,'INTEGRITY'):
            manifest(body+b' ',expected)

    def test_legacy_schema_is_rejected(self):
        body=b'{"schemaVersion":1}'
        with self.assertRaisesRegex(ValueError,'SCHEMA'):
            manifest(body,'sha256:'+hashlib.sha256(body).hexdigest())

    def test_only_one_linux_amd64_manifest_is_selected(self):
        entry={'digest':'sha256:'+'b'*64,'platform':{'os':'linux','architecture':'amd64'}}
        index={'manifests':[entry,{'digest':'sha256:'+'c'*64,'platform':{'os':'linux','architecture':'arm64'}}]}
        self.assertEqual(entry['digest'],select_amd64(index))
        for invalid in [{'manifests':[]},{'manifests':[entry,entry]}]:
            with self.assertRaises(ValueError):
                select_amd64(invalid)

    def test_unapproved_image_fails_before_any_network(self):
        with self.assertRaisesRegex(ValueError,'ALLOWLISTED'):
            resolve(None,'unapproved/image','latest')


if __name__ == '__main__':
    unittest.main()
