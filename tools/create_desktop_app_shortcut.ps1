# Creates a Standalone App Shortcut on Windows Desktops with RideSync.ico
$WshShell = New-Object -ComObject WScript.Shell

$Desktops = @(
    [System.Environment]::GetFolderPath('Desktop'),
    "C:\Users\shaun\OneDrive\Desktop"
)

$IcoPath = "C:\xampp\htdocs\ridesync\RideSync.ico"

# Check for Edge or Chrome
$EdgePath64 = "C:\Program Files\Microsoft\Edge\Application\msedge.exe"
$EdgePath = "C:\Program Files (x86)\Microsoft\Edge\Application\msedge.exe"
$ChromePath = "C:\Program Files\Google\Chrome\Application\chrome.exe"

$ExePath = ""
if (Test-Path $EdgePath64) {
    $ExePath = $EdgePath64
} elseif (Test-Path $EdgePath) {
    $ExePath = $EdgePath
} elseif (Test-Path $ChromePath) {
    $ExePath = $ChromePath
}

foreach ($DesktopPath in $Desktops) {
    if (Test-Path $DesktopPath) {
        $ShortcutPath = Join-Path $DesktopPath "RideSync App.lnk"
        $Shortcut = $WshShell.CreateShortcut($ShortcutPath)
        if ($ExePath -ne "") {
            $Shortcut.TargetPath = $ExePath
            $Shortcut.Arguments = "--app=http://localhost/ridesync"
        } else {
            $Shortcut.TargetPath = "http://localhost/ridesync"
        }
        $Shortcut.IconLocation = "$IcoPath,0"
        $Shortcut.Description = "Launch RideSync Desktop Application"
        $Shortcut.Save()
        Write-Host "Updated App shortcut icon at: $ShortcutPath"
    }
}
