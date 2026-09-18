#requires -Version 5.1

[CmdletBinding()]
param(
    [Parameter(Mandatory = $true)]
    [string] $RepositoryRoot,

    [Parameter(Mandatory = $true)]
    [ValidateSet('windows-x86_64', 'windows-arm64')]
    [string] $Target,

    [Parameter(Mandatory = $true)]
    [pscustomobject] $Toolchain,

    [Parameter(Mandatory = $true)]
    [object[]] $Sources,

    [Parameter(Mandatory = $true)]
    [string] $InvocationRoot
)

Set-StrictMode -Version Latest
$ErrorActionPreference = 'Stop'

Import-Module (Join-Path $PSScriptRoot 'RuntimeBuild.psm1') -Force

function Get-Input {
    param([string] $Id)
    $matches = @($Sources | Where-Object { $_.Id -eq $Id })
    if ($matches.Count -ne 1) { throw "Expected exactly one verified '$Id' input." }
    return $matches[0]
}

function Get-SourceRoot {
    param([string] $Directory, [string] $Marker)
    if (Test-Path -LiteralPath (Join-Path $Directory $Marker) -PathType Leaf) { return $Directory }
    $matches = @(Get-ChildItem -LiteralPath $Directory -Directory | Where-Object {
        Test-Path -LiteralPath (Join-Path $_.FullName $Marker) -PathType Leaf
    })
    if ($matches.Count -ne 1) { throw "Could not identify one source root containing '$Marker' below '$Directory'." }
    return $matches[0].FullName
}

function Assert-BatchSafePath {
    param([string] $Path)
    if ($Path.IndexOfAny([char[]] '"&|<>^%!') -ge 0) {
        throw "Native build path contains a character unsafe for cmd.exe: $Path"
    }
}

function Assert-ArchiveListing {
    param([string[]] $Lines, [string] $Archive)
    [uint32] $entries = 0
    [uint64] $expandedBytes = 0
    $inEntries = $false
    foreach ($line in $Lines) {
        if ($line -match '^-{8,}$') { $inEntries = $true; continue }
        if (-not $inEntries) { continue }
        if ($line -match '^Path = (.+)$') {
            $entries++
            if ($entries -gt 200000) { throw "Archive contains too many entries: $Archive" }
            $entry = $Matches[1]
            if ([System.IO.Path]::IsPathRooted($entry) -or $entry -match '(^|[\\/])\.\.([\\/]|$)') {
                throw "Archive entry escapes its destination in '$Archive': $entry"
            }
        }
        if ($line -match '^Attributes = .*L') {
            throw "Archive contains a symbolic link and is refused: $Archive"
        }
        if ($line -match '^Size = ([0-9]+)$') {
            $expandedBytes += [uint64] $Matches[1]
            if ($expandedBytes -gt 4294967296) { throw "Archive exceeds the expanded-size limit: $Archive" }
        }
    }
}

function Expand-PinnedArchive {
    param(
        [pscustomobject] $VerifiedInput,
        [string] $Destination,
        [string] $SevenZip,
        [string] $ScratchRoot
    )

    if ($VerifiedInput.Source.archiveType -eq 'zip') {
        [void] (Expand-VerifiedZip -Archive $VerifiedInput.Archive -Destination $Destination)
        return
    }
    if ($VerifiedInput.Source.archiveType -notin @('tar.gz', 'tar.xz')) {
        throw "Input '$($VerifiedInput.Id)' is not an extractable archive."
    }
    if (-not (Test-Path -LiteralPath $SevenZip -PathType Leaf)) {
        throw "Pinned PHP SDK 7-Zip executable is missing: $SevenZip"
    }

    $wrapper = Join-Path $ScratchRoot ("unpack-{0}" -f $VerifiedInput.Id)
    if (Test-Path -LiteralPath $wrapper) { throw "Archive scratch path already exists: $wrapper" }
    [void] (New-Item -ItemType Directory -Path $wrapper)
    $wrapperListing = @(& $SevenZip l -slt -- $VerifiedInput.Archive)
    if ($LASTEXITCODE -ne 0) { throw "Pinned 7-Zip could not inspect '$($VerifiedInput.Id)'." }
    Assert-ArchiveListing -Lines $wrapperListing -Archive $VerifiedInput.Archive
    & $SevenZip x -y ("-o{0}" -f $wrapper) -- $VerifiedInput.Archive | Out-Null
    if ($LASTEXITCODE -ne 0) { throw "Pinned 7-Zip could not unwrap '$($VerifiedInput.Id)'." }
    $tarFiles = @(Get-ChildItem -LiteralPath $wrapper -File)
    if ($tarFiles.Count -ne 1) { throw "Compressed tar input '$($VerifiedInput.Id)' did not contain exactly one tar stream." }
    $listing = @(& $SevenZip l -slt -- $tarFiles[0].FullName)
    if ($LASTEXITCODE -ne 0) { throw "Pinned 7-Zip could not inspect the tar for '$($VerifiedInput.Id)'." }
    Assert-ArchiveListing -Lines $listing -Archive $VerifiedInput.Archive
    if (Test-Path -LiteralPath $Destination) { throw "Archive destination already exists: $Destination" }
    [void] (New-Item -ItemType Directory -Path $Destination)
    & $SevenZip x -y ("-o{0}" -f $Destination) -- $tarFiles[0].FullName | Out-Null
    if ($LASTEXITCODE -ne 0) { throw "Pinned 7-Zip could not extract '$($VerifiedInput.Id)'." }
    $links = @(Get-ChildItem -LiteralPath $Destination -Force -Recurse | Where-Object {
        ($_.Attributes -band [System.IO.FileAttributes]::ReparsePoint) -ne 0
    })
    if ($links.Count -gt 0) { throw "Extracted archive contains a reparse point: $($VerifiedInput.Id)" }
}

