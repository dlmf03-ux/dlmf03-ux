using System.Windows;
using System.Windows.Controls;
using LibraryUpdaterDemo.ViewModels;

namespace LibraryUpdaterDemo.Views;

public partial class LibraryTabView : UserControl
{
    public LibraryTabView()
    {
        InitializeComponent();
    }

    private async void InstallCheckBox_OnChecked(object sender, RoutedEventArgs e)
    {
        if (sender is not CheckBox cb) return;
        if (cb.Tag is not LibraryItemViewModel item) return;
        if (DataContext is not LibraryTabViewModel vm) return;

        // защита от повторного запуска при ре-биндинге
        if (item.Installed && cb.IsChecked == true && item.IsBusy == false)
        {
            // Уже установлено — ничего не делаем
            return;
        }

        await vm.InstallSingleAsync(item);
    }

    private async void UpdateSelected_OnClick(object sender, RoutedEventArgs e)
    {
        if (DataContext is not LibraryTabViewModel vm) return;
        var selected = UpdateGrid.SelectedItems
            .OfType<LibraryItemViewModel>()
            .ToList();

        if (selected.Count == 0)
        {
            MessageBox.Show("Выберите библиотеки для обновления",
                "Предупреждение", MessageBoxButton.OK, MessageBoxImage.Warning);
            return;
        }

        var libNames = string.Join("\n", selected.Select(s => s.Name));
        var result = MessageBox.Show(
            $"Будут обновлены следующие библиотеки:\n{libNames}\n\nПродолжить?",
            "Подтверждение", MessageBoxButton.YesNo, MessageBoxImage.Question);

        if (result != MessageBoxResult.Yes) return;

        await vm.UpdateSelectedAsync(selected);
    }

    private async void UpdateAll_OnClick(object sender, RoutedEventArgs e)
    {
        if (DataContext is not LibraryTabViewModel vm) return;

        var needUpdate = vm.UpdateItems
            .Where(i => i.Status == "Требуется обновление")
            .ToList();

        if (needUpdate.Count == 0)
        {
            MessageBox.Show("Нет библиотек, требующих обновления",
                "Информация", MessageBoxButton.OK, MessageBoxImage.Information);
            return;
        }

        var result = MessageBox.Show(
            $"Будут обновлены ВСЕ библиотеки, требующие обновления ({needUpdate.Count} шт.).\n\nПродолжить?",
            "Подтверждение", MessageBoxButton.YesNo, MessageBoxImage.Question);

        if (result != MessageBoxResult.Yes) return;

        await vm.UpdateSelectedAsync(needUpdate);
    }
}
