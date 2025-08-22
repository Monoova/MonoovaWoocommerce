const path = require("path")
const MiniCssExtractPlugin = require("mini-css-extract-plugin")
const defaultConfig = require("@wordpress/scripts/config/webpack.config")

module.exports = {
    ...defaultConfig,
    entry: {
        "primer-payment-handler": "./assets/js/src/primer-payment-handler.js",
        admin: "./assets/js/admin.js",
        "admin-payment-settings": "./assets/js/src/admin/payment-settings-init.js",
        "monoova-card-block": "./assets/js/src/blocks/monoova-card-block.js",
        "monoova-card-express-block": "./assets/js/src/blocks/monoova-card-express-block.js",
        "monoova-payid-block": "./assets/js/src/blocks/monoova-payid-block.js",
        "monoova-payto-block": "./assets/js/src/blocks/monoova-payto-block.js",
        "monoova-payto-express-block": "./assets/js/src/blocks/monoova-payto-express-block.js",
        "monoova-payid-thankyou": "./assets/js/src/monoova-payid-thankyou.js",
    },
    output: {
        filename: "[name].js",
        path: path.resolve(__dirname, "assets/js/build"),
    },
    resolve: {
        alias: {
            "@": path.resolve(__dirname, "assets/js/src"),
            "@components": path.resolve(__dirname, "assets/js/src/components"),
            "@utils": path.resolve(__dirname, "assets/js/src/utils"),
            "@api": path.resolve(__dirname, "assets/js/src/api"),
            "@blocks": path.resolve(__dirname, "assets/js/src/blocks"),
        },
    },
    module: {
        ...defaultConfig.module,
        rules: [
            {
                test: /\.js$/,
                exclude: /node_modules/,
                use: {
                    loader: "babel-loader",
                    options: {
                        presets: [
                            [
                                "@babel/preset-env",
                                {
                                    targets: {
                                        browsers: ["> 1%", "last 2 versions", "not dead"],
                                    },
                                    modules: false, // Keep ES6 modules for webpack tree shaking
                                },
                            ],
                            "@babel/preset-react",
                        ],
                        plugins: ["@babel/plugin-proposal-class-properties", "@babel/plugin-transform-runtime"],
                    },
                },
            },
            {
                test: /\.scss$/,
                use: [MiniCssExtractPlugin.loader, "css-loader", "sass-loader"],
            },
        ],
    },
    plugins: [
        ...defaultConfig.plugins,
        new MiniCssExtractPlugin({
            filename: "../css/[name].css",
        }),
    ],
    externals: {
        ...defaultConfig.externals,
        jquery: "jQuery",
        "@woocommerce/blocks-registry": ["wc", "wcBlocksRegistry"],
        "@woocommerce/settings": ["wc", "wcSettings"],
        "@woocommerce/block-data": ["wc", "wcBlocksData"],
        primer: "Primer",
    },
}
