const Encore = require('@symfony/webpack-encore');

Encore
    .setOutputPath('public/')
    .setPublicPath('/bundles/markocupiccontaoclassbook')
    .setManifestKeyPrefix('')

    // Frontend app (Vue code, the template lives in the Twig template)
    .copyFiles({
        from: './assets/js',
        to: 'js/[path][name].[hash:8].[ext]',
    })

    // Vue with the template compiler
    .copyFiles({
        from: './node_modules/vue/dist',
        to: 'vue/[path][name].[hash:8].[ext]',
        pattern: /(vue\.global\.prod\.js)$/,
    })

    .addStyleEntry('css/class-book', './assets/styles/class-book.scss')

    .disableSingleRuntimeChunk()
    .cleanupOutputBeforeBuild()
    .enableSourceMaps(!Encore.isProduction())
    .enableVersioning()

    // Preprocessing SCSS to CSS
    .enableSassLoader()
    .enablePostCssLoader()
;

module.exports = Encore.getWebpackConfig();
