#requires -Version 5.1

[CmdletBinding()]
param(
    [Parameter(Mandatory = $true)][string] $PhpSource,
    [Parameter(Mandatory = $true)][string] $RuntimeStage,
    [Parameter(Mandatory = $true)][string] $LevelDbDll,
    [Parameter(Mandatory = $true)][string] $VcRuntimeDirectory,
    [Parameter(Mandatory = $true)][ValidateSet('x64', 'arm64')][string] $Architecture,
    [Parameter(Mandatory = $true)][string] $RepositoryRoot,
    [Parameter(Mandatory = $true)][string] $InvocationRoot,
    [Parameter(Mandatory = $true)][string] $ReportRoot
)

Set-StrictMode -Version Latest
$ErrorActionPreference = 'Stop'

function Assert-AbsoluteExistingDirectory([string] $Path, [string] $Description) {
    if (-not [System.IO.Path]::IsPathRooted($Path) -or -not (Test-Path -LiteralPath $Path -PathType Container)) {
        throw "$Description must be an existing absolute directory: $Path"
    }
    return (Resolve-Path -LiteralPath $Path).Path
}

function Assert-PathBelow([string] $Path, [string] $Parent, [string] $Description) {
    $trimCharacters = [char[]] @('\', '/')
    $normalizedPath = [System.IO.Path]::GetFullPath($Path).TrimEnd($trimCharacters)
    $normalizedParent = [System.IO.Path]::GetFullPath($Parent).TrimEnd($trimCharacters) + [System.IO.Path]::DirectorySeparatorChar
    if (-not ($normalizedPath + [System.IO.Path]::DirectorySeparatorChar).StartsWith($normalizedParent, [System.StringComparison]::OrdinalIgnoreCase)) {
        throw "$Description escapes the invocation root: $Path"
    }
}

function Get-PeImports([string] $Dumpbin, [string] $Path) {
    $lines = @(& $Dumpbin /nologo /dependents $Path)
    if ($LASTEXITCODE -ne 0) { throw "Could not inspect PE imports: $Path" }
    $imports = @()
    foreach ($line in $lines) {
        if ($line -match '^\s+([A-Za-z0-9._+-]+\.dll)\s*$') { $imports += $Matches[1] }
    }
    return @($imports | Sort-Object -Unique)
}

function Test-SystemDll([string] $Name) {
    $lower = $Name.ToLowerInvariant()
    if ($lower -like 'api-ms-win-*.dll' -or $lower -like 'ext-ms-win-*.dll') { return $true }
    return $lower -in @(
        'advapi32.dll', 'bcrypt.dll', 'bcryptprimitives.dll', 'cabinet.dll', 'cfgmgr32.dll',
        'combase.dll', 'comdlg32.dll', 'crypt32.dll', 'cryptbase.dll', 'dbghelp.dll',
        'dnsapi.dll', 'gdi32.dll', 'iphlpapi.dll', 'kernel32.dll', 'netapi32.dll',
        'mswsock.dll', 'ncrypt.dll', 'normaliz.dll', 'ntdll.dll', 'ntdsapi.dll',
        'ole32.dll', 'oleaut32.dll', 'powrprof.dll', 'psapi.dll', 'rpcrt4.dll',
        'secur32.dll', 'shell32.dll', 'shlwapi.dll', 'user32.dll', 'userenv.dll',
        'ucrtbase.dll', 'version.dll', 'winhttp.dll', 'winmm.dll', 'wintrust.dll', 'wldap32.dll',
        'ws2_32.dll', 'wtsapi32.dll'
    )
}

function Assert-NoPrivateBytes([string] $Path, [string[]] $ForbiddenRoots) {
    $info = Get-Item -LiteralPath $Path
    if ($info.Length -gt 134217728) { throw "PE file exceeds the inspection limit: $Path" }
    $bytes = [System.IO.File]::ReadAllBytes($Path)
    $ascii = [System.Text.Encoding]::ASCII.GetString($bytes)
    $utf16 = [System.Text.Encoding]::Unicode.GetString($bytes)
    foreach ($root in $ForbiddenRoots) {
        foreach ($needle in @($root, $root.Replace('\', '/'))) {
            if ($needle.Length -ge 3 -and ($ascii.IndexOf($needle, [System.StringComparison]::OrdinalIgnoreCase) -ge 0 -or $utf16.IndexOf($needle, [System.StringComparison]::OrdinalIgnoreCase) -ge 0)) {
                throw "Packaged PE contains a private build path: $Path"
            }
        }
    }
}

$phpRoot = Assert-AbsoluteExistingDirectory $PhpSource 'PHP source root'
$repoRoot = Assert-AbsoluteExistingDirectory $RepositoryRoot 'Repository root'
$invocation = Assert-AbsoluteExistingDirectory $InvocationRoot 'Invocation root'
$report = Assert-AbsoluteExistingDirectory $ReportRoot 'Report root'
$vcRuntime = Assert-AbsoluteExistingDirectory $VcRuntimeDirectory 'Visual C++ runtime directory'
if ((Split-Path -Leaf $vcRuntime) -ne 'Microsoft.VC143.CRT' -or (Split-Path -Leaf (Split-Path -Parent $vcRuntime)) -ne $Architecture) {
    throw "Visual C++ runtime directory does not match the selected $Architecture VC143 closure: $vcRuntime"
}
if (-not [System.IO.Path]::IsPathRooted($RuntimeStage) -or (Test-Path -LiteralPath $RuntimeStage)) {
    throw "Runtime stage must be a new absolute path: $RuntimeStage"
}
if (-not (Test-Path -LiteralPath $LevelDbDll -PathType Leaf)) { throw "Reviewed LevelDB DLL is missing: $LevelDbDll" }
$levelDbResolved = (Resolve-Path -LiteralPath $LevelDbDll).Path
Assert-PathBelow $phpRoot $invocation 'PHP source root'
Assert-PathBelow $report $invocation 'Report root'
Assert-PathBelow $RuntimeStage $invocation 'Runtime stage'
Assert-PathBelow $levelDbResolved $invocation 'Reviewed LevelDB DLL'

$dumpbinCommand = Get-Command dumpbin.exe -CommandType Application -ErrorAction Stop
$dumpbin = $dumpbinCommand.Source
if ([string]::IsNullOrWhiteSpace($dumpbin) -or -not (Test-Path -LiteralPath $dumpbin -PathType Leaf)) {
    throw 'The active VS17 SDK environment did not provide dumpbin.exe.'
}

$phpExecutables = @(Get-ChildItem -LiteralPath $phpRoot -Filter php.exe -File -Recurse)
if ($phpExecutables.Count -gt 32) { throw 'PHP build produced too many executable candidates.' }
$snapshots = @($phpExecutables | Where-Object {
    $_.Directory.Name -like 'php-*' -and (Test-Path -LiteralPath (Join-Path $_.Directory.FullName 'ext') -PathType Container)
})
if ($snapshots.Count -ne 1) { throw "Expected exactly one nmake snap runtime, got $($snapshots.Count)." }
$snapshotRoot = $snapshots[0].Directory.FullName
$snapshotExt = Join-Path $snapshotRoot 'ext'

[void] (New-Item -ItemType Directory -Path $RuntimeStage)
[void] (New-Item -ItemType Directory -Path (Join-Path $RuntimeStage 'ext'))
Copy-Item -LiteralPath $snapshots[0].FullName -Destination (Join-Path $RuntimeStage 'php.exe')

$candidates = @{}
foreach ($dll in @(Get-ChildItem -LiteralPath $snapshotRoot -Filter *.dll -File)) {
    $key = $dll.Name.ToLowerInvariant()
    if ($candidates.ContainsKey($key)) { throw "Duplicate root DLL candidate: $($dll.Name)" }
    $candidates[$key] = $dll.FullName
}
$levelDbName = [System.IO.Path]::GetFileName($LevelDbDll).ToLowerInvariant()
if ($candidates.ContainsKey($levelDbName) -and (Get-FileHash -Algorithm SHA256 $candidates[$levelDbName]).Hash -ne (Get-FileHash -Algorithm SHA256 $LevelDbDll).Hash) {
    throw 'The snapshot and reviewed LevelDB DLL disagree.'
}
$candidates[$levelDbName] = $levelDbResolved

$crtDlls = @(Get-ChildItem -LiteralPath $vcRuntime -Filter *.dll -File)
if ($crtDlls.Count -eq 0 -or $crtDlls.Count -gt 32) {
    throw 'The Visual C++ runtime DLL closure is outside the reviewed bounds.'
}
$crtBytes = [uint64] 0
foreach ($dll in $crtDlls) {
    if ($dll.Name -notmatch '^[A-Za-z0-9._+-]+\.dll$') { throw "Unsafe Visual C++ runtime DLL name: $($dll.Name)" }
    if (($dll.Attributes -band [System.IO.FileAttributes]::ReparsePoint) -ne 0) { throw "Visual C++ runtime DLL is a reparse point: $($dll.Name)" }
    $crtBytes += [uint64] $dll.Length
    if ($crtBytes -gt 134217728) { throw 'The Visual C++ runtime DLL closure exceeds 128 MiB.' }
    $signature = Get-AuthenticodeSignature -LiteralPath $dll.FullName
    if ($signature.Status -ne [System.Management.Automation.SignatureStatus]::Valid -or $null -eq $signature.SignerCertificate -or $signature.SignerCertificate.Subject -notmatch 'CN=Microsoft Corporation(?:,|$)') {
        throw "Visual C++ runtime DLL does not have a valid Microsoft signature: $($dll.Name)"
    }
    $key = $dll.Name.ToLowerInvariant()
    if ($candidates.ContainsKey($key) -and (Get-FileHash -Algorithm SHA256 $candidates[$key]).Hash -ne (Get-FileHash -Algorithm SHA256 $dll.FullName).Hash) {
        throw "Visual C++ runtime DLL conflicts with the PHP snapshot: $($dll.Name)"
    }
    $candidates[$key] = $dll.FullName
}

$queue = New-Object System.Collections.Generic.Queue[string]
$queue.Enqueue((Join-Path $RuntimeStage 'php.exe'))
$extensionFiles = @(Get-ChildItem -LiteralPath $snapshotExt -Filter *.dll -File)
if ($extensionFiles.Count -eq 0 -or $extensionFiles.Count -gt 128) { throw 'The snapshot extension count is outside the reviewed bounds.' }
foreach ($extension in $extensionFiles) {
    if ($extension.Name -notmatch '^[A-Za-z0-9_+-]+\.dll$') { throw "Unsafe extension name: $($extension.Name)" }
    $destination = Join-Path (Join-Path $RuntimeStage 'ext') $extension.Name
    Copy-Item -LiteralPath $extension.FullName -Destination $destination
    $queue.Enqueue($destination)
}

$visited = @{}
while ($queue.Count -gt 0) {
    if ($visited.Count -gt 256) { throw 'PE dependency closure exceeds the reviewed file limit.' }
    $pe = $queue.Dequeue()
    $peKey = (Resolve-Path -LiteralPath $pe).Path.ToLowerInvariant()
    if ($visited.ContainsKey($peKey)) { continue }
    $visited[$peKey] = $true
    foreach ($import in Get-PeImports $dumpbin $pe) {
        $key = $import.ToLowerInvariant()
        if ($candidates.ContainsKey($key)) {
            $destination = Join-Path $RuntimeStage ([System.IO.Path]::GetFileName($candidates[$key]))
            if (-not (Test-Path -LiteralPath $destination -PathType Leaf)) {
                Copy-Item -LiteralPath $candidates[$key] -Destination $destination
            }
            $queue.Enqueue($destination)
        } elseif (-not (Test-SystemDll $import)) {
            throw "PE import is neither packaged nor an allowlisted Windows system DLL: $import (required by $pe)"
        }
    }
}

if (-not (Test-Path -LiteralPath (Join-Path $RuntimeStage 'ext\php_leveldb.dll') -PathType Leaf)) {
    throw 'The runtime stage does not contain php_leveldb.dll.'
}
if (-not (Test-Path -LiteralPath (Join-Path $RuntimeStage 'leveldb.dll') -PathType Leaf)) {
    throw 'The PE closure did not include the reviewed leveldb.dll.'
}

$forbiddenRoots = @($repoRoot, $invocation)
foreach ($privateRoot in @([Environment]::GetFolderPath('UserProfile'), [System.IO.Path]::GetTempPath())) {
    if (-not [string]::IsNullOrWhiteSpace($privateRoot)) { $forbiddenRoots += $privateRoot.TrimEnd([char[]] @('\', '/')) }
}
$peFiles = @(Get-ChildItem -LiteralPath $RuntimeStage -File -Recurse)
$totalBytes = [uint64] 0
$headersReport = New-Object System.Collections.Generic.List[string]
foreach ($pe in $peFiles) {
    if ($pe.Extension -notin @('.exe', '.dll')) { throw "Runtime stage contains a non-runtime file: $($pe.FullName)" }
    $totalBytes += [uint64] $pe.Length
    if ($totalBytes -gt 1073741824) { throw 'Runtime PE inspection exceeds the one-GiB limit.' }
    Assert-NoPrivateBytes $pe.FullName $forbiddenRoots
    $headers = @(& $dumpbin /nologo /headers $pe.FullName)
    if ($LASTEXITCODE -ne 0) { throw "Could not inspect PE debug headers: $($pe.FullName)" }
    $headersReport.Add("FILE $($pe.FullName.Substring($RuntimeStage.Length + 1))")
    foreach ($line in $headers) {
        if ($line -match '(?i)\.pdb' -or $line -match '(?i)debug directories') { $headersReport.Add($line.Trim()) }
        if ($line -match '(?i)[A-Z]:[\\/][^\r\n]*\.pdb') { throw "PE debug data contains an absolute PDB path: $($pe.FullName)" }
    }
}
$headersReport | Set-Content -LiteralPath (Join-Path $report 'runtime-pe-debug-report.txt') -Encoding UTF8

Write-Host "Staged $($peFiles.Count) Windows runtime PE files from the closed import graph."
