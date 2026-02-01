param(
    [string]$OutputDir = "dist",
    [string]$ZipName = "waitpress.zip"
)

$ErrorActionPreference = "Stop"

$repoRoot = Resolve-Path (Join-Path $PSScriptRoot "..")
$repoRootPath = $repoRoot.Path
$outputDirPath = Join-Path $repoRootPath $OutputDir
$stagingDir = Join-Path $outputDirPath "waitpress"
$zipPath = Join-Path $outputDirPath $ZipName

$relativeOutputDir = $outputDirPath.Replace($repoRootPath, "").TrimStart('\', '/')
$outputRootName = ($relativeOutputDir -split '[\\/]', 2)[0]

$excludedNames = @(
    ".git",
    ".gitignore",
    "dist",
    "scripts",
    ".vscode",
    ".stubs"
)

if ($outputRootName -and ($excludedNames -notcontains $outputRootName)) {
    $excludedNames += $outputRootName
}

if (!(Test-Path $outputDirPath)) {
    New-Item -ItemType Directory -Path $outputDirPath | Out-Null
}

if (Test-Path $stagingDir) {
    Remove-Item -Path $stagingDir -Recurse -Force
}

if (Test-Path $zipPath) {
    Remove-Item -Path $zipPath -Force
}

New-Item -ItemType Directory -Path $stagingDir | Out-Null

Get-ChildItem -Path $repoRoot -Force | Where-Object {
    $excludedNames -notcontains $_.Name
} | ForEach-Object {
    Copy-Item -Path $_.FullName -Destination $stagingDir -Recurse -Force
}

Compress-Archive -Path $stagingDir -DestinationPath $zipPath -Force
Remove-Item -Path $stagingDir -Recurse -Force

Write-Host "Package created at: $zipPath"
