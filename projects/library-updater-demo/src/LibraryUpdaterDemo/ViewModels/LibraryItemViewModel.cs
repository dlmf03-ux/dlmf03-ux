using CommunityToolkit.Mvvm.ComponentModel;
using LibraryUpdaterDemo.Models;
using LibraryUpdaterDemo.Services;

namespace LibraryUpdaterDemo.ViewModels;

public partial class LibraryItemViewModel : ObservableObject
{
    public LibraryRecord Record { get; }

    public LibraryItemViewModel(LibraryRecord record)
    {
        Record = record;
        _name = record.Name;
        _availableVersion = record.AvailableVersion;
        _version = record.Version;
        _supplier = record.Supplier;
        _relativePath = record.RelativePath;
        _backupCreated = record.BackupCreated;
        _installed = record.Installed;
        RefreshStatus();
    }

    [ObservableProperty] private string _name = "";
    [ObservableProperty] private string _availableVersion = "";
    [ObservableProperty] private string _version = "";
    [ObservableProperty] private string _supplier = "";
    [ObservableProperty] private string _relativePath = "";
    [ObservableProperty] private string _status = "Неизвестно";
    [ObservableProperty] private bool _backupCreated;
    [ObservableProperty] private bool _installed;
    [ObservableProperty] private bool _isBusy;

    public string BackupMark => BackupCreated ? "✓" : "";

    public void RefreshFromRecord()
    {
        AvailableVersion = Record.AvailableVersion;
        Version = Record.Version;
        Supplier = Record.Supplier;
        RelativePath = Record.RelativePath;
        BackupCreated = Record.BackupCreated;
        Installed = Record.Installed;
        RefreshStatus();
        OnPropertyChanged(nameof(BackupMark));
    }

    public void RefreshStatus()
    {
        if (Installed)
        {
            Status = "Установлена";
            return;
        }

        if (Record.FullPath == null)
        {
            Status = "Не установлена";
            return;
        }

        if (AvailableVersion != "N/A")
        {
            var availableDate = VersionDateComparer.ExtractDate(AvailableVersion);
            var localDate = VersionDateComparer.ExtractDate(Version);

            if (availableDate != null && localDate == null)
            {
                Status = "Требуется обновление";
                return;
            }

            if (availableDate == null || localDate == null)
            {
                Status = "Неизвестно";
                return;
            }

            var cmp = DateTime.Compare(localDate.Value, availableDate.Value);
            Status = cmp < 0 ? "Требуется обновление"
                : cmp == 0 ? "Актуальна"
                : "Новее доступной";
        }
        else
        {
            Status = "Неизвестно";
        }
    }
}
