# SQLite backup — keeps 7 rotating copies of database.sqlite
# Schedule via Windows Task Scheduler, e.g.:
#   powershell -ExecutionPolicy Bypass -File "C:\Users\beshoy\source\repos\chessiq\chesiq\scripts\backup-sqlite.ps1"
#
# Safe: uses the SQLite Online Backup API via the .backup command, which works
# even while the Laravel dev server is running (no file-copy locking issues).
#
# On failure, writes to $BackupDir\backup-errors.log so cron-silent failures are visible.

$ErrorActionPreference = 'Stop'

$DbPath     = 'C:\Users\beshoy\source\repos\chessiq\chesiq\database\database.sqlite'
$BackupDir  = 'C:\Users\beshoy\source\repos\chessiq\chesiq\database\backups'
$Keep       = 7

if (-not (Test-Path $DbPath)) {
    Write-Error "Source database not found: $DbPath"
    exit 1
}

if (-not (Test-Path $BackupDir)) {
    New-Item -ItemType Directory -Path $BackupDir -Force | Out-Null
}

$timestamp  = (Get-Date).ToString('yyyyMMdd-HHmmss')
$outFile    = Join-Path $BackupDir "database-$timestamp.sqlite"

try {
    # Prefer sqlite3 CLI if installed (safe online backup while DB is in use)
    $sqliteCmd = Get-Command sqlite3 -ErrorAction SilentlyContinue
    if ($sqliteCmd) {
        & sqlite3 $DbPath ".backup '$outFile'"
        if ($LASTEXITCODE -ne 0) { throw "sqlite3 .backup returned exit code $LASTEXITCODE" }
    } else {
        # Fallback: plain file copy. Works for small hobby DBs where the write cadence is low.
        # WARNING: may copy an inconsistent snapshot if a write is in progress. Install sqlite3 to avoid.
        Copy-Item -Path $DbPath -Destination $outFile -Force
    }

    # Rotate: keep the most recent $Keep files
    Get-ChildItem -Path $BackupDir -Filter 'database-*.sqlite' |
        Sort-Object LastWriteTime -Descending |
        Select-Object -Skip $Keep |
        Remove-Item -Force

    Write-Host "Backup OK: $outFile"
} catch {
    $errLog = Join-Path $BackupDir 'backup-errors.log'
    $msg    = "$(Get-Date -Format 'u')  $($_.Exception.Message)"
    Add-Content -Path $errLog -Value $msg
    Write-Error $msg
    exit 1
}
