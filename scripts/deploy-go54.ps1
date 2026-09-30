[CmdletBinding()]
param (
    [string] $SshTarget = 'tconnect-production',
    [string] $RemoteAppRoot = '/home/tconnec4/pospilot-app',
    [string] $RemotePublicBuildRoot = '/home/tconnec4/domains/pospilot.tconnect.com.ng/public_html/build',
    [switch] $Deploy,
    [switch] $RunMigrations,
    [string] $IdentityFile
)

$ErrorActionPreference = 'Stop'

function Assert-SafeRemotePath {
    param ([Parameter(Mandatory)][string] $Path, [Parameter(Mandatory)][string] $Label)

    if ($Path -notmatch '^/[A-Za-z0-9._/-]+$' -or $Path -match '(^|/)\.\.(/|$)' -or $Path -match '//') {
        throw "$Label must be an absolute POSIX path containing only letters, numbers, slash, dot, underscore, and dash."
    }
}

function Copy-ReleaseTree {
    param ([Parameter(Mandatory)][string] $RelativePath)

    $source = Join-Path $script:RepositoryRoot $RelativePath
    if (-not (Test-Path -LiteralPath $source -PathType Container)) {
        return
    }

    $sourceRoot = (Resolve-Path -LiteralPath $source).Path
    $items = @(Get-ChildItem -LiteralPath $sourceRoot -Force -Recurse)
    foreach ($item in $items) {
        if (($item.Attributes -band [IO.FileAttributes]::ReparsePoint) -ne 0) {
            continue
        }

        $relativeItem = $item.FullName.Substring($sourceRoot.Length).TrimStart([IO.Path]::DirectorySeparatorChar, [IO.Path]::AltDirectorySeparatorChar)
        $parts = $relativeItem -split '[\\/]'
        $sensitiveExtension = $item.Extension -in @('.pdf', '.csv', '.xlsx', '.xls', '.pem', '.p12', '.pfx', '.key', '.sqlite', '.db', '.enc')
        $sensitiveName = $item.Name -match '^(authorized_keys|id_(rsa|ed25519)|credentials)(\.|$)'
        if ($sensitiveExtension -or $sensitiveName -or ($parts | Where-Object { $_ -in @('.git', 'node_modules', 'vendor', 'storage', 'tests', 'build', 'cache') -or $_ -like '.env*' })) {
            continue
        }

        $destination = Join-Path (Join-Path $script:StageRoot $RelativePath) $relativeItem
        if ($item.PSIsContainer) {
            New-Item -ItemType Directory -Path $destination -Force | Out-Null
        } else {
            $parent = Split-Path -Parent $destination
            New-Item -ItemType Directory -Path $parent -Force | Out-Null
            Copy-Item -LiteralPath $item.FullName -Destination $destination -Force
        }
    }
}

$script:RepositoryRoot = (Resolve-Path (Join-Path $PSScriptRoot '..')).Path
$requiredRootFiles = @('artisan', 'composer.json', 'composer.lock')
foreach ($relativePath in $requiredRootFiles) {
    if (-not (Test-Path -LiteralPath (Join-Path $script:RepositoryRoot $relativePath) -PathType Leaf)) {
        throw "Required release file is missing: $relativePath"
    }
}

$script:StageRoot = Join-Path ([IO.Path]::GetTempPath()) ("pospilot-go54-" + [guid]::NewGuid().ToString('N'))
$bundlePath = "${script:StageRoot}.tar.gz"
$remoteScriptPath = "${script:StageRoot}.remote.sh"
$releaseId = (Get-Date).ToUniversalTime().ToString('yyyyMMdd-HHmmss') + '-' + (git -C $script:RepositoryRoot rev-parse --short HEAD).Trim()
$remoteBundlePath = $null
$remoteScriptUploadPath = $null

