#requires -Version 5.1

Set-StrictMode -Version Latest
$ErrorActionPreference = 'Stop'

function Get-NormalizedDirectoryPath {
    param(
        [Parameter(Mandatory = $true)]
        [string] $Path
    )

    return [System.IO.Path]::GetFullPath($Path).TrimEnd(
        [System.IO.Path]::DirectorySeparatorChar,
        [System.IO.Path]::AltDirectorySeparatorChar
    )
}

function Resolve-BoundedBuildPath {
    [CmdletBinding()]
    param(
        [Parameter(Mandatory = $true)]
        [string] $RepositoryRoot,

        [Parameter(Mandatory = $true)]
        [string] $RelativePath
    )

    if ([System.IO.Path]::IsPathRooted($RelativePath)) {
        throw "Build path must be repository-relative: $RelativePath"
    }

    $root = Get-NormalizedDirectoryPath -Path $RepositoryRoot
    $candidate = Get-NormalizedDirectoryPath -Path (Join-Path $root $RelativePath)
    $prefix = $root + [System.IO.Path]::DirectorySeparatorChar
    if (-not $candidate.StartsWith($prefix, [System.StringComparison]::OrdinalIgnoreCase)) {
        throw "Build path escapes the repository: $RelativePath"
    }

    return $candidate
}

function New-BoundedBuildDirectory {
    [CmdletBinding()]
    param(
        [Parameter(Mandatory = $true)]
        [string] $RepositoryRoot,

        [Parameter(Mandatory = $true)]
        [string] $RelativePath
    )

    $path = Resolve-BoundedBuildPath -RepositoryRoot $RepositoryRoot -RelativePath $RelativePath
    if (-not (Test-Path -LiteralPath $path)) {
        [void] (New-Item -ItemType Directory -Path $path)
    }
    if (-not (Test-Path -LiteralPath $path -PathType Container)) {
        throw "Expected a build directory at: $path"
    }

    return $path
}

function Resolve-BootstrapPhp {
    [CmdletBinding()]
    param(
        [string] $RequestedPath
    )

    if ($RequestedPath) {
        $resolved = Resolve-Path -LiteralPath $RequestedPath -ErrorAction Stop
        if (-not (Test-Path -LiteralPath $resolved.Path -PathType Leaf)) {
            throw "Bootstrap PHP is not a file: $RequestedPath"
        }
        return $resolved.Path
    }

    $command = Get-Command php.exe -CommandType Application -ErrorAction SilentlyContinue |
        Select-Object -First 1
    if ($null -eq $command) {
        throw 'Bootstrap PHP was not supplied and php.exe was not found. Pass -BootstrapPhp with an explicit PHP CLI path.'
    }

    Write-Host "Using bootstrap PHP only for repository tooling: $($command.Source)"
    return $command.Source
}

function Invoke-ManifestTool {
    [CmdletBinding()]
    param(
        [Parameter(Mandatory = $true)]
        [string] $Php,

        [Parameter(Mandatory = $true)]
        [string] $RepositoryRoot,

        [Parameter(Mandatory = $true)]
        [string[]] $Arguments
    )

    $tool = Join-Path $RepositoryRoot 'tools\manifest.php'
    if (-not (Test-Path -LiteralPath $tool -PathType Leaf)) {
        throw "Manifest tool is missing: $tool"
    }

    $output = & $Php $tool @Arguments 2>&1
    if ($LASTEXITCODE -ne 0) {
        throw "Manifest tool failed for '$($Arguments -join ' ')': $($output -join [Environment]::NewLine)"
    }

    return (($output | ForEach-Object { [string] $_ }) -join "`n").Trim()
}

