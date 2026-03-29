using System;
using System.Diagnostics;
using System.IO;
using System.Reflection;
using System.Runtime.InteropServices;
using System.Security.Cryptography;
using System.Net;
using System.Net.Http;
using System.Threading.Tasks;
using System.IO.Compression;
using System.Linq;
using System.Text;
using System.Threading;
using System.Windows.Forms;
using System.Drawing;

internal class FoxyClient
{
    [DllImport("kernel32.dll")]
    static extern IntPtr GetConsoleWindow();

    [DllImport("user32.dll")]
    static extern bool ShowWindow(IntPtr hWnd, int nCmdShow);

    [DllImport("user32.dll", CharSet = CharSet.Auto)]
    public static extern int MessageBox(IntPtr hWnd, String text, String caption, uint type);

    private const string UPDATE_URL = "https://github.com/Minosuko/FoxyClient/releases/latest/download/FoxyClient_OTA.zip";
    private const int MAX_RETRIES = 3;
    private const int RETRY_DELAY_MS = 2000;

    // PEM Public Key
    private const string PUBLIC_KEY_PEM = @"-----BEGIN PUBLIC KEY-----
MIICIjANBgkqhkiG9w0BAQEFAAOCAg8AMIICCgKCAgEA5MXCJgfGlZzSKurJWeza
tLslQpImwg4/Zp/noOKkvs5uurpPb3TKVCGcV3KiuVAqdyT4KKKdd7n8Ofnks4SH
H0HTlkU4QPAe/0+DflJyLvKnvrfnl47ORpo43JpEOejNhVYWRnDdg/dHEz/mczM2
96MgcKMvm89ChfM/YEPuh5AtuaGUlqcNCXSr4fXPZ8aVvlWqvn8YAJ1yosD3Q0xR
TKwZ37w/8giTESXOf892h9FG7NPS9JS3XtZhoih4l1OTSkjVD4q9cmRIAoofLOUQ
pDu45dpEcLuGpULIm9P0WvfLZ0n9slejnuY1Z5bpUVHuXWdTk7SFzn0y91hz/01W
w8100qa2OjaWNGwxx1gZboxknYBujsm4R1MfdEXFy6yHGHb6FFVuLakdolXmuZZt
bjVUMME/DuwCMX3MSzZY06QaBk1V9lLgZBnPK74zahCn/YArHDWCgdL74LEWiaqR
Ge9sLvUFzof0gag6nRW1YggMAC8Vu360fNifYBnrkJggCwks8TLkKMat9VO2p+cr
/u1K9BcYoKCckX8vUZDFyQEcz28MpqmerqDwcJgIc1ctR8erJk+G2rT7sFWZRYuK
NTDsYVpcjb9mRwnWCMba23ZWBefZjn24hlOwOq/CMmabPGNpkU7r5fM+fxBND0UH
htxZN3kC3BC1pHniNpxcgHUCAwEAAQ==
-----END PUBLIC KEY-----";

    private static readonly string AppDir = Path.GetDirectoryName(Assembly.GetEntryAssembly().Location);
    private static readonly string PhpPath = Path.Combine(AppDir, "FoxyClient", "php", "php.exe");
    private static readonly string ScriptPath = Path.Combine(AppDir, "FoxyClient.php");
    private static readonly string SignPath = Path.Combine(AppDir, "FoxyClient.sign");
    private static readonly string LogPath = Path.Combine(AppDir, "FoxyClient", "logs", "updater.log");

