// Build-time supply-chain gate. Fails the Docker build if a known-compromised
// package (name and/or exact malicious version) is present in package-lock.json.
// No external dependencies — builtins only.
"use strict";

const fs = require("fs");
const path = require("path");

// name -> array of known-malicious versions ("*" = any version banned outright)
const BLACKLIST = {
  // 2022 protestware / destructive
  "node-ipc": ["10.1.1", "10.1.2", "9.1.6", "9.2.3", "12.0.1"],
  peacenotwar: ["*"],
  "oneday-test": ["*"],
  colors: ["1.4.1", "1.4.2", "1.4.44-liberty-2"],
  faker: ["6.6.6"],
  // classic hijacks
  "ua-parser-js": ["0.7.29", "0.8.0", "1.0.0"],
  coa: ["2.0.3", "2.0.4", "2.1.1", "2.1.3", "3.1.3"],
  rc: ["1.2.8", "1.3.6"],
  "event-stream": ["3.3.6"],
  "flatmap-stream": ["*"],
  getcookies: ["*"],
  crossenv: ["*"],
  "eslint-scope": ["3.7.2"],
  "event-source-polyfill": ["*"],
  // Sep 2025 maintainer-account phishing wave (exact malicious builds)
  chalk: ["5.6.1"],
  debug: ["4.4.2"],
  "ansi-styles": ["6.2.2"],
  "supports-color": ["10.2.1"],
  "ansi-regex": ["6.2.1"],
  "strip-ansi": ["7.1.1"],
  "wrap-ansi": ["9.0.1"],
  "slice-ansi": ["7.1.1"],
  "has-ansi": ["6.0.1"],
  "supports-hyperlinks": ["4.1.1"],
  "chalk-template": ["1.1.1"],
  "color-convert": ["3.1.1"],
  "color-name": ["2.0.1"],
  "color-string": ["2.1.1"],
  "is-arrayish": ["0.3.3"],
  "error-ex": ["1.3.3"],
  "simple-swizzle": ["0.2.3"],
  backslash: ["0.2.1"],
  "proto-tinker-wc": ["0.1.87"],
  "prebid-universal-creative": ["1.17.3"],
  prebid: ["10.9.1"],
  "prebid.js": ["10.9.2"],
  duckdb: ["1.3.3"],
  "@duckdb/node-api": ["1.3.3"],
  "@duckdb/node-bindings": ["1.3.3"],
  "@duckdb/duckdb-wasm": ["1.29.2"],
  "@coveops/abi": ["2.0.1"],
  color: ["5.0.1"],
};

const lockPath = path.join(process.cwd(), "package-lock.json");
const lock = JSON.parse(fs.readFileSync(lockPath, "utf8"));
const packages = lock.packages || {};

const hits = [];
for (const [key, meta] of Object.entries(packages)) {
  if (!key.startsWith("node_modules/")) continue;
  const name = key.slice("node_modules/".length);
  const banned = BLACKLIST[name];
  if (!banned) continue;
  const version = (meta && meta.version) || "unknown";
  if (banned.includes("*") || banned.includes(version)) {
    hits.push(`${name}@${version}`);
  }
}

if (hits.length) {
  console.error("audit-deps: BLOCKED packages found:\n  " + hits.join("\n  "));
  process.exit(1);
}
console.log("audit-deps: OK, no blacklisted packages in lockfile.");
