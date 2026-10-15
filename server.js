// Thin HTTP adapter over the existing PDF generator.
// Builtins only (http, fs, path, crypto) — no new npm dependencies by policy.
"use strict";

const http = require("http");
const fs = require("fs");
const path = require("path");

const tradePDF = require("./index");

const PORT = Number(process.env.PORT || 3000);
const RENDER_TOKEN = process.env.RENDER_TOKEN || "";
const BODY_LIMIT = 10 * 1024 * 1024; // 10 MB

const ROOT = __dirname;
const STATIC_MAP = {
  "/preview/": "public",
  "/locales/": "locales",
  "/fonts/": "fonts",
  "/test/": "test", // demo images (logo/QR) for prototypes only
};

const MIME = {
  ".html": "text/html; charset=utf-8",
  ".js": "application/javascript; charset=utf-8",
  ".json": "application/json; charset=utf-8",
  ".css": "text/css; charset=utf-8",
  ".ttf": "font/ttf",
  ".png": "image/png",
  ".jpg": "image/jpeg",
  ".jpeg": "image/jpeg",
};

const RENDER_TYPES = new Set([
  "quote",
  "invoice",
  "packing",
  "return",
  "receipt",
  "refund",
]);

// init() writes to a module-global config, so concurrent renders with
// different settings would race. Serialize all renders through one chain.
let renderChain = Promise.resolve();

function sendJson(res, status, obj) {
  const body = JSON.stringify(obj);
  res.writeHead(status, {
    "Content-Type": "application/json; charset=utf-8",
    "Content-Length": Buffer.byteLength(body),
  });
  res.end(body);
}

function serveStatic(req, res) {
  const urlPath = req.url.split("?")[0];
  let dir = null;
  let rel = null;
  for (const [prefix, mapped] of Object.entries(STATIC_MAP)) {
    if (urlPath === prefix.slice(0, -1) || urlPath.startsWith(prefix)) {
      dir = path.join(ROOT, mapped);
      rel = urlPath === prefix.slice(0, -1) ? "index.html" : urlPath.slice(prefix.length) || "index.html";
      break;
    }
  }
  if (urlPath === "/") {
    res.writeHead(302, { Location: "/preview/" });
    res.end();
    return;
  }
  if (!dir) {
    sendJson(res, 404, { error: "not found" });
    return;
  }
  const safe = path.normalize(rel).replace(/^(\.\.[/\\])+/, "");
  const file = path.join(dir, safe);
  if (!file.startsWith(dir)) {
    sendJson(res, 403, { error: "forbidden" });
    return;
  }
  fs.stat(file, (err, st) => {
    if (err || !st.isFile()) {
      sendJson(res, 404, { error: "not found" });
      return;
    }
    // Only serve allow-listed extensions (never .js sources outside public/)
    const ext = path.extname(file).toLowerCase();
    if (!MIME[ext] || (dir !== path.join(ROOT, "public") && ext === ".js")) {
      sendJson(res, 403, { error: "forbidden" });
      return;
    }
    res.writeHead(200, { "Content-Type": MIME[ext] });
    fs.createReadStream(file).pipe(res);
  });
}

function readBody(req) {
  return new Promise((resolve, reject) => {
    const chunks = [];
    let size = 0;
    req.on("data", (c) => {
      size += c.length;
      if (size > BODY_LIMIT) {
        reject(new Error("body too large"));
        req.destroy();
        return;
      }
      chunks.push(c);
    });
    req.on("end", () => resolve(Buffer.concat(chunks).toString("utf8")));
    req.on("error", reject);
  });
}

function fromBase64(v) {
  return typeof v === "string" && v ? Buffer.from(v, "base64") : undefined;
}

async function handleRender(type, body, res) {
  let payload;
  try {
    payload = JSON.parse(body || "{}");
  } catch {
    sendJson(res, 400, { error: "invalid JSON body" });
    return;
  }
  const { settings, order, payment } = payload;
  if (!settings || typeof settings !== "object") {
    sendJson(res, 400, { error: "missing object: settings" });
    return;
  }
  if (!order || typeof order !== "object") {
    sendJson(res, 400, { error: "missing object: order" });
    return;
  }
  // Binary fields travel as base64 inside JSON
  const initSettings = { ...settings };
  if (initSettings.logoBase64) {
    initSettings.logo = fromBase64(initSettings.logoBase64);
    delete initSettings.logoBase64;
  }
  const pay = payment && typeof payment === "object" ? { ...payment } : undefined;
  if (pay && pay.qrBase64) {
    pay.qr = fromBase64(pay.qrBase64);
    delete pay.qrBase64;
  }

  const job = renderChain.then(async () => {
    tradePDF.init(initSettings);
    const method = type === "return" ? "return" : type;
    return tradePDF[method](order, pay);
  });
  // Keep the chain alive even if this job fails
  renderChain = job.then(
    () => undefined,
    () => undefined
  );

  try {
    const pdf = await job;
    if (!Buffer.isBuffer(pdf)) throw new Error("generator did not return a Buffer");
    res.writeHead(200, {
      "Content-Type": "application/pdf",
      "Content-Length": pdf.length,
      "Content-Disposition": `inline; filename="${type}.pdf"`,
    });
    res.end(pdf);
  } catch (e) {
    sendJson(res, 500, { error: "render failed", detail: String((e && e.message) || e) });
  }
}

const server = http.createServer(async (req, res) => {
  try {
    const urlPath = req.url.split("?")[0];

    if (req.method === "GET" && urlPath === "/health") {
      sendJson(res, 200, { status: "ok", version: "1.0.11", node: process.version });
      return;
    }

    const renderMatch = req.method === "POST" && /^\/api\/render\/([a-z]+)\/?$/.exec(urlPath);
    if (renderMatch) {
      const type = renderMatch[1];
      if (!RENDER_TYPES.has(type)) {
        sendJson(res, 404, { error: "unknown render type" });
        return;
      }
      if (RENDER_TOKEN) {
        const got = req.headers["x-render-token"];
        if (got !== RENDER_TOKEN) {
          sendJson(res, 401, { error: "unauthorized" });
          return;
        }
      }
      const body = await readBody(req);
      await handleRender(type, body, res);
      return;
    }

    if (req.method === "GET") {
      serveStatic(req, res);
      return;
    }

    sendJson(res, 405, { error: "method not allowed" });
  } catch (e) {
    sendJson(res, 500, { error: "internal error" });
  }
});

server.listen(PORT, () => {
  console.log(`render service listening on :${PORT}`);
});
