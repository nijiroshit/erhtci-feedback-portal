$ErrorActionPreference = "Stop"

$php = "C:\xampp\php\php.exe"
$setupScript = Join-Path $PSScriptRoot "create-admin.php"
if (-not (Test-Path -LiteralPath $php -PathType Leaf)) {
  throw "Could not find XAMPP PHP at $php. Update the php path in create-admin.ps1."
}
if (-not (Test-Path -LiteralPath $setupScript -PathType Leaf)) {
  throw "Could not find create-admin.php beside this script."
}

$name = Read-Host "Admin full name"
$email = Read-Host "Admin email"
$securePassword = Read-Host "Admin password (at least 12 characters; maximum 72 UTF-8 bytes)" -AsSecureString
$passwordPointer = [IntPtr]::Zero

try {
  $passwordPointer = [Runtime.InteropServices.Marshal]::SecureStringToBSTR($securePassword)
  $password = [Runtime.InteropServices.Marshal]::PtrToStringBSTR($passwordPointer)
  $payload = [ordered]@{
    name = $name
    email = $email
    password = $password
  } | ConvertTo-Json -Compress

  $startInfo = New-Object System.Diagnostics.ProcessStartInfo
  $startInfo.FileName = $php
  $startInfo.Arguments = '"' + $setupScript + '"'
  $startInfo.UseShellExecute = $false
  $startInfo.CreateNoWindow = $true
  $startInfo.RedirectStandardInput = $true
  $startInfo.RedirectStandardOutput = $true
  $startInfo.RedirectStandardError = $true
  $process = New-Object System.Diagnostics.Process
  $process.StartInfo = $startInfo
  [void]$process.Start()

  $bytes = [System.Text.Encoding]::UTF8.GetBytes($payload)
  $process.StandardInput.BaseStream.Write($bytes, 0, $bytes.Length)
  $process.StandardInput.Close()
  $stdout = $process.StandardOutput.ReadToEnd()
  $stderr = $process.StandardError.ReadToEnd()
  $process.WaitForExit()

  if ($stdout) { Write-Output $stdout.TrimEnd() }
  if ($stderr) { Write-Error $stderr.TrimEnd() }
  if ($process.ExitCode -ne 0) {
    throw "Admin setup did not complete."
  }
} finally {
  if ($passwordPointer -ne [IntPtr]::Zero) {
    [Runtime.InteropServices.Marshal]::ZeroFreeBSTR($passwordPointer)
  }
  $password = $null
  $payload = $null
}
