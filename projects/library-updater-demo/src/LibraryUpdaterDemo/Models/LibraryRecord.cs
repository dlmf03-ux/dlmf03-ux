namespace LibraryUpdaterDemo.Models;

/// <summary>
/// Представление одной библиотеки exocad. Объединяет данные с диска (config.xml)
/// и данные с сервера (CSV) — минимум одно из этих двух присутствует.
/// </summary>
public class LibraryRecord
{
    public string Name { get; set; } = "";
    public string? FullPath { get; set; }            // путь на диске, null если не установлена
    public string RelativePath { get; set; } = "Не установлена";
    public string Version { get; set; } = "Не установлена";
    public string Supplier { get; set; } = "Неизвестно";
    public string AvailableVersion { get; set; } = "N/A";
    public string? DownloadUrl { get; set; }
    public string? Category { get; set; }
    public string? Subcategory { get; set; }
    public bool BackupCreated { get; set; }
    public bool Installed { get; set; }   // помечается true сразу после успешной install/update
}
