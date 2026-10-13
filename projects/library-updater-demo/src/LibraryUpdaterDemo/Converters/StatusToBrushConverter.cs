using System.Globalization;
using System.Windows;
using System.Windows.Data;
using System.Windows.Media;

namespace LibraryUpdaterDemo.Converters;

public class StatusToBrushConverter : IValueConverter
{
    public object Convert(object? value, Type targetType, object? parameter, CultureInfo culture)
    {
        var s = value as string ?? "";
        return s switch
        {
            "Установлена" => (SolidColorBrush)Application.Current.Resources["BrandMintBrush"],
            "Актуальна" => (SolidColorBrush)Application.Current.Resources["BrandMintBrush"],
            "Требуется обновление" => (SolidColorBrush)Application.Current.Resources["BrandOrangeBrush"],
            "Обновлена" => (SolidColorBrush)Application.Current.Resources["BrandMintBrush"],
            "Ошибка" => (SolidColorBrush)Application.Current.Resources["BrandMagentaBrush"],
            "Не установлена" => (SolidColorBrush)Application.Current.Resources["BrandLightBlueBrush"],
            "Новее доступной" => (SolidColorBrush)Application.Current.Resources["BrandCyanBrush"],
            _ => (SolidColorBrush)Application.Current.Resources["WhiteBrush"]
        };
    }

    public object ConvertBack(object? value, Type targetType, object? parameter, CultureInfo culture)
        => Binding.DoNothing;
}
