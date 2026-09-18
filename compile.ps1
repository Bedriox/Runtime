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

$buildScript = Join-Path $PSScriptRoot 'scripts\windows\build.ps1'
if (-not (Test-Path -LiteralPath $buildScript -PathType Leaf)) {
    throw "Windows build entry point is missing: $buildScript"
}

& $buildScript @PSBoundParameters
exit $LASTEXITCODE
