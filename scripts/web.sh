parent_path=$( cd "$(dirname "${BASH_SOURCE[0]}")" ; pwd -P )
cd "$parent_path"

mkdir -p ../../modules;
mkdir -p ../../themes;

cp ../web/index.php ../../
