/**
 * ESLint config for the block checkout bundle.
 *
 * The `@woocommerce/*` packages are intentionally not installed: the build
 * treats them as externals that resolve to the `wc.*` globals WooCommerce
 * already prints on the page. Declaring them as core modules stops
 * import/no-unresolved from flagging imports that are correct by design.
 */

module.exports = {
	extends: [ 'plugin:@wordpress/eslint-plugin/recommended' ],
	settings: {
		'import/core-modules': [
			'@woocommerce/blocks-registry',
			'@woocommerce/settings',
		],
	},
};
