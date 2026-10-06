# Envoie le plugin dans la pre-production de la Raspberry Pi et l'(re)installe.
# Pendant de scripts/preprod.sh (Mac).
#
#   .\scripts\preprod.ps1
#   .\scripts\preprod.ps1 -Hote nv-pi
#
# Ce fichier reste en ASCII: Windows PowerShell 5.1 lit les scripts sans BOM
# comme de l'ANSI, et les lettres accentuees y casseraient les chaines.

param([string]$Hote = "nv-pi")

$ErrorActionPreference = "Continue"
Set-Location (Resolve-Path (Join-Path $PSScriptRoot ".."))
$dossier = "nyassobi-preprod"

Write-Host "`n### Envoi du plugin et de la pre-production vers $Hote" -ForegroundColor Cyan
$archive = Join-Path $env:TEMP "preprod.tgz"
# tar est fourni avec Windows 10 et 11.
tar -czf $archive nyassobi-wp-plugin.php includes preprod
if ($LASTEXITCODE -ne 0) { Write-Host "ECHEC: archive" -ForegroundColor Red; exit 1 }
scp -q $archive "${Hote}:/tmp/preprod.tgz"
if ($LASTEXITCODE -ne 0) { Write-Host "ECHEC: copie vers $Hote (acces SSH ?)" -ForegroundColor Red; exit 1 }
Remove-Item $archive

# Le contenu est remplace, pas les dossiers (montes par les conteneurs), et
# .env, propre a la Pi, n'est jamais touche.
$commande = "set -e; mkdir -p ~/$dossier/plugin ~/$dossier/mu && cd ~/$dossier; " +
  "rm -rf .arrivee && mkdir .arrivee && tar -xzf /tmp/preprod.tgz -C .arrivee && rm -f /tmp/preprod.tgz; " +
  "find plugin mu -mindepth 1 -delete; " +
  "mv .arrivee/nyassobi-wp-plugin.php .arrivee/includes plugin/; " +
  "cp -r .arrivee/preprod/. . && rm -rf .arrivee; " +
  "tr -d '\r' < installer.sh > installer.unix && bash installer.unix"
ssh $Hote $commande
if ($LASTEXITCODE -ne 0) { Write-Host "ECHEC: installation sur la Pi" -ForegroundColor Red; exit 1 }
