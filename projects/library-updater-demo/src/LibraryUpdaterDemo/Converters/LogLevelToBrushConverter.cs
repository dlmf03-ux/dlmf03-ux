using System.Globalization;
using System.Windows;
using System.Windows.Data;
using System.Windows.Media;
using LibraryUpdaterDemo.Models;

namespace LibraryUpdaterDemo.Converters;

public class LogLevelToBrushConverter : IValueConverter
{
    public object Convert(object? value, Type targetType, object? parameter, CultureInfo culture)
    {
        if (value is not LogLevel lvl) return Application.Current.Resources["WhiteBrush"];
        return lvl switch
        {
            LogLevel.Error => (SolidColorBrush)Application.Current.Resources["BrandMagentaBrush"],
            LogLevel.Success => (SolidColorBrush)Application.Current.Resources["BrandMintBrush"],
            LogLevel.Warning => (SolidColorBrush)Application.Current.Resources["BrandOrangeBrush"],
            LogLevel.Header => (SolidColorBrush)Application.Current.Resources["BrandCyanBrush"],
            _ => (SolidColorBrush)Application.Current.Resources["BrandLightBlueBrush"]
        };
    }

    public object ConvertBack(object? value, Type targetType, object? parameter, CultureInfo culture)
        => Binding.DoNothing;
}