function Get-ManifestSource {
    [CmdletBinding()]
    param(
        [Parameter(Mandatory = $true)]
        [string] $Php,

        [Parameter(Mandatory = $true)]
        [string] $RepositoryRoot,

        [Parameter(Mandatory = $true)]
        [ValidatePattern('^[a-z0-9][a-z0-9_-]*$')]
        [string] $Id
    )

    $fields = @('version', 'commit', 'url', 'sha256', 'bytes', 'archiveType', 'license', 'licenseFile', 'targets')
    $source = [ordered] @{ id = $Id }
    foreach ($field in $fields) {
        try {
            $value = Invoke-ManifestTool -Php $Php -RepositoryRoot $RepositoryRoot -Arguments @('source', $Id, $field)
        } catch {
            if ($field -in @('version', 'commit', 'licenseFile')) {
                continue
            }
            throw
        }

        if ($field -eq 'targets') {
            try {
                $value = [string[]] ($value | ConvertFrom-Json)
            } catch {
                throw "Manifest source '$Id' has invalid targets JSON."
            }
        }
        $source[$field] = $value
    }

    if (-not $source.Contains('url') -or $source.url -notmatch '^https://') {
        throw "Manifest source '$Id' must use an HTTPS URL."
    }
    if (-not $source.Contains('sha256') -or $source.sha256 -notmatch '^[a-fA-F0-9]{64}$') {
        throw "Manifest source '$Id' has an invalid SHA-256 digest."
    }
    if (-not $source.Contains('license') -or [string]::IsNullOrWhiteSpace($source.license)) {
        throw "Manifest source '$Id' has no license identifier."
    }
    if (-not $source.Contains('bytes') -or [uint64] $source.bytes -lt 1) {
        throw "Manifest source '$Id' has no positive byte limit."
    }
    if (-not $source.Contains('archiveType') -or $source.archiveType -notin @('zip', 'tar.gz', 'tar.xz', 'pem', 'text')) {
        throw "Manifest source '$Id' has an unsupported archive type."
    }

    return [pscustomobject] $source
}

function Get-ManifestTargetClosure {
    [CmdletBinding()]
    param(
        [Parameter(Mandatory = $true)]
        [string] $Php,

        [Parameter(Mandatory = $true)]
        [string] $RepositoryRoot,

        [Parameter(Mandatory = $true)]
        [string] $Target
    )

    $raw = Invoke-ManifestTool -Php $Php -RepositoryRoot $RepositoryRoot -Arguments @('closure', $Target)
    try {
        $closure = [string[]] ($raw | ConvertFrom-Json)
    } catch {
        throw "Manifest closure for '$Target' is not valid JSON."
    }
    if ($closure.Count -eq 0 -or ($closure | Select-Object -Unique).Count -ne $closure.Count) {
        throw "Manifest closure for '$Target' must be a non-empty unique list."
    }
    return $closure
}

function Assert-ManifestTarget {
    [CmdletBinding()]
    param(
        [Parameter(Mandatory = $true)]
        [string] $Php,

        [Parameter(Mandatory = $true)]
        [string] $RepositoryRoot,

        [Parameter(Mandatory = $true)]
        [string] $Target
    )

    $raw = Invoke-ManifestTool -Php $Php -RepositoryRoot $RepositoryRoot -Arguments @('targets')
    try {
        $targets = [string[]] ($raw | ConvertFrom-Json)
    } catch {
        throw 'Manifest target output is not valid JSON.'
    }
    if ($Target -notin $targets) {
        throw "Target '$Target' is not declared by the dependency manifest."
    }
}

