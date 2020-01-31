parent_path=$( cd "$(dirname "${BASH_SOURCE[0]}")" ; pwd -P )
cd "$parent_path"

mkdir -p ../../modules;
mkdir -p ../../themes;

cp ../web/index.php ../../
cp ../web/config.json ../../
cp -avr ../web/modules/* ../../modules/

wget -O ../../logo.png https://picsum.photos/300/300
