using System.Globalization;
using System.Text.RegularExpressions;

namespace LibraryUpdaterDemo.Services;

public static class VersionDateComparer
{
    private static readonly (Regex pattern, bool yearLast)[] Patterns =
    {
        (new Regex(@"(\d{2})\.(\d{2})\.(\d{4})", RegexOptions.Compiled), true),
        (new Regex(@"(\d{2})\.(\d{2})\.(\d{2})",   RegexOptions.Compiled), true),
        (new Regex(@"(\d{4})-(\d{2})-(\d{2})",     RegexOptions.Compiled), false),
        (new Regex(@"(\d{2})/(\d{2})/(\d{4})",     RegexOptions.Compiled), true)
    };

    public static int Compare(string a, string b)
    {
        var da = ExtractDate(a);
        var db = ExtractDate(b);
        if (da == null || db == null) return 0;
        return DateTime.Compare(da.Value, db.Value);
    }

    public static DateTime? ExtractDate(string version)
    {
        if (string.IsNullOrWhiteSpace(version)) return null;
        foreach (var (pattern, yearLast) in Patterns)
        {
            var m = pattern.Match(version);
            if (!m.Success) continue;

            try
            {
                int day, month, year;
                if (yearLast)
                {
                    day = int.Parse(m.Groups[1].Value);
                    month = int.Parse(m.Groups[2].Value);
                    year = int.Parse(m.Groups[3].Value);
                    if (year < 100) year += 2000;
                }
                else
                {
                    year = int.Parse(m.Groups[1].Value);
                    month = int.Parse(m.Groups[2].Value);
                    day = int.Parse(m.Groups[3].Value);
                }
                return new DateTime(year, month, day);
            }
            catch { /* пробуем следующий паттерн */ }
        }
        return null;
    }
}
