"""One-time media cutover. Inventory by default; --apply copies/verifies and updates URLs.

Run on the VPS host after disabling the exact old recipe-cleanup cron, not in the
development container. Original images remain until explicit --retire-source.
"""
import argparse
import hashlib
import json
import os
from pathlib import Path
import re
import subprocess
import tempfile
import urllib.parse
import urllib.request

NAME = re.compile(r'(?:(?:[a-f0-9]{48}|[a-f0-9]{64}|[a-z][a-z0-9-]*-ai-v[1-9][0-9]*)\.(?:jpg|png|webp)|category-[1-9][0-9]*\.jpg)')
HOST = 'https://ecngx362.inmotionhosting.com:2083'


def digest(path):
    with path.open('rb') as stream:
        return hashlib.file_digest(stream, 'sha256').hexdigest()


class Hosting:
    def __init__(self, config):
        auth = json.loads(config.read_text())
        self.authorization = f"cpanel {auth['username']}:{auth['api_token']}"
        self.directory = f"/home/{auth['username']}/coffeybean.com/CoffeyTableRecipes/media/recipes"

    def api(self, operation, **params):
        request = urllib.request.Request(HOST + '/execute/Fileman/' + operation + '?' + urllib.parse.urlencode(params),
                                         headers={'Authorization': self.authorization})
        with urllib.request.urlopen(request, timeout=60) as response:
            data = json.load(response)
        if data.get('status') != 1:
            raise RuntimeError('Hosting file operation failed; private response omitted')
        return data['data']

    def inventory(self):
        files = {}
        self.locations = {}
        directories = [self.directory, self.directory.removesuffix('/recipes') + '/recipe-photos', self.directory.removesuffix('/recipes') + '/recipe-illustrations']
        for directory in directories:
            rows = self.api('list_files', dir=directory, types='file')
            if not isinstance(rows, list):
                raise ValueError('Unrecognized media inventory')
            for row in rows:
                name = row.get('file', '')
                if name in ('.', '..', '.htaccess'):
                    continue
                if (re.fullmatch(r'category-[1-9][0-9]*\.jpg\.lock', name)
                        and row.get('type') == 'file' and int(row.get('size') or 0) == 0):
                    continue
                if not NAME.fullmatch(name) or row.get('type') != 'file':
                    raise ValueError('Unexpected media entry; review before moving files')
                size = int(row.get('size') or 0)
                if not 0 < size <= 40 * 1024 * 1024:
                    raise ValueError('Unexpected media size')
                if name in files:
                    raise ValueError('Duplicate filenames across source directories require review')
                files[name] = size
                self.locations[name] = directory
        return files

    def read(self, name, size):
        if not NAME.fullmatch(name):
            raise ValueError('Unsafe media name')
        # ISO-8859-1 maps every byte to one Unicode code point, then JSON's UTF-8
        # transports it losslessly. This reads/transcodes the response, not the file.
        data = self.api('get_file_content', dir=getattr(self, 'locations', {}).get(name, self.directory), file=name,
                        from_charset='ISO-8859-1', to_charset='UTF-8')
        content = data.get('content')
        if not isinstance(content, str):
            raise ValueError('Unreadable source image')
        result = content.encode('latin-1')
        if len(result) != size:
            raise ValueError('Source media byte count changed')
        return result

    def trash(self, name):
        if not NAME.fullmatch(name):
            raise ValueError('Unsafe retirement target')
        # The deployed cPanel version exposes trash through API 2, not UAPI.
        path = getattr(self, 'locations', {}).get(name, self.directory) + '/' + name
        body = urllib.parse.urlencode(dict(cpanel_jsonapi_user=self.directory.split('/')[2],
            cpanel_jsonapi_apiversion=2, cpanel_jsonapi_module='Fileman',
            cpanel_jsonapi_func='fileop', op='trash', sourcefiles=path, doubledecode=1)).encode()
        request = urllib.request.Request(HOST + '/json-api/cpanel', data=body,
                                         headers={'Authorization': self.authorization})
        with urllib.request.urlopen(request, timeout=60) as response:
            result = json.load(response)['cpanelresult']
        if (result.get('event', {}).get('result') != 1 or not result.get('data') or
                any(row.get('result') != 1 for row in result['data'])):
            raise RuntimeError('Hosting image retirement failed; private response omitted')


