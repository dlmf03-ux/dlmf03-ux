using System.Collections.ObjectModel;
using System.ComponentModel;
using System.IO;
using System.Windows.Data;
using CommunityToolkit.Mvvm.ComponentModel;
using CommunityToolkit.Mvvm.Input;
using LibraryUpdaterDemo.Models;
using LibraryUpdaterDemo.Services;

namespace LibraryUpdaterDemo.ViewModels;

public partial class LibraryTabViewModel : ObservableObject
{
    private readonly LibraryKind _kind;
    private readonly LogService _log;
    private readonly CsvService _csv;
    private readonly LibraryScannerService _scanner;
    private readonly InstallExecutor _executor;
    private readonly Action _persistSettings;

    public string Title => _kind.DisplayName();

    [ObservableProperty] private string _libraryPath = "";
    [ObservableProperty] private bool _isBusy;
    [ObservableProperty] private string _searchText = "";
    [ObservableProperty] private string _selectedSupplier = "Все";
    [ObservableProperty] private string _selectedCategory = "Все";
    [ObservableProperty] private string _selectedSubcategory = "Все";
    [ObservableProperty] private bool _showActual;
    [ObservableProperty] private string _statusSummary = "";

    public ObservableCollection<LibraryItemViewModel> InstallItems { get; } = new();
    public ObservableCollection<LibraryItemViewModel> UpdateItems { get; } = new();
    public ICollectionView InstallView { get; }
    public ObservableCollection<string> Suppliers { get; } = new() { "Все" };
    public ObservableCollection<string> Categories { get; } = new() { "Все" };
    public ObservableCollection<string> Subcategories { get; } = new() { "Все" };

    public event Action<string>? RequestBrowseFolder;
    public event Action<string>? RequestPickShortcut;
    public event Action<string>? PathPicked;

    private readonly Dictionary<string, HashSet<string>> _categoryToSubcategories = new();
    private Dictionary<string, string> _subcategoryDisplayMap = new();

    /// <summary>Имена библиотек, для которых установка/обновление прямо сейчас в работе.</summary>
    private readonly HashSet<string> _activeInstalls = new(StringComparer.OrdinalIgnoreCase);
    private readonly object _activeLock = new();

    public bool HasActiveInstalls
    {
        get { lock (_activeLock) return _activeInstalls.Count > 0; }
    }

    public LibraryTabViewModel(
        LibraryKind kind,
        LogService log,
        CsvService csv,
        LibraryScannerService scanner,
        InstallExecutor executor,
        Action persistSettings)
    {
        _kind = kind;
        _log = log;
        _csv = csv;
        _scanner = scanner;
        _executor = executor;
        _persistSettings = persistSettings;

        InstallView = CollectionViewSource.GetDefaultView(InstallItems);
        InstallView.Filter = ItemFilter;
    }

    partial void OnLibraryPathChanged(string value) => _persistSettings();
    partial void OnSearchTextChanged(string value) => InstallView.Refresh();
    partial void OnSelectedSupplierChanged(string value) => InstallView.Refresh();
    partial void OnShowActualChanged(bool value) => InstallView.Refresh();

    partial void OnSelectedCategoryChanged(string value)
    {
        Subcategories.Clear();
        Subcategories.Add("Все");
        _subcategoryDisplayMap = new Dictionary<string, string>();

        if (value != "Все" && CategoryHelper.DisplayToOriginal.TryGetValue(value, out var orig)
            && _categoryToSubcategories.TryGetValue(orig, out var subs))
        {
            foreach (var sub in subs.OrderBy(s => s))
            {
                var display = CategoryHelper.GetSubcategoryDisplay(sub);
                if (!_subcategoryDisplayMap.ContainsKey(display))
                {
                    _subcategoryDisplayMap[display] = sub;
                    Subcategories.Add(display);
                }
            }
        }
        SelectedSubcategory = "Все";
        InstallView.Refresh();
    }

    partial void OnSelectedSubcategoryChanged(string value) => InstallView.Refresh();

    private bool ItemFilter(object obj)
    {
        if (obj is not LibraryItemViewModel item) return false;
        var rec = item.Record;

        if (Suppliers.Count > 0 && SelectedSupplier != Suppliers[0] &&
            !SupplierMatchesFilter(rec.Supplier, SelectedSupplier))
            return false;

        if (SelectedCategory != "Все")
        {
            if (!CategoryHelper.DisplayToOriginal.TryGetValue(SelectedCategory, out var origCat))
                origCat = SelectedCategory;
            if (rec.Category != origCat) return false;
        }

        if (SelectedSubcategory != "Все")
        {
            var origSub = _subcategoryDisplayMap.TryGetValue(SelectedSubcategory, out var s) ? s : SelectedSubcategory;
            if (rec.Subcategory != origSub) return false;
        }

        if (!string.IsNullOrEmpty(SearchText) &&
            rec.Name.IndexOf(SearchText, StringComparison.OrdinalIgnoreCase) < 0) return false;

        if (!ShowActual && !rec.Installed && item.Status == "Актуальна") return false;

        return true;
    }

