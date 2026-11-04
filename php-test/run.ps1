# Starts garmin_test.php with the required PHP extensions, independent of any existing php.ini.
param([Parameter(Mandatory)][string]$Mode, [switch]$PhpToken)
$php = (Get-Command php).Source
$ext = Join-Path (Split-Path $php) 'ext'
# WHY: -n ignores php.ini, otherwise curl/openssl would be loaded twice (warning "already loaded").
# Mode apikey runs apikey_test.php (personal API key with the app's own classes), every other mode garmin_test.php.
$script = if ($Mode -eq 'apikey') { 'apikey_test.php' } else { 'garmin_test.php' }
# WHY the CA bundle: without php.ini, curl has no trust anchors on Windows. garmin_test.php uses the
# Windows store itself; apikey_test.php runs the app's CurlHttpClient unchanged and takes Git's bundle.
$caBundle = Join-Path (Split-Path (Split-Path (git --exec-path))) 'etc/ssl/certs/ca-bundle.crt'
$phpArgs = @('-n', '-d', "extension_dir=$ext", '-d', 'extension=curl', '-d', 'extension=openssl',
    '-d', 'extension=mbstring', '-d', "curl.cainfo=$caBundle", (Join-Path $PSScriptRoot $script))
if ($Mode -ne 'apikey') { $phpArgs += $Mode }
if ($PhpToken) { $phpArgs += '--php-token' }
& $php @phpArgs
