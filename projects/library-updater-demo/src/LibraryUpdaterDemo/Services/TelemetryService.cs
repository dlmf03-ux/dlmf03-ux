using System.Net.Http;
using System.Net.Http.Json;
using System.Reflection;
using LibraryUpdaterDemo.Models;

namespace LibraryUpdaterDemo.Services;

public class TelemetryService
{
    private readonly Func<AppSettings> _getSettings;

    public TelemetryService(Func<AppSettings> getSettings)
    {
        _getSettings = getSettings;
    }

    public async Task<bool> TrackAppInstallAsync(CancellationToken ct = default)
    {
        var settings = _getSettings();
        return await SendAsync(new
        {
            event_type = "app_install",
            client_id = settings.ClientId,
            app_version = GetAppVersion()
        }, ct);
    }

    public Task TrackAppStartAsync(CancellationToken ct = default)
    {
        var settings = _getSettings();
        return SendAsync(new
        {
            event_type = "app_start",
            client_id = settings.ClientId,
            app_version = GetAppVersion()
        }, ct);
    }

    public Task TrackCheckForUpdatesAsync(
        int implantCount,
        int modelCount,
        CancellationToken ct = default)
    {
        var settings = _getSettings();
        return SendAsync(new
        {
            event_type = "check_updates",
            client_id = settings.ClientId,
            app_version = GetAppVersion(),
            metadata = new
            {
                implant_items = implantCount,
                model_items = modelCount
            }
        }, ct);
    }

    public Task TrackLibraryDownloadAsync(
        LibraryRecord library,
        string operation,
        long? bytesDownloaded,
        CancellationToken ct = default)
    {
        var settings = _getSettings();
        return SendAsync(new
        {
            event_type = "library_download",
            client_id = settings.ClientId,
            app_version = GetAppVersion(),
            library_name = library.Name,
            library_version = library.AvailableVersion,
            supplier = library.Supplier,
            category = library.Category,
            subcategory = library.Subcategory,
            operation,
            bytes_downloaded = bytesDownloaded,
            download_url = library.DownloadUrl
        }, ct);
    }

    public Task TrackLibraryOperationAsync(
        LibraryRecord library,
        string operation,
        bool success,
        string? errorMessage = null,
        CancellationToken ct = default)
    {
        var settings = _getSettings();
        return SendAsync(new
        {
            event_type = "library_operation",
            client_id = settings.ClientId,
            app_version = GetAppVersion(),
            library_name = library.Name,
            library_version = library.AvailableVersion,
            supplier = library.Supplier,
            category = library.Category,
            subcategory = library.Subcategory,
            operation,
            status = success ? "success" : "failed",
            error_message = errorMessage,
            download_url = library.DownloadUrl
        }, ct);
    }

    private async Task<bool> SendAsync(object payload, CancellationToken ct)
    {
        var url = _getSettings().AnalyticsUrl;
        if (string.IsNullOrWhiteSpace(url)) return false;

        try
        {
            using var timeoutCts = CancellationTokenSource.CreateLinkedTokenSource(ct);
            timeoutCts.CancelAfter(TimeSpan.FromSeconds(3));

            using var client = new HttpClient
            {
                Timeout = TimeSpan.FromSeconds(4)
            };
            using var response = await client.PostAsJsonAsync(url, payload, timeoutCts.Token);
            return response.IsSuccessStatusCode;
        }
        catch
        {
            return false;
        }
    }

    private static string GetAppVersion() =>
        Assembly.GetExecutingAssembly()
            .GetCustomAttribute<AssemblyInformationalVersionAttribute>()
            ?.InformationalVersion
        ?? Assembly.GetExecutingAssembly().GetName().Version?.ToString()
        ?? "";
}