$sourceRoot = Join-Path $InvocationRoot 'sources'
$workRoot = Join-Path $InvocationRoot 'work'
$runtimeStage = Join-Path $InvocationRoot 'runtime-stage'
foreach ($path in @($sourceRoot, $workRoot)) {
    if (Test-Path -LiteralPath $path) { throw "Native stage path already exists: $path" }
    [void] (New-Item -ItemType Directory -Path $path)
}
if (Test-Path -LiteralPath $runtimeStage) { throw "Native stage path already exists: $runtimeStage" }

$sdkInput = Get-Input -Id 'php-sdk-binary-tools'
$sdkDestination = Join-Path $sourceRoot 'php-sdk-binary-tools'
[void] (Expand-VerifiedZip -Archive $sdkInput.Archive -Destination $sdkDestination)
$sdkRoot = Get-SourceRoot -Directory $sdkDestination -Marker 'phpsdk-vs17-x64.bat'
$sevenZip = Get-ChildItem -LiteralPath $sdkRoot -Filter 7za.exe -File -Recurse | Select-Object -First 1
if ($null -eq $sevenZip) { throw 'The pinned PHP SDK archive does not contain 7za.exe.' }

$expanded = @{ 'php-sdk-binary-tools' = $sdkDestination }
foreach ($id in @('php', 'leveldb', 'php-leveldb', 'cmake-windows-x86_64')) {
    $verifiedInput = Get-Input -Id $id
    $destination = Join-Path $sourceRoot $id
    Expand-PinnedArchive -VerifiedInput $verifiedInput -Destination $destination -SevenZip $sevenZip.FullName -ScratchRoot $workRoot
    $expanded[$id] = $destination
}

$depsSource = Join-Path $sourceRoot 'php-sdk-deps'
[void] (New-Item -ItemType Directory -Path $depsSource)
$dependencyInputs = @($Sources | Where-Object { $_.Id -like 'php-sdk-dep-*' })
if ($dependencyInputs.Count -ne 16) {
    throw "Expected the reviewed 16-package Windows dependency closure, got $($dependencyInputs.Count)."
}
foreach ($dependencyInput in $dependencyInputs) {
    if ($dependencyInput.Source.archiveType -ne 'zip') {
        throw "Windows dependency package '$($dependencyInput.Id)' is not a ZIP archive."
    }
    $packageRoot = Join-Path $sourceRoot $dependencyInput.Id
    [void] (Expand-VerifiedZip -Archive $dependencyInput.Archive -Destination $packageRoot)
    Merge-VerifiedDependencyTree -Source $packageRoot -Destination $depsSource -PackageId $dependencyInput.Id
}

$phpSource = Get-SourceRoot -Directory $expanded.php -Marker 'buildconf.bat'
$leveldbSource = Get-SourceRoot -Directory $expanded.leveldb -Marker 'CMakeLists.txt'
$extensionSource = Get-SourceRoot -Directory $expanded.'php-leveldb' -Marker 'config.w32'
$cmakeRoot = $expanded.'cmake-windows-x86_64'
$cmake = Get-ChildItem -LiteralPath $cmakeRoot -Filter cmake.exe -File -Recurse | Select-Object -First 1
if ($null -eq $cmake) { throw 'The pinned CMake input does not contain cmake.exe.' }

$sdkArch = if ($Target -eq 'windows-arm64') { 'arm64' } else { 'x64' }
$starter = Join-Path $sdkRoot ("phpsdk-vs17-{0}.bat" -f $sdkArch)
if (-not (Test-Path -LiteralPath $starter -PathType Leaf)) {
    throw "The pinned PHP SDK does not provide the required starter: $starter"
}

