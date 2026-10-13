import sys
import tempfile
import unittest
import zipfile
from pathlib import Path

sys.path.insert(0, str(Path(__file__).resolve().parents[1]))
from scan_archives import read_metadata, scan


class ArchiveScannerTests(unittest.TestCase):
    def test_reads_config_metadata(self):
        with tempfile.TemporaryDirectory() as tmp:
            archive = Path(tmp) / "sample.zip"
            with zipfile.ZipFile(archive, "w") as bundle:
                bundle.writestr("config.xml", "<Root><SupplierVersion>2.1</SupplierVersion><Supplier>Example</Supplier></Root>")
            self.assertEqual(read_metadata(archive), ("2.1", "Example"))

    def test_missing_config_is_reported(self):
        with tempfile.TemporaryDirectory() as tmp:
            archive = Path(tmp) / "sample.zip"
            with zipfile.ZipFile(archive, "w") as bundle:
                bundle.writestr("readme.txt", "demo")
            self.assertEqual(read_metadata(archive), ("config.xml not found", ""))

    def test_scan_writes_inventory_rows(self):
        with tempfile.TemporaryDirectory() as tmp:
            root = Path(tmp)
            (root / "nested").mkdir()
            with zipfile.ZipFile(root / "nested" / "a.zip", "w") as bundle:
                bundle.writestr("config.xml", "<Root><SupplierVersion>1</SupplierVersion></Root>")
            rows = scan(root)
            self.assertEqual(len(rows), 1)
            self.assertEqual(rows[0]["filename"], "a.zip")
            self.assertEqual(rows[0]["directory"], "nested")


if __name__ == "__main__":
    unittest.main()
