const path = require('path');
module.exports = {
    mode: "development", //production | development
    devtool: 'source-map',
    entry: ["./index.ts", "./index.scss"],
    output: {
        path: path.resolve(__dirname, '../dist'),
        filename: 'bundle.[contenthash].js',
        clean: true,
    },
    resolve: {
        extensions: [".js", ".ts"],
    },
    target: ['web', 'es5'],
    module: {
        rules: [
            {
                test: /\.ts$/,
                exclude: /(node_modules|bower_components)/,
                use: {
                    loader: 'babel-loader',
                    options: {
                        babelrc: true,
                        presets: [
                            [
                                '@babel/preset-env',
                                {
                                    targets: {
                                        "ie": "11"
                                    }
                                }
                            ]
                        ]
                    }
                }
            },
            {
                test: /\.tsx?$/,
                use: 'ts-loader',
                exclude: /node_modules/,
            },
            {
                test: /\.s[ac]ss$/i,
                use: [
                    // Creates `style` nodes from JS strings
                    'style-loader',
                    // Translates CSS into CommonJS
                    'css-loader',
                    // Compiles Sass to CSS
                    'sass-loader',
                ],
            },
            {
                test: /\.(svg|eot|woff|woff2|ttf)$/,
                use: ['file-loader']
            },
            {
                test: /\.(jpg|png)$/,
                use: ['file-loader']
            },
        ],
    },
    performance: {
        maxEntrypointSize: 512000,
        maxAssetSize: 512000
    }
}
