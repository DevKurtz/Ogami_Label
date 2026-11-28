param(
    [Parameter(Mandatory = $true)]
    [string] $PhpRuntime
)

$ErrorActionPreference = 'Stop'
$source = (Resolve-Path -LiteralPath $PhpRuntime).Path
$target = Join-Path $PSScriptRoot 'runtime\php'
if (-not (Test-Path -LiteralPath (Join-Path $source 'php.exe'))) { throw "php.exe was not found in $source" }

foreach ($extension in @('php_fileinfo.dll', 'php_pdo_sqlite.dll', 'php_sqlite3.dll', 'php_mbstring.dll', 'php_zip.dll', 'php_openssl.dll')) {
    if (-not (Test-Path -LiteralPath (Join-Path $source "ext\$extension"))) { throw "Required PHP extension $extension is missing from $source\ext" }
}

if (Test-Path -LiteralPath $target) {
    $resolvedTarget = (Resolve-Path -LiteralPath $target).Path
    $expectedParent = (Resolve-Path -LiteralPath (Join-Path $PSScriptRoot 'runtime')).Path
    if (-not $resolvedTarget.StartsWith($expectedParent, [System.StringComparison]::OrdinalIgnoreCase)) {
        throw "Refusing to replace a PHP runtime outside $expectedParent"
    }
    Remove-Item -LiteralPath $resolvedTarget -Recurse -Force
}
New-Item -ItemType Directory -Path $target -Force | Out-Null
$extensionsTarget = Join-Path $target 'ext'
New-Item -ItemType Directory -Path $extensionsTarget -Force | Out-Null
Copy-Item -LiteralPath (Join-Path $source 'php.exe') -Destination $target
Get-ChildItem -LiteralPath $source -File -Filter '*.dll' |
    Where-Object { $_.Name -notmatch '^icu.*\.dll$' } |
    Copy-Item -Destination $target
foreach ($extension in @('php_fileinfo.dll', 'php_pdo_sqlite.dll', 'php_sqlite3.dll', 'php_mbstring.dll', 'php_zip.dll', 'php_openssl.dll')) {
    Copy-Item -LiteralPath (Join-Path $source "ext\$extension") -Destination $extensionsTarget
}
foreach ($notice in @('license.txt', 'readme-redist-bins.txt')) {
    $noticePath = Join-Path $source $notice
    if (Test-Path -LiteralPath $noticePath) { Copy-Item -LiteralPath $noticePath -Destination $target }
}

$ini = Join-Path $target 'php.ini-development'
if (-not (Test-Path -LiteralPath (Join-Path $source 'php.ini-development'))) { throw 'The PHP distribution does not include php.ini-development.' }
$ini = Join-Path $source 'php.ini-development'
$configuration = Get-Content -LiteralPath $ini -Raw
$configuration = $configuration -replace '(?m)^;?\s*extension_dir\s*=.*$', 'extension_dir="ext"'
foreach ($extension in @('fileinfo', 'pdo_sqlite', 'sqlite3', 'mbstring', 'zip', 'openssl')) {
    $configuration = $configuration -replace "(?m)^;\s*extension\s*=\s*$extension\s*$", "extension=$extension"
}
Set-Content -LiteralPath (Join-Path $target 'php.ini') -Value $configuration -Encoding ascii
Write-Host "Prepared the PHP runtime at $target"
