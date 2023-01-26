const Path = require('path');
const { JavascriptWebpackConfig, CssWebpackConfig } = require('@silverstripe/webpack-config');

const ENV = process.env.NODE_ENV;
const PATHS = {
  ROOT: Path.resolve(),
  SRC: Path.resolve('client/src'),
  DIST: Path.resolve('client/dist'),
};

const config = [
  // Main JS bundle
  new JavascriptWebpackConfig('js', PATHS, 'symbiote/silverstripe-advancedworkflow')
    .setEntry({
      advancedworkflow: `${PATHS.SRC}/bundles/bundle.js`,
    })
    .getConfig(),
  // sass to css
  new CssWebpackConfig('css', PATHS)
    .setEntry({
      advancedworkflow: `${PATHS.SRC}/styles/bundle.scss`,
    })
    .getConfig(),
];

module.exports = config;
