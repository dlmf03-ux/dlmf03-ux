namespace LibraryUpdaterDemo.Models;

public class CsvLibraryEntry
{
    public string FullPath { get; set; } = "";
    public string Directory { get; set; } = "";
    public string Filename { get; set; } = "";
    public string Version { get; set; } = "";
    public string Supplier { get; set; } = "";
    public string DownloadUrl { get; set; } = "";
    public string? Category { get; set; }
    public string? Subcategory { get; set; }
    public LibraryKind Kind { get; set; }
}
