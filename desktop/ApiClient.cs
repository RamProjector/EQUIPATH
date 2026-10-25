using System.Net.Http.Json;

namespace EquiPath.Desktop;

internal sealed class ApiClient : IDisposable
{
    private readonly HttpClient _httpClient;

    public ApiClient(string baseUrl)
    {
        if (!Uri.TryCreate(baseUrl, UriKind.Absolute, out var uri) ||
            (uri.Scheme != Uri.UriSchemeHttp && uri.Scheme != Uri.UriSchemeHttps))
        {
            throw new ArgumentException("API base URL must be an absolute HTTP(S) URL.", nameof(baseUrl));
        }

        _httpClient = new HttpClient
        {
            BaseAddress = new Uri(baseUrl.TrimEnd('/') + "/", UriKind.Absolute),
            Timeout = TimeSpan.FromSeconds(10)
        };
    }

    public async Task<HealthResponse?> GetHealthAsync(CancellationToken cancellationToken = default)
    {
        using var response = await _httpClient.GetAsync("health.php", cancellationToken);
        response.EnsureSuccessStatusCode();
        return await response.Content.ReadFromJsonAsync<HealthResponse>(cancellationToken: cancellationToken);
    }

    public void Dispose() => _httpClient.Dispose();
}

internal sealed record HealthResponse(bool Ok, string Service, string Status, string Database);
