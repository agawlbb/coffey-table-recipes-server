"""Disposable byte fixtures only: no hosting access or real recipe database."""
import hashlib
import importlib.util
from pathlib import Path
import tempfile
import unittest
from unittest.mock import patch

spec=importlib.util.spec_from_file_location('move_media',Path(__file__).with_name('move-recipe-media.py'))
media=importlib.util.module_from_spec(spec); spec.loader.exec_module(media)
PHOTO='a'*48+'.jpg'

class HostingFixture:
    def __init__(self, files): self.files=dict(files); self.removed=[]
    def inventory(self): return {k:len(v) for k,v in self.files.items()}
    def read(self,name,size): return self.files[name]
    def trash(self,name): self.removed.append(name); del self.files[name]

class MediaTests(unittest.TestCase):
    def test_inventory_skips_only_empty_category_lock_markers(self):
        hosting=object.__new__(media.Hosting); hosting.directory='/fixture'
        rows=[{'file':'category-8.jpg.lock','type':'file','size':''},
              {'file':PHOTO,'type':'file','size':'10'}]
        with patch.object(hosting,'api',return_value=rows):
            self.assertEqual(hosting.inventory(),{PHOTO:10})
        rows[0]['size']='12'
        with patch.object(hosting,'api',return_value=rows):
            with self.assertRaises(ValueError): hosting.inventory()

    def test_binary_response_round_trip_covers_every_byte(self):
        hosting=object.__new__(media.Hosting); hosting.directory='/fixture'
        original=bytes(range(256))*3
        with patch.object(hosting,'api',return_value={'content':original.decode('latin-1')}) as request:
            self.assertEqual(hosting.read(PHOTO,len(original)),original)
            self.assertEqual(request.call_args.kwargs['from_charset'],'ISO-8859-1')
        with patch.object(hosting,'api',return_value={'content':'wrong'}):
            with self.assertRaises(ValueError): hosting.read(PHOTO,3)

    def test_copy_verify_keep_newer_category_then_explicit_retirement(self):
        hosting=HostingFixture({PHOTO:bytes(range(256)),'category-8.jpg':b'old category'})
        with tempfile.TemporaryDirectory() as directory:
            target=Path(directory); (target/'category-8.jpg').write_bytes(b'new VPS artwork')
            manifest=media.transfer(hosting,target)
            self.assertEqual((target/PHOTO).read_bytes(),bytes(range(256)))
            self.assertEqual((target/'category-8.jpg').read_bytes(),b'new VPS artwork')
            self.assertEqual(manifest['files']['category-8.jpg']['resolution'],'kept-vps-category')
            self.assertFalse(hosting.removed)
            with self.assertRaises(ValueError): media.retire(hosting,target,manifest)
            manifest['references_updated']=True
            self.assertEqual(media.retire(hosting,target,manifest),2)
            self.assertTrue((target/PHOTO).exists())
            self.assertEqual(media.retire(hosting,target,manifest),0)

    def test_conflicting_photo_never_overwrites_or_trashes(self):
        hosting=HostingFixture({PHOTO:b'original'})
        with tempfile.TemporaryDirectory() as directory:
            target=Path(directory); (target/PHOTO).write_bytes(b'existing')
            with self.assertRaisesRegex(ValueError,'Conflicting'): media.transfer(hosting,target)
            self.assertEqual((target/PHOTO).read_bytes(),b'existing')
            self.assertFalse(hosting.removed)

    def test_retirement_checks_whole_set_before_removing_anything(self):
        for change in ('source','target','new-source'):
            with self.subTest(change=change), tempfile.TemporaryDirectory() as directory:
                hosting=HostingFixture({PHOTO:b'original','category-9.jpg':b'category'})
                target=Path(directory); manifest=media.transfer(hosting,target); manifest['references_updated']=True
                if change=='source': hosting.files['category-9.jpg']=b'changed!'
                elif change=='target': (target/'category-9.jpg').write_bytes(b'changed')
                else: hosting.files['category-10.jpg']=b'new'
                with self.assertRaises(ValueError): media.retire(hosting,target,manifest)
                self.assertFalse(hosting.removed)

    def test_source_changes_during_copy_abort_before_reference_update(self):
        hosting=HostingFixture({PHOTO:b'original'})
        with tempfile.TemporaryDirectory() as directory, patch.object(hosting,'read',side_effect=[b'original',b'modified']):
            with self.assertRaisesRegex(ValueError,'changed during transfer'): media.transfer(hosting,Path(directory))

    def test_symlinks_and_unexpected_inventory_are_rejected(self):
        hosting=HostingFixture({PHOTO:b'original'})
        with tempfile.TemporaryDirectory() as directory:
            target=Path(directory); (target/PHOTO).symlink_to(target/'outside')
            with self.assertRaisesRegex(ValueError,'symlink'): media.transfer(hosting,target)
        client=object.__new__(media.Hosting); client.directory='/fixture'
        with patch.object(client,'api',return_value=[{'file':'credentials.json','type':'file','size':20}]):
            with self.assertRaises(ValueError): client.inventory()

if __name__=='__main__': unittest.main()