    private static bool IsDemoVendorSupplier(string? supplier)
    {
        if (string.IsNullOrWhiteSpace(supplier)) return false;

        var normalized = supplier.Trim();
        return normalized.Equals("DemoVendor", StringComparison.OrdinalIgnoreCase);
    }

    private static string GetSupplierFilterDisplay(string supplier) =>
        IsDemoVendorSupplier(supplier) ? "DemoVendor" : supplier.Trim();

    private static bool SupplierMatchesFilter(string supplier, string selectedSupplier)
    {
        if (string.Equals(selectedSupplier, "DemoVendor", StringComparison.OrdinalIgnoreCase))
            return IsDemoVendorSupplier(supplier);

        return string.Equals(
            GetSupplierFilterDisplay(supplier),
            selectedSupplier,
            StringComparison.OrdinalIgnoreCase);
    }

    private static bool IsDashAllBundle(string libraryName) =>
        libraryName.Contains("-ALL", StringComparison.OrdinalIgnoreCase);

    private static bool IsUnderscoreAllBundle(string libraryName) =>
        libraryName.Contains("_ALL", StringComparison.OrdinalIgnoreCase);

    private const string MissingConfigVersion = "01.01.1900 (config.xml не найден)";
    private const string MissingVersion = "01.01.1900 (версия не найдена)";

    private static Dictionary<string, string> FindCsvNamedDirectoriesWithoutConfig(
        string rootPath,
        IEnumerable<string> csvNames,
        IEnumerable<string> alreadyFoundNames)
    {
        var result = new Dictionary<string, string>(StringComparer.OrdinalIgnoreCase);
        var csvSet = new HashSet<string>(csvNames, StringComparer.OrdinalIgnoreCase);
        var foundSet = new HashSet<string>(alreadyFoundNames, StringComparer.OrdinalIgnoreCase);

        try
        {
            foreach (var dir in Directory.EnumerateDirectories(rootPath))
            {
                var name = Path.GetFileName(dir);
                if (string.IsNullOrEmpty(name)) continue;
                if (name.EndsWith("backup", StringComparison.OrdinalIgnoreCase)) continue;
                if (!csvSet.Contains(name) || foundSet.Contains(name)) continue;
                if (File.Exists(Path.Combine(dir, "config.xml"))) continue;

                result.TryAdd(name, dir);
            }
        }
        catch
        {
            // Directory scan can skip inaccessible branches; regular config.xml scan handles the rest.
        }

        return result;
    }

    [RelayCommand]
    private void BrowseFolder() => RequestBrowseFolder?.Invoke(_kind.DisplayName());

    [RelayCommand]
    private void PickShortcut() => RequestPickShortcut?.Invoke(_kind.DisplayName());

