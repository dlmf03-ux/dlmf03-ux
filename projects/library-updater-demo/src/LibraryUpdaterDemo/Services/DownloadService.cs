using System.IO;
using System.Net;
using System.Net.Http;
using System.Net.Http.Headers;

namespace LibraryUpdaterDemo.Services;

public class DownloadService
{
    private readonly LogService _log;
    public DownloadService(LogService log) => _log = log;

    public async Task<bool> DownloadFileAsync(string url, string destPath, int maxRetries = 3, CancellationToken ct = default)
    {
        for (var attempt = 1; attempt <= maxRetries; attempt++)
        {
            try
            {
                _log.Log($"Попытка загрузки {attempt}/{maxRetries}...");
                if (await TryDownloadAsync(url, destPath, ct))
                    return true;
            }
            catch (Exception ex)
            {
                _log.Log($"Ошибка при загрузке: {ex.Message}");
                if (attempt < maxRetries)
                {
                    _log.Log("Повторная попытка через 2 секунды...");
                    await Task.Delay(2000, ct);
                }
            }
        }
        return false;
    }

    private async Task<bool> TryDownloadAsync(string url, string destPath, CancellationToken ct)
    {
        var cookies = new CookieContainer();
        using var handler = new HttpClientHandler
        {
            CookieContainer = cookies,
            AllowAutoRedirect = true
        };
        // Большой общий timeout на крупные RAR через медленный канал.
        // Реальная защита от зависшего соединения — inactivity-timeout ниже.
        using var client = new HttpClient(handler)
        {
            Timeout = TimeSpan.FromMinutes(10)
        };
        client.DefaultRequestHeaders.UserAgent.ParseAdd(
            "Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/91.0.4472.124 Safari/537.36");
        client.DefaultRequestHeaders.Accept.ParseAdd("*/*");
        client.DefaultRequestHeaders.AcceptLanguage.ParseAdd("ru-RU,ru;q=0.9,en-US;q=0.8,en;q=0.7");
        client.DefaultRequestHeaders.Referrer = new Uri("https://example.invalid/");
        client.DefaultRequestHeaders.Connection.ParseAdd("keep-alive");

        // прогрев — установить cookies
        try { await client.GetAsync("https://example.invalid", HttpCompletionOption.ResponseHeadersRead, ct); }
        catch { /* ignore */ }

        using var response = await client.GetAsync(url, HttpCompletionOption.ResponseHeadersRead, ct);
        _log.Log($"Статус ответа: {(int)response.StatusCode}");

        if (!response.IsSuccessStatusCode)
        {
            _log.Log($"Ошибка HTTP: {(int)response.StatusCode}");
            return false;
        }

        var contentType = response.Content.Headers.ContentType?.MediaType ?? "";
        var contentLength = response.Content.Headers.ContentLength;
        _log.Log($"Content-Type: {contentType}" +
                 (contentLength.HasValue ? $", размер: {contentLength.Value / 1024.0 / 1024.0:F2} MB" : ""));

        if (contentType.Contains("text/html", StringComparison.OrdinalIgnoreCase))
        {
            // Некоторые источники могут возвращать страницу проверки вместо архива.
            using var preview = await response.Content.ReadAsStreamAsync(ct);
            var buf = new byte[200];
            var read = await preview.ReadAsync(buf, ct);
            var s = System.Text.Encoding.ASCII.GetString(buf, 0, read).ToLowerInvariant();
            if (s.Contains("<!doctype html") || s.Contains("<html"))
            {
                _log.Log("Сервер вернул HTML страницу вместо файла");
                return false;
            }
        }

        // Стрим с прогрессом + inactivity-timeout (30 сек без новых данных → ретрай)
        const int bufferSize = 64 * 1024;
        const int inactivityMs = 30_000;
        var buffer = new byte[bufferSize];
        long total = 0;
        long lastLogged = 0;

        await using var sourceStream = await response.Content.ReadAsStreamAsync(ct);
        await using (var fs = File.Create(destPath))
        {
            while (true)
            {
                using var inactivityCts = CancellationTokenSource.CreateLinkedTokenSource(ct);
                inactivityCts.CancelAfter(inactivityMs);

                int read;
                try
                {
                    read = await sourceStream.ReadAsync(buffer.AsMemory(0, bufferSize), inactivityCts.Token);
                }
                catch (OperationCanceledException) when (!ct.IsCancellationRequested)
                {
                    _log.Log($"⚠ Соединение зависло (нет данных {inactivityMs / 1000} сек), прерываем");
                    return false;
                }

                if (read == 0) break;

                await fs.WriteAsync(buffer.AsMemory(0, read), ct);
                total += read;

                // Лог прогресса каждые 2 МБ или 25%, чтобы не спамить
                if (total - lastLogged >= 2 * 1024 * 1024)
                {
                    if (contentLength.HasValue && contentLength.Value > 0)
                    {
                        var pct = total * 100.0 / contentLength.Value;
                        _log.Log($"Скачано {total / 1024.0 / 1024.0:F1} MB ({pct:F0}%)");
                    }
                    else
                    {
                        _log.Log($"Скачано {total / 1024.0 / 1024.0:F1} MB");
                    }
                    lastLogged = total;
                }
            }
        }

        if (!File.Exists(destPath))
        {
            _log.Log("Файл не был создан");
            return false;
        }

        var size = new FileInfo(destPath).Length;
        if (size <= 0)
        {
            _log.Log("Загружен пустой файл");
            return false;
        }

        // быстрая проверка что это RAR
        await using (var check = File.OpenRead(destPath))
        {
            var head = new byte[5];
            var n = await check.ReadAsync(head, ct);
            if (n >= 3 && (head[0] == 'R' && head[1] == 'a' && head[2] == 'r' || head[0] == 'R' && head[1] == '!'))
                _log.Log("✓ Файл является RAR архивом");
            else
                _log.Log("⚠ Предупреждение: файл не является RAR архивом");
        }

        _log.Log($"✓ Файл успешно загружен. Размер: {size / 1024.0 / 1024.0:F2} MB");
        return true;
    }
}