try {
    New-Item -ItemType Directory -Path $script:StageRoot -Force | Out-Null

    foreach ($directory in @('app', 'bootstrap', 'config', 'lang', 'resources', 'routes', 'public')) {
        Copy-ReleaseTree -RelativePath $directory
    }
    Copy-ReleaseTree -RelativePath 'database/migrations'

    foreach ($relativePath in $requiredRootFiles) {
        Copy-Item -LiteralPath (Join-Path $script:RepositoryRoot $relativePath) -Destination (Join-Path $script:StageRoot $relativePath)
    }

    $buildSource = Join-Path $script:RepositoryRoot 'public/build'
    if (-not (Test-Path -LiteralPath (Join-Path $buildSource 'manifest.json') -PathType Leaf)) {
        throw 'The production frontend build is missing. Run npm run build first.'
    }
    New-Item -ItemType Directory -Path (Join-Path $script:StageRoot 'public/build') -Force | Out-Null
    Copy-Item -Path (Join-Path $buildSource '*') -Destination (Join-Path $script:StageRoot 'public/build') -Recurse -Force

    & tar.exe -czf $bundlePath -C $script:StageRoot .
    if ($LASTEXITCODE -ne 0) {
        throw 'Could not create the release archive.'
    }
    $archiveEntries = @(& tar.exe -tzf $bundlePath)
    $protectedArchiveEntry = $archiveEntries | Where-Object { $_ -match '(^|/)\.env([^/]*)(/|$)|(^|/)(vendor|storage|node_modules|\.git|tests)(/|$)|\.(pdf|csv|xlsx?|pem|p12|pfx|key|sqlite|db|enc)$' } | Select-Object -First 1
    if ($LASTEXITCODE -ne 0 -or $protectedArchiveEntry) {
        throw 'The release archive contains a protected path or could not be inspected.'
    }

    $archiveInfo = Get-Item -LiteralPath $bundlePath
    Write-Host "Release package ready: $releaseId ($([math]::Round($archiveInfo.Length / 1MB, 1)) MB)"
    Write-Host 'Package includes Laravel runtime source, reviewed migrations, and public/build only.'
    Write-Host 'Excluded: .env files, vendor, storage, tests, node_modules, Git metadata, and local statement files.'

    if (-not $Deploy) {
        Write-Host 'Dry run only. Add -Deploy after SSH is enabled and the two Go54 server paths are verified.'
        return
    }

    if ([string]::IsNullOrWhiteSpace($SshTarget) -or $SshTarget -notmatch '^[A-Za-z0-9._@-]+$') {
        throw 'Supply -SshTarget as a configured SSH alias or username@host.'
    }
    if ([string]::IsNullOrWhiteSpace($RemoteAppRoot) -or [string]::IsNullOrWhiteSpace($RemotePublicBuildRoot)) {
        throw 'Supply the verified Laravel release root and the domain public build directory from the Go54 account.'
    }
    Assert-SafeRemotePath -Path $RemoteAppRoot -Label 'RemoteAppRoot'
    Assert-SafeRemotePath -Path $RemotePublicBuildRoot -Label 'RemotePublicBuildRoot'
    if ($IdentityFile) {
        if (-not (Test-Path -LiteralPath $IdentityFile -PathType Leaf)) {
            throw 'The specified SSH identity file does not exist.'
        }
        $sshIdentityArguments = @('-i', (Resolve-Path -LiteralPath $IdentityFile).Path)
    } else {
        $sshIdentityArguments = @()
    }

    $remoteBundlePath = "$RemoteAppRoot/releases/.upload-$releaseId.tar.gz"
    $remoteScriptUploadPath = "$RemoteAppRoot/releases/.deploy-$releaseId.sh"
    $migrationCommand = if ($RunMigrations) { 'php artisan migrate --force' } else { 'echo "Migrations skipped by request."' }
    $remoteScript = @'
set -euo pipefail
APP_ROOT='@APP_ROOT@'
PUBLIC_BUILD_ROOT='@PUBLIC_BUILD_ROOT@'
RELEASE_ID='@RELEASE_ID@'
BUNDLE='@BUNDLE@'
MIGRATION_COMMAND='@MIGRATION_COMMAND@'
CURRENT="$APP_ROOT/current"
RELEASES="$APP_ROOT/releases"

test -d "$APP_ROOT" || { echo 'Laravel app root not found.' >&2; exit 20; }
test -L "$CURRENT" || { echo 'Current release is not a symlink; refusing in-place deployment.' >&2; exit 21; }
OLD_RELEASE="$(readlink -f "$CURRENT")"
case "$OLD_RELEASE" in "$RELEASES"/*) ;; *) echo 'Current target is outside releases; refusing activation.' >&2; exit 22 ;; esac
test -f "$CURRENT/artisan" || { echo 'Current release is not a Laravel app.' >&2; exit 23; }
test -L "$CURRENT/.env" && test -f "$CURRENT/.env" || { echo 'Production environment link is missing; refusing to copy or replace it.' >&2; exit 24; }
test -L "$CURRENT/storage" && test -d "$CURRENT/storage" || { echo 'Shared storage link is missing; refusing to copy or replace it.' >&2; exit 25; }
test -f "$CURRENT/vendor/autoload.php" || { echo 'Current Composer dependencies are missing.' >&2; exit 26; }
ENV_TARGET="$(readlink -f "$CURRENT/.env")"
STORAGE_TARGET="$(readlink -f "$CURRENT/storage")"
test -f "$ENV_TARGET" && test -d "$STORAGE_TARGET"
test -d "$PUBLIC_BUILD_ROOT" || { echo 'Verified domain public build directory not found.' >&2; exit 27; }

NEW_RELEASE="$RELEASES/$RELEASE_ID"
test ! -e "$NEW_RELEASE" || { echo 'Release already exists; refusing overwrite.' >&2; exit 28; }
mkdir -p "$RELEASES" "$NEW_RELEASE" "$PUBLIC_BUILD_ROOT"
cleanup_uploads() { rm -f "$BUNDLE" "$RELEASES/.deploy-$RELEASE_ID.sh"; }
trap cleanup_uploads EXIT
tar -xzf "$BUNDLE" -C "$NEW_RELEASE"
rm -f "$BUNDLE"
ln -s "$ENV_TARGET" "$NEW_RELEASE/.env"
ln -s "$STORAGE_TARGET" "$NEW_RELEASE/storage"
mkdir -p "$NEW_RELEASE/bootstrap/cache"
if [ -L "$CURRENT/public/storage" ]; then
    ln -s "$(readlink -f "$CURRENT/public/storage")" "$NEW_RELEASE/public/storage"
fi

cd "$NEW_RELEASE"
composer install --no-dev --prefer-dist --optimize-autoloader --no-interaction
cp -a "$NEW_RELEASE/public/build/." "$PUBLIC_BUILD_ROOT/"
eval "$MIGRATION_COMMAND"
php artisan config:cache
php artisan route:cache
php artisan view:cache

ln -s "$NEW_RELEASE" "$APP_ROOT/current.next"
mv -Tf "$APP_ROOT/current.next" "$CURRENT"
if ! curl --fail --silent --show-error --output /dev/null --max-time 20 'https://pospilot.tconnect.com.ng/' \
    || ! curl --fail --silent --show-error --output /dev/null --max-time 20 'https://pospilot.tconnect.com.ng/build/manifest.json'; then
    ln -s "$OLD_RELEASE" "$APP_ROOT/current.rollback-$RELEASE_ID"
    mv -Tf "$APP_ROOT/current.rollback-$RELEASE_ID" "$CURRENT"
    echo 'Production smoke test failed; previous release restored.' >&2
    exit 30
fi

rm -f "$APP_ROOT/releases/.deploy-$RELEASE_ID.sh"
echo "Activated release $RELEASE_ID. Previous release retained at $OLD_RELEASE."
'@
    $remoteScript = $remoteScript.Replace('@APP_ROOT@', $RemoteAppRoot).Replace('@PUBLIC_BUILD_ROOT@', $RemotePublicBuildRoot).Replace('@RELEASE_ID@', $releaseId).Replace('@BUNDLE@', $remoteBundlePath).Replace('@MIGRATION_COMMAND@', $migrationCommand)
    $remoteScript = $remoteScript.Replace("`r`n", "`n")
    [IO.File]::WriteAllText($remoteScriptPath, $remoteScript, [Text.UTF8Encoding]::new($false))

    $sshBaseArguments = @('-o', 'BatchMode=yes') + $sshIdentityArguments
    & ssh @sshBaseArguments $SshTarget "test -d '$RemoteAppRoot/releases' && test -L '$RemoteAppRoot/current' && test -d '$RemotePublicBuildRoot'"
    if ($LASTEXITCODE -ne 0) {
        throw 'SSH preflight failed. Confirm Go54 SSH access, host-key trust, and the verified release/public paths.'
    }
    & scp @sshIdentityArguments -- $bundlePath "${SshTarget}:$remoteBundlePath"
    if ($LASTEXITCODE -ne 0) {
        throw 'Could not upload the release package.'
    }
    & scp @sshIdentityArguments -- $remoteScriptPath "${SshTarget}:$remoteScriptUploadPath"
    if ($LASTEXITCODE -ne 0) {
        throw 'Could not upload the deploy procedure.'
    }

    $sshRunArguments = @('-o', 'BatchMode=yes') + $sshIdentityArguments
    & ssh @sshRunArguments $SshTarget "bash '$remoteScriptUploadPath'"
    if ($LASTEXITCODE -ne 0) {
        throw 'Remote deployment did not complete. The previous release remains active unless the script reports successful activation.'
    }
} finally {
    if (Test-Path -LiteralPath $script:StageRoot) {
        Remove-Item -LiteralPath $script:StageRoot -Recurse -Force
    }
    if (Test-Path -LiteralPath $bundlePath) {
        Remove-Item -LiteralPath $bundlePath -Force
    }
    if (Test-Path -LiteralPath $remoteScriptPath) {
        Remove-Item -LiteralPath $remoteScriptPath -Force
    }
}