    [STAThread]
    static void Main(string[] args)
    {
        // Force TLS 1.2 for GitHub downloads (critical for .NET 4.0/4.5)
        ServicePointManager.SecurityProtocol = SecurityProtocolType.Tls12 | SecurityProtocolType.Tls11 | SecurityProtocolType.Tls;

        bool isUpdate = args.Contains("--update");
        bool isUninstall = args.Contains("--uninstall");

        var handle = GetConsoleWindow();
        ShowWindow(handle, 0); // Hide console window

        Log("FoxyClient Launcher starting. Args: " + string.Join(" ", args));

        if (isUninstall)
        {
            RunUninstallMode();
            return;
        }

        if (isUpdate)
        {
            bool updateOk = RunUpdateMode();
            if (!updateOk)
            {
                // Update failed — don't launch
                return;
            }
        }

        // Normal launch: verify integrity then start PHP client
        if (!VerifyIntegrity())
        {
            ShowWindow(handle, 5);
            Log("CRITICAL: RSA integrity check failed.");
            MessageBox(IntPtr.Zero,
                "CRITICAL: RSA Integrity check failed for FoxyClient.php!\n\n" +
                "Signature in FoxyClient.sign does not match or is invalid.\n" +
                "Please run with --update to fix this issue.",
                "FoxyClient Integrity Error", 0x10);
            return;
        }

        LaunchClient();
    }

    #region Update Mode

    private static bool RunUpdateMode()
    {
        Application.EnableVisualStyles();
        Application.SetCompatibleTextRenderingDefault(false);

        bool success = false;
        var form = new ProgressForm("FoxyClient Update Tool");

        form.Shown += async (s, e) =>
        {
            success = await RunUpdateAsync(form);
            form.Close();
        };

        Application.Run(form);

        if (success)
        {
            MessageBox(IntPtr.Zero,
                "Update completed successfully! FoxyClient is now up to date.",
                "FoxyClient Update", 0x40);
        }

        return success;
    }

    private static async Task<bool> RunUpdateAsync(ProgressForm form)
    {
        Action<string, int> setStatus = (text, percent) =>
        {
            if (form.InvokeRequired)
                form.BeginInvoke(new Action(() => { form.StatusLabel.Text = text; form.ProgressBar.Value = Math.Min(percent, 100); }));
            else
            {
                form.StatusLabel.Text = text;
                form.ProgressBar.Value = Math.Min(percent, 100);
            }
        };

        string zipPath = Path.Combine(AppDir, "update_" + DateTime.Now.Ticks + ".zip");

        try
        {
            // Step 1: Kill running PHP processes
            setStatus("Terminating any running FoxyClient instances...", 5);
            KillRunningClients();
            await Task.Delay(500);

            // Step 2: Download update with retry and progress
            byte[] zipData = null;
            for (int attempt = 1; attempt <= MAX_RETRIES; attempt++)
            {
                bool shouldRetry = false;

                try
                {
                    setStatus(string.Format("Downloading update (attempt {0}/{1})...", attempt, MAX_RETRIES), 10);
                    Log("Download attempt " + attempt + " from: " + UPDATE_URL);

                    zipData = await DownloadWithProgressAsync(UPDATE_URL, (downloaded, total) =>
                    {
                        if (total > 0)
                        {
                            int pct = (int)(10 + (downloaded * 50.0 / total));
                            string size = FormatBytes(downloaded) + " / " + FormatBytes(total);
                            setStatus("Downloading: " + size, pct);
                        }
                        else
                        {
                            setStatus("Downloading: " + FormatBytes(downloaded), 30);
                        }
                    });

                    if (zipData != null && zipData.Length > 0)
                    {
                        Log("Download complete: " + FormatBytes(zipData.Length));
                        break;
                    }
                }
                catch (Exception ex)
                {
                    Log("Download attempt " + attempt + " failed: " + ex.Message);
                    if (attempt < MAX_RETRIES)
                    {
                        shouldRetry = true;
                    }
                }

                if (shouldRetry)
                {
                    setStatus(string.Format("Download failed, retrying in {0}s...", RETRY_DELAY_MS / 1000), 10);
                    await Task.Delay(RETRY_DELAY_MS);
                }
            }

            if (zipData == null || zipData.Length == 0)
            {
                string msg = "Failed to download update after " + MAX_RETRIES + " attempts.";
                Log("ERROR: " + msg);
                MessageBox(IntPtr.Zero, msg, "FoxyClient Update Error", 0x10);
                return false;
            }

            // Step 3: Write zip to disk
            setStatus("Saving update package...", 62);
            File.WriteAllBytes(zipPath, zipData);

            // Step 4: Extract files
            setStatus("Extracting update files...", 65);
            Log("Extracting to: " + AppDir);

            int entryCount = 0;
            int totalEntries = 0;

            using (ZipArchive archive = ZipFile.OpenRead(zipPath))
            {
                totalEntries = archive.Entries.Count;
                foreach (ZipArchiveEntry entry in archive.Entries)
                {
                    entryCount++;
                    string destinationPath = Path.Combine(AppDir, entry.FullName);

                    // Security: prevent path traversal
                    string fullDest = Path.GetFullPath(destinationPath);
                    if (!fullDest.StartsWith(Path.GetFullPath(AppDir), StringComparison.OrdinalIgnoreCase))
                    {
                        Log("WARNING: Skipping entry with path traversal: " + entry.FullName);
                        continue;
                    }

                    string directory = Path.GetDirectoryName(destinationPath);
                    if (!string.IsNullOrEmpty(directory) && !Directory.Exists(directory))
                        Directory.CreateDirectory(directory);

                    if (!string.IsNullOrEmpty(entry.Name))
                    {
                        int pct = (int)(65 + (entryCount * 20.0 / totalEntries));
                        setStatus(string.Format("Extracting ({0}/{1}): {2}", entryCount, totalEntries, entry.Name), pct);
                        entry.ExtractToFile(destinationPath, true);
                    }
                }
            }

            Log("Extracted " + entryCount + " entries.");

            // Step 5: Cleanup
            setStatus("Cleaning up...", 88);
            if (File.Exists(zipPath))
            {
                File.Delete(zipPath);
                Log("Deleted temp zip: " + zipPath);
            }

            // Step 6: Verify integrity after update
            setStatus("Verifying update integrity...", 92);
            if (!VerifyIntegrity())
            {
                string msg = "Update extracted but RSA integrity verification failed!\n" +
                             "The downloaded files may be corrupted.\n" +
                             "Please try updating again.";
                Log("ERROR: Post-update integrity check failed.");
                MessageBox(IntPtr.Zero, msg, "FoxyClient Update Error", 0x10);
                return false;
            }

            Log("Post-update integrity check passed.");
            setStatus("Update complete!", 100);
            await Task.Delay(800);
            return true;
        }
        catch (Exception ex)
        {
            Log("Update error: " + ex.ToString());
            MessageBox(IntPtr.Zero, "Update failed: " + ex.Message, "FoxyClient Update Error", 0x10);

            // Cleanup on failure
            try { if (File.Exists(zipPath)) File.Delete(zipPath); } catch { }

            return false;
        }
    }

