using System;
using System.Diagnostics;
using System.IO;
using System.Reflection;
using System.Runtime.InteropServices;
using System.Security.Cryptography;
using System.Net.Http;
using System.Threading.Tasks;
using System.IO.Compression;
using System.Linq;
using System.Text;
using System.Threading;

internal class FoxyClient
{
    [DllImport("kernel32.dll")]
    static extern IntPtr GetConsoleWindow();

    [DllImport("user32.dll")]
    static extern bool ShowWindow(IntPtr hWnd, int nCmdShow);

    [DllImport("user32.dll", CharSet = CharSet.Auto)]
    public static extern int MessageBox(IntPtr hWnd, String text, String caption, uint type);

    private const string UPDATE_URL = "https://github.com/Minosuko/FoxyClient/releases/latest/download/FoxyClient-OTA.zip";
    
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

    private static string AppDir = Path.GetDirectoryName(Assembly.GetEntryAssembly().Location);
    private static string PhpPath = Path.Combine(AppDir, "FoxyClient", "php", "php.exe");
    private static string ScriptPath = Path.Combine(AppDir, "FoxyClient.php");
    private static string SignPath = Path.Combine(AppDir, "FoxyClient.sign");

    // .NET 4.0 compatible entry point
    static void Main(string[] args)
    {
        bool isUpdate = args.Contains("--update");
        bool isUninstall = args.Contains("--uninstall");

        var handle = GetConsoleWindow();

        if (isUninstall)
        {
            ShowWindow(handle, 5); // Show uninstall console
            Console.Title = "FoxyClient Official Uninstaller";
            RunUninstall();
            return;
        }

        if (!isUpdate)
        {
            ShowWindow(handle, 0); // Hide console
            if (!VerifyIntegrity())
            {
                ShowWindow(handle, 5); // Show error console
                MessageBox(IntPtr.Zero, "CRITICAL: RSA Integrity check failed for FoxyClient.php!\n\nSignature in FoxyClient.sign does not match or is invalid.\nPlease run with --update to fix this issue.", "FoxyClient Integrity Error", 0x10);
                Console.WriteLine("CRITICAL: RSA Integrity check failed for FoxyClient.php!");
                Console.WriteLine("Signature in FoxyClient.sign does not match or is invalid.");
                Console.WriteLine("Press any key to exit or run with --update to fix...");
                Console.ReadKey();
                return;
            }
        }
        else
        {
            ShowWindow(handle, 5); // Show update console
            Console.Title = "FoxyClient Official Update Tool";
            RunUpdateTask(args).Wait();
            MessageBox(IntPtr.Zero, "Update completed successfully! FoxyClient is now up to date.", "FoxyClient Update", 0x40);
        }

        LaunchClient();
    }

    private static void RunUninstall()
    {
        var result = MessageBox(IntPtr.Zero, "Are you sure you want to completely remove FoxyClient and all of its components?", "FoxyClient Uninstaller", 0x24); // Yes/No + Question
        if (result != 6) return; // 6 = IDYES

        try
        {
            Console.WriteLine("Terminating any running FoxyClient instances...");
            KillRunningClients();

            Console.WriteLine("Removing system shortcuts...");
            string appName = "FoxyClient";
            string desktopShortcut = Path.Combine(Environment.GetFolderPath(Environment.SpecialFolder.Desktop), appName + ".lnk");
            string startShortcut = Path.Combine(Environment.GetFolderPath(Environment.SpecialFolder.StartMenu), appName + ".lnk");
            
            if (File.Exists(desktopShortcut)) File.Delete(desktopShortcut);
            if (File.Exists(startShortcut)) File.Delete(startShortcut);

            Console.WriteLine("Cleaning Windows Registry...");
            using (var key = Microsoft.Win32.Registry.CurrentUser.OpenSubKey(@"Software\Microsoft\Windows\CurrentVersion\Uninstall", true))
            {
                if (key != null) key.DeleteSubKeyTree(appName, false);
            }

            Console.WriteLine("Scheduling folder deletion...");
            string appDir = AppDomain.CurrentDomain.BaseDirectory;
            string cmd = "/c timeout /t 2 /nobreak && rd /s /q \"" + appDir.TrimEnd('\\') + "\"";
            
            ProcessStartInfo psi = new ProcessStartInfo("cmd.exe", cmd);
            psi.WindowStyle = ProcessWindowStyle.Hidden;
            psi.CreateNoWindow = true;
            Process.Start(psi);

            Console.WriteLine("Uninstallation complete. This window will now close.");
            Thread.Sleep(1000);
            Environment.Exit(0);
        }
        catch (Exception ex)
        {
            MessageBox(IntPtr.Zero, "Uninstallation failed: " + ex.Message, "FoxyClient Error", 0x10);
        }
    }

