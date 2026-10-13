#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""Scan local archive fixtures and extract metadata from config.xml."""

import os
import sys
import zipfile
import csv
import re
import subprocess
import logging
from pathlib import Path
from typing import Optional, List, Dict, Any


logging.basicConfig(
    level=logging.INFO,
    format='%(asctime)s - %(levelname)s - %(message)s'
)
logger = logging.getLogger(__name__)


def setup_rarfile():
    """
    Настраивает библиотеку rarfile для работы с unrar.
    """
    try:
        import rarfile

        unrar_paths = ['unrar', '/usr/bin/unrar', '/usr/local/bin/unrar']
        for path in unrar_paths:
            try:
                subprocess.run([path, '--version'], capture_output=True, check=False)
                rarfile.UNRAR_TOOL = path
                logger.info(f"Установлен путь к unrar: {path}")
                return rarfile
            except Exception:
                continue

        logger.warning("Не найден unrar. RAR-архивы будут пропущены; установите unrar отдельно при необходимости.")
        return None

    except ImportError:
        logger.error("Библиотека rarfile не установлена")
        logger.info("Для RAR-архивов отдельно установите Python-пакет rarfile и утилиту unrar.")
        return None


def read_file_from_zip(zip_path: str) -> Optional[str]:
    """
    Читает config.xml из ZIP архива.
    """
    try:
        with zipfile.ZipFile(zip_path, 'r') as zip_ref:
            xml_files = []

            for file_in_zip in zip_ref.namelist():
                if file_in_zip.lower().endswith('config.xml'):
                    xml_files.append(file_in_zip)

            if not xml_files:
                for file_in_zip in zip_ref.namelist():
                    if 'config.xml' in file_in_zip.lower():
                        xml_files.append(file_in_zip)

            if xml_files:
                with zip_ref.open(xml_files[0]) as f:
                    content = f.read()

                    encodings = ['utf-8', 'cp1251', 'iso-8859-1', 'windows-1251']
                    for encoding in encodings:
                        try:
                            return content.decode(encoding)
                        except UnicodeDecodeError:
                            continue

                    return content.decode('utf-8', errors='ignore')

    except zipfile.BadZipFile:
        logger.error(f"Поврежден ZIP архив: {zip_path}")
    except Exception as e:
        logger.error(f"Ошибка чтения ZIP {zip_path}: {e}")

    return None


def read_file_from_rar(rar_path: str, rarfile_module) -> Optional[str]:
    """
    Читает config.xml из RAR архива.
    """
    if not rarfile_module:
        return None

    try:
        with rarfile_module.RarFile(rar_path, 'r') as rar_ref:
            xml_files = []

            for file_info in rar_ref.infolist():
                filename = file_info.filename.lower()
                if filename.endswith('config.xml') or '/config.xml' in filename:
                    xml_files.append(file_info.filename)

            if not xml_files:
                for file_info in rar_ref.infolist():
                    if 'config.xml' in file_info.filename.lower():
                        xml_files.append(file_info.filename)

            if xml_files:
                with rar_ref.open(xml_files[0]) as f:
                    content = f.read()

                    encodings = ['utf-8', 'cp1251', 'iso-8859-1', 'windows-1251']
                    for encoding in encodings:
                        try:
                            return content.decode(encoding)
                        except UnicodeDecodeError:
                            continue

                    return content.decode('utf-8', errors='ignore')

    except rarfile_module.BadRarFile:
        logger.error(f"Поврежден RAR архив: {rar_path}")
    except rarfile_module.NeedFirstVolume:
        logger.warning(f"Многотомный RAR архив (требуются все части): {rar_path}")
    except Exception as e:
        logger.error(f"Ошибка чтения RAR {rar_path}: {e}")

    return None


def extract_version_from_content(content: str) -> str:
    """
    Извлекает SupplierVersion из содержимого файла.
    """
    if not content:
        return "SupplierVersion отсутствует"

    try:
        patterns = [
            r'<SupplierVersion[^>]*>(.*?)</SupplierVersion>',
            r'<SupplierVersion>(.*?)</SupplierVersion>',
            r'<SupplierVersion\s*>\s*(.*?)\s*</SupplierVersion>',
            r'SupplierVersion[=>]\s*["\']?(.*?)["\']?\s*[<\n]',
        ]

        for pattern in patterns:
            matches = re.findall(pattern, content, re.IGNORECASE | re.DOTALL)
            if matches:
                version = matches[0].strip()
                version = re.sub(r'\s+', ' ', version)
                if version:
                    logger.debug(f"Найдена версия: {version}")
                    return version

        lines = content.split('\n')
        for i, line in enumerate(lines):
            if '<SupplierVersion' in line or '</SupplierVersion>' in line:
                search_text = line
                if i + 1 < len(lines):
                    search_text += lines[i + 1]

                start = search_text.find('>', search_text.find('<SupplierVersion'))
                if start != -1:
                    end = search_text.find('</SupplierVersion>', start)
                    if end != -1:
                        version = search_text[start + 1:end].strip()
                        if version:
                            version = re.sub(r'\s+', ' ', version)
                            return version

        try:
            import xml.etree.ElementTree as ET

            if not content.strip().startswith('<?xml'):
                xml_content = '<?xml version="1.0" encoding="UTF-8"?>\n' + content
            else:
                xml_content = content

            root = ET.fromstring(xml_content)

            def find_supplier_version(elem):
                if 'SupplierVersion' in elem.tag:
                    if elem.text and elem.text.strip():
                        return elem.text.strip()

                for child in elem:
                    result = find_supplier_version(child)
                    if result:
                        return result

                return None

            version = find_supplier_version(root)
            if version:
                return version

        except Exception:
            pass

    except Exception as e:
        logger.error(f"Ошибка при извлечении версии: {e}")

    return "SupplierVersion отсутствует"


