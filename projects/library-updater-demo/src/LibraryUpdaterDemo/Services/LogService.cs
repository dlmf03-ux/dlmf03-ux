using System.Collections.ObjectModel;
using System.IO;
using System.Windows;
using LibraryUpdaterDemo.Models;

namespace LibraryUpdaterDemo.Services;

public class LogEntry
{
    public DateTime Timestamp { get; init; } = DateTime.Now;
    public string Message { get; init; } = "";
    public LogLevel Level { get; init; }

    public string Display => $"[{Timestamp:HH:mm:ss}] {Message}";
}

public class LogService
{
    public ObservableCollection<LogEntry> Entries { get; } = new();

    public event EventHandler<LogEntry>? EntryAdded;

    public void Log(string message, LogLevel? level = null)
    {
        var lvl = level ?? Detect(message);
        var entry = new LogEntry { Message = message, Level = lvl };

        if (Application.Current?.Dispatcher.CheckAccess() == false)
        {
            Application.Current.Dispatcher.BeginInvoke(() => Append(entry));
        }
        else
        {
            Append(entry);
        }
    }

    private void Append(LogEntry entry)
    {
        Entries.Add(entry);
        if (Entries.Count > 5000)
            Entries.RemoveAt(0);
        EntryAdded?.Invoke(this, entry);
    }

    private static LogLevel Detect(string m)
    {
        if (m.Contains('✗') || m.Contains("Ошибка", StringComparison.OrdinalIgnoreCase)) return LogLevel.Error;
        if (m.Contains('✓') || m.Contains("успешно", StringComparison.OrdinalIgnoreCase)) return LogLevel.Success;
        if (m.Contains("===")) return LogLevel.Header;
        if (m.Contains("Предупреждение", StringComparison.OrdinalIgnoreCase) || m.Contains('⚠')) return LogLevel.Warning;
        return LogLevel.Info;
    }

    public void SaveToFile(string path, string? implantPath, string? modelPath)
    {
        using var writer = new StreamWriter(path, false, System.Text.Encoding.UTF8);
        writer.WriteLine("Library Updater Log");
        writer.WriteLine($"Дата и время: {DateTime.Now:dd.MM.yyyy HH:mm:ss}");
        writer.WriteLine($"Путь основания: {implantPath}");
        writer.WriteLine($"Путь 3D аналоги: {modelPath}");
        writer.WriteLine(new string('=', 80));
        writer.WriteLine();
        foreach (var entry in Entries)
            writer.WriteLine(entry.Display);
    }
}
