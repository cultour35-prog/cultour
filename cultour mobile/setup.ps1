param(
  [string]$ProjectName = "cultour_mobile",
  [string]$Org = "com.ormoc.cultour"
)

$ErrorActionPreference = "Stop"
Set-Location $PSScriptRoot

function Find-Flutter {
  $cmd = Get-Command flutter -ErrorAction SilentlyContinue
  if ($cmd) { return $cmd.Source }
  $guesses = @(
    "$env:LOCALAPPDATA\flutter\bin\flutter.bat",
    "C:\flutter\bin\flutter.bat",
    "C:\src\flutter\bin\flutter.bat",
    "C:\src\flutter\flutter\bin\flutter.bat",
    "$env:USERPROFILE\flutter\bin\flutter.bat",
    "$env:USERPROFILE\develop\flutter\bin\flutter.bat",
    "$env:USERPROFILE\Downloads\flutter_windows_3.47.4-stable\flutter\bin\flutter.bat"
  )
  foreach ($g in $guesses) {
    if (Test-Path $g) { return $g }
  }
  return $null
}

$flutter = Find-Flutter
if (-not $flutter) {
  Write-Host "Flutter SDK not found on PATH."
  Write-Host "Install it from https://docs.flutter.dev/get-started/install/windows"
  Write-Host "then re-run this script."
  exit 1
}

Write-Host "Using $flutter"
& $flutter --version

$backup = Join-Path $env:TEMP "cultour_mobile_src_backup"
if (Test-Path $backup) { Remove-Item $backup -Recurse -Force }
New-Item -ItemType Directory -Path $backup | Out-Null
Copy-Item -Recurse "lib" $backup
Copy-Item "pubspec.yaml" $backup
if (Test-Path "test") { Copy-Item -Recurse "test" $backup }
Copy-Item "analysis_options.yaml" $backup -ErrorAction SilentlyContinue
Copy-Item "README.md" $backup -ErrorAction SilentlyContinue

& $flutter create . --project-name $ProjectName --org $Org --platforms=android,ios,windows
if ($LASTEXITCODE -ne 0) { exit $LASTEXITCODE }

Copy-Item -Recurse -Force (Join-Path $backup "lib") $PSScriptRoot
Copy-Item -Force (Join-Path $backup "pubspec.yaml") $PSScriptRoot
if (Test-Path (Join-Path $backup "test")) {
  Copy-Item -Recurse -Force (Join-Path $backup "test") $PSScriptRoot
}
Copy-Item -Force (Join-Path $backup "analysis_options.yaml") $PSScriptRoot -ErrorAction SilentlyContinue
Copy-Item -Force (Join-Path $backup "README.md") $PSScriptRoot -ErrorAction SilentlyContinue

& $flutter pub get
if ($LASTEXITCODE -ne 0) { exit $LASTEXITCODE }

$manifest = Join-Path $PSScriptRoot "android\app\src\main\AndroidManifest.xml"
if (Test-Path $manifest) {
  $xml = Get-Content $manifest -Raw
  if ($xml -notmatch "usesCleartextTraffic") {
    $xml = $xml -replace "<application", "<application android:usesCleartextTraffic=`"true`""
    Set-Content -Path $manifest -Value $xml -Encoding UTF8
    Write-Host "Enabled cleartext HTTP in AndroidManifest.xml (needed for XAMPP)."
  }
}

$plist = Join-Path $PSScriptRoot "ios\Runner\Info.plist"
if (Test-Path $plist) {
  $plistXml = Get-Content $plist -Raw
  if ($plistXml -notmatch "NSAppTransportSecurity") {
    $plistXml = $plistXml -replace "</dict>\s*</plist>", @"
	<key>NSAppTransportSecurity</key>
	<dict>
		<key>NSAllowsArbitraryLoads</key>
		<true/>
	</dict>
</dict>
</plist>
"@
    Set-Content -Path $plist -Value $plistXml -Encoding UTF8
    Write-Host "Allowed local HTTP in iOS Info.plist (needed for XAMPP)."
  }
}
Write-Host "  flutter run"
Write-Host ""
Write-Host "Physical phone API URL example: http://192.168.1.10/cultour/api"
Write-Host "Set it in the app: Profile -> API server"