def extract_supplier_from_content(content: str) -> str:
    """
    Извлекает Supplier из содержимого файла.
    Ищет тег <Supplier> или аналогичные: <Publisher>, <Vendor>, <Manufacturer>.
    """
    if not content:
        return "Supplier отсутствует"

    supplier_tags = ['Supplier', 'Publisher', 'Vendor', 'Manufacturer']

    try:
        for tag in supplier_tags:
            patterns = [
                rf'<{tag}[^>]*>(.*?)</{tag}>',
                rf'<{tag}>(.*?)</{tag}>',
                rf'<{tag}\s*>\s*(.*?)\s*</{tag}>',
                rf'{tag}[=>]\s*["\']?(.*?)["\']?\s*[<\n]',
            ]

            for pattern in patterns:
                matches = re.findall(pattern, content, re.IGNORECASE | re.DOTALL)
                if matches:
                    supplier = matches[0].strip()
                    supplier = re.sub(r'\s+', ' ', supplier)
                    if supplier:
                        logger.debug(f"Найден поставщик ({tag}): {supplier}")
                        return supplier

        lines = content.split('\n')
        for tag in supplier_tags:
            for i, line in enumerate(lines):
                if f'<{tag}' in line or f'</{tag}>' in line:
                    search_text = line
                    if i + 1 < len(lines):
                        search_text += lines[i + 1]

                    start = search_text.find('>', search_text.find(f'<{tag}'))
                    if start != -1:
                        end = search_text.find(f'</{tag}>', start)
                        if end != -1:
                            supplier = search_text[start + 1:end].strip()
                            if supplier:
                                supplier = re.sub(r'\s+', ' ', supplier)
                                return supplier

        try:
            import xml.etree.ElementTree as ET

            if not content.strip().startswith('<?xml'):
                xml_content = '<?xml version="1.0" encoding="UTF-8"?>\n' + content
            else:
                xml_content = content

            root = ET.fromstring(xml_content)

            def find_supplier(elem):
                for tag in supplier_tags:
                    if tag in elem.tag or (elem.tag.endswith(tag) and elem.text and elem.text.strip()):
                        return elem.text.strip()

                for attr_name, attr_value in elem.attrib.items():
                    for tag in supplier_tags:
                        if tag.lower() in attr_name.lower():
                            return attr_value

                for child in elem:
                    result = find_supplier(child)
                    if result:
                        return result
                return None

            supplier = find_supplier(root)
            if supplier:
                return supplier

        except Exception:
            pass

    except Exception as e:
        logger.error(f"Ошибка при извлечении поставщика: {e}")

    return "Supplier отсутствует"


def scan_directory(root_dir: str) -> List[Dict[str, Any]]:
    """
    Сканирует директорию на наличие архивов.
    Возвращает список словарей с путем, именем файла, версией и поставщиком.
    """
    results = []
    rarfile_module = setup_rarfile()
    archive_extensions = {'.zip', '.rar', '.7z'}

    try:
        for archive_path in Path(root_dir).rglob('*'):
            if archive_path.is_file():
                file_ext = archive_path.suffix.lower()

                if file_ext in archive_extensions:
                    logger.info(f"Обработка: {archive_path.name}")

                    content = None

                    if file_ext == '.zip':
                        content = read_file_from_zip(str(archive_path))

                    elif file_ext == '.rar':
                        content = read_file_from_rar(str(archive_path), rarfile_module)

                    elif file_ext == '.7z':
                        try:
                            import py7zr
                            with py7zr.SevenZipFile(str(archive_path), 'r') as z:
                                for filename in z.getnames():
                                    if 'config.xml' in filename.lower():
                                        extracted = z.read([filename])
                                        content = extracted[filename].read().decode('utf-8', errors='ignore')
                                        break
                        except ImportError:
                            logger.warning("Библиотека py7zr не установлена, пропускаем .7z архивы")
                        except Exception as e:
                            logger.error(f"Ошибка чтения 7z {archive_path}: {e}")

                    if content:
                        version = extract_version_from_content(content)
                        supplier = extract_supplier_from_content(content)
                    else:
                        version = "config.xml не найден"
                        supplier = "config.xml не найден"

                    results.append({
                        'full_path': str(archive_path.absolute()),
                        'directory': str(archive_path.parent.absolute()),
                        'filename': archive_path.name,
                        'version': version,
                        'supplier': supplier
                    })

    except Exception as e:
        logger.error(f"Ошибка при сканировании: {e}")

    return results