def transfer(hosting, destination):
    destination.mkdir(parents=True, exist_ok=True)
    if destination.is_symlink():
        raise ValueError('Media directory must not be a symlink')
    inventory = hosting.inventory()
    files = {}
    for name, size in inventory.items():
        content = hosting.read(name, size)
        source_hash = hashlib.sha256(content).hexdigest()
        target = destination / name
        if target.is_symlink():
            raise ValueError('Media target must not be a symlink')
        if not target.exists():
            # Create without replacing a newer upload if the app writes concurrently.
            temporary = None
            try:
                with tempfile.NamedTemporaryFile(dir=destination, delete=False) as output:
                    temporary = Path(output.name)
                    output.write(content)
                    output.flush()
                    os.fsync(output.fileno())
                temporary.chmod(0o644)
                try:
                    os.link(temporary, target)
                except FileExistsError:
                    pass
            finally:
                if temporary:
                    temporary.unlink(missing_ok=True)
        actual = digest(target)
        if actual != source_hash and not name.startswith('category-'):
            raise ValueError('Conflicting photo file; no existing file overwritten')
        # Category generation already moved to the VPS: its existing artwork wins.
        files[name] = dict(sha256=actual, source_sha256=source_hash, source_size=size,
                           resolution='kept-vps-category' if actual != source_hash else 'copied-or-matched')
    for target in destination.iterdir():
        if target.is_symlink():
            raise ValueError('Unexpected symlink in media directory')
        if target.is_file() and NAME.fullmatch(target.name) and target.name not in files:
            files[target.name] = dict(sha256=digest(target), source_sha256=None)
    if hosting.inventory() != inventory:
        raise ValueError('Source inventory changed; verify before cutover')
    # Re-read source bytes, catching same-size changes during transfer.
    for name, size in inventory.items():
        if hashlib.sha256(hosting.read(name, size)).hexdigest() != files[name]['source_sha256']:
            raise ValueError('Source image changed during transfer')
    return dict(files=files, references_updated=False)


def rewrite_references(manifest, vps_source):
    helper = vps_source / 'src/MediaMigration.php'
    subprocess.run(['sudo', '-n', 'docker', 'cp', str(helper), 'ctr-backend:/tmp/ctr-media-migration.php'], check=True, capture_output=True)
    try:
        code = 'require "/app/bootstrap.php"; require "/tmp/ctr-media-migration.php"; echo json_encode(migrateRecipeMedia(runtimeDatabase(),json_decode(stream_get_contents(STDIN),true,32,JSON_THROW_ON_ERROR),CTR_STORAGE."/media/recipes"));'
        result = subprocess.run(['sudo', '-n', 'docker', 'exec', '-i', 'ctr-backend', 'php', '-r', code],
                                input=json.dumps(manifest).encode(), capture_output=True)
        if result.returncode:
            raise RuntimeError('Media reference migration failed; private details omitted')
        return json.loads(result.stdout)
    finally:
        subprocess.run(['sudo', '-n', 'docker', 'exec', 'ctr-backend', 'rm', '-f', '/tmp/ctr-media-migration.php'], check=True, capture_output=True)


def retire(hosting, destination, manifest):
    if manifest.get('references_updated') is not True:
        raise ValueError('References have not been verified on the VPS')
    current = hosting.inventory()
    expected = {name for name, row in manifest['files'].items() if row.get('source_sha256')}
    if not set(current) <= expected:
        raise ValueError('New source images appeared after migration; no retirement performed')
    # Validate the whole retirement set before trashing any source image.
    for name, size in current.items():
        row = manifest['files'][name]
        if (destination / name).is_symlink() or digest(destination / name) != row['sha256']:
            raise ValueError('VPS replacement changed or disappeared; no retirement performed')
        if hashlib.sha256(hosting.read(name, size)).hexdigest() != row['source_sha256']:
            raise ValueError('Source image changed; no retirement performed')
    for name in current:
        hosting.trash(name)
    if hosting.inventory():
        raise ValueError('Source retirement could not be confirmed')
    return len(current)


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--config', type=Path, default=Path('/home/debian/.config/coffey-table-recipes/cpanel.json'))
    parser.add_argument('--destination', type=Path, default=Path('/opt/services/ctr-backend/state/media/recipes'))
    parser.add_argument('--manifest', type=Path, default=Path('/opt/services/ctr-backend/private/media-migration.json'))
    parser.add_argument('--vps-source', type=Path)
    mode = parser.add_mutually_exclusive_group()
    mode.add_argument('--apply', action='store_true')
    mode.add_argument('--retire-source', action='store_true')
    args = parser.parse_args()
    hosting = Hosting(args.config)
    if args.retire_source:
        print(json.dumps(dict(retired=retire(hosting, args.destination, json.loads(args.manifest.read_text())))))
    elif args.apply:
        if args.vps_source is None:
            parser.error('--apply requires the reviewed --vps-source checkout')
        manifest = transfer(hosting, args.destination)
        manifest['database'] = rewrite_references(manifest, args.vps_source)
        manifest['references_updated'] = True
        args.manifest.parent.mkdir(parents=True, exist_ok=True)
        with tempfile.NamedTemporaryFile(dir=args.manifest.parent, mode='w', delete=False) as output:
            temporary = Path(output.name)
            json.dump(manifest, output, indent=2)
        temporary.chmod(0o600)
        temporary.replace(args.manifest)
        print(json.dumps(dict(verified_files=len(manifest['files']), **manifest['database'])))
    else:
        rows = hosting.inventory()
        print(json.dumps(dict(files=len(rows), bytes=sum(rows.values()), changed=False)))


if __name__ == '__main__':
    try:
        main()
    except Exception as error:
        print('Media migration failed: ' + (str(error) if isinstance(error, (ValueError, RuntimeError)) else type(error).__name__) + '. Credentials are not logged.')
        raise SystemExit(1)
