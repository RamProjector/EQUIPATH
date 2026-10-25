namespace EquiPath.Desktop;

internal sealed class MainForm : Form
{
    private readonly Label _status = new();
    private readonly Button _checkButton = new();
    private readonly ApiClient _api = new("http://localhost/equipath/api/");

    public MainForm()
    {
        Text = "EquiPath | ADFC ETEEAP";
        StartPosition = FormStartPosition.CenterScreen;
        MinimumSize = new Size(640, 420);
        Size = new Size(820, 520);
        BackColor = Color.FromArgb(245, 248, 250);
        Font = new Font("Segoe UI", 10F);

        var heading = new Label
        {
            Text = "EquiPath",
            Font = new Font("Segoe UI", 26F, FontStyle.Bold),
            ForeColor = Color.FromArgb(23, 43, 58),
            AutoSize = true,
            Location = new Point(36, 34)
        };

        var subtitle = new Label
        {
            Text = "ADFC · ETEEAP application and accreditation portal",
            ForeColor = Color.FromArgb(97, 115, 130),
            AutoSize = true,
            Location = new Point(40, 86)
        };

        _status.Text = "API status has not been checked.";
        _status.AutoSize = true;
        _status.MaximumSize = new Size(700, 0);
        _status.Location = new Point(40, 150);

        _checkButton.Text = "Check PHP API";
        _checkButton.AutoSize = true;
        _checkButton.Padding = new Padding(12, 7, 12, 7);
        _checkButton.Location = new Point(40, 195);
        _checkButton.Click += async (_, _) => await CheckApiAsync();

        Controls.AddRange([heading, subtitle, _status, _checkButton]);
        FormClosed += (_, _) => _api.Dispose();
    }

    private async Task CheckApiAsync()
    {
        _checkButton.Enabled = false;
        _status.Text = "Checking API…";

        try
        {
            var result = await _api.GetHealthAsync();
            _status.Text = result is { Ok: true }
                ? $"API reachable: {result.Service} · status: {result.Status} · database: {result.Database}"
                : "API responded, but the response was not recognized.";
        }
        catch (HttpRequestException)
        {
            _status.Text = "Could not reach the PHP API. Check that Apache is running and the API URL is correct.";
        }
        catch (TaskCanceledException)
        {
            _status.Text = "The API request timed out. Check Apache and try again.";
        }
        catch (Exception)
        {
            _status.Text = "An unexpected error occurred while checking the API.";
        }
        finally
        {
            _checkButton.Enabled = true;
        }
    }
}
