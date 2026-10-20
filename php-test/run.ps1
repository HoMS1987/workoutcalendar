# Startet garmin_test.php mit den nötigen PHP-Erweiterungen, unabhängig von einer vorhandenen php.ini.
param([Parameter(Mandatory)][string]$Mode, [switch]$PhpToken)
$php = (Get-Command php).Source
$ext = Join-Path (Split-Path $php) 'ext'
# WHY: -n ignoriert die php.ini, sonst würden curl/openssl doppelt geladen (Warnung "already loaded").
$phpArgs = @('-n', '-d', "extension_dir=$ext", '-d', 'extension=curl', '-d', 'extension=openssl',
    (Join-Path $PSScriptRoot 'garmin_test.php'), $Mode)
if ($PhpToken) { $phpArgs += '--php-token' }
& $php @phpArgs