function Get-VisualStudioToolchain {
    [CmdletBinding()]
    param(
        [Parameter(Mandatory = $true)]
        [ValidateSet('windows-x86_64', 'windows-arm64')]
        [string] $Target
    )

    if ($env:OS -ne 'Windows_NT') {
        throw 'The Windows build entry point must run on Windows.'
    }

    $vswhere = Join-Path ${env:ProgramFiles(x86)} 'Microsoft Visual Studio\Installer\vswhere.exe'
    if (-not (Test-Path -LiteralPath $vswhere -PathType Leaf)) {
        throw 'Visual Studio Installer was not found. Install Visual Studio 2022 with Desktop development with C++.'
    }

    $v143Component = if ($Target -eq 'windows-arm64') {
        'Microsoft.VisualStudio.Component.VC.v143.ARM64'
    } else {
        'Microsoft.VisualStudio.Component.VC.v143.x86.x64'
    }
    $defaultComponent = if ($Target -eq 'windows-arm64') {
        'Microsoft.VisualStudio.Component.VC.Tools.ARM64'
    } else {
        'Microsoft.VisualStudio.Component.VC.Tools.x86.x64'
    }

    # A newer Visual Studio may host the reviewed v143 toolset, but its newer
    # default ABI is not an implicit substitute for the PHP 8.4 build ABI.
    $installPath = (@(& $vswhere -latest -products '*' -version '[17.0,19.0)' -requires $v143Component -property installationPath) -join '').Trim()
    if ([string]::IsNullOrWhiteSpace($installPath)) {
        $installPath = (@(& $vswhere -latest -products '*' -version '[17.0,18.0)' -requires $defaultComponent -property installationPath) -join '').Trim()
    }
    if ($LASTEXITCODE -ne 0 -or [string]::IsNullOrWhiteSpace($installPath)) {
        $architecture = if ($Target -eq 'windows-arm64') { 'ARM64' } else { 'x64/x86' }
        throw "The Visual Studio v143 C++ tools for $architecture were not found. Add Desktop development with C++, the MSVC v143 build tools, and a Windows SDK in Visual Studio Installer."
    }

    $vcvars = Join-Path $installPath 'VC\Auxiliary\Build\vcvarsall.bat'
    if (-not (Test-Path -LiteralPath $vcvars -PathType Leaf)) {
        throw "Visual Studio installation is missing vcvarsall.bat: $installPath"
    }

    $windowsSdkRoot = Join-Path ${env:ProgramFiles(x86)} 'Windows Kits\10\Include'
    if (-not (Test-Path -LiteralPath $windowsSdkRoot -PathType Container)) {
        throw 'A Windows 10 or Windows 11 SDK was not found. Add a Windows SDK through Visual Studio Installer.'
    }

    return [pscustomobject] @{
        InstallationPath = $installPath
        VcVarsAll = $vcvars
        Architecture = if ($Target -eq 'windows-arm64') { 'arm64' } else { 'x64' }
    }
}

function Get-CacheFileName {
    param(
        [Parameter(Mandatory = $true)]
        [pscustomobject] $Source
    )

    $uri = [System.Uri] $Source.url
    $leaf = [System.IO.Path]::GetFileName($uri.AbsolutePath)
    if ([string]::IsNullOrWhiteSpace($leaf) -or $leaf -notmatch '^[A-Za-z0-9._+-]+$') {
        throw "Source '$($Source.id)' URL has an unsafe filename."
    }

    return "$($Source.id)-$($Source.sha256.Substring(0, 16).ToLowerInvariant())-$leaf"
}