    private static async Task<byte[]> DownloadWithProgressAsync(string url, Action<long, long> onProgress)
    {
        using (var client = new HttpClient())
        {
            client.Timeout = TimeSpan.FromMinutes(5);
            client.DefaultRequestHeaders.Add("User-Agent", "FoxyClient-Updater/1.0");

            using (var response = await client.GetAsync(url, HttpCompletionOption.ResponseHeadersRead))
            {
                response.EnsureSuccessStatusCode();

                long totalBytes = response.Content.Headers.ContentLength ?? -1;

                using (var contentStream = await response.Content.ReadAsStreamAsync())
                using (var memStream = new MemoryStream())
                {
                    byte[] buffer = new byte[65536]; // 64KB buffer
                    long totalRead = 0;
                    int bytesRead;
                    DateTime lastReport = DateTime.MinValue;

                    while ((bytesRead = await contentStream.ReadAsync(buffer, 0, buffer.Length)) > 0)
                    {
                        await memStream.WriteAsync(buffer, 0, bytesRead);
                        totalRead += bytesRead;

                        // Throttle progress reports to avoid UI spam
                        if ((DateTime.Now - lastReport).TotalMilliseconds > 100)
                        {
                            onProgress(totalRead, totalBytes);
                            lastReport = DateTime.Now;
                        }
                    }

                    onProgress(totalRead, totalBytes > 0 ? totalBytes : totalRead);
                    return memStream.ToArray();
                }
            }
        }
    }

    #endregion

