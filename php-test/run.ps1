# Starts garmin_test.php with the required PHP extensions, independent of any existing php.ini.
param([Parameter(Mandatory)][string]$Mode, [switch]$PhpToken)
$php = (Get-Command php).Source
$ext = Join-Path (Split-Path $php) 'ext'
# WHY: -n ignores php.ini, otherwise curl/openssl would be loaded twice (warning "already loaded").
$phpArgs = @('-n', '-d', "extension_dir=$ext", '-d', 'extension=curl', '-d', 'extension=openssl',
    (Join-Path $PSScriptRoot 'garmin_test.php'), $Mode)
if ($PhpToken) { $phpArgs += '--php-token' }
& $php @phpArgs