def save_results(results: List[Dict[str, Any]], output_path: str):
    """
    Сохраняет результаты в CSV.
    """
    if not results:
        logger.warning("Нет результатов для сохранения")
        return False

    try:
        Path(output_path).parent.mkdir(parents=True, exist_ok=True)
        with open(output_path, 'w', newline='', encoding='utf-8') as csvfile:
            fieldnames = ['full_path', 'directory', 'filename', 'version', 'supplier']
            writer = csv.DictWriter(csvfile, fieldnames=fieldnames)
            writer.writeheader()
            writer.writerows(results)

        logger.info(f"Сохранено {len(results)} записей в {output_path}")
        return True

    except Exception as e:
        logger.error(f"Ошибка сохранения CSV: {e}")
        return False


def print_statistics(results: List[Dict[str, Any]]):
    """
    Выводит статистику, включая информацию о поставщиках.
    """
    if not results:
        print("Нет результатов")
        return

    print("\n" + "=" * 60)
    print("СТАТИСТИКА")
    print("=" * 60)

    total = len(results)
    version_stats = {
        'с версией': 0,
        'без версии': 0,
        'без config.xml': 0,
        'с ошибкой': 0
    }
    supplier_stats = {
        'с поставщиком': 0,
        'без поставщика': 0,
        'без config.xml': 0
    }
    extensions = {}
    unique_versions = set()
    unique_suppliers = set()

    for result in results:
        version = result['version']
        supplier = result['supplier']

        if 'не найден' in version:
            version_stats['без config.xml'] += 1
        elif 'отсутствует' in version:
            version_stats['без версии'] += 1
        elif 'Ошибка' in version:
            version_stats['с ошибкой'] += 1
        else:
            version_stats['с версией'] += 1
            unique_versions.add(version)

        if 'не найден' in supplier:
            supplier_stats['без config.xml'] += 1
        elif 'отсутствует' in supplier:
            supplier_stats['без поставщика'] += 1
        else:
            supplier_stats['с поставщиком'] += 1
            unique_suppliers.add(supplier)

        ext = os.path.splitext(result['filename'])[1].lower()
        extensions[ext] = extensions.get(ext, 0) + 1

    print(f"Всего архивов: {total}")

    print("\nСтатистика по версиям:")
    for category, count in version_stats.items():
        if count > 0:
            print(f"  {category}: {count} ({count / total * 100:.1f}%)")

    print("\nСтатистика по поставщикам:")
    for category, count in supplier_stats.items():
        if count > 0:
            print(f"  {category}: {count} ({count / total * 100:.1f}%)")

    print("\nПо форматам:")
    for ext, count in sorted(extensions.items()):
        print(f"  {ext}: {count}")

    if unique_versions:
        print(f"\nНайдено уникальных версий: {len(unique_versions)}")
        for i, version in enumerate(sorted(unique_versions)[:10]):
            print(f"  {i + 1}. {version}")
        if len(unique_versions) > 10:
            print(f"  ... и еще {len(unique_versions) - 10}")

    if unique_suppliers:
        print(f"\nНайдено уникальных поставщиков: {len(unique_suppliers)}")
        for i, supplier in enumerate(sorted(unique_suppliers)[:10]):
            print(f"  {i + 1}. {supplier}")
        if len(unique_suppliers) > 10:
            print(f"  ... и еще {len(unique_suppliers) - 10}")


def main():
    """
    Основная функция.
    """
    scan_dir = os.environ.get("LIBRARY_SCAN_DIR", "./sample-library/")
    output_file = os.environ.get("LIBRARY_SCAN_OUTPUT", "./output/archive_scan_results.csv")

    print("СКРИПТ СКАНИРОВАНИЯ АРХИВОВ")
    print(f"Директория: {scan_dir}")
    print("-" * 50)

    if not os.path.exists(scan_dir):
        print(f"Ошибка: Директория {scan_dir} не существует!")
        sys.exit(1)

    results = scan_directory(scan_dir)

    if results:
        if save_results(results, output_file):
            print_statistics(results)

    else:
        print("Архивы не найдены или произошла ошибка при сканировании")


if __name__ == "__main__":
    try:
        main()
    except KeyboardInterrupt:
        print("\nПрервано пользователем")
        sys.exit(0)
    except Exception as e:
        logger.error(f"Критическая ошибка: {e}")
        sys.exit(1)
