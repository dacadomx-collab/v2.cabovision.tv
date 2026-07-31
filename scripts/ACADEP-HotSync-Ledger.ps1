# ==============================================================================
# ACADEP HotSync Ledger - Ecosistema Híbrido v2.0
# Sincronizador Automatizado de Bitácora Inter-AI (Zero-Trust)
# ==============================================================================
$LocalFile  = "C:\xampp\htdocs\CaboVision.tv\knowledge\99_INTER_AI_HANDSHAKE_LEDGER.md"
$SSHUser    = "servidor-acadep"
$SSHHost    = "192.168.1.224"
$RemoteFile = "/home/servidor-acadep/aura-hosting-sentinel/knowledge/99_INTER_AI_HANDSHAKE_LEDGER.md"
$PrivateKey = "$env:USERPROFILE\.ssh\id_ed25519"

# Asegurar existencia del entorno base
$LocalDir = Split-Path $LocalFile
if (!(Test-Path $LocalDir)) { New-Item -ItemType Directory -Path $LocalDir | Out-Null }
if (!(Test-Path $LocalFile)) { New-Item -ItemType File -Path $LocalFile -Value "# INIT" | Out-Null }

$script:LastSyncedHash = ""

function Get-LocalHash {
    return (Get-FileHash $LocalFile -Algorithm SHA256).Hash
}

# Inyección directa de identidad (Bypass de ssh-agent)
function Get-RemoteHash {
    $cmd = "sha256sum $RemoteFile 2>/dev/null"
    # Pasamos la llave directamente con -i para no depender del agente local
    $res = ssh -i $PrivateKey -o StrictHostKeyChecking=accept-new "$($SSHUser)@$($SSHHost)" $cmd
    if ($res -match "^([a-fA-F0-9]{64})") { return $Matches[1].ToUpper() }
    return $null
}

$script:LastSyncedHash = Get-LocalHash
Write-Host "==============================================================================" -ForegroundColor Cyan
Write-Host " ACADEP HOTSYNC SENTINEL ACTIVADO - MODO DE COORDINACIÓN ASÍNCRONA" -ForegroundColor Green
Write-Host "==============================================================================" -ForegroundColor Cyan

# 1. TRANSMISIÓN PROACTIVA (Windows -> Linux)
$watcher = New-Object System.IO.FileSystemWatcher
$watcher.Path = $LocalDir
$watcher.Filter = Split-Path $LocalFile -Leaf
$watcher.NotifyFilter = [System.IO.NotifyFilters]'LastWrite, Size'
$watcher.EnableRaisingEvents = $true

$Action = {
    Start-Sleep -Milliseconds 500
    $currentHash = (Get-FileHash $using:LocalFile -Algorithm SHA256).Hash
    if ($currentHash -ne $script:LastSyncedHash) {
        Write-Host "[PUSH] Cambios detectados en Windows. Sincronizando al búnker Linux..." -ForegroundColor Cyan
        $remoteTarget = "$($using:SSHUser)@$($using:SSHHost):$($using:RemoteFile)"
        scp -i $using:PrivateKey -o StrictHostKeyChecking=accept-new $using:LocalFile $remoteTarget
        if ($LASTEXITCODE -eq 0) {
            $script:LastSyncedHash = $currentHash
            Write-Host " -> OK: Servidor central actualizado con éxito." -ForegroundColor Green
        }
    }
}
Register-ObjectEvent $watcher Changed -Action $Action | Out-Null

# 2. ESCANEO PERIÓDICO DE RETORNO (Linux -> Windows)
while ($true) {
    Start-Sleep -Seconds 15
    $remoteHash = Get-RemoteHash
    if ($remoteHash -and ($remoteHash -ne $script:LastSyncedHash)) {
        $localHashBefore = Get-LocalHash
        if ($localHashBefore -eq $script:LastSyncedHash) {
            Write-Host "[PULL] Cambios detectados en Linux. Actualizando espacio de trabajo local..." -ForegroundColor Magenta
            $remoteSource = "$($SSHUser)@$($SSHHost):$RemoteFile"
            scp -i $PrivateKey -o StrictHostKeyChecking=accept-new $remoteSource $LocalFile
            if ($LASTEXITCODE -eq 0) {
                $script:LastSyncedHash = Get-LocalHash
                Write-Host " -> OK: Entorno Windows XAMPP alineado." -ForegroundColor Green
            }
        }
    }
}