    [RelayCommand]
    public async Task ScanAsync()
    {
        if (string.IsNullOrEmpty(LibraryPath) || !Directory.Exists(LibraryPath))
        {
            _log.Log("✗ Укажите корректный путь к библиотекам");
            return;
        }

        if (HasActiveInstalls)
        {
            _log.Log("⚠ Сканирование заблокировано: дождитесь завершения текущих установок");
            return;
        }

        var csvData = _kind == LibraryKind.Implant ? _csv.ImplantData : _csv.ModelData;
        if (csvData.Count == 0)
        {
            _log.Log("✗ Сначала загрузите данные с сервера");
            return;
        }

        IsBusy = true;
        StatusSummary = "Сканирование...";
        _log.Log($"Начато сканирование библиотек для {_kind.DisplayName()}...");

        try
        {
            var rootPath = LibraryPath;
            var found = await Task.Run(() => _scanner.Scan(rootPath));
            var foundDict = found.GroupBy(l => l.Name)
                                 .ToDictionary(g => g.Key, g => g.First(),
                                               StringComparer.OrdinalIgnoreCase);
            var noConfigDirectories = FindCsvNamedDirectoriesWithoutConfig(
                rootPath,
                csvData.Keys,
                foundDict.Keys);

            var installList = new List<LibraryRecord>();
            var updateList = new List<LibraryRecord>();

            // Сканированные библиотеки
            foreach (var lib in found)
            {
                if (lib.Name.EndsWith("backup", StringComparison.OrdinalIgnoreCase)) continue;
                if (IsDashAllBundle(lib.Name)) continue;
                if (lib.Version == "Неизвестно" || string.IsNullOrEmpty(lib.Version)) continue;

                var rec = new LibraryRecord
                {
                    Name = lib.Name,
                    FullPath = lib.FullPath,
                    RelativePath = lib.RelativePath,
                    Version = lib.Version,
                    Supplier = lib.Supplier
                };

                if (csvData.TryGetValue(lib.Name, out var csvEntry))
                {
                    rec.AvailableVersion = csvEntry.Version;
                    rec.DownloadUrl = csvEntry.DownloadUrl;
                    rec.Category = csvEntry.Category;
                    rec.Subcategory = csvEntry.Subcategory;
                    if (rec.Supplier is "Неизвестно" or "?" or "")
                        rec.Supplier = csvEntry.Supplier;
                }

                var isServerAllBundle = IsUnderscoreAllBundle(lib.Name) && csvData.ContainsKey(lib.Name);

                if (IsDemoVendorSupplier(rec.Supplier) || isServerAllBundle)
                    updateList.Add(rec);

                // В установку добавляем демонстрационные записи с устаревшей версией.
                if ((IsDemoVendorSupplier(rec.Supplier) || isServerAllBundle) &&
                    csvData.TryGetValue(lib.Name, out var entry2) &&
                    rec.Version != entry2.Version)
                {
                    var copy = new LibraryRecord
                    {
                        Name = rec.Name,
                        FullPath = rec.FullPath,
                        RelativePath = rec.RelativePath,
                        Version = rec.Version,
                        Supplier = rec.Supplier,
                        AvailableVersion = entry2.Version,
                        DownloadUrl = entry2.DownloadUrl,
                        Category = entry2.Category,
                        Subcategory = entry2.Subcategory
                    };
                    installList.Add(copy);
                }
            }

            // Из CSV добавляем неустановленные демонстрационные записи.
            foreach (var (name, fullPath) in noConfigDirectories)
            {
                if (!csvData.TryGetValue(name, out var entry)) continue;
                if (!IsDemoVendorSupplier(entry.Supplier) && !IsUnderscoreAllBundle(name)) continue;
                if (IsDashAllBundle(name)) continue;
                if (string.IsNullOrEmpty(entry.Version) || entry.Version == "РќРµРёР·РІРµСЃС‚РЅРѕ") continue;

                var relativePath = Path.GetRelativePath(rootPath, fullPath);
                var updateRecord = new LibraryRecord
                {
                    Name = name,
                    FullPath = fullPath,
                    RelativePath = relativePath,
                    Version = MissingConfigVersion,
                    Supplier = entry.Supplier,
                    AvailableVersion = entry.Version,
                    DownloadUrl = entry.DownloadUrl,
                    Category = entry.Category,
                    Subcategory = entry.Subcategory
                };

                updateList.Add(updateRecord);
                installList.Add(new LibraryRecord
                {
                    Name = updateRecord.Name,
                    FullPath = updateRecord.FullPath,
                    RelativePath = updateRecord.RelativePath,
                    Version = updateRecord.Version,
                    Supplier = updateRecord.Supplier,
                    AvailableVersion = updateRecord.AvailableVersion,
                    DownloadUrl = updateRecord.DownloadUrl,
                    Category = updateRecord.Category,
                    Subcategory = updateRecord.Subcategory
                });
            }

            foreach (var (name, entry) in csvData)
            {
                if (foundDict.ContainsKey(name)) continue;
                if (noConfigDirectories.ContainsKey(name)) continue;
                if (!IsDemoVendorSupplier(entry.Supplier) && !IsUnderscoreAllBundle(name)) continue;
                if (IsDashAllBundle(name)) continue;
                if (string.IsNullOrEmpty(entry.Version) || entry.Version == "Неизвестно") continue;

                installList.Add(new LibraryRecord
                {
                    Name = name,
                    FullPath = null,
                    RelativePath = "Не установлена",
                    Version = "Не установлена",
                    Supplier = entry.Supplier,
                    AvailableVersion = entry.Version,
                    DownloadUrl = entry.DownloadUrl,
                    Category = entry.Category,
                    Subcategory = entry.Subcategory
                });
            }

            // Применяем результаты в UI
            UpdateUiAfterScan(installList, updateList);
            _log.Log($"✓ Сканирование для {_kind.DisplayName()} завершено. " +
                     $"К установке: {installList.Count}, к обновлению: {updateList.Count}");
            StatusSummary = $"Найдено к установке: {installList.Count}, к обновлению: {updateList.Count}";
        }
        catch (Exception ex)
        {
            _log.Log($"✗ Ошибка при сканировании: {ex.Message}");
            StatusSummary = "Ошибка сканирования";
        }
        finally
        {
            IsBusy = false;
        }
    }

