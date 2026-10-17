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

// Static: URL path == directory name, no aliasing.
// /preview/* -> public/* with {{placeholders}} filled from DEMO data below.
// /template/* -> public/* raw (placeholders intact, for editing).
// /locales/*, /fonts/* served as-is.
// /test/ is deliberately NOT served (excluded from the image).
const STATIC_DIRS = new Set(["preview", "template", "locales", "fonts"]);
const STATIC_ROOT = { preview: "public", template: "public", locales: "locales", fonts: "fonts" };

// Demo data for /preview/ (same values as test/run.js).
const DEMO = {
  company: {
    company: "Inquistador",
    addr1: "18th St.",
    city: "Fort Meade",
    zip: "FL 33941",
    state: "Florida",
    country: "United States of America",
    tel: "+1 863-285-5552",
    web: "https://admiralmotors.co.uk",
    email: "admiralmotors@protonmail.com",
    account: "NL33 ABNA 0383 9284 93",
    bankID: "BIC ABNABNL2AXXX",
    registration: "K.v.K. 0441.956.713",
    taxID: "BTW nr. BE0423798694",
    termsURL: "https://shopping-example.com/terms",
    // terms: ["- Only valid if paid on time", "- Pay on time for real please"],
    // tagline: "Quality machinery for life - Thank you for doing business.",
  },
  bill: {
    company: "Super Grip Tires BV",
    name: "Jan van Eihof",
    addr1: "Dorpstraat 587",
    city: "3884AR Leusden",
    email: "finance@supergriptires.com",
  },
  ship: {
    company: "SGT Logistics Netherlands",
    name: "Tony Forklift",
    addr1: "Industrieweg 43",
    city: "4432GT Arnhem",
    state: "Gelderland",
    country: "The Netherlands",
  },
  order: {
    id: "Y4TIX8GX",
    ref: "V1002",
    clientID: "C345524U",
    salesRep: "Georgina",
    date: { created: "2022-08-01", due: "2022-06-13" },
  },
  page: "1",
  pages: "1",
};

const DEMO_ITEMS = [
  { id: "if3moh3f", desc: "Uber Expensive Watch", qty: "14", price: "458845.25" },
  { id: "roh0aith", desc: "Behringer Audio Digital Mixing Console", qty: "8", price: "158845.25" },
  { id: "k2d822e2", desc: "Expensive Watch", qty: "18", price: "258845.25" },
];

function demoVal(path) {
  let v = DEMO;
  for (const k of path.split(".")) {
    if (v == null || typeof v !== "object" || !(k in v)) return "";
    v = v[k];
  }
  // if (Array.isArray(v)) return v.join(" ");
  return v == null ? "" : String(v);
}

function fillDemo(html) {
  return html.replace(/\{\{\s*([a-zA-Z0-9_.]+)\s*\}\}/g, (_, k) => demoVal(k));
}

function demoItemsTable() {
  const rows = DEMO_ITEMS.map(
    (it) =>
      `<tr><td>${it.id}</td><td>${it.desc}</td><td>${it.qty}</td><td>${it.price}</td></tr>`
  ).join("");
  return (
    `<table style="width:100%;border-collapse:collapse;font-size:10pt">` +
    `<tr><td><b>Code</b></td><td><b>Description</b></td><td><b>Qty</b></td><td><b>Price</b></td></tr>` +
    rows +
    `</table>`
  );
}

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
  // "/" serves the preview shell directly (no redirect).
  const first = urlPath === "/" ? "preview" : urlPath.slice(1).split("/", 1)[0];
  if (!STATIC_DIRS.has(first)) {
    sendJson(res, 404, { error: "not found" });
    return;
  }
  const dir = path.join(ROOT, STATIC_ROOT[first]);
  const rel = urlPath === "/" || urlPath === `/${first}` ? "index.html" : urlPath.slice(first.length + 2) || "index.html";
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
    if (ext === ".html" && (first === "preview" || first === "template")) {
      fs.readFile(file, "utf8", (readErr, html) => {
        if (readErr) {
          sendJson(res, 404, { error: "not found" });
          return;
        }
        if (first === "preview") {
          html = fillDemo(html);
          if (rel === "index.html") {
            html = html.replace(
              /<div class="body-stub">[\s\S]*?<\/div>/,
              `<div class="body-stub" style="display:block">${demoItemsTable()}</div>`
            );
          }
        }
        const body = Buffer.from(html, "utf8");
        res.writeHead(200, {
          "Content-Type": MIME[ext],
          "Content-Length": body.length,
        });
        res.end(body);
      });
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

    // Dynamic language list for the preview <select> (fresh readdir every
    // request — no cache, so a newly added locales/<lang>/ shows up at once).
    // Falls back to ["en"] client-side if unreachable.
    if (req.method === "GET" && urlPath === "/api/languages") {
      try {
        const localesDir = path.join(ROOT, "locales");
        const langs = fs
          .readdirSync(localesDir, { withFileTypes: true })
          .filter(
            (e) =>
              e.isDirectory() &&
              fs.existsSync(path.join(localesDir, e.name, "translation.json"))
          )
          .map((e) => e.name)
          .sort();
        sendJson(res, 200, { languages: langs });
      } catch (e) {
        sendJson(res, 500, { error: "cannot list languages" });
      }
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
