#!/usr/bin/env python3
"""Build the offline plugin using only the Python standard library."""
from __future__ import annotations

import base64
import hashlib
import io
from pathlib import Path
import tarfile
import xml.etree.ElementTree as ET

ROOT = Path(__file__).resolve().parents[1]
VERSION = '2026.11.15c'
NAME = 'silo-streams'
PROJECT_URL = 'https://github.com/b-mannos/unraid-silo-streams'
PLUGIN_URL = 'https://raw.githubusercontent.com/b-mannos/unraid-silo-streams/main/silo-streams.plg'
PACKAGE = f'{NAME}-{VERSION}-noarch-1.txz'
PLUGIN_ROOT = Path('usr/local/emhttp/plugins') / NAME


def sha256(data: bytes) -> str:
    return hashlib.sha256(data).hexdigest()


def build() -> None:
    source = ROOT / 'src'
    entries: dict[str, tuple[bytes | None, int]] = {}
    for path in sorted(source.rglob('*')):
        rel = path.relative_to(source)
        if path.is_symlink():
            raise ValueError(f'Symlink forbidden: {rel}')
        if not (rel == PLUGIN_ROOT or PLUGIN_ROOT in rel.parents or rel in PLUGIN_ROOT.parents):
            raise ValueError(f'Unexpected package path: {rel}')
        if path.is_file():
            if path.name.startswith('.') or path.suffix in {'.env', '.pem', '.key'} or path.name == 'config.json':
                raise ValueError(f'Private or configuration file forbidden: {rel}')
            entries[rel.as_posix()] = (path.read_bytes(), 0o644)
        elif path.is_dir():
            entries[rel.as_posix()] = (None, 0o755)
    if not any(data is not None for data, _ in entries.values()):
        raise ValueError('No plugin source files found')
    icon = (ROOT / 'assets/icon.png').read_bytes()
    if not icon.startswith(b'\x89PNG\r\n\x1a\n'):
        raise ValueError('The release icon must be a PNG')
    entries[(PLUGIN_ROOT / 'icon.png').as_posix()] = (icon, 0o644)
    entries['install'] = (None, 0o755)
    entries['install/slack-desc'] = ((ROOT / 'install/slack-desc').read_bytes(), 0o644)
    archive = io.BytesIO()
    with tarfile.open(fileobj=archive, mode='w:xz', format=tarfile.USTAR_FORMAT, preset=9) as tar:
        for name, (data, mode) in sorted(entries.items()):
            item = tarfile.TarInfo(name)
            item.mode = mode
            item.uid = item.gid = item.mtime = 0
            item.uname = item.gname = 'root'
            if data is None:
                item.type = tarfile.DIRTYPE
                tar.addfile(item)
            else:
                item.size = len(data)
                tar.addfile(item, io.BytesIO(data))
    payload = archive.getvalue()
    plugin = ET.Element('PLUGIN', {
        'name': NAME, 'author': 'Bryan Mannos', 'version': VERSION,
        'min': '7.0.0', 'launch': 'Settings/SiloStreams', 'icon': 'icon.png',
        'pluginURL': PLUGIN_URL, 'support': PROJECT_URL + '/issues',
    })
    ET.SubElement(plugin, 'CHANGES').text = (ROOT / 'CHANGELOG.md').read_text(encoding='utf-8')
    check = ET.SubElement(plugin, 'FILE', {'Run': '/bin/bash'})
    ET.SubElement(check, 'INLINE').text = '''
set -eu
command -v php >/dev/null || { echo 'Silo Streams requires PHP 8.2 and cURL.'; exit 1; }
php -r 'exit(PHP_VERSION_ID >= 80200 && extension_loaded("curl") ? 0 : 1);' || { echo 'Silo Streams requires PHP 8.2 and cURL.'; exit 1; }
umask 077
mkdir -p /boot/config/plugins/silo-streams
# FAT mount options govern flash permissions; chmod is best effort.
chmod 700 /boot/config/plugins/silo-streams 2>/dev/null || true
'''
    package = ET.SubElement(plugin, 'FILE', {
        'Name': f'/boot/config/plugins/{NAME}/{PACKAGE}',
        'Type': 'base64', 'Run': 'upgradepkg --install-new --reinstall',
    })
    encoded = base64.b64encode(payload).decode('ascii')
    ET.SubElement(package, 'INLINE').text = '\n' + '\n'.join(encoded[i:i + 76] for i in range(0, len(encoded), 76)) + '\n'
    ET.SubElement(package, 'SHA256').text = sha256(payload)
    remove = ET.SubElement(plugin, 'FILE', {'Run': '/bin/bash', 'Method': 'remove'})
    ET.SubElement(remove, 'INLINE').text = '''
set -eu
for package in /var/log/packages/silo-streams-*-noarch-1; do
  [ -f "$package" ] || continue
  removepkg "${package##*/}"
done
rm -rf -- /usr/local/emhttp/plugins/silo-streams
for package in /boot/config/plugins/silo-streams/silo-streams-*-noarch-1.txz; do
  [ ! -f "$package" ] || rm -f -- "$package"
done
# Keep saved settings and plugin-manager state on flash.
'''
    ET.indent(plugin, space='  ')
    manifest = ET.tostring(plugin, encoding='utf-8', xml_declaration=True) + b'\n'
    output = ROOT / 'dist'
    output.mkdir(exist_ok=True)
    (output / PACKAGE).write_bytes(payload)
    (output / f'{NAME}.plg').write_bytes(manifest)
    sums = ''.join(f'{sha256(data)}  {name}\n' for name, data in sorted({PACKAGE: payload, f'{NAME}.plg': manifest}.items()))
    (output / 'SHA256SUMS').write_text(sums, encoding='utf-8')
    print(f'Built {PACKAGE} and {NAME}.plg')


if __name__ == '__main__':
    build()
