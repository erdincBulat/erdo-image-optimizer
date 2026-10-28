<#
.SYNOPSIS
  Faz 9 test matrix in Docker: PHP 7.4 / 8.1 / 8.3, WP 6.5 / 6.9 / latest,
  GD only / Imagick, Apache (.htaccess) / nginx (plugin snippet), multisite.

.EXAMPLE
  .\tests\docker\matrix.ps1                 # build, install and test every combo
  .\tests\docker\matrix.ps1 -Only a,d       # selected combos
  .\tests\docker\matrix.ps1 -Setup          # install only, no tests
  .\tests\docker\matrix.ps1 -Down           # remove containers, network and volumes

  Containers are named erdo-m-<id>; run single suites with
  .\tests\run.ps1 -Docker erdo-m-<id> -Suite 40-delivery
  The plugin (trunk/) and tests/ are mounted read-only.
#>
param(
    [string[]]$Only = @(),
    [switch]$Setup,
    [switch]$Down,
    [string]$ReportDir = ''
)

$ErrorActionPreference = 'Continue'
$here  = $PSScriptRoot
$tests = Split-Path $here -Parent
$trunk = Join-Path (Split-Path $tests -Parent) 'trunk'
$net   = 'erdo-matrix'
$db    = 'erdo-m-db'

$combos = @(
    @{ id = 'a'; php = '7.4'; wp = '6.5';    variant = 'apache'; imagick = 0; multisite = $false },
    @{ id = 'b'; php = '8.1'; wp = '6.9';    variant = 'apache'; imagick = 1; multisite = $false },
    @{ id = 'c'; php = '8.3'; wp = 'latest'; variant = 'apache'; imagick = 1; multisite = $false },
    @{ id = 'd'; php = '8.3'; wp = 'latest'; variant = 'fpm';    imagick = 0; multisite = $false },
    @{ id = 'e'; php = '8.3'; wp = 'latest'; variant = 'apache'; imagick = 1; multisite = $true }
)
if ($Only.Count) { $combos = $combos | Where-Object { $Only -contains $_.id } }

function Exec([string]$name, [string]$cmd) {
    $out = & docker exec -u www-data -e HOME=/tmp $name sh -c $cmd 2>&1
    if ($LASTEXITCODE -ne 0) { throw "[$name] $cmd`n$out" }
    return $out
}

if ($Down) {
    docker ps -a --format '{{.Names}}' | Where-Object { $_ -like 'erdo-m-*' } | ForEach-Object { docker rm -f $_ | Out-Null }
    docker network rm $net 2>$null | Out-Null
    Write-Host 'matrix removed'
    return
}

if (-not (docker network ls --format '{{.Name}}' | Where-Object { $_ -eq $net })) { docker network create $net | Out-Null }
if (-not (docker ps -a --format '{{.Names}}' | Where-Object { $_ -eq $db })) {
    docker run -d --name $db --network $net -e MYSQL_ROOT_PASSWORD=erdo mysql:8.4 | Out-Null
}
docker start $db | Out-Null
Write-Host 'waiting for MySQL...'
for ($i = 0; $i -lt 60; $i++) {
    docker exec -e MYSQL_PWD=erdo $db mysqladmin ping -uroot --silent 2>$null | Out-Null
    if ($LASTEXITCODE -eq 0) { break }
    Start-Sleep -Seconds 2
}

