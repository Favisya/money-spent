#!/usr/bin/env bash
# Builds "Траты.app" and a .dmg with everything inside: the Symfony app, PHP and PostgreSQL from Homebrew.
# Requirements on the build machine: Xcode command line tools, Homebrew php and postgresql@16, composer.
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/../.." && pwd)"
HERE="$ROOT/packaging/macos"
OUT="$ROOT/build/macos"
APP="$OUT/Траты.app"
RES="$APP/Contents/Resources"
VERSION="${VERSION:-1.0.0}"
BUILD_NUMBER="$(date +%Y%m%d%H%M%S)"
BREW="$(brew --prefix)"

step() { printf '\n==> %s\n' "$1"; }

step "Clean"
rm -rf "$OUT"
mkdir -p "$APP/Contents/MacOS" "$RES/app"

step "Application code"
for item in bin config migrations public src templates composer.json composer.lock symfony.lock .env; do
    cp -R "$ROOT/$item" "$RES/app/"
done
rm -f "$RES/app/config/services_test.yaml"
(
    cd "$RES/app"
    COMPOSER_ALLOW_SUPERUSER=0 APP_ENV=prod composer install \
        --no-dev --classmap-authoritative --no-interaction --no-progress --no-scripts --quiet
)

step "PHP and PostgreSQL runtime"
"$BREW/bin/php" "$HERE/bundle-runtime.php" "$RES/runtime" "$BREW"
cp "$HERE/php.ini" "$HERE/router.php" "$RES/runtime/"

step "Native wrapper"
swiftc -O -target "$(uname -m)-apple-macos13.0" -o "$APP/Contents/MacOS/MoneySpent" "$HERE"/Sources/*.swift
sed -e "s/__VERSION__/$VERSION/" -e "s/__BUILD__/$BUILD_NUMBER/" "$HERE/Info.plist" > "$APP/Contents/Info.plist"

step "Icon"
swift "$HERE/make-icon.swift" "$OUT/AppIcon.iconset"
iconutil -c icns -o "$RES/AppIcon.icns" "$OUT/AppIcon.iconset"
rm -rf "$OUT/AppIcon.iconset"

step "Sign (ad hoc)"
codesign --force --deep --sign - "$APP"
codesign --verify --deep --strict "$APP"

step "Disk image"
STAGE="$OUT/dmg"
mkdir -p "$STAGE"
cp -R "$APP" "$STAGE/"
ln -s /Applications "$STAGE/Applications"
DMG="$ROOT/build/Traty-$VERSION.dmg"
rm -f "$DMG"
hdiutil create -quiet -volname "Траты" -srcfolder "$STAGE" -ov -format UDZO "$DMG"
rm -rf "$STAGE"

printf '\nDone: %s (%s)\n' "$DMG" "$(du -h "$DMG" | cut -f1)"
