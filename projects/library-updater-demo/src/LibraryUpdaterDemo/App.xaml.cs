using System.IO;
using System.Windows;
using System.Windows.Threading;
using LibraryUpdaterDemo.Services;
using LibraryUpdaterDemo.ViewModels;

namespace LibraryUpdaterDemo;

public partial class App : Application
{
    private const string SingleInstanceMutexName = "LibraryUpdaterDemo.SingleInstance.B7F4";
    private const string ShowWindowEventName = "LibraryUpdaterDemo.ShowWindow.B7F4";
    private static Mutex? _singleInstanceMutex;
    private static bool _ownsSingleInstanceMutex;
    private static EventWaitHandle? _showWindowEvent;
    public TrayService Tray { get; } = new();
    public MainViewModel? Vm { get; private set; }

    private void App_OnStartup(object sender, StartupEventArgs e)
    {
        AppDomain.CurrentDomain.UnhandledException += (_, args) =>
            WriteCrashLog(args.ExceptionObject as Exception);
        TaskScheduler.UnobservedTaskException += (_, args) =>
        {
            WriteCrashLog(args.Exception);
            args.SetObserved();
        };

        _showWindowEvent = new EventWaitHandle(false, EventResetMode.AutoReset, ShowWindowEventName);

        _singleInstanceMutex = new Mutex(true, SingleInstanceMutexName, out var createdNew);
        _ownsSingleInstanceMutex = createdNew;
        if (!createdNew)
        {
            // если уже запущен — просто завершаемся (трей-иконка из первого экземпляра доступна)
            SignalExistingInstance();
            Shutdown();
            return;
        }

        Vm = new MainViewModel();
        var window = new MainWindow { DataContext = Vm };
        MainWindow = window;
        Tray.Attach(window);

        Tray.OpenRequested += () => Tray.RestoreFromTray();
        Tray.CheckNowRequested += async () =>
        {
            Tray.RestoreFromTray();
            if (Vm != null) await Vm.CheckForUpdatesAsync();
        };
        Tray.ExitRequested += () => Shutdown();
        StartShowWindowSignalListener();

        // авто-загрузка CSV при старте + старт авто-проверки
        window.Show();
        _ = Vm.DownloadCsvCommand.ExecuteAsync(null);
        if (Vm.AutoCheckEnabled)
            Vm.AutoCheck.Start();
    }

    private void App_OnExit(object sender, ExitEventArgs e)
    {
        try { Vm?.Executor.CleanupTempDir(); } catch { }
        try { Vm?.PersistSettings(); } catch { }
        try { Tray.Dispose(); } catch { }
        if (_ownsSingleInstanceMutex)
        {
            try { _singleInstanceMutex?.ReleaseMutex(); }
            catch (ApplicationException) { /* нет владения — ОК */ }
        }
        _singleInstanceMutex?.Dispose();
        _showWindowEvent?.Dispose();
    }

    private static void SignalExistingInstance()
    {
        try
        {
            _showWindowEvent?.Set();
        }
        catch
        {
            // If the first instance is shutting down, there is nothing useful to do here.
        }
    }

    private void StartShowWindowSignalListener()
    {
        var thread = new Thread(() =>
        {
            while (true)
            {
                try
                {
                    _showWindowEvent?.WaitOne();
                    Dispatcher.BeginInvoke(() => Tray.RestoreFromTray());
                }
                catch (ObjectDisposedException)
                {
                    return;
                }
                catch
                {
                    return;
                }
            }
        })
        {
            IsBackground = true,
            Name = "LibraryUpdaterDemo.ShowWindowListener"
        };
        thread.Start();
    }

    private void App_OnDispatcherUnhandledException(object sender, DispatcherUnhandledExceptionEventArgs e)
    {
        WriteCrashLog(e.Exception);
        e.Handled = true;
    }

    private static void WriteCrashLog(Exception? exception)
    {
        if (exception == null) return;

        try
        {
            var directory = Path.Combine(
                Environment.GetFolderPath(Environment.SpecialFolder.LocalApplicationData),
                "LibraryUpdaterDemo");
            Directory.CreateDirectory(directory);

            var entry = $"[{DateTime.Now:yyyy-MM-dd HH:mm:ss}]\n{exception}\n\n";
            File.AppendAllText(Path.Combine(directory, "crash.log"), entry);
        }
        catch
        {
            // Logging must not trigger another application failure.
        }
    }
}
