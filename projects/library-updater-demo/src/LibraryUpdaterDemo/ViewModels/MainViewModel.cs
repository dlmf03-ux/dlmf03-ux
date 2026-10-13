using System.Collections.ObjectModel;
using System.IO;
using CommunityToolkit.Mvvm.ComponentModel;
using CommunityToolkit.Mvvm.Input;
using LibraryUpdaterDemo.Models;
using LibraryUpdaterDemo.Services;

namespace LibraryUpdaterDemo.ViewModels;

public partial class MainViewModel : ObservableObject
{
    private readonly SettingsService _settingsService;
    public AppSettings Settings { get; }

    public LogService Log { get; }
    public CsvService Csv { get; }
    private readonly LibraryScannerService _scanner;
    private readonly DownloadService _download;
    private readonly ArchiveExtractorService _extractor;
    private readonly TelemetryService _telemetry;
    public ShortcutResolverService Shortcuts { get; }
    public InstallExecutor Executor { get; }
    public AutoCheckService AutoCheck { get; }

    public LibraryTabViewModel ImplantTab { get; }
    public LibraryTabViewModel ModelTab { get; }
    public ObservableCollection<LibraryTabViewModel> Tabs { get; }

    private bool _isInitialized;
    [ObservableProperty] private string _csvUrl = "";
    [ObservableProperty] private bool _isBusy;
    [ObservableProperty] private string _statusText = "Готов к работе";
    [ObservableProperty] private bool _autoCheckEnabled;
    [ObservableProperty] private int _checkIntervalDays;
    [ObservableProperty] private bool _minimizeToTrayOnClose;

    public event Action<string>? RequestBrowseFolder;
    public event Action<string>? RequestPickShortcut;
    public event Action<string, string>? RequestSaveLog;

    public MainViewModel()
    {
        _settingsService = new SettingsService();
        Settings = _settingsService.Load();

        Log = new LogService();
        Csv = new CsvService(Log);
        _scanner = new LibraryScannerService(Log);
        _download = new DownloadService(Log);
        _extractor = new ArchiveExtractorService(Log);
        _telemetry = new TelemetryService(() => Settings);
        Shortcuts = new ShortcutResolverService(Log);
        Executor = new InstallExecutor(Log, _download, _extractor, _telemetry);
        AutoCheck = new AutoCheckService(() => Settings, Log);

        ImplantTab = new LibraryTabViewModel(LibraryKind.Implant, Log, Csv, _scanner, Executor, PersistSettings)
        {
            LibraryPath = Settings.ImplantPath
        };
        ModelTab = new LibraryTabViewModel(LibraryKind.Model, Log, Csv, _scanner, Executor, PersistSettings)
        {
            LibraryPath = Settings.ModelPath
        };
        Tabs = new ObservableCollection<LibraryTabViewModel> { ImplantTab, ModelTab };

        ImplantTab.RequestBrowseFolder += t => RequestBrowseFolder?.Invoke(t);
        ImplantTab.RequestPickShortcut += t => RequestPickShortcut?.Invoke(t);
        ModelTab.RequestBrowseFolder += t => RequestBrowseFolder?.Invoke(t);
        ModelTab.RequestPickShortcut += t => RequestPickShortcut?.Invoke(t);

        _csvUrl = Settings.CsvUrl;
        _autoCheckEnabled = Settings.AutoCheckEnabled;
        _checkIntervalDays = Settings.CheckIntervalDays;
        _minimizeToTrayOnClose = Settings.MinimizeToTrayOnClose;

        AutoCheck.CheckRequested += CheckForUpdatesAsync;
        _isInitialized = true;
        _ = _telemetry.TrackAppStartAsync();
        _ = TrackInstallOnceAsync();
    }

    partial void OnAutoCheckEnabledChanged(bool value)
    {
        Settings.AutoCheckEnabled = value;
        PersistSettings();
        if (value) AutoCheck.Start();
        else AutoCheck.Stop();
        Log.Log(value ? "✓ Автоматическая проверка включена" : "✗ Автоматическая проверка отключена");
    }

    partial void OnCheckIntervalDaysChanged(int value)
    {
        if (value < 1) value = 1;
        if (value > 30) value = 30;
        Settings.CheckIntervalDays = value;
        PersistSettings();
    }