    #region Uninstall Mode

    private static void RunUninstallMode()
    {
        var result = MessageBox(IntPtr.Zero,
            "Are you sure you want to completely remove FoxyClient and all of its components?",
            "FoxyClient Uninstaller", 0x24); // Yes/No + Question icon

        if (result != 6) // IDYES
            return;

        Application.EnableVisualStyles();
        Application.SetCompatibleTextRenderingDefault(false);
        var form = new ProgressForm("FoxyClient Uninstaller");
        form.Shown += async (s, e) =>
        {
            await RunUninstallAsync(form);
            form.Close();
        };
        Application.Run(form);
    }

    private static async Task RunUninstallAsync(ProgressForm form)
    {
        Action<string, int> setStatus = (text, percent) =>
        {
            if (form.InvokeRequired)
                form.BeginInvoke(new Action(() => { form.StatusLabel.Text = text; form.ProgressBar.Value = percent; }));
            else
            {
                form.StatusLabel.Text = text;
                form.ProgressBar.Value = percent;
            }
        };

        try
        {
            setStatus("Terminating any running FoxyClient instances...", 20);
            KillRunningClients();
            await Task.Delay(500);

            setStatus("Removing system shortcuts...", 40);
            string appName = "FoxyClient";
            string desktopShortcut = Path.Combine(
                Environment.GetFolderPath(Environment.SpecialFolder.Desktop), appName + ".lnk");
            string startShortcut = Path.Combine(
                Environment.GetFolderPath(Environment.SpecialFolder.StartMenu), appName + ".lnk");

            if (File.Exists(desktopShortcut)) File.Delete(desktopShortcut);
            if (File.Exists(startShortcut)) File.Delete(startShortcut);

            setStatus("Cleaning Windows Registry...", 60);
            try
            {
                using (var key = Microsoft.Win32.Registry.CurrentUser.OpenSubKey(
                    @"Software\Microsoft\Windows\CurrentVersion\Uninstall", true))
                {
                    if (key != null) key.DeleteSubKeyTree(appName, false);
                }
            }
            catch (Exception ex)
            {
                Log("Registry cleanup warning: " + ex.Message);
            }

            setStatus("Scheduling folder deletion...", 80);
            string appDir = AppDomain.CurrentDomain.BaseDirectory;
            string cmd = "/c timeout /t 2 /nobreak && rd /s /q \"" + appDir.TrimEnd('\\') + "\"";

            Process.Start(new ProcessStartInfo("cmd.exe", cmd)
            {
                WindowStyle = ProcessWindowStyle.Hidden,
                CreateNoWindow = true
            });

            setStatus("Uninstallation complete. This window will now close.", 100);
            await Task.Delay(1500);
            Environment.Exit(0);
        }
        catch (Exception ex)
        {
            MessageBox(IntPtr.Zero, "Uninstallation failed: " + ex.Message, "FoxyClient Error", 0x10);
        }
    }

    #endregion

    #region RSA Integrity Verification

    private static bool VerifyIntegrity()
    {
        if (!File.Exists(ScriptPath))
        {
            Log("Integrity check: FoxyClient.php not found at " + ScriptPath);
            return false;
        }
        if (!File.Exists(SignPath))
        {
            Log("Integrity check: FoxyClient.sign not found at " + SignPath);
            return false;
        }

        try
        {
            string signatureBase64 = File.ReadAllText(SignPath).Trim();
            if (string.IsNullOrEmpty(signatureBase64))
            {
                Log("Integrity check: Signature file is empty.");
                return false;
            }

            byte[] signature = Convert.FromBase64String(signatureBase64);
            byte[] data = File.ReadAllBytes(ScriptPath);

            Log("Verifying: script=" + data.Length + " bytes, signature=" + signature.Length + " bytes");

            using (var rsa = DecodePublicKeyPem(PUBLIC_KEY_PEM))
            {
                bool valid = rsa.VerifyData(data, CryptoConfig.MapNameToOID("SHA256"), signature);
                Log("Integrity check result: " + (valid ? "PASS" : "FAIL"));
                return valid;
            }
        }
        catch (Exception ex)
        {
            Log("Integrity check error: " + ex.Message);
            try { File.WriteAllText(Path.Combine(AppDir, "ERROR.txt"), "RSA Verification Error: " + ex.ToString()); } catch { }
            return false;
        }
    }

