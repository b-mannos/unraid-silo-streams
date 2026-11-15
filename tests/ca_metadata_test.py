#!/usr/bin/env python3
"""Validate the public Community Apps metadata and its local assets."""
from pathlib import Path
import struct
import unittest
from urllib.parse import urlsplit
import xml.etree.ElementTree as ET

ROOT = Path(__file__).resolve().parents[1]
REPO = 'https://github.com/b-mannos/unraid-silo-streams'
RAW = 'https://raw.githubusercontent.com/b-mannos/unraid-silo-streams/main/'


class CommunityAppsTests(unittest.TestCase):
    def test_profile_and_listing(self):
        profile = ET.parse(ROOT / 'ca_profile.xml').getroot()
        self.assertEqual(profile.tag, 'CommunityApplications')
        self.assertTrue((profile.findtext('Profile') or '').strip())
        listing = ET.parse(ROOT / 'plugins/silo-streams.xml').getroot()
        self.assertEqual(listing.tag, 'Plugin')
        for field in ['Name', 'Overview', 'PluginURL', 'Support', 'Project', 'Icon', 'Screenshot']:
            self.assertTrue((listing.findtext(field) or '').strip(), field)
        self.assertEqual(listing.findtext('Project'), REPO)
        self.assertEqual(listing.findtext('Support'), REPO + '/issues')
        self.assertEqual(listing.findtext('License'), 'MIT')
        self.assertEqual(listing.findtext('PluginURL'), RAW + 'silo-streams.plg')
        manifest = ET.parse(ROOT / 'dist/silo-streams.plg').getroot()
        self.assertEqual(listing.findtext('PluginURL'), manifest.get('pluginURL'))
        self.assertEqual(manifest.get('support'), listing.findtext('Support'))
        self.assertEqual(listing.findtext('MinVer'), manifest.get('min'))
        for element in [*profile, *listing]:
            value = (element.text or '').strip()
            if value.startswith('https://'):
                self.assertIn(urlsplit(value).hostname, ['github.com', 'raw.githubusercontent.com'])
            self.assertNotIn('YOUR_', value)
            if value.startswith(RAW) and value != RAW + 'silo-streams.plg':
                self.assertTrue((ROOT / value.removeprefix(RAW)).is_file(), value)
        if (ROOT / 'silo-streams.plg').is_file():
            self.assertEqual((ROOT / 'silo-streams.plg').read_bytes(), (ROOT / 'dist/silo-streams.plg').read_bytes(), 'Regenerate the published root installer')

    def test_license_and_brand_assets(self):
        license_text = (ROOT / 'LICENSE').read_text()
        self.assertIn('MIT License', license_text)
        self.assertIn('Permission is hereby granted, free of charge', license_text)
        self.assertIn('2026 Bryan Mannos', license_text)
        for name in ['icon.png', 'screenshot.png']:
            data = (ROOT / 'assets' / name).read_bytes()
            self.assertTrue(data.startswith(b'\x89PNG\r\n\x1a\n'))
            width, height = struct.unpack('>II', data[16:24])
            self.assertGreater(width, 0)
            self.assertGreater(height, 0)
        icon = (ROOT / 'assets/icon.png').read_bytes()
        self.assertEqual(*struct.unpack('>II', icon[16:24]))


if __name__ == '__main__':
    unittest.main()
