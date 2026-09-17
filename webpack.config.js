/**
 * Build config for the block checkout bundle.
 *
 * Extends the @wordpress/scripts default, swapping the dependency-extraction
 * plugin for WooCommerce's. That swap is what makes `@woocommerce/blocks-registry`
 * and `@woocommerce/settings` resolve to the `wc.wcBlocksRegistry` /
 * `wc.wcSettings` globals WooCommerce already prints on the page, and what lists
 * them in the generated .asset.php so PHP enqueues them in the right order.
 * Without it the build tries to bundle packages that are not installed.
 */

const defaultConfig = require( '@wordpress/scripts/config/webpack.config' );
const WooCommerceDependencyExtractionWebpackPlugin = require( '@woocommerce/dependency-extraction-webpack-plugin' );

const plugins = defaultConfig.plugins.filter(
	( plugin ) => plugin.constructor.name !== 'DependencyExtractionWebpackPlugin'
);

module.exports = {
	...defaultConfig,
	plugins: [ ...plugins, new WooCommerceDependencyExtractionWebpackPlugin() ],
};
