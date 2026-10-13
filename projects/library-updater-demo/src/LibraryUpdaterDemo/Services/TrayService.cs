using System.Drawing;
using System.IO;
using System.Reflection;
using System.Threading;
using System.Windows;
using H.NotifyIcon;
using H.NotifyIcon.Core;

namespace LibraryUpdaterDemo.Services;

/// <summary>
/// Обёртка над H.NotifyIcon.Wpf TaskbarIcon.
/// Предоставляет «свернуть/закрыть в трей», балунные подсказки и контекстное меню.
/// </summary>
public class TrayService : IDisposable
{
    private TaskbarIcon? _taskbar;
    private Window? _window;
    private bool _isHiddenToTray;
    private int _restoreRequested;
    public bool TrueExitRequested { get; private set; }

    public event Action? OpenRequested;
    public event Action? CheckNowRequested;
    public event Action? ExitRequested;

    public void Attach(Window window)
    {
        _window = window;

        _taskbar = new TaskbarIcon
        {
            ToolTipText = "Library Updater Demo",
            Visibility = Visibility.Visible
        };

        // Загружаем иконку из ресурсов сборки (pack-URI)
        try
        {
            var iconStream = Application.GetResourceStream(
                new Uri("pack://application:,,,/Assets/app.ico"))?.Stream;
            if (iconStream != null)
            {
                _taskbar.Icon = new Icon(iconStream);
            }
            else
            {
                // fallback: иконка приложения из exe
                var exePath = System.Diagnostics.Process.GetCurrentProcess().MainModule?.FileName;
                if (!string.IsNullOrEmpty(exePath))
                    _taskbar.Icon = Icon.ExtractAssociatedIcon(exePath);
            }
        }
        catch
        {
            // если не удалось — H.NotifyIcon покажет иконку по умолчанию
        }

        _taskbar.TrayMouseDoubleClick += (_, _) => RequestOpen();

        // Явные цвета: ContextMenu в трее рендерится в отдельном popup-окне.
        // Глобальный стиль из BrandStyles.xaml применяется, но local value
        // на каждом MenuItem страхует от любых наследований Foreground=White.
        var whiteBrush = (System.Windows.Media.Brush?)Application.Current.Resources["WhiteBrush"]
                         ?? System.Windows.Media.Brushes.White;

        var menu = new System.Windows.Controls.ContextMenu();
        _taskbar.ContextMenu = menu;

        menu.Items.Add(BuildMenuItem("Открыть", whiteBrush, RequestOpen));
        menu.Items.Add(BuildMenuItem("Проверить обновления", whiteBrush, () => CheckNowRequested?.Invoke()));
        menu.Items.Add(new System.Windows.Controls.Separator());
        menu.Items.Add(BuildMenuItem("Выход", whiteBrush, () =>
        {
            TrueExitRequested = true;
            ExitRequested?.Invoke();
        }));

        // Форсируем регистрацию иконки в трее даже если TaskbarIcon не в visual tree.
        // Без этого иконка не появится при свёртывании окна.
        _taskbar.ForceCreate();
    }

    private static System.Windows.Controls.MenuItem BuildMenuItem(
        string header,
        System.Windows.Media.Brush foreground,
        Action onClick)
    {
        var item = new System.Windows.Controls.MenuItem
        {
            Header = header,
            Foreground = foreground
        };
        item.Click += (_, _) => onClick();
        return item;
    }

    public void HideToTray()
    {
        var dispatcher = _window?.Dispatcher;
        if (dispatcher != null && !dispatcher.CheckAccess())
        {
            _ = dispatcher.BeginInvoke(new Action(HideToTray));
            return;
        }

        if (_window == null || _isHiddenToTray) return;

        try
        {
            _isHiddenToTray = true;
            _window.ShowInTaskbar = false;
            _window.Hide();
        }
        catch
        {
            _isHiddenToTray = false;
            return;
        }
        ShowBalloon("Library Updater", "Приложение продолжает работу в трее. Авто-проверка обновлений активна.");
    }

    public void RestoreFromTray()
    {
        var dispatcher = _window?.Dispatcher;
        if (dispatcher != null && !dispatcher.CheckAccess())
        {
            try
            {
                _ = dispatcher.BeginInvoke(new Action(RestoreFromTray));
            }
            catch
            {
                Interlocked.Exchange(ref _restoreRequested, 0);
            }
            return;
        }

        try
        {
            if (_window == null) return;

            if (!_window.IsVisible) _window.Show();
            _window.WindowState = WindowState.Normal;
            _window.ShowInTaskbar = true;
            _window.Activate();
            _window.Topmost = true;
            _window.Topmost = false;
            _window.Focus();
            _isHiddenToTray = false;
        }
        catch
        {
            // A tray click must never terminate the application.
        }
        finally
        {
            Interlocked.Exchange(ref _restoreRequested, 0);
        }
    }

    private void RequestOpen()
    {
        if (Interlocked.Exchange(ref _restoreRequested, 1) != 0) return;

        try
        {
            OpenRequested?.Invoke();
        }
        catch
        {
            Interlocked.Exchange(ref _restoreRequested, 0);
        }
    }

    public void ShowBalloon(string title, string message)
    {
        try
        {
            _taskbar?.ShowNotification(title, message, NotificationIcon.Info);
        }
        catch
        {
            // Win32 ToastNotification может не поддерживаться в некоторых средах — игнорируем
        }
    }

    public void Dispose()
    {
        _taskbar?.Dispose();
    }
}
