using System;
using System.Diagnostics;
using System.IO;
using System.Reflection;
using System.Runtime.InteropServices;

internal class FoxyClient
{
	[DllImport("kernel32.dll")]
	static extern IntPtr GetConsoleWindow();
	[DllImport("user32.dll")]
	static extern bool ShowWindow(IntPtr hWnd, int nCmdShow);
	private static void Main(string[] args)
	{
		Console.Title = "FoxyClient_ASA (Application Startup App)";
		var handle = GetConsoleWindow();
		// ShowWindow(handle, 0);
		try
		{
			Process process = new Process
			{
				StartInfo = new ProcessStartInfo
				{
					FileName = Path.GetDirectoryName(Assembly.GetEntryAssembly().Location) + "\\FoxyClient\\php\\php.exe",
					Arguments = "\"" + Path.GetDirectoryName(Assembly.GetEntryAssembly().Location) + "\\FoxyClient.php\"",
					UseShellExecute = false,
					RedirectStandardOutput = true,
					CreateNoWindow = true
				}
			};
			process.Start();
			process.WaitForExit();
		}
		catch (Exception ex)
		{
			File.WriteAllText(Path.GetDirectoryName(Assembly.GetEntryAssembly().Location) + "\\ERROR.txt", ex.Message);
		}
	}
}
