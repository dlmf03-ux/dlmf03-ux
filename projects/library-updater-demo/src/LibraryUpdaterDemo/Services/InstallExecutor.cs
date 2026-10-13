using System.IO;
using LibraryUpdaterDemo.Models;

namespace LibraryUpdaterDemo.Services;

/// <summary>
/// Бизнес-логика установки и обновления одной библиотеки.
/// Не зависит от UI/VM — только от сервисов и моделей.
/// </summary>
public class InstallExecutor
{
    private readonly LogService _log;
    private readonly DownloadService _download;
    private readonly ArchiveExtractorService _extractor;
    private readonly TelemetryService _telemetry;

    public InstallExecutor(
        LogService log,
        DownloadService download,
        ArchiveExtractorService extractor,
        TelemetryService telemetry)
    {
        _log = log;
        _download = download;
        _extractor = extractor;
        _telemetry = telemetry;
    }

    public string TempDir { get; } =
        Path.Combine(Path.GetTempPath(), "exocad_updater");

    /// <summary>
    /// Установить библиотеку с нуля (или переустановить). Возвращает true при успехе.
    /// </summary>
    public async Task<bool> InstallAsync(LibraryRecord lib, string libraryRoot, CancellationToken ct = default)
    {
        Directory.CreateDirectory(TempDir);

        if (string.IsNullOrEmpty(lib.DownloadUrl))
        {
            _log.Log("✗ Нет ссылки для скачивания");
            return false;
        }

        _log.Log($"\n=== УСТАНОВКА БИБЛИОТЕКИ: {lib.Name} ===");

        var libPath = Path.Combine(libraryRoot, lib.Name);
        // Уникальное имя временного файла исключает конфликт двух параллельных
        // попыток установки одной и той же библиотеки.
        var rarPath = Path.Combine(TempDir, $"{lib.Name}_new_{Guid.NewGuid():N}.rar");

        SafeDelete(rarPath);

        _log.Log("Загрузка библиотеки из источника...");
        if (!await _download.DownloadFileAsync(lib.DownloadUrl, rarPath, ct: ct))
        {
            _log.Log("✗ Не удалось загрузить файл");
            return false;
        }
        _ = _telemetry.TrackLibraryDownloadAsync(lib, "install", GetFileSize(rarPath));

        // удалить старую версию, если была
        if (Directory.Exists(libPath))
        {
            try { Directory.Delete(libPath, true); }
            catch (Exception ex) { _log.Log($"⚠ Не удалось удалить старую версию: {ex.Message}"); }
        }

        Directory.CreateDirectory(libPath);

        if (!await _extractor.ExtractRarToFolderAsync(rarPath, libPath, lib.Name, TempDir))
        {
            _log.Log($"✗ Ошибка распаковки");
            _ = _telemetry.TrackLibraryOperationAsync(lib, "install", false, "Ошибка распаковки");
            SafeDelete(rarPath);
            return false;
        }

        _log.Log($"✓ Библиотека {lib.Name} успешно установлена");

        lib.FullPath = libPath;
        lib.Version = lib.AvailableVersion;
        lib.RelativePath = lib.Name;
        lib.Installed = true;
        _ = _telemetry.TrackLibraryOperationAsync(lib, "install", true);

        SafeDelete(rarPath);
        return true;
    }

    /// <summary>Обновить установленную библиотеку с предварительным бэкапом.</summary>
    public async Task<bool> UpdateAsync(LibraryRecord lib, string libraryRoot, CancellationToken ct = default)
    {
        Directory.CreateDirectory(TempDir);

        if (lib.FullPath == null || !Directory.Exists(lib.FullPath))
        {
            _log.Log("✗ Путь к библиотеке не существует");
            return false;
        }

        _log.Log($"\n=== ОБНОВЛЕНИЕ БИБЛИОТЕКИ: {lib.Name} ===");

        var backupDir = Path.Combine(libraryRoot, "backup");
        Directory.CreateDirectory(backupDir);
        var stamp = DateTime.Now.ToString("yyyyMMdd_HHmmss");
        var backupPath = Path.Combine(backupDir, $"{lib.Name}_backup_{stamp}");

        try
        {
            CopyDirectory(lib.FullPath, backupPath);
            _log.Log($"✓ Бэкап создан: {backupPath}");
            lib.BackupCreated = true;
        }
        catch (Exception ex)
        {
            _log.Log($"✗ Ошибка создания бэкапа: {ex.Message}");
            return false;
        }

        if (string.IsNullOrEmpty(lib.DownloadUrl))
        {
            _log.Log("✗ Нет ссылки для скачивания");
            return false;
        }

        var rarPath = Path.Combine(TempDir, $"{lib.Name}_new_{Guid.NewGuid():N}.rar");
        SafeDelete(rarPath);

        _log.Log("Загрузка библиотеки из источника...");
        try
        {
            if (!await _download.DownloadFileAsync(lib.DownloadUrl, rarPath, ct: ct))
                throw new IOException("Не удалось загрузить файл");
            _ = _telemetry.TrackLibraryDownloadAsync(lib, "update", GetFileSize(rarPath));

            try { Directory.Delete(lib.FullPath, true); }
            catch { }
            Directory.CreateDirectory(lib.FullPath);

            _log.Log("Распаковка новой версии...");
            if (!await _extractor.ExtractRarToFolderAsync(rarPath, lib.FullPath, lib.Name, TempDir))
                throw new IOException("Ошибка распаковки RAR");

            _log.Log($"✓ Библиотека {lib.Name} успешно обновлена");
            lib.Version = lib.AvailableVersion;
            _ = _telemetry.TrackLibraryOperationAsync(lib, "update", true);
            return true;
        }
        catch (Exception ex)
        {
            _log.Log($"✗ Ошибка при обновлении: {ex.Message}");
            _ = _telemetry.TrackLibraryOperationAsync(lib, "update", false, ex.Message);
            RestoreFromBackup(backupPath, lib.FullPath);
            return false;
        }
        finally
        {
            SafeDelete(rarPath);
        }
    }

    private void RestoreFromBackup(string backupPath, string libPath)
    {
        try
        {
            if (Directory.Exists(backupPath))
            {
                _log.Log($"Восстановление из бэкапа: {backupPath}");
                if (Directory.Exists(libPath)) Directory.Delete(libPath, true);
                CopyDirectory(backupPath, libPath);
                _log.Log("✓ Восстановлено из бэкапа");
            }
            else
            {
                _log.Log("✗ Бэкап не найден для восстановления");
            }
        }
        catch (Exception ex)
        {
            _log.Log($"✗ Ошибка восстановления из бэкапа: {ex.Message}");
        }
    }

    private static void CopyDirectory(string src, string dst)
    {
        Directory.CreateDirectory(dst);
        foreach (var file in Directory.GetFiles(src))
            File.Copy(file, Path.Combine(dst, Path.GetFileName(file)), true);
        foreach (var dir in Directory.GetDirectories(src))
            CopyDirectory(dir, Path.Combine(dst, Path.GetFileName(dir)));
    }

    private static void SafeDelete(string path)
    {
        try { if (File.Exists(path)) File.Delete(path); }
        catch { }
    }

    private static long? GetFileSize(string path)
    {
        try { return File.Exists(path) ? new FileInfo(path).Length : null; }
        catch { return null; }
    }

    public void CleanupTempDir()
    {
        try { if (Directory.Exists(TempDir)) Directory.Delete(TempDir, true); }
        catch { }
    }
}
