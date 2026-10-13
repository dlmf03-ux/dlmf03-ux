param(
    [Parameter(Mandatory = $true)]
    [string]$Stl,

    [string]$Output = ".\output",

    [double]$SurfaceLevel = 0.0,

    [Nullable[double]]$BaseYaw = $null,

    [string]$Prefix = "",

    [switch]$SaveBlend
)

$ErrorActionPreference = "Stop"
$ProjectRoot = Split-Path -Parent $MyInvocation.MyCommand.Path
$Blender = Join-Path $ProjectRoot "tools\\blender\\blender.exe"
$Template = Join-Path $ProjectRoot "analysis_input\reference-scene.blend"
$Script = Join-Path $ProjectRoot "scripts\render_stl_mvp.py"
$OutputPath = if ([IO.Path]::IsPathRooted($Output)) {
    [IO.Path]::GetFullPath($Output)
} else {
    [IO.Path]::GetFullPath((Join-Path $ProjectRoot $Output))
}
$StlPath = if ([IO.Path]::IsPathRooted($Stl)) {
    [IO.Path]::GetFullPath($Stl)
} else {
    [IO.Path]::GetFullPath((Join-Path $ProjectRoot $Stl))
}

if (-not (Test-Path -LiteralPath $Blender)) { throw "Blender not found: $Blender" }
if (-not (Test-Path -LiteralPath $Template)) { throw "Template not found: $Template" }
if (-not (Test-Path -LiteralPath $StlPath)) { throw "STL not found: $StlPath" }

$Arguments = @(
    "--background", $Template,
    "--python-exit-code", "1",
    "--python", $Script,
    "--",
    "--stl", $StlPath,
    "--output", $OutputPath,
    "--surface-level", $SurfaceLevel.ToString([Globalization.CultureInfo]::InvariantCulture)
)
if ($null -ne $BaseYaw) {
    $Arguments += @("--base-yaw", $BaseYaw.Value.ToString([Globalization.CultureInfo]::InvariantCulture))
}
if ($Prefix) { $Arguments += @("--prefix", $Prefix) }
if ($SaveBlend) { $Arguments += "--save-blend" }

& $Blender @Arguments
if ($LASTEXITCODE -ne 0) { throw "Blender failed with exit code $LASTEXITCODE" }