    partial void OnMinimizeToTrayOnCloseChanged(bool value)
    {
        Settings.MinimizeToTrayOnClose = value;
        PersistSettings();
    }

    partial void OnCsvUrlChanged(string value)
    {
        Settings.CsvUrl = value;
        PersistSettings();
    }

    public void PersistSettings()
    {
        if (!_isInitialized) return; // защита от вызова во время конструктора
        Settings.ImplantPath = ImplantTab.LibraryPath;
        Settings.ModelPath = ModelTab.LibraryPath;
        Settings.CsvUrl = CsvUrl;
        Settings.AutoCheckEnabled = AutoCheckEnabled;
        Settings.CheckIntervalDays = CheckIntervalDays;
        Settings.MinimizeToTrayOnClose = MinimizeToTrayOnClose;
        _settingsService.Save(Settings);
    }

    private async Task TrackInstallOnceAsync()
    {
        if (Settings.InstallTracked) return;

        if (await _telemetry.TrackAppInstallAsync())
        {
            Settings.InstallTracked = true;
            PersistSettings();
        }
    }

    [RelayCommand]
    private async Task DownloadCsvAsync()
    {
        IsBusy = true;
        StatusText = "Загрузка данных с сервера...";
        try
        {
            await Csv.DownloadAsync(CsvUrl);
            StatusText = $"Данные загружены: implant={Csv.ImplantData.Count}, model={Csv.ModelData.Count}";
        }
        catch (Exception ex)
        {
            Log.Log($"✗ Ошибка загрузки данных: {ex.Message}");
            StatusText = "Ошибка загрузки данных";
        }
        finally
        {
            IsBusy = false;
        }
    }

    [RelayCommand]
    public async Task CheckForUpdatesAsync()
    {
        Log.Log("Запуск проверки обновлений...");
        Settings.LastCheck = DateTime.Now;
        PersistSettings();

        if (Csv.ImplantData.Count == 0 && Csv.ModelData.Count == 0)
        {
            await DownloadCsvAsync();
        }

        foreach (var tab in Tabs)
        {
            if (Directory.Exists(tab.LibraryPath))
                await tab.ScanAsync();
        }

        _ = _telemetry.TrackCheckForUpdatesAsync(Csv.ImplantData.Count, Csv.ModelData.Count);
    }

    [RelayCommand]
    private void SaveLog()
    {
        var ts = DateTime.Now.ToString("yyyyMMdd_HHmmss");
        var defaultName = $"demo_updater_log_{ts}.txt";
        RequestSaveLog?.Invoke("Сохранить лог", defaultName);
    }

    public void HandleSaveLogPath(string path)
    {
        try
        {
            Log.SaveToFile(path, ImplantTab.LibraryPath, ModelTab.LibraryPath);
            Log.Log($"✓ Лог сохранен в файл: {path}");
        }
        catch (Exception ex)
        {
            Log.Log($"✗ Ошибка сохранения лога: {ex.Message}");
        }
    }

    public void HandlePathPicked(string section, string path)
    {
        // section = display name; нормализуем путь и сохраняем
        var normalized = Path.GetFullPath(path);
        if (section == LibraryKind.Implant.DisplayName())
            ImplantTab.LibraryPath = normalized;
        else
            ModelTab.LibraryPath = normalized;
        Log.Log($"Выбрана папка для {section}: {normalized}");
        PersistSettings();
    }

    public void HandleShortcutPicked(string lnkPath)
    {
        var root = Shortcuts.FindExocadRoot(lnkPath);
        if (root == null)
        {
            Log.Log("✗ Не удалось определить корневую папку exocad по выбранному ярлыку");
            return;
        }

        var implant = Path.Combine(root, "DentalCADApp", "library", "implant");
        var model = Path.Combine(root, "DentalCADApp", "library", "modelcreator", "implants");
        ImplantTab.LibraryPath = implant;
        ModelTab.LibraryPath = model;
        Log.Log($"Пути установлены из ярлыка:\nОснования: {implant}\n3D аналоги: {model}");
        PersistSettings();
    }
}
