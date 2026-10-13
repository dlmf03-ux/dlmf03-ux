using System.Windows.Threading;
using LibraryUpdaterDemo.Models;

namespace LibraryUpdaterDemo.Services;

public class AutoCheckService
{
    private readonly DispatcherTimer _timer;
    private readonly Func<AppSettings> _getSettings;
    private readonly LogService _log;

    public event Func<Task>? CheckRequested;

    public AutoCheckService(Func<AppSettings> getSettings, LogService log)
    {
        _getSettings = getSettings;
        _log = log;
        _timer = new DispatcherTimer { Interval = TimeSpan.FromMinutes(60) };
        _timer.Tick += async (_, _) => await TickAsync();
    }

    public void Start()
    {
        if (_timer.IsEnabled) return;
        _timer.Start();
        _ = TickAsync();
    }

    public void Stop()
    {
        if (!_timer.IsEnabled) return;
        _timer.Stop();
    }

    private async Task TickAsync()
    {
        var s = _getSettings();
        if (!s.AutoCheckEnabled) return;

        if (s.LastCheck is { } last)
        {
            var days = (DateTime.Now - last).TotalDays;
            if (days < s.CheckIntervalDays) return;
        }

        _log.Log("Запуск автоматической проверки обновлений...");
        if (CheckRequested != null)
            await CheckRequested.Invoke();
    }
}
