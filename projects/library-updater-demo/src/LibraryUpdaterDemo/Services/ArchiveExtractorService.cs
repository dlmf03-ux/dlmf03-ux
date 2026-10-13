using System.Diagnostics;
using System.IO;
using SharpCompress.Archives;
using SharpCompress.Archives.Rar;
using SharpCompress.Common;

namespace LibraryUpdaterDemo.Services;

public class ArchiveExtractorService
{
    private readonly LogService _log;
    public ArchiveExtractorService(LogService log) => _log = log;

    /// <summary>
    /// Распаковывает RAR в targetFolder, копируя содержимое внутренней папки библиотеки
    /// (если архив содержит обёрточную директорию с таким же именем).
    /// </summary>
    public async Task<bool> ExtractRarToFolderAsync(string rarPath, string targetFolder, string libName, string tempDir)
    {
        var tempExtract = Path.Combine(tempDir, $"temp_{libName}");
        try
        {
            if (Directory.Exists(tempExtract)) Directory.Delete(tempExtract, true);
            Directory.CreateDirectory(tempExtract);

            if (!await ExtractRarAsync(rarPath, tempExtract))
                return false;

            var extractedLibFolder = FindLibraryFolder(tempExtract, libName);
            if (extractedLibFolder == null)
            {
                _log.Log("✗ Не найдена папка с библиотекой в архиве");
                return false;
            }

            _log.Log($"Копирование содержимого из {Path.GetFileName(extractedLibFolder)}");
            CopyContents(extractedLibFolder, targetFolder);
            return true;
        }
        catch (Exception ex)
        {
            _log.Log($"✗ Ошибка при распаковке в папку: {ex.Message}");
            return false;
        }
        finally
        {
            try { if (Directory.Exists(tempExtract)) Directory.Delete(tempExtract, true); }
            catch { }
        }
    }

    private async Task<bool> ExtractRarAsync(string rarPath, string extractTo)
    {
        // 1. SharpCompress (managed)
        try
        {
            await Task.Run(() =>
            {
                using var archive = RarArchive.Open(rarPath);
                foreach (var entry in archive.Entries.Where(e => !e.IsDirectory))
                {
                    entry.WriteToDirectory(extractTo, new ExtractionOptions
                    {
                        ExtractFullPath = true,
                        Overwrite = true
                    });
                }
            });
            _log.Log("✓ Распаковка SharpCompress успешна");
            return true;
        }
        catch (Exception ex)
        {
            _log.Log($"SharpCompress не справился: {ex.Message}, пробуем системные распаковщики...");
        }

        // 2. WinRAR
        var winrarPaths = new[]
        {
            @"C:\Program Files\WinRAR\WinRAR.exe",
            @"C:\Program Files (x86)\WinRAR\WinRAR.exe"
        };
        foreach (var winrar in winrarPaths)
        {
            if (File.Exists(winrar) && await RunProcessAsync(winrar, $"x -y \"{rarPath}\" \"{extractTo}\""))
            {
                _log.Log("✓ Распаковка WinRAR успешна");
                return true;
            }
        }

        // 3. 7-Zip
        var sevenPaths = new[]
        {
            @"C:\Program Files\7-Zip\7z.exe",
            @"C:\Program Files (x86)\7-Zip\7z.exe"
        };
        foreach (var sz in sevenPaths)
        {
            if (File.Exists(sz) && await RunProcessAsync(sz, $"x -y \"{rarPath}\" -o\"{extractTo}\""))
            {
                _log.Log("✓ Распаковка 7-Zip успешна");
                return true;
            }
        }

        return false;
    }

    private static async Task<bool> RunProcessAsync(string exe, string args)
    {
        try
        {
            using var p = new Process
            {
                StartInfo = new ProcessStartInfo
                {
                    FileName = exe,
                    Arguments = args,
                    UseShellExecute = false,
                    CreateNoWindow = true,
                    RedirectStandardOutput = true,
                    RedirectStandardError = true
                }
            };
            p.Start();
            await p.WaitForExitAsync();
            return p.ExitCode == 0;
        }
        catch
        {
            return false;
        }
    }

    private static string? FindLibraryFolder(string extractDir, string libName)
    {
        var exact = Path.Combine(extractDir, libName);
        if (Directory.Exists(exact)) return exact;

        foreach (var sub in Directory.GetDirectories(extractDir))
        {
            if (File.Exists(Path.Combine(sub, "config.xml")))
                return sub;
        }
        return extractDir;
    }

    private static void CopyContents(string source, string target)
    {
        Directory.CreateDirectory(target);
        foreach (var dir in Directory.GetDirectories(source))
        {
            var name = Path.GetFileName(dir);
            var dst = Path.Combine(target, name);
            if (Directory.Exists(dst)) Directory.Delete(dst, true);
            CopyDirectory(dir, dst);
        }
        foreach (var file in Directory.GetFiles(source))
        {
            var name = Path.GetFileName(file);
            File.Copy(file, Path.Combine(target, name), true);
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
}