function Get-VerifiedDownload {
    [CmdletBinding()]
    param(
        [Parameter(Mandatory = $true)]
        [pscustomobject] $Source,

        [Parameter(Mandatory = $true)]
        [string] $DownloadRoot,

        [switch] $Refresh
    )

    $destination = Join-Path $DownloadRoot (Get-CacheFileName -Source $Source)
    if (Test-Path -LiteralPath $destination -PathType Leaf) {
        $existingHash = (Get-FileHash -LiteralPath $destination -Algorithm SHA256).Hash
        $existingBytes = (Get-Item -LiteralPath $destination).Length
        if ($existingHash -ieq $Source.sha256 -and $existingBytes -eq [uint64] $Source.bytes -and -not $Refresh) {
            Write-Host "Using verified cached source: $($Source.id)"
            return $destination
        }
        throw "Cached source '$destination' does not match its manifest digest, or refresh was requested. Remove that single cache file after reviewing it and retry."
    }

    $temporary = "$destination.part-$PID"
    if (Test-Path -LiteralPath $temporary) {
        throw "Temporary download path already exists: $temporary"
    }

    try {
        Write-Host "Downloading pinned source: $($Source.id)"
        $request = [System.Net.HttpWebRequest]::Create([System.Uri] $Source.url)
        $request.AllowAutoRedirect = $true
        $request.MaximumAutomaticRedirections = 5
        $response = $request.GetResponse()
        try {
            if ($response.ResponseUri.Scheme -ne [System.Uri]::UriSchemeHttps) {
                throw "Source '$($Source.id)' redirected away from HTTPS."
            }
            $expectedBytes = [uint64] $Source.bytes
            if ($response.ContentLength -gt $expectedBytes) {
                throw "Source '$($Source.id)' exceeds its declared byte count."
            }
            $inputStream = $null
            $outputStream = $null
            try {
                $inputStream = $response.GetResponseStream()
                $outputStream = [System.IO.File]::Open(
                    $temporary,
                    [System.IO.FileMode]::CreateNew,
                    [System.IO.FileAccess]::Write,
                    [System.IO.FileShare]::None
                )
                $buffer = New-Object byte[] 81920
                [uint64] $received = 0
                while (($read = $inputStream.Read($buffer, 0, $buffer.Length)) -gt 0) {
                    $received += [uint64] $read
                    if ($received -gt $expectedBytes) {
                        throw "Source '$($Source.id)' exceeded its declared byte count while downloading."
                    }
                    $outputStream.Write($buffer, 0, $read)
                }
                if ($received -ne $expectedBytes) {
                    throw "Source '$($Source.id)' byte count mismatch: expected $expectedBytes, got $received."
                }
            } finally {
                if ($null -ne $outputStream) { $outputStream.Dispose() }
                if ($null -ne $inputStream) { $inputStream.Dispose() }
            }
        } finally {
            $response.Dispose()
        }
        $actualHash = (Get-FileHash -LiteralPath $temporary -Algorithm SHA256).Hash
        if ($actualHash -ine $Source.sha256) {
            throw "SHA-256 mismatch for '$($Source.id)': expected $($Source.sha256), got $actualHash."
        }
        Move-Item -LiteralPath $temporary -Destination $destination
    } finally {
        if (Test-Path -LiteralPath $temporary -PathType Leaf) {
            Remove-Item -LiteralPath $temporary -Force
        }
    }

    return $destination
}

function Expand-VerifiedZip {
    [CmdletBinding()]
    param(
        [Parameter(Mandatory = $true)]
        [string] $Archive,

        [Parameter(Mandatory = $true)]
        [string] $Destination,

        [uint64] $MaximumExpandedBytes = 4294967296,

        [uint32] $MaximumEntries = 200000
    )

    if (Test-Path -LiteralPath $Destination) {
        throw "Archive destination already exists: $Destination"
    }
    [void] (New-Item -ItemType Directory -Path $Destination)
    $root = Get-NormalizedDirectoryPath -Path $Destination
    $prefix = $root + [System.IO.Path]::DirectorySeparatorChar

    Add-Type -AssemblyName System.IO.Compression.FileSystem
    $zip = [System.IO.Compression.ZipFile]::OpenRead($Archive)
    try {
        if ($zip.Entries.Count -gt $MaximumEntries) {
            throw "Archive contains too many entries: $Archive"
        }
        [uint64] $expanded = 0
        foreach ($entry in $zip.Entries) {
            $expanded += [uint64] $entry.Length
            if ($expanded -gt $MaximumExpandedBytes) {
                throw "Archive exceeds the expanded-size limit: $Archive"
            }
            $externalAttributes = [System.BitConverter]::ToUInt32(
                [System.BitConverter]::GetBytes([int32] $entry.ExternalAttributes),
                0
            )
            $unixMode = ($externalAttributes -shr 16) -band 0xF000
            if ($unixMode -eq 0xA000) {
                throw "Archive contains a symbolic link: $($entry.FullName)"
            }
            $target = [System.IO.Path]::GetFullPath((Join-Path $root $entry.FullName))
            if (-not $target.StartsWith($prefix, [System.StringComparison]::OrdinalIgnoreCase)) {
                throw "Archive entry escapes its destination: $($entry.FullName)"
            }
            if ([string]::IsNullOrEmpty($entry.Name)) {
                if (-not (Test-Path -LiteralPath $target)) { [void] (New-Item -ItemType Directory -Path $target) }
                continue
            }
            $parent = Split-Path -Parent $target
            if (-not (Test-Path -LiteralPath $parent)) { [void] (New-Item -ItemType Directory -Path $parent) }
            $inputStream = $entry.Open()
            $output = [System.IO.File]::Open(
                $target,
                [System.IO.FileMode]::CreateNew,
                [System.IO.FileAccess]::Write,
                [System.IO.FileShare]::None
            )
            try { $inputStream.CopyTo($output) } finally { $output.Dispose(); $inputStream.Dispose() }
        }
    } finally {
        $zip.Dispose()
    }

    return $Destination
}

