param(
    [ValidateRange(1, 65535)]
    [int]$Port = 5501
)

$ErrorActionPreference = "Stop"
$backendPython = Join-Path $PSScriptRoot "..\FeelY-Backend\.venv\Scripts\python.exe"

if (Test-Path -LiteralPath $backendPython) {
    $python = $backendPython
} else {
    $python = (Get-Command python -ErrorAction Stop).Source
}

$url = "http://127.0.0.1:$Port/chat.html"
Write-Host "Serving the Bernard website at $url"
Write-Host "Press Ctrl+C to stop it."

& $python -m http.server $Port --bind 127.0.0.1 --directory $PSScriptRoot
