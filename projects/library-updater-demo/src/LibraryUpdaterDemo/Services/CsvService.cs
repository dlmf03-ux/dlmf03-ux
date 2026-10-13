using System.IO;
using System.Net.Http;
using System.Net.Http.Headers;
using System.Text;
using LibraryUpdaterDemo.Models;

namespace LibraryUpdaterDemo.Services;

public class CsvService
{
    private readonly LogService _log;

    public Dictionary<string, CsvLibraryEntry> ImplantData { get; } = new();
    public Dictionary<string, CsvLibraryEntry> ModelData { get; } = new();
    public HashSet<string> ImplantSuppliers { get; } = new();
    public HashSet<string> ModelSuppliers { get; } = new();

    private static readonly string[] ImplantPathPrefixes =
    {
        "/catalog/exocad/fixtures",
        "/catalog/exocad/bases",
        "/catalog/exocad/system-a",
        "/catalog/exocad/system-b"
    };

    private const string ModelPathPrefix = "/catalog/exocad/analogs";

    public CsvService(LogService log) => _log = log;

    public async Task DownloadAsync(string url, CancellationToken ct = default)
    {
        _log.Log("Загрузка данных из источника...");

        using var client = new HttpClient
        {
            Timeout = TimeSpan.FromSeconds(30)
        };
        client.DefaultRequestHeaders.UserAgent.ParseAdd(
            "Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36");

        using var response = await client.GetAsync(url, ct);
        response.EnsureSuccessStatusCode();

        var bytes = await response.Content.ReadAsByteArrayAsync(ct);
        var content = StripBom(Encoding.UTF8.GetString(bytes));
        ParseCsv(content);

        _log.Log($"✓ Данные загружены: implant={ImplantData.Count}, model={ModelData.Count}");
    }

    private static string StripBom(string s) =>
        (s.Length > 0 && s[0] == '\uFEFF') ? s[1..] : s;

    private void ParseCsv(string content)
    {
        ImplantData.Clear();
        ModelData.Clear();
        ImplantSuppliers.Clear();
        ModelSuppliers.Clear();

        var lines = content.Replace("\r\n", "\n").Split('\n', StringSplitOptions.RemoveEmptyEntries);
        var first = true;
        foreach (var line in lines)
        {
            var row = ParseCsvRow(line);
            if (first)
            {
                first = false;
                if (row.Count > 0 && row[0] == "full_path") continue;
            }
            if (row.Count < 4) continue;

            var fullPath = row[0].Trim();
            var directory = row[1].Trim();
            var filename = row[2].Trim();
            var version = row[3].Trim();
            var supplier = row.Count > 4 ? row[4].Trim() : "Неизвестно";
            var libName = Path.GetFileNameWithoutExtension(filename);

            if (IsDashAllBundle(libName)) continue;
            if (string.IsNullOrEmpty(version) || version is "Неизвестно" or "N/A") continue;

            LibraryKind kind;
            if (ImplantPathPrefixes.Any(p => fullPath.Contains(p))) kind = LibraryKind.Implant;
            else if (fullPath.Contains(ModelPathPrefix)) kind = LibraryKind.Model;
            else continue;

            var (cat, sub) = CategoryHelper.ExtractCategoryAndSubcategory(fullPath);

            string downloadUrl;
            if (fullPath.Contains("/catalog/"))
            {
                var idx = fullPath.IndexOf("/catalog/");
                var rest = fullPath[(idx + "/catalog/".Length)..];
                downloadUrl = $"https://example.invalid/catalog/{rest}";
            }
            else
            {
                downloadUrl = $"https://example.invalid/{fullPath.TrimStart('/')}";
            }

            var entry = new CsvLibraryEntry
            {
                FullPath = fullPath,
                Directory = directory,
                Filename = filename,
                Version = version,
                Supplier = supplier,
                DownloadUrl = downloadUrl,
                Category = cat,
                Subcategory = sub,
                Kind = kind
            };

            if (kind == LibraryKind.Implant)
            {
                ImplantData[libName] = entry;
                if (!string.IsNullOrEmpty(supplier) && supplier != "Неизвестно" && supplier != "?")
                    ImplantSuppliers.Add(supplier);
            }
            else
            {
                ModelData[libName] = entry;
                if (!string.IsNullOrEmpty(supplier) && supplier != "Неизвестно" && supplier != "?")
                    ModelSuppliers.Add(supplier);
            }
        }
    }

    private static List<string> ParseCsvRow(string line)
    {
        var result = new List<string>();
        var sb = new StringBuilder();
        var inQuotes = false;
        for (var i = 0; i < line.Length; i++)
        {
            var c = line[i];
            if (inQuotes)
            {
                if (c == '"')
                {
                    if (i + 1 < line.Length && line[i + 1] == '"')
                    {
                        sb.Append('"');
                        i++;
                    }
                    else inQuotes = false;
                }
                else sb.Append(c);
            }
            else
            {
                if (c == '"') inQuotes = true;
                else if (c == ',')
                {
                    result.Add(sb.ToString());
                    sb.Clear();
                }
                else sb.Append(c);
            }
        }
        result.Add(sb.ToString());
        return result;
    }

    private static bool IsDashAllBundle(string libraryName) =>
        libraryName.Contains("-ALL", StringComparison.OrdinalIgnoreCase);
}
