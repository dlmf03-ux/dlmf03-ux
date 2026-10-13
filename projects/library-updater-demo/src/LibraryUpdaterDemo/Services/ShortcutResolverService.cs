using System.IO;
using System.Runtime.InteropServices;

namespace LibraryUpdaterDemo.Services;

/// <summary>
/// Резолвер .lnk-ярлыков Windows. Использует WScript.Shell через late-binding COM,
/// что не требует tlbimp-обёртки и компилируется как dotnet CLI, так и в VS.
/// </summary>
public class ShortcutResolverService
{
    private readonly LogService _log;
    public ShortcutResolverService(LogService log) => _log = log;

    public string? Resolve(string lnkPath)
    {
        dynamic? shell = null;
        dynamic? sc = null;
        try
        {
            var shellType = Type.GetTypeFromProgID("WScript.Shell")
                ?? throw new InvalidOperationException("WScript.Shell COM-объект недоступен");
            shell = Activator.CreateInstance(shellType)!;
            sc = shell.CreateShortcut(lnkPath);

            string target = sc.TargetPath;
            string workingDir = sc.WorkingDirectory;
            if (string.IsNullOrEmpty(target) && !string.IsNullOrEmpty(workingDir))
                target = workingDir;

            if (!string.IsNullOrEmpty(target) &&
                (System.IO.File.Exists(target) || Directory.Exists(target)))
                return target;

            _log.Log($"Не удалось определить целевой путь из ярлыка: {lnkPath}");
            return null;
        }
        catch (Exception ex)
        {
            _log.Log($"Ошибка чтения ярлыка: {ex.Message}");
            return null;
        }
        finally
        {
            if (sc != null) Marshal.FinalReleaseComObject(sc);
            if (shell != null) Marshal.FinalReleaseComObject(shell);
        }
    }

    public string? FindExocadRoot(string lnkPath)
    {
        var target = Resolve(lnkPath);
        if (target == null) return null;

        var parts = target.Split(Path.DirectorySeparatorChar, Path.AltDirectorySeparatorChar);
        var idx = Array.FindIndex(parts, p => string.Equals(p, "DentalCADApp", StringComparison.OrdinalIgnoreCase));
        if (idx > 0)
        {
            var root = string.Join(Path.DirectorySeparatorChar, parts.Take(idx));
            if (Directory.Exists(Path.Combine(root, "DentalCADApp")))
                return root;
        }

        var current = System.IO.File.Exists(target) ? Path.GetDirectoryName(target) : target;
        for (var i = 0; i < 10 && current != null; i++)
        {
            if (Directory.Exists(Path.Combine(current, "DentalCADApp")))
                return current;
            var parent = Path.GetDirectoryName(current);
            if (parent == current) break;
            current = parent;
        }

        current = System.IO.File.Exists(target) ? Path.GetDirectoryName(target) : target;
        for (var i = 0; i < 10 && current != null; i++)
        {
            var name = new DirectoryInfo(current).Name;
            if (name.StartsWith("exocad-DentalCAD", StringComparison.OrdinalIgnoreCase))
                return current;
            var parent = Path.GetDirectoryName(current);
            if (parent == current) break;
            current = parent;
        }

        _log.Log($"Не удалось определить корневую папку exocad из пути: {target}");
        return null;
    }
}
