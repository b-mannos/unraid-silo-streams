#!/usr/bin/env python3
"""Offline artifact checks; no Unraid installation is performed."""
import base64
import hashlib
import io
from pathlib import Path, PurePosixPath
import runpy
import tarfile
import unittest
import xml.etree.ElementTree as ET

ROOT = Path(__file__).resolve().parents[1]
BUILD = runpy.run_path(str(ROOT / 'scripts/build.py'))


class PackageTests(unittest.TestCase):
    def test_manifest_and_archive(self):
        dist = ROOT / 'dist'
        manifest = ET.parse(dist / 'silo-streams.plg').getroot()
        self.assertEqual(manifest.tag, 'PLUGIN')
        self.assertEqual(manifest.attrib['name'], 'silo-streams')
        self.assertEqual(manifest.attrib['min'], '7.0.0')
        self.assertEqual(manifest.attrib['launch'], 'Settings/SiloStreams')
        self.assertEqual(manifest.attrib['author'], 'Bryan Mannos')
        self.assertEqual(manifest.attrib['pluginURL'], BUILD['PLUGIN_URL'])
        self.assertEqual(manifest.attrib['support'], BUILD['PROJECT_URL'] + '/issues')
        self.assertEqual(manifest.attrib['icon'], 'icon.png')
        files = manifest.findall('FILE')
        self.assertIn('PHP_VERSION_ID >= 80200', files[0].findtext('INLINE'))
        self.assertIn('extension_loaded("curl")', files[0].findtext('INLINE'))
        package = next(item for item in files if item.get('Type') == 'base64')
        self.assertEqual(package.get('Run'), 'upgradepkg --install-new --reinstall')
        self.assertEqual(package.get('Name'), f"/boot/config/plugins/silo-streams/{BUILD['PACKAGE']}")
        payload = base64.b64decode(''.join(package.findtext('INLINE').split()), validate=True)
        self.assertEqual(hashlib.sha256(payload).hexdigest(), package.findtext('SHA256'))
        self.assertEqual(payload, (dist / BUILD['PACKAGE']).read_bytes())
        root = 'usr/local/emhttp/plugins/silo-streams'
        with tarfile.open(fileobj=io.BytesIO(payload), mode='r:xz') as archive:
            members = archive.getmembers()
            self.assertEqual(archive.extractfile(root + '/icon.png').read(), (ROOT / 'assets/icon.png').read_bytes())
            self.assertTrue(any(member.name == 'install/slack-desc' for member in members))
            for member in members:
                name = PurePosixPath(member.name)
                self.assertFalse(name.is_absolute())
                self.assertNotIn('..', name.parts)
                self.assertTrue(member.isdir() or member.isfile())
                self.assertEqual((member.uid, member.gid, member.mtime), (0, 0, 0))
                self.assertTrue(member.name in ('usr', 'usr/local', 'usr/local/emhttp', 'usr/local/emhttp/plugins', root, 'install', 'install/slack-desc') or member.name.startswith(root + '/'))
                self.assertFalse(any(part.startswith('.') for part in name.parts))
                self.assertNotEqual(name.name, 'config.json')
                self.assertNotIn(name.suffix, ('.env', '.pem', '.key'))
            for source in (ROOT / 'src').rglob('*'):
                if source.is_file():
                    relative = source.relative_to(ROOT / 'src').as_posix()
                    self.assertEqual(archive.extractfile(relative).read(), source.read_bytes())
        remove = next(item.findtext('INLINE') for item in files if item.get('Method') == 'remove')
        self.assertIn('removepkg', remove)
        self.assertNotIn('rm -rf -- /boot', remove)
        self.assertNotIn('config.json', remove)
        self.assertNotIn('.plg', remove)
        for line in (dist / 'SHA256SUMS').read_text().splitlines():
            expected, name = line.split('  ', 1)
            self.assertEqual(hashlib.sha256((dist / name).read_bytes()).hexdigest(), expected)

    def test_deterministic_rebuild(self):
        dist = ROOT / 'dist'
        names = [BUILD['PACKAGE'], 'silo-streams.plg', 'SHA256SUMS']
        before = {name: (dist / name).read_bytes() for name in names}
        BUILD['build']()
        self.assertEqual(before, {name: (dist / name).read_bytes() for name in names})


if __name__ == '__main__':
    unittest.main()
