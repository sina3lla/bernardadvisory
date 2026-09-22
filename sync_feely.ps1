param(
  [string]$SourceDirectory = "$(Join-Path $PSScriptRoot '..\FeelY-Backend\website')"
)

Set-StrictMode -Version Latest
$ErrorActionPreference = "Stop"

$source = (Resolve-Path -LiteralPath $SourceDirectory).Path
$target = Join-Path $PSScriptRoot "feely"
$assetTarget = Join-Path $target "assets"
$requiredFiles = @(
  "chat.html",
  "app.js",
  "styles.css",
  "assets\feely-landscape.jpg"
)

foreach ($relativePath in $requiredFiles) {
  $sourcePath = Join-Path $source $relativePath
  if (-not (Test-Path -LiteralPath $sourcePath -PathType Leaf)) {
    throw "Missing FeelY source file: $sourcePath"
  }
}

New-Item -ItemType Directory -Path $assetTarget -Force | Out-Null
foreach ($relativePath in $requiredFiles) {
  $sourcePath = Join-Path $source $relativePath
  $destinationPath = Join-Path $target $relativePath
  Copy-Item -LiteralPath $sourcePath -Destination $destinationPath -Force
}

Write-Host "FeelY app files synced to $target"
