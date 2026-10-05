# Envoie le plugin dans le bac a sable de la Raspberry Pi et l'(re)installe.
# Pendant de scripts/bac-a-sable.sh (Mac).
#
#   .\scripts\bac-a-sable.ps1
#   .\scripts\bac-a-sable.ps1 -Hote nv-pi
#
# Ce fichier reste en ASCII: Windows PowerShell 5.1 lit les scripts sans BOM
# comme de l'ANSI, et les lettres accentuees y casseraient les chaines.

param([string]$Hote = "nv-pi")

$ErrorActionPreference = "Continue"
Set-Location (Resolve-Path (Join-Path $PSScriptRoot ".."))
$dossier = "nyassobi-bac-a-sable"

Write-Host "`n### Envoi du plugin et du bac a sable vers $Hote" -ForegroundColor Cyan
$archive = Join-Path $env:TEMP "bac-a-sable.tgz"
# tar est fourni avec Windows 10 et 11.
tar -czf $archive nyassobi-wp-plugin.php includes bac-a-sable
if ($LASTEXITCODE -ne 0) { Write-Host "ECHEC: archive" -ForegroundColor Red; exit 1 }
scp -q $archive "${Hote}:/tmp/bac-a-sable.tgz"
if ($LASTEXITCODE -ne 0) { Write-Host "ECHEC: copie vers $Hote (acces SSH ?)" -ForegroundColor Red; exit 1 }
Remove-Item $archive

# Le contenu du plugin est remplace en entier, mais pas les dossiers eux-memes:
# les conteneurs les montent, et un dossier recree leur resterait invisible.
$commande = "set -e; mkdir -p ~/$dossier/plugin ~/$dossier/mu && cd ~/$dossier; " +
  "rm -rf .arrivee && mkdir .arrivee && tar -xzf /tmp/bac-a-sable.tgz -C .arrivee && rm -f /tmp/bac-a-sable.tgz; " +
  "find plugin mu -mindepth 1 -delete; " +
  "mv .arrivee/nyassobi-wp-plugin.php .arrivee/includes plugin/; " +
  "cp -r .arrivee/bac-a-sable/. . && rm -rf .arrivee; " +
  "tr -d '\r' < installer.sh > installer.unix && bash installer.unix"
ssh $Hote $commande
if ($LASTEXITCODE -ne 0) { Write-Host "ECHEC: installation sur la Pi" -ForegroundColor Red; exit 1 }

Write-Host "`nBac a sable: http://nv-pi:8504/bac-a-sable/ (Tailscale: http://nv-pi:8504/bac-a-sable/)" -ForegroundColor Green
