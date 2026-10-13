using System.Text.Json.Serialization;

namespace LibraryUpdaterDemo.Models;

public class AppSettings
{
    [JsonPropertyName("implant_path")]
    public string ImplantPath { get; set; } = @"C:\DentalCAD\library\implant";

    [JsonPropertyName("model_path")]
    public string ModelPath { get; set; } = @"C:\DentalCAD\library\modelcreator\implants";

    [JsonPropertyName("check_interval_days")]
    public int CheckIntervalDays { get; set; } = 14;

    [JsonPropertyName("auto_check_enabled")]
    public bool AutoCheckEnabled { get; set; } = true;

    [JsonPropertyName("last_check")]
    public DateTime? LastCheck { get; set; }

    [JsonPropertyName("csv_url")]
    public string CsvUrl { get; set; } = "";

    [JsonPropertyName("minimize_to_tray_on_close")]
    public bool MinimizeToTrayOnClose { get; set; } = true;

    [JsonPropertyName("analytics_url")]
    public string AnalyticsUrl { get; set; } = "";

    [JsonPropertyName("client_id")]
    public string ClientId { get; set; } = Guid.NewGuid().ToString("N");

    [JsonPropertyName("install_tracked")]
    public bool InstallTracked { get; set; }
}