    private static async Task RunUpdateTask(string[] args)
    {
        await RunUpdate();
    }

    private static bool VerifyIntegrity()
    {
        if (!File.Exists(ScriptPath) || !File.Exists(SignPath)) return false;

        try
        {
            byte[] signature = Convert.FromBase64String(File.ReadAllText(SignPath).Trim());
            byte[] data = File.ReadAllBytes(ScriptPath);

            using (RSACryptoServiceProvider rsa = GetRSAFromPem(PUBLIC_KEY_PEM))
            {
                return rsa.VerifyData(data, "SHA256", signature);
            }
        }
        catch (Exception ex)
        {
            File.WriteAllText(Path.Combine(AppDir, "ERROR.txt"), "RSA Verification Error: " + ex.Message);
            return false;
        }
    }

    // Manual PEM Public Key loading for older .NET Framework
    private static RSACryptoServiceProvider GetRSAFromPem(string pem)
    {
        string base64 = pem.Replace("-----BEGIN PUBLIC KEY-----", "")
                           .Replace("-----END PUBLIC KEY-----", "")
                           .Replace("\r", "").Replace("\n", "").Trim();
        byte[] keyData = Convert.FromBase64String(base64);
        
        // This is a simple SubjectPublicKeyInfo (X.509) parser
        // It skips the ASN.1 header to find the actual RSA public key parts
        var rsa = new RSACryptoServiceProvider();
        rsa.ImportCspBlob(GetPublicKeyBlob(keyData));
        return rsa;
    }

    private static byte[] GetPublicKeyBlob(byte[] keyData)
    {
        // For production, a full ASN.1 parser is better, but since we know the key format:
        // We can use a simpler approach or just use the XML format. 
        // However, to keep it working with the user's PEM:
        using (var ms = new MemoryStream(keyData))
        using (var reader = new BinaryReader(ms))
        {
            // Simplified SubjectPublicKeyInfo parser for .NET compatibility
            // This is a common utility pattern for .NET Framework RSA PEM loading
            byte b = reader.ReadByte();
            if (b != 0x30) return null; // Sequence
            ReadLength(reader);
            
            // Skip AlgorithmIdentifier
            b = reader.ReadByte();
            if (b != 0x30) return null;
            int algLen = ReadLength(reader);
            reader.ReadBytes(algLen);

            // Read PublicKey bitstring
            b = reader.ReadByte();
            if (b != 0x03) return null;
            ReadLength(reader);
            reader.ReadByte(); // Padding bits

            // Now at the actual RSAPublicKey (Sequence of Modulus and Exponent)
            return ExportPublicKeyBlob(reader.ReadBytes((int)(ms.Length - ms.Position)));
        }
    }

    private static int ReadLength(BinaryReader reader)
    {
        int length = reader.ReadByte();
        if (length > 0x80)
        {
            int count = length & 0x7f;
            length = 0;
            for (int i = 0; i < count; i++)
                length = (length << 8) | reader.ReadByte();
        }
        return length;
    }

