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

    // The logo: the SVG for the web, the PNG for e-mails (most mail programs show no SVG).
    // Without a hash, because the mailers read the PNG from the file system
    .copyFiles({
        from: './assets/images',
        to: 'images/[path][name].[ext]',
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
