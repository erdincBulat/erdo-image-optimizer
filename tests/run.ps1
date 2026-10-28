<#
.SYNOPSIS
  Runs the Erdo test suites against the WP Studio test site.

.EXAMPLE
  .\tests\run.ps1                    # all suites except perf
  .\tests\run.ps1 -Suite 10-usage    # one suite (name or prefix)
  .\tests\run.ps1 -Perf              # include the slow performance suite
  .\tests\run.ps1 -Lint              # php -l + Plugin Check first
  .\tests\run.ps1 -Keep              # keep fixtures of the last suite for inspection
  .\tests\run.ps1 -Docker erdo-m-a   # run inside a tests/docker matrix container

  Exit code 0 = no failures outside the "expected gap" phases listed in -AllowPhase.
#>
param(
    [string]$Suite = '',
    [string]$Site = "$env:USERPROFILE\Studio\erdo-test",
    [string]$Studio = "$env:LOCALAPPDATA\studio_app\bin\studio.bat",
    [switch]$Perf,
    [switch]$Lint,
    [switch]$Keep,
    [string[]]$AllowPhase = @(),
    [string]$Report = '',
    [string[]]$SuiteArgs = @(),  # key=value pairs passed to suites, e.g. n=5000
    [string]$Docker = ''         # container from tests/docker/matrix.ps1 instead of Studio
)

$ErrorActionPreference = 'Continue'
$testsDir  = $PSScriptRoot
$trunk     = Join-Path (Split-Path $testsDir -Parent) 'trunk'
$bootstrap = Join-Path $testsDir 'bootstrap.php'
if ($Docker) { $bootstrap = '/erdo-tests/bootstrap.php' }

function Invoke-Studio([string[]]$wpArgs) {
    if ($Docker) {
        # Tests are mounted at /erdo-tests, the plugin at wp-content/plugins/erdo-image-optimizer.
        return & docker exec -u www-data -e HOME=/tmp $Docker wp --path=/var/www/html @($wpArgs | ForEach-Object { $_.Trim('"') }) 2>&1
    }
    Push-Location $Site
    try { & cmd /c "`"$Studio`" wp $($wpArgs -join ' ') 2>&1" } finally { Pop-Location }
}

if ($Lint) {
    Write-Host '== php -l ==' -ForegroundColor Cyan
    $bad = 0
    Get-ChildItem $trunk -Recurse -Filter *.php | ForEach-Object {
        $o = & php -l $_.FullName 2>&1
        if ($LASTEXITCODE -ne 0) { $bad++; Write-Host $o -ForegroundColor Red }
    }
    Write-Host "php -l: $bad file(s) with syntax errors"
    Write-Host '== Plugin Check ==' -ForegroundColor Cyan
    Invoke-Studio @('plugin', 'check', 'erdo-image-optimizer', '--format=csv') | Write-Host
}

$suites = Get-ChildItem (Join-Path $testsDir 'suites') -Filter *.php | Sort-Object Name | ForEach-Object { $_.BaseName }
if ($Suite) { $suites = $suites | Where-Object { $_ -like "$Suite*" } }
if (-not $Perf) { $suites = $suites | Where-Object { $_ -notlike '*perf*' } }

$results = @()
$crashed = @()
foreach ($s in $suites) {
    Write-Host "`n== $s ==" -ForegroundColor Cyan
    $extra = @()
    if ($Keep) { $extra += 'keep=1' }
    $extra += $SuiteArgs
    $out = Invoke-Studio (@('eval-file', "`"$bootstrap`"", $s) + $extra)
    $done = $false
    $seen = @{}
    foreach ($line in $out) {
        $l = "$line"
        $p = $l -split '\|'
        switch ($p[0]) {
            'PASS' { $results += [pscustomobject]@{Suite=$p[1];Phase=$p[2];Status='PASS';Name=$p[3];Detail=''}; Write-Host "  PASS  $($p[3])" -ForegroundColor Green }
            'FAIL' { $results += [pscustomobject]@{Suite=$p[1];Phase=$p[2];Status='FAIL';Name=$p[3];Detail=($p[4..($p.Length-1)] -join '|')}
                     $c = if ($p[2] -ne '-') { 'Yellow' } else { 'Red' }
                     Write-Host "  FAIL  [$($p[2])] $($p[3]) - $($p[4..($p.Length-1)] -join '|')" -ForegroundColor $c }
            'SKIP' { $results += [pscustomobject]@{Suite=$p[1];Phase=$p[2];Status='SKIP';Name=$p[3];Detail=$p[4]}; Write-Host "  SKIP  $($p[3]) - $($p[4])" -ForegroundColor DarkGray }
            'INFO' { Write-Host "  info  $($p[2..($p.Length-1)] -join '|')" -ForegroundColor DarkGray }
            'DONE' { $done = $true; if ($p[5] -ne '1') { $crashed += $s } }
            default {
                # PHP notices/warnings are printed once each with a repeat count at the end.
                if ($l.Trim() -and $l -notmatch 'plugin-check') {
                    if ($seen.ContainsKey($l)) { $seen[$l]++ } else { $seen[$l] = 1; Write-Host "  >> $l" -ForegroundColor Magenta }
                }
            }
        }
    }
    $seen.GetEnumerator() | Where-Object { $_.Value -gt 1 } | ForEach-Object { Write-Host "  >> (x$($_.Value)) $($_.Key)" -ForegroundColor DarkMagenta }
    if (-not $done) { $crashed += $s; Write-Host "  !! suite did not report DONE (fatal error?)" -ForegroundColor Red }
}

$pass = ($results | Where-Object Status -eq 'PASS').Count
$fail = @($results | Where-Object Status -eq 'FAIL')
$skip = ($results | Where-Object Status -eq 'SKIP').Count
Write-Host "`n================ SUMMARY ================" -ForegroundColor Cyan
Write-Host "PASS $pass   FAIL $($fail.Count)   SKIP $skip   suites $($suites.Count)"
if ($fail.Count) {
    Write-Host "Failures by target phase (- = must already pass):"
    $fail | Group-Object Phase | Sort-Object Name | ForEach-Object { Write-Host ("  {0,-4} {1}" -f $_.Name, $_.Count) }
}
if ($crashed.Count) { Write-Host "Crashed / incomplete suites: $($crashed -join ', ')" -ForegroundColor Red }

if ($Report) {
    $results | Export-Csv -Path $Report -NoTypeInformation -Encoding UTF8
    Write-Host "Report written to $Report"
}

$blocking = @($fail | Where-Object { $_.Phase -eq '-' -or ($AllowPhase -notcontains $_.Phase) })
if ($blocking.Count -or $crashed.Count) { exit 1 } else { exit 0 }
