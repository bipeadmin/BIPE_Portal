param(
    [string] $InputHtml = "docs/BIPE_Academic_Portal_Detailed_Report.html",
    [string] $OutputPdf = "docs/BIPE_Academic_Portal_Detailed_Report.pdf"
)

$ErrorActionPreference = "Stop"

$projectRoot = Split-Path -Parent $PSScriptRoot
$inputPath = [System.IO.Path]::GetFullPath((Join-Path $projectRoot $InputHtml))
$outputPath = [System.IO.Path]::GetFullPath((Join-Path $projectRoot $OutputPdf))
$outputDir = Split-Path -Parent $outputPath

if (-not (Test-Path -LiteralPath $inputPath)) {
    throw "Input HTML file not found: $inputPath"
}

if (-not (Test-Path -LiteralPath $outputDir)) {
    New-Item -ItemType Directory -Path $outputDir | Out-Null
}

$browserCandidates = @(
    "C:\Program Files (x86)\Microsoft\Edge\Application\msedge.exe",
    "C:\Program Files\Microsoft\Edge\Application\msedge.exe",
    "C:\Program Files\Google\Chrome\Application\chrome.exe"
)

$browserPath = $browserCandidates | Where-Object { Test-Path -LiteralPath $_ } | Select-Object -First 1
if (-not $browserPath) {
    throw "No supported browser found for PDF generation."
}

$htmlUri = "file:///" + ($inputPath -replace "\\", "/")

& $browserPath `
    --headless=new `
    --disable-gpu `
    --allow-file-access-from-files `
    --print-to-pdf-no-header `
    "--print-to-pdf=$outputPath" `
    $htmlUri | Out-Null

if (-not (Test-Path -LiteralPath $outputPath)) {
    throw "PDF generation did not produce the expected output file: $outputPath"
}

Write-Output "Generated report PDF: $outputPath"
