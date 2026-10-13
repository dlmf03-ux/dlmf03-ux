using System.ComponentModel;
using System.IO;
using System.Windows;
using System.Windows.Controls;
using Microsoft.Win32;
using LibraryUpdaterDemo.ViewModels;

namespace LibraryUpdaterDemo;

public partial class MainWindow : Window
{
    public MainWindow()
    {
        InitializeComponent();
        DataContextChanged += MainWindow_OnDataContextChanged;
    }

    private void MainWindow_OnDataContextChanged(object sender, DependencyPropertyChangedEventArgs e)
    {
        if (e.OldValue is MainViewModel oldVm)
        {
            oldVm.RequestBrowseFolder -= OnBrowseFolder;
            oldVm.RequestPickShortcut -= OnPickShortcut;
            oldVm.RequestSaveLog -= OnSaveLog;
            oldVm.Log.EntryAdded -= OnLogEntryAdded;
        }
        if (e.NewValue is MainViewModel vm)
        {
            vm.RequestBrowseFolder += OnBrowseFolder;
            vm.RequestPickShortcut += OnPickShortcut;
            vm.RequestSaveLog += OnSaveLog;
            vm.Log.EntryAdded += OnLogEntryAdded;
        }
    }

    private void OnLogEntryAdded(object? sender, Services.LogEntry entry)
    {
        // авто-скролл
        Dispatcher.BeginInvoke(() =>
        {
            if (LogList.Items.Count > 0)
                LogList.ScrollIntoView(LogList.Items[^1]);
        });
    }

    private void OnBrowseFolder(string section)
    {
        var dialog = new OpenFolderDialog
        {
            Title = $"Выберите папку с библиотеками — {section}"
        };
        if (dialog.ShowDialog() == true)
        {
            ((MainViewModel)DataContext).HandlePathPicked(section, dialog.FolderName);
        }
    }

    private void OnPickShortcut(string _)
    {
        var dialog = new OpenFileDialog
        {
            Title = "Выберите ярлык exocad (например, exocad.lnk)",
            Filter = "Ярлыки exocad (*.lnk)|*.lnk|Все файлы (*.*)|*.*"
        };
        if (dialog.ShowDialog() == true)
        {
            ((MainViewModel)DataContext).HandleShortcutPicked(dialog.FileName);
        }
    }

    private void OnSaveLog(string title, string defaultName)
    {
        var dialog = new SaveFileDialog
        {
            Title = title,
            Filter = "Текстовый файл (*.txt)|*.txt|Все файлы (*.*)|*.*",
            FileName = defaultName,
            DefaultExt = ".txt"
        };
        if (dialog.ShowDialog() == true)
        {
            ((MainViewModel)DataContext).HandleSaveLogPath(dialog.FileName);
        }
    }

    private void MainWindow_OnStateChanged(object? sender, EventArgs e)
    {
        // Свернуть в трей при минимизации
        if (WindowState == WindowState.Minimized
            && DataContext is MainViewModel vm
            && vm.MinimizeToTrayOnClose
            && Application.Current is App app)
        {
            app.Tray.HideToTray();
        }
    }

    private void MainWindow_OnClosing(object? sender, CancelEventArgs e)
    {
        if (DataContext is MainViewModel vm
            && vm.MinimizeToTrayOnClose
            && Application.Current is App app
            && !app.Tray.TrueExitRequested)
        {
            // Не закрываем приложение — прячем в трей
            e.Cancel = true;
            app.Tray.HideToTray();
        }
    }
}
