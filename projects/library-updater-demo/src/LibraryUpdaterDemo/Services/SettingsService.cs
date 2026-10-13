using System.IO;
using System.Text.Json;
using LibraryUpdaterDemo.Models;

namespace LibraryUpdaterDemo.Services;

public class SettingsService
{
    private static readonly string SettingsPath =
        Path.Combine(Environment.GetFolderPath(Environment.SpecialFolder.UserProfile),
            ".exocad_updater_settings.json");

    private static readonly JsonSerializerOptions JsonOptions = new()
    {
        WriteIndented = true,
        Encoder = System.Text.Encodings.Web.JavaScriptEncoder.UnsafeRelaxedJsonEscaping
    };

    public AppSettings Load()
    {
        try
        {
            if (File.Exists(SettingsPath))
            {
                var json = File.ReadAllText(SettingsPath);
                var settings = JsonSerializer.Deserialize<AppSettings>(json);
                if (settings != null) return settings;
            }
        }
        catch
        {
            // используем дефолт
        }
        return new AppSettings();
    }

    public void Save(AppSettings settings)
    {
        try
        {
            var json = JsonSerializer.Serialize(settings, JsonOptions);
            File.WriteAllText(SettingsPath, json);
        }
        catch
        {
            // ignore — настройки не критичны
        }
    }
}
