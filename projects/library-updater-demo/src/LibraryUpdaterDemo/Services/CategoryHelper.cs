namespace LibraryUpdaterDemo.Services;

public static class CategoryHelper
{
    public static readonly Dictionary<string, string> CategoryDisplayNames = new()
    {
        ["analogs"] = "CAD-библиотеки для аналогов",
        ["fixtures"] = "CAD-библиотеки для примерочных элементов",
        ["bases"] = "CAD-библиотеки для оснований",
        ["system-a"] = "CAD-библиотеки системы A",
        ["system-b"] = "CAD-библиотеки системы B"
    };

    public static readonly Dictionary<string, string> DisplayToOriginal =
        CategoryDisplayNames.ToDictionary(kv => kv.Value, kv => kv.Key);

    public static string GetCategoryDisplay(string? original) =>
        original is null ? "" :
        CategoryDisplayNames.TryGetValue(original, out var d) ? d : original;

    /// <summary>
    /// Точные названия для тех slug-ов, где автоматический Title Case даёт
    /// неподходящий результат (русские транслит-слова, аббревиатуры, амперсанды).
    /// </summary>
    private static readonly Dictionary<string, string> SubcategoryOverrides =
        new(StringComparer.OrdinalIgnoreCase)
        {
            ["shestigrannoe-soedinenie"] = "Шестигранное соединение",
            ["sample-vendor-a"] = "Поставщик A",
            ["sample-vendor-b"] = "Поставщик B",
            ["sample-vendor-c"] = "Поставщик C"
        };

    public static string GetSubcategoryDisplay(string? sub)
    {
        if (string.IsNullOrEmpty(sub)) return "";

        // 0. Явный override — самый высокий приоритет.
        if (SubcategoryOverrides.TryGetValue(sub, out var fixedName))
            return fixedName;

        // 1. MUA-формат «U-SND-MUA (Straumann Neodent)» — берём содержимое последних скобок.
        var lastOpen = sub.LastIndexOf('(');
        if (lastOpen >= 0)
        {
            var lastClose = sub.LastIndexOf(')');
            if (lastClose > lastOpen)
                return sub.Substring(lastOpen + 1, lastClose - lastOpen - 1).Trim();
        }

        // 2. slug-формат — Title Case через пробел.
        //    Покрывает и многословные («straumann-neodent» → «Straumann Neodent»),
        //    и однословные («ankylos» → «Ankylos», «dentium» → «Dentium»).
        //    Применяем только к строкам целиком в lower-case с дефисами/цифрами,
        //    чтобы не ломать артикулы вроде «U-DAS-BAR».
        if (sub.All(c => char.IsLower(c) || c == '-' || c == '_' || char.IsDigit(c)))
        {
            var parts = sub.Split(new[] { '-', '_' }, StringSplitOptions.RemoveEmptyEntries);
            var words = parts.Select(p =>
                p.Length == 0 ? p
                : char.IsDigit(p[0]) ? p
                : char.ToUpper(p[0]) + p[1..]);
            return string.Join(" ", words);
        }

        return sub;
    }

    /// <summary>Извлекает category и subcategory из полного пути CSV.</summary>
    public static (string? Category, string? Subcategory) ExtractCategoryAndSubcategory(string fullPath)
    {
        try
        {
            const string token = "/catalog/";
            var idx = fullPath.IndexOf(token);
            if (idx < 0) return (null, null);
            var after = fullPath[(idx + token.Length)..];
            var parts = after.Split('/');
            if (parts.Length >= 2 && parts[0] == "exocad")
            {
                var cat = parts.Length > 1 ? parts[1] : null;
                var sub = parts.Length > 2 ? parts[2] : null;
                return (cat, sub);
            }
        }
        catch { }
        return (null, null);
    }
}