    private static byte[] ExportPublicKeyBlob(byte[] rsaKey)
    {
        // Wraps the raw RSA key into a Windows CspBlob format for ImportCspBlob
        using (var ms = new MemoryStream(rsaKey))
        using (var reader = new BinaryReader(ms))
        {
            reader.ReadByte(); // Sequence
            ReadLength(reader);
            reader.ReadByte(); // Integer (Modulus)
            int modLen = ReadLength(reader);
            if (reader.PeekChar() == 0x00) { reader.ReadByte(); modLen--; }
            byte[] modulus = reader.ReadBytes(modLen);
            reader.ReadByte(); // Integer (Exponent)
            int expLen = ReadLength(reader);
            byte[] exponent = reader.ReadBytes(expLen);

            using (var outMs = new MemoryStream())
            using (var writer = new BinaryWriter(outMs))
            {
                writer.Write((byte)0x06); // PUBLICKEYBLOB
                writer.Write((byte)0x02); // Version
                writer.Write((short)0x00); // Reserved
                writer.Write((int)0x0000a400); // ALGID (CALG_RSA_KEYX)
                writer.Write(Encoding.ASCII.GetBytes("RSA1")); // Magic
                writer.Write((int)(modulus.Length * 8)); // Bitlen
                writer.Write(exponent.Reverse().ToArray());
                // Pad exponent to 4 bytes if necessary (standard for RSA1 blobs)
                for (int i = 0; i < (4 - exponent.Length); i++) writer.Write((byte)0);
                writer.Write(modulus.Reverse().ToArray());
                return outMs.ToArray();
            }
        }
    }

    private static async Task RunUpdate()
    {
        Console.WriteLine("Terminating any running FoxyClient instances...");
        KillRunningClients();

        Console.WriteLine("Downloading update (FoxyClient.zip)...");
        string zipPath = Path.Combine(AppDir, "update.zip");
        
        using (var client = new HttpClient())
        {
            try
            {
                var zipData = await client.GetByteArrayAsync(UPDATE_URL);
                File.WriteAllBytes(zipPath, zipData);

                Console.WriteLine("Extracting files...");
                if (File.Exists(zipPath))
                {
                    using (ZipArchive archive = ZipFile.OpenRead(zipPath))
                    {
                        foreach (ZipArchiveEntry entry in archive.Entries)
                        {
                            string destinationPath = Path.Combine(AppDir, entry.FullName);
                            string directory = Path.GetDirectoryName(destinationPath);

                            if (!Directory.Exists(directory)) Directory.CreateDirectory(directory);
                            if (!string.IsNullOrEmpty(entry.Name)) entry.ExtractToFile(destinationPath, true);
                        }
                    }
                }

                if (File.Exists(zipPath)) File.Delete(zipPath);
                Console.WriteLine("Cleanup complete.");
            }
            catch (Exception ex)
            {
                Console.WriteLine("Update failed: " + ex.Message);
                File.WriteAllText(Path.Combine(AppDir, "ERROR.txt"), "Update error: " + ex.Message);
                Console.WriteLine("Press any key to exit update...");
                Console.ReadKey();
            }
        }
    }

    private static void KillRunningClients()
    {
        foreach (var process in Process.GetProcessesByName("php"))
        {
            try { process.Kill(); process.WaitForExit(2000); } catch { }
        }
    }

    private static void LaunchClient()
    {
        if (!File.Exists(PhpPath))
        {
            File.WriteAllText(Path.Combine(AppDir, "ERROR.txt"), "PHP executable not found: " + PhpPath);
            return;
        }

        try
        {
            Process.Start(new ProcessStartInfo
            {
                FileName = PhpPath,
                Arguments = "\"" + ScriptPath + "\"",
                UseShellExecute = false,
                CreateNoWindow = true
            });
        }
        catch (Exception ex)
        {
            File.WriteAllText(Path.Combine(AppDir, "ERROR.txt"), "Launch error: " + ex.Message);
        }
    }
}
