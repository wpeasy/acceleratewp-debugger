# create-plugin-zip.ps1
# Creates a UNIX/Linux compatible WordPress plugin ZIP for AccelerateWP Debugger.
#
# Output: plugin/{plugin-slug}-{version}.zip
# Entries inside the ZIP all start with "{plugin-slug}/" so Linux extraction
# produces a single plugin folder, not scattered files.

$ErrorActionPreference = 'Stop'

$pluginRoot = $PSScriptRoot
$pluginSlug = Split-Path -Leaf $pluginRoot

# Locate the main plugin file
$mainFile = Join-Path $pluginRoot "$pluginSlug.php"
if (-not (Test-Path $mainFile)) {
    $candidate = Get-ChildItem -Path $pluginRoot -Filter '*.php' -File | Where-Object {
        ((Get-Content $_.FullName -TotalCount 30) -join "`n") -match 'Plugin Name:'
    } | Select-Object -First 1
    if ($candidate) { $mainFile = $candidate.FullName }
}
if (-not (Test-Path $mainFile)) {
    throw "Could not locate main plugin file in $pluginRoot"
}

# Read version from plugin header
$header = (Get-Content $mainFile -TotalCount 40) -join "`n"
if ($header -notmatch '(?m)^\s*\*?\s*Version:\s*([0-9][^\s]*)') {
    throw "Could not parse Version: from $mainFile"
}
$pluginVersion = $Matches[1]

# Output dir
$outDir = Join-Path $pluginRoot 'plugin'
if (-not (Test-Path $outDir)) {
    New-Item -ItemType Directory -Path $outDir | Out-Null
}

# Remove old zips for this plugin
Get-ChildItem -Path $outDir -Filter "$pluginSlug-*.zip" -File -ErrorAction SilentlyContinue |
    Remove-Item -Force

$zipPath = Join-Path $outDir "$pluginSlug-$pluginVersion.zip"

# Path-relative exclusion rules
function ShouldExclude {
    param([string]$RelativePath)

    $p = $RelativePath -replace '\\', '/'
    if ([string]::IsNullOrEmpty($p)) { return $true }

    $segments = $p.Split('/')
    foreach ($seg in $segments) {
        if ([string]::IsNullOrEmpty($seg)) { continue }
        if ($seg.StartsWith('.'))     { return $true }   # hidden (.git, .claude, .gitignore, etc.)
        if ($seg -like 'src-*')       { return $true }   # source-only folders
        if ($seg -like 'svelte-*')    { return $true }   # framework dev folders
        if ($seg -eq 'node_modules')  { return $true }
        if ($seg -eq 'plugin')        { return $true }   # the output dir itself
    }

    $file = $segments[-1]
    if ($file -eq 'create-plugin-zip.ps1') { return $true }
    if ($file -like 'vite.config.*')       { return $true }
    if ($file -like 'tsconfig*')           { return $true }
    if ($file -like 'svelte.config.*')     { return $true }
    if ($file -eq 'package.json')          { return $true }
    if ($file -eq 'package-lock.json')     { return $true }
    if ($file -eq 'composer.json')         { return $true }
    if ($file -eq 'composer.lock')         { return $true }
    if ($file -eq 'phpcs.xml')             { return $true }
    if ($file -eq 'phpcs.xml.dist')        { return $true }
    if ($file -eq 'phpunit.xml')           { return $true }
    if ($file -eq 'phpunit.xml.dist')      { return $true }
    if ($file -like '*.md')                { return $true }
    if ($file -like '*.log')               { return $true }

    return $false
}

Add-Type -AssemblyName System.IO.Compression
Add-Type -AssemblyName System.IO.Compression.FileSystem

$archive = [System.IO.Compression.ZipFile]::Open(
    $zipPath,
    [System.IO.Compression.ZipArchiveMode]::Create
)

$included = 0
$skipped  = 0
try {
    $files = Get-ChildItem -Path $pluginRoot -Recurse -File -Force
    foreach ($file in $files) {
        $rel = $file.FullName.Substring($pluginRoot.Length).TrimStart('\', '/')
        if (ShouldExclude $rel) {
            $skipped++
            continue
        }
        $entryName = "$pluginSlug/" + ($rel -replace '\\', '/')
        [System.IO.Compression.ZipFileExtensions]::CreateEntryFromFile(
            $archive,
            $file.FullName,
            $entryName,
            [System.IO.Compression.CompressionLevel]::Optimal
        ) | Out-Null
        $included++
    }
} finally {
    $archive.Dispose()
}

Write-Output "Created:  $zipPath"
Write-Output "Version:  $pluginVersion"
Write-Output "Included: $included files"
Write-Output "Skipped:  $skipped files"
