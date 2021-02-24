#!/bin/bash

parent_path=$( cd "$(dirname "${BASH_SOURCE[0]}")" ; pwd -P );
cd "$parent_path";

mkdir -p ../../modules;
mkdir -p ../../ajax;
mkdir -p ../../assets/src;
mkdir -p ../../themes;
mkdir -p ../../Logs;

mkdir -p ../../themes/default;
touch ../../themes/default/index.html;
touch ../../themes/default/error.html;

cp ../web/index.php ../../;
cp ../web/config.json ../../;
cp ../web/service-worker.js ../../;
cp ../web/.htaccess ../../;
cp ../web/.gitignore ../../;
cp ../web/settings.json ../../;
cp ../web/manifest.json ../../;
cp -avR ../web/modules/* ../../modules/;

wget -O ../../logo.png https://picsum.photos/300/300;
wget -O ../../favicon.png https://picsum.photos/16/16;

#cd assets/src && ln -s ../../core/web/assets/src/defaults.ts
cp -avR ../web/assets/* ../../assets/;
cd ../../assets/src && yarn && yarn webpack:build;