function Merge-VerifiedDependencyTree {
    [CmdletBinding()]
    param(
        [Parameter(Mandatory = $true)]
        [string] $Source,

        [Parameter(Mandatory = $true)]
        [string] $Destination,

        [Parameter(Mandatory = $true)]
        [string] $PackageId
    )

    foreach ($entry in Get-ChildItem -LiteralPath $Source -Force -Recurse) {
        $relative = $entry.FullName.Substring($Source.Length).TrimStart([char[]] '\/')
        if ([string]::IsNullOrWhiteSpace($relative)) { continue }
        $target = Join-Path $Destination $relative
        if ($entry.PSIsContainer) {
            if (Test-Path -LiteralPath $target -PathType Leaf) {
                throw "Dependency package '$PackageId' directory conflicts with a file: $relative"
            }
            if (-not (Test-Path -LiteralPath $target)) {
                [void] (New-Item -ItemType Directory -Path $target)
            }
            continue
        }
        if (Test-Path -LiteralPath $target -PathType Container) {
            throw "Dependency package '$PackageId' file conflicts with a directory: $relative"
        }
        if (Test-Path -LiteralPath $target -PathType Leaf) {
            $sourceHash = (Get-FileHash -LiteralPath $entry.FullName -Algorithm SHA256).Hash
            $targetHash = (Get-FileHash -LiteralPath $target -Algorithm SHA256).Hash
            if ($sourceHash -ine $targetHash) {
                throw "Dependency package '$PackageId' conflicts with another package: $relative"
            }
            continue
        }
        $parent = Split-Path -Parent $target
        if (-not (Test-Path -LiteralPath $parent)) {
            [void] (New-Item -ItemType Directory -Path $parent)
        }
        Copy-Item -LiteralPath $entry.FullName -Destination $target
    }
}

function Assert-SourceSupportsTarget {
    [CmdletBinding()]
    param(
        [Parameter(Mandatory = $true)]
        [pscustomobject] $Source,

        [Parameter(Mandatory = $true)]
        [string] $Target
    )

    if ($Target -notin @($Source.targets)) {
        throw "Manifest source '$($Source.id)' does not declare target '$Target'."
    }
}

function New-BuildInvocationDirectory {
    [CmdletBinding()]
    param(
        [Parameter(Mandatory = $true)]
        [string] $RepositoryRoot,

        [Parameter(Mandatory = $true)]
        [string] $Target
    )

    $parent = New-BoundedBuildDirectory -RepositoryRoot $RepositoryRoot -RelativePath ("build\windows\{0}" -f $Target)
    $name = '{0:yyyyMMdd-HHmmss}-{1}' -f [DateTime]::UtcNow, $PID
    $path = Join-Path $parent $name
    if (Test-Path -LiteralPath $path) {
        throw "Build invocation directory already exists: $path"
    }
    [void] (New-Item -ItemType Directory -Path $path)
    return $path
}

Export-ModuleMember -Function @(
    'Assert-ManifestTarget',
    'Assert-SourceSupportsTarget',
    'Expand-VerifiedZip',
    'Get-ManifestTargetClosure',
    'Get-ManifestSource',
    'Get-VerifiedDownload',
    'Get-VisualStudioToolchain',
    'Merge-VerifiedDependencyTree',
    'New-BoundedBuildDirectory',
    'New-BuildInvocationDirectory',
    'Resolve-BootstrapPhp',
    'Resolve-BoundedBuildPath'
)