    private void UpdateUiAfterScan(List<LibraryRecord> install, List<LibraryRecord> update)
    {
        // Suppliers (только из CSV, как в исходнике)
        var suppliers = (_kind == LibraryKind.Implant ? _csv.ImplantSuppliers : _csv.ModelSuppliers)
                        .Select(GetSupplierFilterDisplay)
                        .Distinct(StringComparer.OrdinalIgnoreCase)
                        .OrderBy(s => s).ToList();
        Suppliers.Clear();
        Suppliers.Add("Все");
        foreach (var s in suppliers) Suppliers.Add(s);
        SelectedSupplier = Suppliers[0];

        // Categories
        _categoryToSubcategories.Clear();
        foreach (var rec in install)
        {
            if (rec.Category == null) continue;
            if (!_categoryToSubcategories.TryGetValue(rec.Category, out var set))
                _categoryToSubcategories[rec.Category] = set = new HashSet<string>();
            if (rec.Subcategory != null) set.Add(rec.Subcategory);
        }

        Categories.Clear();
        Categories.Add("Все");
        foreach (var cat in _categoryToSubcategories.Keys.OrderBy(c => c))
            Categories.Add(CategoryHelper.GetCategoryDisplay(cat));
        SelectedCategory = "Все";

        Subcategories.Clear();
        Subcategories.Add("Все");
        SelectedSubcategory = "Все";

        InstallItems.Clear();
        foreach (var rec in install) InstallItems.Add(new LibraryItemViewModel(rec));

        UpdateItems.Clear();
        foreach (var rec in update) UpdateItems.Add(new LibraryItemViewModel(rec));

        InstallView.Refresh();
    }

    public async Task InstallSingleAsync(LibraryItemViewModel item)
    {
        if (item.Installed || item.IsBusy) return;

        // Защита от двойного клика и от повторного запуска после ре-сканирования:
        // одна и та же библиотека не должна устанавливаться параллельно.
        lock (_activeLock)
        {
            if (_activeInstalls.Contains(item.Name))
            {
                _log.Log($"⚠ Установка {item.Name} уже идёт, повторный запуск пропущен");
                return;
            }
            _activeInstalls.Add(item.Name);
        }

        item.IsBusy = true;
        item.Installed = true;
        item.RefreshStatus();
        IsBusy = true;
        StatusSummary = $"Установка {item.Name}...";

        try
        {
            var ok = await _executor.InstallAsync(item.Record, LibraryPath);
            if (ok)
            {
                item.RefreshFromRecord();
                _log.Log("✓ Операции установки завершены");

                // Добавляем в Update-список если ещё нет
                if (!UpdateItems.Any(u => u.Name == item.Name))
                {
                    var copy = new LibraryItemViewModel(item.Record);
                    UpdateItems.Add(copy);
                }
            }
            else
            {
                item.Record.Installed = false;
                item.Installed = false;
                item.Status = "Ошибка";
            }
        }
        catch (Exception ex)
        {
            _log.Log($"✗ Ошибка установки {item.Name}: {ex.Message}");
            item.Record.Installed = false;
            item.Installed = false;
            item.Status = "Ошибка";
        }
        finally
        {
            lock (_activeLock) _activeInstalls.Remove(item.Name);
            item.IsBusy = false;
            IsBusy = HasActiveInstalls;
            StatusSummary = "Установка завершена";
        }
    }

    public async Task UpdateSelectedAsync(IEnumerable<LibraryItemViewModel> selected)
    {
        var toUpdate = selected.Where(i => i.Status == "Требуется обновление").ToList();
        if (toUpdate.Count == 0)
        {
            _log.Log("Нет выбранных библиотек, требующих обновления");
            return;
        }

        IsBusy = true;
        _log.Log($"Начато обновление {toUpdate.Count} библиотек...");
        StatusSummary = $"Обновление {toUpdate.Count} библиотек...";

        foreach (var item in toUpdate)
        {
            lock (_activeLock)
            {
                if (_activeInstalls.Contains(item.Name))
                {
                    _log.Log($"⚠ {item.Name} уже в работе, пропуск");
                    continue;
                }
                _activeInstalls.Add(item.Name);
            }

            item.IsBusy = true;
            try
            {
                var ok = await _executor.UpdateAsync(item.Record, LibraryPath);
                item.RefreshFromRecord();
                if (!ok) item.Status = "Ошибка";
                else item.Status = "Обновлена";
            }
            catch (Exception ex)
            {
                _log.Log($"✗ Ошибка при обновлении {item.Name}: {ex.Message}");
                item.Status = "Ошибка";
            }
            finally
            {
                lock (_activeLock) _activeInstalls.Remove(item.Name);
                item.IsBusy = false;
            }
        }

        _log.Log("\n=== ОБНОВЛЕНИЕ ЗАВЕРШЕНО ===");
        StatusSummary = "Обновление завершено";
        IsBusy = HasActiveInstalls;
        _persistSettings();
    }

    public void SetPath(string path)
    {
        LibraryPath = path;
        PathPicked?.Invoke(path);
    }
}
