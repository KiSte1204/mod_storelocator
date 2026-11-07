#!/bin/sh
# Builds dist/pkg_lwstorelocator-<version>.zip
set -e
cd "$(dirname "$0")"
VERSION=$(sed -n 's:.*<version>\(.*\)</version>.*:\1:p' pkg/pkg_lwstorelocator.xml | head -1)
rm -rf build && mkdir -p build/packages dist
(cd com && zip -rqX ../build/packages/com_lwstorelocator.zip . -x '*.DS_Store')
(cd mod && zip -rqX ../build/packages/mod_lwstorelocator.zip . -x '*.DS_Store')
(cd plg && zip -rqX ../build/packages/plg_task_lwstorelocator.zip . -x '*.DS_Store')
cp -r pkg/pkg_lwstorelocator.xml pkg/script.php pkg/language LICENSE.txt build/
rm -f "dist/pkg_lwstorelocator-$VERSION.zip"
(cd build && zip -rqX "../dist/pkg_lwstorelocator-$VERSION.zip" . -x '*.DS_Store')
rm -rf build
echo "dist/pkg_lwstorelocator-$VERSION.zip"
