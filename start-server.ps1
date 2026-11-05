$root = (Get-Location).Path
$port = 8000
$listener = [System.Net.Sockets.TcpListener]::new([System.Net.IPAddress]::Loopback, $port)
$listener.Start()
Write-Host "ERHTCI Feedback Portal is running at: http://localhost:8000"
Write-Host "WARNING: This serves static files only; PHP and database actions will not work."
Write-Host "Use XAMPP Apache at http://localhost/erhtci/ for the full system."
Write-Host "This static demo is available only on this computer."
Write-Host "Press Ctrl+C to stop the server."

$mimeTypes = @{
  ".html" = "text/html; charset=utf-8"
  ".css" = "text/css; charset=utf-8"
  ".js" = "application/javascript; charset=utf-8"
  ".json" = "application/json; charset=utf-8"
  ".png" = "image/png"
  ".jpg" = "image/jpeg"
  ".jpeg" = "image/jpeg"
  ".gif" = "image/gif"
  ".svg" = "image/svg+xml"
  ".ico" = "image/x-icon"
}

try {
  while ($listener.Server.IsBound) {
    $client = $listener.AcceptTcpClient()
    $stream = $client.GetStream()
    $reader = [System.IO.StreamReader]::new($stream)
    $requestLine = $reader.ReadLine()
    while ($reader.ReadLine()) { }
    $requestParts = $requestLine -split " "
    $relativePath = if ($requestParts.Length -ge 2) { [Uri]::UnescapeDataString($requestParts[1].TrimStart('/')) } else { "" }
    if ([string]::IsNullOrWhiteSpace($relativePath)) { $relativePath = "index.html" }
    $filePath = [System.IO.Path]::GetFullPath((Join-Path $root $relativePath))

    if (-not $filePath.StartsWith($root, [System.StringComparison]::OrdinalIgnoreCase) -or -not (Test-Path -LiteralPath $filePath -PathType Leaf)) {
      $body = [Text.Encoding]::UTF8.GetBytes("Not found")
      $status = "404 Not Found"
      $contentType = "text/plain; charset=utf-8"
    } else {
      $body = [System.IO.File]::ReadAllBytes($filePath)
      $extension = [System.IO.Path]::GetExtension($filePath).ToLowerInvariant()
      $status = "200 OK"
      $contentType = if ($mimeTypes.ContainsKey($extension)) { $mimeTypes[$extension] } else { "application/octet-stream" }
    }

    $header = "HTTP/1.1 $status`r`nContent-Type: $contentType`r`nContent-Length: $($body.Length)`r`nConnection: close`r`n`r`n"
    $headerBytes = [Text.Encoding]::ASCII.GetBytes($header)
    $stream.Write($headerBytes, 0, $headerBytes.Length)
    $stream.Write($body, 0, $body.Length)
    $stream.Close()
    $client.Close()
  }
} finally {
  $listener.Stop()
}