$driver = Join-Path $PSScriptRoot 'native-build.cmd'
$stageScript = Join-Path $PSScriptRoot 'stage-runtime.ps1'
foreach ($path in @($starter, $driver, $stageScript, $phpSource, $depsSource, $leveldbSource, $extensionSource, $workRoot, $runtimeStage, $cmake.FullName, $RepositoryRoot, $InvocationRoot)) {
    Assert-BatchSafePath -Path $path
}

Write-Host 'Starting native PHP and LevelDB compilation in the pinned PHP SDK environment.'
$nativeEnvironment = [ordered] @{
    BEDRIOX_NATIVE_PHP_SOURCE = $phpSource
    BEDRIOX_NATIVE_PHP_DEPS_SOURCE = $depsSource
    BEDRIOX_NATIVE_LEVELDB_SOURCE = $leveldbSource
    BEDRIOX_NATIVE_LEVELDB_EXTENSION_SOURCE = $extensionSource
    BEDRIOX_NATIVE_WORK_ROOT = $workRoot
    BEDRIOX_NATIVE_RUNTIME_STAGE = $runtimeStage
    BEDRIOX_NATIVE_CMAKE_EXE = $cmake.FullName
    BEDRIOX_NATIVE_STAGE_SCRIPT = $stageScript
    BEDRIOX_NATIVE_REPOSITORY_ROOT = $RepositoryRoot
    BEDRIOX_NATIVE_INVOCATION_ROOT = $InvocationRoot
}
foreach ($name in $nativeEnvironment.Keys) {
    if ($null -ne [Environment]::GetEnvironmentVariable($name, 'Process')) {
        throw "Reserved native build environment variable is already defined: $name"
    }
}
foreach ($name in $nativeEnvironment.Keys) {
    [Environment]::SetEnvironmentVariable($name, $nativeEnvironment[$name], 'Process')
}
$nativeExitCode = -1
try {
    & cmd.exe /d /s /c "`"$starter`" -t `"$driver`""
    $nativeExitCode = $LASTEXITCODE
} finally {
    foreach ($name in $nativeEnvironment.Keys) {
        [Environment]::SetEnvironmentVariable($name, $null, 'Process')
    }
}
if ($nativeExitCode -ne 0) { throw "Native Windows build failed with exit code $nativeExitCode." }

$phpExe = Get-Item -LiteralPath (Join-Path $runtimeStage 'php.exe')
if ($null -eq $phpExe -or -not $phpExe.Exists) { throw 'Native build completed without staging php.exe.' }

$compilerIdentityFile = Join-Path $workRoot 'compiler-identity.txt'
$linkerIdentityFile = Join-Path $workRoot 'linker-identity.txt'
$compilerIdentity = (Get-Content -LiteralPath $compilerIdentityFile | Where-Object { -not [string]::IsNullOrWhiteSpace($_) } | Select-Object -First 1).Trim()
$linkerIdentity = (Get-Content -LiteralPath $linkerIdentityFile | Where-Object { -not [string]::IsNullOrWhiteSpace($_) } | Select-Object -First 1).Trim()
if ([string]::IsNullOrWhiteSpace($compilerIdentity) -or [string]::IsNullOrWhiteSpace($linkerIdentity)) {
    throw 'Native build did not record exact compiler and linker identities.'
}

$caInput = Get-Input -Id 'ca-bundle'
$packageParent = Join-Path $InvocationRoot 'package'
[void] (New-Item -ItemType Directory -Path $packageParent)
$packageRoot = Join-Path $packageParent 'bin'
$artifactRoot = New-BoundedBuildDirectory -RepositoryRoot $RepositoryRoot -RelativePath 'artifacts'
$sourceIds = ($Sources | ForEach-Object { $_.Id }) -join ','
$packager = Join-Path $RepositoryRoot 'tools\package.php'
$env:BEDRIOX_RUNTIME_ROOT = $packageRoot
try {
    & $phpExe.FullName -n $packager `
        --repository-root=$RepositoryRoot `
        --target=$Target `
        --invocation-root=$InvocationRoot `
        --source-root=$runtimeStage `
        --package-parent=$packageParent `
        --package-root=$packageRoot `
        --artifact-root=$artifactRoot `
        --ca-file=$($caInput.Archive) `
        --compiler=$compilerIdentity `
        --linker=$linkerIdentity `
        --source-ids=$sourceIds `
        --format=zip
    if ($LASTEXITCODE -ne 0) { throw "Runtime packaging and qualification failed with exit code $LASTEXITCODE." }
} finally {
    Remove-Item Env:\BEDRIOX_RUNTIME_ROOT -ErrorAction SilentlyContinue
}

return [pscustomobject] @{
    InstallRoot = $packageRoot
    Php = Join-Path $packageRoot 'php.exe'
    Artifact = Join-Path $artifactRoot ("bedriox-runtime-{0}.zip" -f $Target)
}
