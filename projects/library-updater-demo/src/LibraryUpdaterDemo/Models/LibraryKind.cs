namespace LibraryUpdaterDemo.Models;

public enum LibraryKind
{
    Implant,
    Model
}

public static class LibraryKindExtensions
{
    public static string DisplayName(this LibraryKind kind) => kind switch
    {
        LibraryKind.Implant => "Основания",
        LibraryKind.Model => "3D аналоги",
        _ => kind.ToString()
    };
}
