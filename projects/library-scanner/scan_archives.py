"""Build a CSV inventory of archive metadata in a local directory.

The example scans ZIP archives and reads an optional config.xml member.
Other archive formats can be added with dedicated libraries if required.
"""

from __future__ import annotations

import argparse
import csv
import zipfile
from pathlib import Path
from xml.etree import ElementTree


FIELDS = ("full_path", "directory", "filename", "version", "supplier")


def read_metadata(archive: Path) -> tuple[str, str]:
    try:
        with zipfile.ZipFile(archive) as bundle:
            member = next(
                (name for name in bundle.namelist() if Path(name).name.lower() == "config.xml"),
                None,
            )
            if member is None:
                return "config.xml not found", ""
            root = ElementTree.fromstring(bundle.read(member))
    except (OSError, zipfile.BadZipFile, ElementTree.ParseError):
        return "unreadable archive", ""

    values = {node.tag.rsplit("}", 1)[-1].lower(): (node.text or "").strip() for node in root.iter()}
    version = values.get("supplierversion", "")
    supplier = next(
        (values[key] for key in ("supplier", "publisher", "vendor", "manufacturer") if values.get(key)),
        "",
    )
    return version, supplier


def scan(root: Path) -> list[dict[str, str]]:
    rows = []
    for archive in sorted(root.rglob("*.zip")):
        version, supplier = read_metadata(archive)
        rows.append(
            {
                "full_path": str(archive.resolve()),
                "directory": str(archive.parent.relative_to(root)),
                "filename": archive.name,
                "version": version,
                "supplier": supplier,
            }
        )
    return rows


def main() -> None:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("root", type=Path, help="Directory to scan")
    parser.add_argument("output", type=Path, help="Destination CSV file")
    args = parser.parse_args()
    rows = scan(args.root.resolve())
    args.output.parent.mkdir(parents=True, exist_ok=True)
    with args.output.open("w", newline="", encoding="utf-8-sig") as stream:
        writer = csv.DictWriter(stream, fieldnames=FIELDS)
        writer.writeheader()
        writer.writerows(rows)
    print(f"Processed {len(rows)} archives; wrote {args.output}")


if __name__ == "__main__":
    main()
