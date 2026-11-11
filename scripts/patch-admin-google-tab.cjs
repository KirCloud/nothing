#!/usr/bin/env node
// The customized admin is shipped as a bundle. Keep the integration small and
// the editable settings component in public/assets/admin/google-oauth-settings.js.
const fs = require('node:fs');
const path = require('node:path');
const file = path.resolve(__dirname, '../public/assets/admin/umi.js');
let source = fs.readFileSync(file, 'utf8');
if (source.includes('f.a.createElement("google-oauth-settings")')) {
  console.log('Google OAuth settings tab is already installed.');
  process.exit(0);
}
const anchor = String.raw`}, f.a.createElement(s["a"].TabPane, {
                    tab: "\u7ad9\u70b9",
                    key: "site"`;
if (source.split(anchor).length !== 2) {
  throw new Error('Admin configuration component changed; review its tab integration before patching.');
}
const tab = `}, f.a.createElement(s["a"].TabPane, {
                    tab: "Google 登录",
                    key: "google"
                }, f.a.createElement("google-oauth-settings")), f.a.createElement(s["a"].TabPane, {
                    tab: "\\u7ad9\\u70b9",
                    key: "site"`;
source = source.replace(anchor, tab);
fs.writeFileSync(file, source);
console.log('Installed Google OAuth settings tab.');
