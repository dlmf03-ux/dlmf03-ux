using System.IO;
using System.Xml.Linq;
using LibraryUpdaterDemo.Models;

namespace LibraryUpdaterDemo.Services;

public class ScannedLibrary
{
    public string Name { get; set; } = "";
    public string FullPath { get; set; } = "";
    public string RelativePath { get; set; } = "";
    public string Version { get; set; } = "01.01.1900 (версия не найдена)";
    public string Supplier { get; set; } = "Неизвестно";
}

public class LibraryScannerService
{
    private readonly LogService _log;
    public LibraryScannerService(LogService log) => _log = log;

    public List<ScannedLibrary> Scan(string libraryRoot)
    {
        var result = new List<ScannedLibrary>();
        if (string.IsNullOrEmpty(libraryRoot) || !Directory.Exists(libraryRoot))
            return result;

        DirectoryInfo[] dirs;
        try { dirs = new DirectoryInfo(libraryRoot).GetDirectories(); }
        catch { return result; }

        foreach (var dir in dirs)
        {
            if (dir.Name.EndsWith("backup", StringComparison.OrdinalIgnoreCase)) continue;

            var configPath = Path.Combine(dir.FullName, "config.xml");
            if (File.Exists(configPath))
            {
                if (TryReadConfig(configPath, out var version, out var supplier))
                {
                    result.Add(new ScannedLibrary
                    {
                        Name = dir.Name,
                        FullPath = dir.FullName,
                        RelativePath = dir.Name,
                        Version = version,
                        Supplier = supplier
                    });
                }
            }
        }

        return result;
    }

    private static bool TryReadConfig(string path, out string version, out string supplier)
    {
        version = "01.01.1900 (версия не найдена)";
        supplier = "Неизвестно";
        try
        {
            var doc = XDocument.Load(path);
            var v = doc.Descendants("SupplierVersion").FirstOrDefault();
            var s = doc.Descendants("Supplier").FirstOrDefault();
            if (v != null && !string.IsNullOrWhiteSpace(v.Value)) version = v.Value;
            if (s != null && !string.IsNullOrWhiteSpace(s.Value)) supplier = s.Value;
            return true;
        }
        catch
        {
            return false;
        }
    }
}
