# Startet garmin_test.php mit den nötigen PHP-Erweiterungen (die winget-Installation hat keine php.ini).
param([Parameter(Mandatory)][string]$Mode, [switch]$PhpToken)
$php = (Get-Command php).Source
$ext = Join-Path (Split-Path $php) 'ext'
$phpArgs = @('-d', "extension_dir=$ext", '-d', 'extension=curl', '-d', 'extension=openssl',
    (Join-Path $PSScriptRoot 'garmin_test.php'), $Mode)
if ($PhpToken) { $phpArgs += '--php-token' }
& $php @phpArgs