    /// <summary>
    /// Decodes a PEM-encoded SubjectPublicKeyInfo (X.509) RSA public key.
    /// Parses the ASN.1 DER structure to extract modulus and exponent,
    /// then imports them into RSACryptoServiceProvider.
    /// </summary>
    private static RSACryptoServiceProvider DecodePublicKeyPem(string pem)
    {
        // Strip PEM headers and whitespace to get raw Base64
        string base64 = pem
            .Replace("-----BEGIN PUBLIC KEY-----", "")
            .Replace("-----END PUBLIC KEY-----", "")
            .Replace("\r", "").Replace("\n", "").Replace(" ", "").Trim();

        byte[] der = Convert.FromBase64String(base64);

        // Parse SubjectPublicKeyInfo ASN.1 structure:
        //   SEQUENCE {
        //     SEQUENCE { algorithm OID, parameters }
        //     BIT STRING { RSAPublicKey SEQUENCE { modulus INTEGER, exponent INTEGER } }
        //   }
        int offset = 0;

        // Outer SEQUENCE
        if (der[offset++] != 0x30)
            throw new CryptographicException("Invalid SubjectPublicKeyInfo: expected SEQUENCE");
        ReadDerLength(der, ref offset);

        // AlgorithmIdentifier SEQUENCE — skip it entirely
        if (der[offset++] != 0x30)
            throw new CryptographicException("Invalid AlgorithmIdentifier: expected SEQUENCE");
        int algLen = ReadDerLength(der, ref offset);
        offset += algLen;

        // BIT STRING containing RSAPublicKey
        if (der[offset++] != 0x03)
            throw new CryptographicException("Invalid SubjectPublicKeyInfo: expected BIT STRING");
        ReadDerLength(der, ref offset);
        byte paddingBits = der[offset++]; // Should be 0 for key data
        if (paddingBits != 0)
            throw new CryptographicException("Unexpected padding bits in BIT STRING: " + paddingBits);

        // RSAPublicKey SEQUENCE
        if (der[offset++] != 0x30)
            throw new CryptographicException("Invalid RSAPublicKey: expected SEQUENCE");
        ReadDerLength(der, ref offset);

        // Modulus INTEGER
        byte[] modulus = ReadDerInteger(der, ref offset);

        // Exponent INTEGER
        byte[] exponent = ReadDerInteger(der, ref offset);

        // Import into RSA
        var rsa = new RSACryptoServiceProvider();
        rsa.ImportParameters(new RSAParameters
        {
            Modulus = modulus,
            Exponent = exponent
        });

        return rsa;
    }

    /// <summary>
    /// Reads an ASN.1 DER length value (supports short and long forms).
    /// </summary>
    private static int ReadDerLength(byte[] data, ref int offset)
    {
        int length = data[offset++];
        if ((length & 0x80) != 0)
        {
            int numBytes = length & 0x7F;
            if (numBytes > 4)
                throw new CryptographicException("ASN.1 length too large: " + numBytes + " bytes");
            length = 0;
            for (int i = 0; i < numBytes; i++)
                length = (length << 8) | data[offset++];
        }
        return length;
    }

    /// <summary>
    /// Reads an ASN.1 DER INTEGER, strips leading zero padding byte if present.
    /// </summary>
    private static byte[] ReadDerInteger(byte[] data, ref int offset)
    {
        if (data[offset++] != 0x02)
            throw new CryptographicException("Expected ASN.1 INTEGER tag (0x02)");

        int length = ReadDerLength(data, ref offset);

        // Skip leading 0x00 padding (used to keep the integer positive in ASN.1)
        if (length > 1 && data[offset] == 0x00)
        {
            offset++;
            length--;
        }

        byte[] value = new byte[length];
        Array.Copy(data, offset, value, 0, length);
        offset += length;
        return value;
    }

