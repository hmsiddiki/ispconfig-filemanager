// The release number, shown in a small tag in the corner (App.vue): after an update anyone can see which version the page runs.
// package.json "version" is set with VERSION for every release (PUBLISHING.md).
process.env.VUE_APP_VERSION = require('./package.json').version

module.exports = {
  indexPath: 'main.html',
  filenameHashing: false,
  css: {
	extract: true
  },
  configureWebpack: config => {
    config.entry = {
      app: [
        './frontend/main.js'
      ]
    }
  }
}
