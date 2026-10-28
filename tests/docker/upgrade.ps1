<#
.SYNOPSIS
  Real 2.2.2 -> 3.0 upgrade in Docker: tags/2.2.2 converts images the old,
  destructive way, then trunk/ replaces it like a plugin update would.

.EXAMPLE
  .\tests\docker\upgrade.ps1     # needs the erdo-matrix:php83-apache-im1 image (matrix.ps1 -Only c)
#>
$ErrorActionPreference = 'Continue'
$here  = $PSScriptRoot
$tests = Split-Path $here -Parent
$repo  = Split-Path $tests -Parent
$name  = 'erdo-m-u'
$image = 'erdo-matrix:php83-apache-im1'
$wp    = '--path=/var/www/html'
$plug  = '/var/www/html/wp-content/plugins/erdo-image-optimizer'

function Exec([string]$cmd) {
    $out = & docker exec -u www-data -e HOME=/tmp $name sh -c $cmd 2>&1
    if ($LASTEXITCODE -ne 0) { Write-Host "[$name] $cmd`n$out" -ForegroundColor Red; exit 1 }
    return $out
}

docker rm -f $name 2>$null | Out-Null
docker exec -e MYSQL_PWD=erdo erdo-m-db mysql -uroot -e 'DROP DATABASE IF EXISTS wp_u; CREATE DATABASE wp_u;' 2>$null | Out-Null
docker run -d --name $name --network erdo-matrix `
    -v "${tests}:/erdo-tests:ro" `
    -v "$(Join-Path $repo 'tags\2.2.2'):/erdo-old:ro" `
    -v "$(Join-Path $repo 'trunk'):/erdo-new:ro" $image | Out-Null
docker exec $name sh -c 'chown www-data:www-data /var/www/html' | Out-Null

Exec "wp core download --skip-content $wp" | Out-Null
Exec "mkdir -p /var/www/html/wp-content/plugins /var/www/html/wp-content/themes /var/www/html/wp-content/uploads" | Out-Null
Exec "wp config create $wp --dbname=wp_u --dbuser=root --dbpass=erdo --dbhost=erdo-m-db --skip-check" | Out-Null
Exec "wp core install $wp --url=http://localhost --title=Upgrade --admin_user=admin --admin_password=password --admin_email=admin@example.test --skip-email" | Out-Null
Exec "wp theme install twentytwentyfour --activate $wp" | Out-Null
Exec "wp rewrite structure '/%postname%/' $wp" | Out-Null
Exec "sed 's/\r$//' /erdo-tests/docker/htaccess-single > /var/www/html/.htaccess" | Out-Null

Write-Host '== 2.2.2: seed and convert ==' -ForegroundColor Cyan
Exec "cp -r /erdo-old $plug && wp plugin activate erdo-image-optimizer $wp" | Out-Null
Exec "wp eval-file /erdo-tests/docker/upgrade-seed.php $wp" | Write-Host

Write-Host '== update to 3.0 ==' -ForegroundColor Cyan
Exec "rm -rf $plug && cp -r /erdo-new $plug && wp eval 'echo ERDO_VERSION;' $wp" | Write-Host
Exec "wp eval-file /erdo-tests/docker/upgrade-check.php before $wp" | Write-Host

Write-Host '== wp erdo migrate ==' -ForegroundColor Cyan
Exec "wp erdo migrate $wp" | Write-Host
Exec "wp erdo index $wp" | Write-Host
Exec "wp eval-file /erdo-tests/docker/upgrade-check.php after $wp" | Write-Host
