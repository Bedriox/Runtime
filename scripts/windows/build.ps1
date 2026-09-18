#requires -Version 5.1

[CmdletBinding()]
param(
    [ValidateSet('windows-x86_64', 'windows-arm64')]
    [string] $Target = 'windows-x86_64',

    [switch] $Experimental,

    [switch] $PreflightOnly,

    [switch] $DownloadOnly,

    [string] $BootstrapPhp
)

Set-StrictMode -Version Latest
$ErrorActionPreference = 'Stop'

$repositoryRoot = [System.IO.Path]::GetFullPath((Join-Path $PSScriptRoot '..\..'))
$modulePath = Join-Path $PSScriptRoot 'RuntimeBuild.psm1'
Import-Module $modulePath -Force

try {
    if ($Target -eq 'windows-arm64' -and -not $Experimental) {
        throw "Windows ARM64 is experimental. Re-run with -Experimental only on a native Windows ARM64 host prepared for qualification."
    }
    if ($Target -eq 'windows-arm64' -and $env:PROCESSOR_ARCHITECTURE -ne 'ARM64') {
        throw 'Windows ARM64 builds require a native ARM64 host; cross-compilation is not a qualification substitute.'
    }

    $php = Resolve-BootstrapPhp -RequestedPath $BootstrapPhp
    Assert-ManifestTarget -Php $php -RepositoryRoot $repositoryRoot -Target $Target
    $toolchain = Get-VisualStudioToolchain -Target $Target

    $downloadRoot = New-BoundedBuildDirectory -RepositoryRoot $repositoryRoot -RelativePath 'downloads'
    [void] (New-BoundedBuildDirectory -RepositoryRoot $repositoryRoot -RelativePath 'cache')
    [void] (New-BoundedBuildDirectory -RepositoryRoot $repositoryRoot -RelativePath 'sources')
    [void] (New-BoundedBuildDirectory -RepositoryRoot $repositoryRoot -RelativePath 'artifacts')

    Write-Host "Target: $Target"
    Write-Host "Visual Studio: $($toolchain.InstallationPath)"
    Write-Host "Toolchain architecture: $($toolchain.Architecture)"

    if ($PreflightOnly) {
        Write-Host 'Windows build preflight passed.'
        exit 0
    }

    $closure = Get-ManifestTargetClosure -Php $php -RepositoryRoot $repositoryRoot -Target $Target
    $sources = @()
    foreach ($id in $closure) {
        $source = Get-ManifestSource -Php $php -RepositoryRoot $repositoryRoot -Id $id
        Assert-SourceSupportsTarget -Source $source -Target $Target
        $archive = Get-VerifiedDownload -Source $source -DownloadRoot $downloadRoot
        $sources += [pscustomobject] @{
            Id = $id
            Archive = $archive
            Sha256 = $source.sha256.ToLowerInvariant()
            Source = $source
        }
    }

    if ($DownloadOnly) {
        Write-Host "Verified $($sources.Count) pinned inputs."
        exit 0
    }

    $invocationRoot = New-BuildInvocationDirectory -RepositoryRoot $repositoryRoot -Target $Target
    $nativeBuild = Join-Path $PSScriptRoot 'native-build.ps1'
    $result = & $nativeBuild -RepositoryRoot $repositoryRoot -Target $Target -Toolchain $toolchain -Sources $sources -InvocationRoot $invocationRoot
    if ($LASTEXITCODE -ne 0) { throw "Native build stage failed with exit code $LASTEXITCODE." }
    Write-Host "Qualified runtime staged at: $($result.InstallRoot)"
    Write-Host "Qualified artifact: $($result.Artifact)"
} catch {
    [Console]::Error.WriteLine("Bedriox Runtime build failed: $($_.Exception.Message)")
    exit 1
}