    #endregion

    #region Launch & Utilities

    private static void KillRunningClients()
    {
        try
        {
            foreach (var process in Process.GetProcessesByName("php"))
            {
                try
                {
                    // Only kill PHP processes in our directory
                    string procPath = null;
                    try { procPath = process.MainModule.FileName; } catch { }

                    if (procPath == null || procPath.StartsWith(AppDir, StringComparison.OrdinalIgnoreCase))
                    {
                        Log("Killing PHP process: PID " + process.Id);
                        process.Kill();
                        process.WaitForExit(3000);
                    }
                }
                catch { }
            }
        }
        catch (Exception ex)
        {
            Log("KillRunningClients warning: " + ex.Message);
        }
    }

    private static void LaunchClient()
    {
        if (!File.Exists(PhpPath))
        {
            string msg = "PHP executable not found: " + PhpPath;
            Log("ERROR: " + msg);
            MessageBox(IntPtr.Zero,
                "Cannot start FoxyClient: PHP runtime not found.\n\n" +
                "Expected at: " + PhpPath + "\n\n" +
                "Please run with --update to download required components.",
                "FoxyClient Launch Error", 0x10);
            return;
        }

        if (!File.Exists(ScriptPath))
        {
            string msg = "FoxyClient.php not found: " + ScriptPath;
            Log("ERROR: " + msg);
            MessageBox(IntPtr.Zero,
                "Cannot start FoxyClient: Main script not found.\n\n" +
                "Expected at: " + ScriptPath + "\n\n" +
                "Please run with --update to fix this.",
                "FoxyClient Launch Error", 0x10);
            return;
        }

        try
        {
            Log("Launching PHP client: " + PhpPath + " \"" + ScriptPath + "\"");
            Process.Start(new ProcessStartInfo
            {
                FileName = PhpPath,
                Arguments = "\"" + ScriptPath + "\"",
                WorkingDirectory = AppDir,
                UseShellExecute = false,
                CreateNoWindow = true
            });
        }
        catch (Exception ex)
        {
            Log("Launch error: " + ex.Message);
            MessageBox(IntPtr.Zero,
                "Failed to launch FoxyClient:\n\n" + ex.Message,
                "FoxyClient Launch Error", 0x10);
        }
    }

    private static string FormatBytes(long bytes)
    {
        if (bytes < 1024) return bytes + " B";
        if (bytes < 1024 * 1024) return (bytes / 1024.0).ToString("F1") + " KB";
        return (bytes / (1024.0 * 1024.0)).ToString("F1") + " MB";
    }

    private static void Log(string message)
    {
        try
        {
            string dir = Path.GetDirectoryName(LogPath);
            if (!string.IsNullOrEmpty(dir) && !Directory.Exists(dir))
                Directory.CreateDirectory(dir);

            string line = DateTime.Now.ToString("yyyy-MM-dd HH:mm:ss") + " " + message + Environment.NewLine;
            File.AppendAllText(LogPath, line);
        }
        catch { }
    }

    #endregion
}

public class ProgressForm : Form
{
    public Label StatusLabel;
    public ProgressBar ProgressBar;

    public ProgressForm(string title)
    {
        this.Text = title;
        this.Size = new Size(480, 160);
        this.StartPosition = FormStartPosition.CenterScreen;
        this.FormBorderStyle = FormBorderStyle.FixedDialog;
        this.MaximizeBox = false;
        this.MinimizeBox = false;
        this.Icon = SystemIcons.Application;

        StatusLabel = new Label();
        StatusLabel.Location = new Point(20, 20);
        StatusLabel.Size = new Size(420, 35);
        StatusLabel.Text = "Initializing...";
        StatusLabel.Font = new Font("Segoe UI", 9);
        this.Controls.Add(StatusLabel);

        ProgressBar = new ProgressBar();
        ProgressBar.Location = new Point(20, 65);
        ProgressBar.Size = new Size(420, 28);
        ProgressBar.Style = ProgressBarStyle.Continuous;
        this.Controls.Add(ProgressBar);
    }
}