$summary = @()
foreach ($c in $combos) {
    $name  = "erdo-m-$($c.id)"
    $phpTag = $c.php -replace '\.', ''
    $image = "erdo-matrix:php$phpTag-$($c.variant)-im$($c.imagick)"
    Write-Host "`n######## $name  PHP $($c.php)  WP $($c.wp)  $($c.variant)  imagick=$($c.imagick)  multisite=$($c.multisite)" -ForegroundColor Cyan

    if (-not (docker images -q $image)) {
        docker build -t $image --build-arg PHP=$($c.php) --build-arg VARIANT=$($c.variant) --build-arg IMAGICK=$($c.imagick) $here
        if ($LASTEXITCODE -ne 0) { $summary += "$($c.id)  BUILD FAILED $image"; continue }
    }

    docker rm -f $name 2>$null | Out-Null
    docker exec -e MYSQL_PWD=erdo $db mysql -uroot -e "DROP DATABASE IF EXISTS wp_$($c.id); CREATE DATABASE wp_$($c.id);" 2>$null | Out-Null
    docker run -d --name $name --network $net `
        -v "${trunk}:/var/www/html/wp-content/plugins/erdo-image-optimizer:ro" `
        -v "${tests}:/erdo-tests:ro" $image | Out-Null
    docker exec $name sh -c 'chown www-data:www-data /var/www/html /var/www/html/wp-content /var/www/html/wp-content/plugins' | Out-Null

    $ver = if ($c.wp -eq 'latest') { '' } else { "--version=$($c.wp)" }
    Exec $name "wp core download $ver --skip-content --path=/var/www/html" | Out-Null
    Exec $name "mkdir -p /var/www/html/wp-content/themes /var/www/html/wp-content/uploads" | Out-Null
    Exec $name "wp config create --path=/var/www/html --dbname=wp_$($c.id) --dbuser=root --dbpass=erdo --dbhost=$db --skip-check" | Out-Null
    foreach ($k in 'WP_DEBUG', 'WP_DEBUG_LOG') { Exec $name "wp config set $k true --raw --path=/var/www/html" | Out-Null }
    Exec $name "wp config set WP_DEBUG_DISPLAY false --raw --path=/var/www/html" | Out-Null
    $install = if ($c.multisite) { 'multisite-install' } else { 'install' }
    Exec $name "wp core $install --path=/var/www/html --url=http://localhost --title='Erdo Matrix $($c.id)' --admin_user=admin --admin_password=password --admin_email=admin@example.test --skip-email" | Out-Null
    Exec $name "wp theme install twentytwentyfour --activate --path=/var/www/html" | Out-Null
    Exec $name "wp rewrite structure '/%postname%/' --path=/var/www/html" | Out-Null
    if ($c.variant -eq 'apache') {
        # WP-CLI cannot detect mod_rewrite, so it never writes .htaccess itself.
        $ht = if ($c.multisite) { 'htaccess-multisite' } else { 'htaccess-single' }
        Exec $name "sed 's/\r$//' /erdo-tests/docker/$ht > /var/www/html/.htaccess" | Out-Null
    }
    $network = if ($c.multisite) { '--network' } else { '' }
    Exec $name "wp plugin activate erdo-image-optimizer $network --path=/var/www/html" | Out-Null
    if ($c.variant -eq 'fpm') {
        Exec $name "wp eval-file /erdo-tests/docker/nginx-snippet.php --path=/var/www/html" | Out-Null
        docker exec $name sh -c 'nginx -t && nginx -s reload' 2>&1 | Out-Null
    }
    $info = Exec $name "wp eval-file /erdo-tests/docker/info.php --path=/var/www/html"
    Write-Host "  ready: $info"

    if ($Setup) { continue }
    $report = ''
    if ($ReportDir) { $report = Join-Path $ReportDir "results-faz9-$($c.id).csv" }
    & (Join-Path $tests 'run.ps1') -Docker $name -Report $report | Tee-Object -Variable out | Out-Host
    $line = ($out | Where-Object { "$_" -match '^PASS \d+' } | Select-Object -Last 1)
    $summary += "{0}  PHP {1,-4} WP {2,-6} {3,-6} imagick={4} ms={5}  {6}" -f $c.id, $c.php, $c.wp, $c.variant, $c.imagick, $c.multisite, $line
}

if ($summary.Count) {
    Write-Host "`n================ MATRIX ================" -ForegroundColor Cyan
    $summary | ForEach-Object { Write-Host $_ }
}
