// Minimal HTML -> pdfkit renderer for the header/footer prototypes in
// public/header.html and public/footer.html. Builtins only, no new deps.
// The prototype files are the layout source: node reads them at runtime,
// fills {{placeholders}} with real cabinet data / data-i18n with locales,
// and draws the matching PDF zone with pdfkit.
"use strict";

const fs = require("fs");
const path = require("path");
const { config, t } = require("./globals");

const PUBLIC_DIR = path.join(__dirname, "..", "public");
const PX = 0.75; // CSS px -> PDF pt (96dpi -> 72dpi)

const VOID = new Set(["img", "br", "meta", "link", "input", "hr"]);

// ---------------------------------------------------------------- HTML parse

function parseAttrs(s) {
  const attrs = {};
  const re = /([a-zA-Z0-9_:-]+)(?:\s*=\s*"([^"]*)")?/g;
  let m;
  while ((m = re.exec(s))) attrs[m[1].toLowerCase()] = m[2] === undefined ? "" : m[2];
  return attrs;
}

function parseHTML(html) {
  const root = { tag: "body", attrs: {}, children: [] };
  const stack = [root];
  let i = 0;
  while (i < html.length) {
    const lt = html.indexOf("<", i);
    if (lt < 0) {
      pushText(stack[stack.length - 1], html.slice(i));
      break;
    }
    if (lt > i) pushText(stack[stack.length - 1], html.slice(i, lt));
    if (html.startsWith("<!--", lt)) {
      const end = html.indexOf("-->", lt);
      i = end < 0 ? html.length : end + 3;
      continue;
    }
    const gt = html.indexOf(">", lt);
    if (gt < 0) break;
    let tag = html.slice(lt + 1, gt).trim();
    i = gt + 1;
    if (tag.startsWith("/")) {
      const name = tag.slice(1).trim().toLowerCase();
      for (let s = stack.length - 1; s > 0; s--) {
        if (stack[s].tag === name) {
          stack.length = s;
          break;
        }
      }
      continue;
    }
    const selfClose = tag.endsWith("/");
    if (selfClose) tag = tag.slice(0, -1).trim();
    const m = tag.match(/^([a-zA-Z0-9]+)/);
    if (!m) continue;
    const name = m[1].toLowerCase();
    const el = { tag: name, attrs: parseAttrs(tag.slice(m[1].length)), children: [] };
    stack[stack.length - 1].children.push(el);
    if (!selfClose && !VOID.has(name)) stack.push(el);
  }
  return root;
}

function pushText(container, value) {
  if (!value) return;
  container.children.push({ tag: "#text", value });
}

// ------------------------------------------------------------------ CSS parse

function stripAtRules(css) {
  let out = "";
  let i = 0;
  while (i < css.length) {
    const at = css.indexOf("@", i);
    if (at < 0) {
      out += css.slice(i);
      break;
    }
    out += css.slice(i, at);
    const brace = css.indexOf("{", at);
    if (brace < 0) break;
    let depth = 1;
    let j = brace + 1;
    while (j < css.length && depth > 0) {
      if (css[j] === "{") depth++;
      else if (css[j] === "}") depth--;
      j++;
    }
    i = j;
  }
  return out;
}

function parseDecls(s) {
  const d = {};
  for (const part of s.split(";")) {
    const idx = part.indexOf(":");
    if (idx < 0) continue;
    const k = part.slice(0, idx).trim().toLowerCase();
    const v = part.slice(idx + 1).trim();
    if (k) d[k] = v;
  }
  return d;
}

function parseRules(css) {
  const text = stripAtRules(css.replace(/\/\*[\s\S]*?\*\//g, ""));
  const rules = [];
  const re = /([^{}]+)\{([^{}]*)\}/g;
  let m;
  while ((m = re.exec(text))) {
    const decls = parseDecls(m[2]);
    for (const sel of m[1].split(",").map((x) => x.trim()).filter(Boolean)) {
      rules.push({ chain: parseSelector(sel), decls });
    }
  }
  return rules;
}

function parseSelector(sel) {
  return sel.split(/\s+/).filter(Boolean).map((comp) => ({
    tag: (comp.match(/^([a-zA-Z0-9]+)/) || [])[1]?.toLowerCase() || null,
    classes: [...comp.matchAll(/\.([a-zA-Z0-9_-]+)/g)].map((x) => x[1]),
  }));
}

function classesOf(el) {
  return (el.attrs && el.attrs.class ? el.attrs.class : "").split(/\s+/).filter(Boolean);
}

function matchCompound(el, comp) {
  if (el.tag === "#text") return false;
  if (comp.tag && el.tag !== comp.tag) return false;
  const cls = classesOf(el);
  return comp.classes.every((c) => cls.includes(c));
}

function matchChain(el, chain, ancestors) {
  if (!matchCompound(el, chain[chain.length - 1])) return false;
  let ai = ancestors.length - 1;
  for (let i = chain.length - 2; i >= 0; i--) {
    let found = false;
    while (ai >= 0) {
      const a = ancestors[ai--];
      if (matchCompound(a, chain[i])) {
        found = true;
        break;
      }
    }
    if (!found) return false;
  }
  return true;
}

function specificity(chain) {
  let b = 0;
  let c = 0;
  for (const comp of chain) {
    b += comp.classes.length;
    if (comp.tag) c++;
  }
  return b * 100 + c;
}

// ------------------------------------------------------------------ length

function len(v, fallback) {
  if (v == null) return fallback;
  const s = String(v).trim();
  if (s === "") return fallback;
  const num = parseFloat(s);
  if (isNaN(num)) return fallback;
  if (s.endsWith("px")) return num * PX;
  if (s.endsWith("pt")) return num;
  if (s.endsWith("em")) return num * (config.fontSize || 11);
  return num;
}

function margin(decls) {
  const out = { top: 0, right: 0, bottom: 0, left: 0 };
  const shorthand = decls.margin;
  if (shorthand) {
    const parts = shorthand.split(/\s+/);
    const get = (idx, last) => len(parts[idx] != null ? parts[idx] : parts[last], 0);
    out.top = get(0, 0);
    out.right = parts.length > 1 ? get(1, 1) : out.top;
    out.bottom = parts.length > 2 ? get(2, 0) : out.top;
    out.left = parts.length > 3 ? get(3, 1) : out.right;
  }
  if (decls["margin-top"]) out.top = len(decls["margin-top"], out.top);
  if (decls["margin-bottom"]) out.bottom = len(decls["margin-bottom"], out.bottom);
  if (decls["margin-right"]) out.right = len(decls["margin-right"], out.right);
  if (decls["margin-left"]) out.left = len(decls["margin-left"], out.left);
  return out;
}

// ----------------------------------------------------------- computed style

function applyDecls(st, decls) {
  if (decls["font-size"]) st.fontSize = len(decls["font-size"], st.fontSize);
  if (decls["font-weight"]) {
    const w = decls["font-weight"];
    st.fontWeight = w === "bold" ? 700 : parseInt(w, 10) || st.fontWeight;
  }
  if (decls["text-align"]) st.textAlign = decls["text-align"];
  if (decls.display) st.display = decls.display;
  if (decls.gap) st.gap = len(decls.gap, st.gap);
  if (decls.width) st.width = len(decls.width, st.width);
  if (decls.flex) st.flex = decls.flex;
  if (decls.position) st.position = decls.position;
  if (decls.top) st.top = len(decls.top, st.top);
  if (decls.right) st.right = len(decls.right, st.right);
  if (decls["max-width"]) st.maxWidth = len(decls["max-width"], st.maxWidth);
  if (decls["max-height"]) st.maxHeight = len(decls["max-height"], st.maxHeight);
  if (decls.margin || decls["margin-top"] || decls["margin-bottom"]) {
    Object.assign(st.margin, margin(decls));
  }
}

function computedStyle(el, ancestors, inherit) {
  const st = {
    fontSize: inherit.fontSize,
    fontWeight: inherit.fontWeight,
    textAlign: inherit.textAlign,
    display: "block",
    gap: 0,
    width: null,
    flex: null,
    position: "static",
    top: null,
    right: null,
    maxWidth: null,
    maxHeight: null,
    margin: { top: 0, right: 0, bottom: 0, left: 0 },
  };
  const matched = [];
  for (const rule of CSS.rules) {
    if (matchChain(el, rule.chain, ancestors)) matched.push(rule);
  }
  matched.sort((a, b) => specificity(a.chain) - specificity(b.chain));
  for (const rule of matched) applyDecls(st, rule.decls);
  if (el.attrs && el.attrs.style) applyDecls(st, parseDecls(el.attrs.style));
  return st;
}

function pickInherit(st) {
  return { fontSize: st.fontSize, fontWeight: st.fontWeight, textAlign: st.textAlign };
}

// -------------------------------------------------------------------- text

function dataVal(obj, dotted) {
  let v = obj;
  for (const k of dotted.split(".")) {
    if (v == null || typeof v !== "object" || !(k in v)) return "";
    v = v[k];
  }
  if (v == null) return "";
  return Array.isArray(v) ? v.join("\n") : String(v);
}

function fillText(s, data) {
  return String(s).replace(/\{\{\s*([a-zA-Z0-9_.]+)\s*\}\}/g, (_, p) => dataVal(data, p));
}

function textOf(el) {
  if (el.attrs && el.attrs["data-i18n"]) return t(el.attrs["data-i18n"]) ?? "";
  let s = "";
  for (const c of el.children) {
    if (c.tag === "#text") s += c.value;
    else if (c.tag === "span") s += textOf(c);
  }
  return s;
}

function isTextLeaf(el) {
  return el.children.every((c) => c.tag === "#text" || c.tag === "span");
}

// ------------------------------------------------------------------ drawing

function drawLines(doc, text, x, y, width, st, data) {
  const s = fillText(text, data)
    .split("\n")
    .map((l) => l.replace(/[ \t\r]+/g, " ").trim())
    .filter((l) => l !== "")
    .join("\n");
  if (!s) return 0;
  doc.font(st.fontWeight >= 700 ? config.fontNameBold : config.fontName);
  doc.fontSize(st.fontSize);
  const h = doc.heightOfString(s, { width, align: st.textAlign });
  doc.text(s, x, y, { width, align: st.textAlign });
  return h;
}

const CSS = { rules: [] };

function skip(el) {
  if (["script", "style", "head"].includes(el.tag)) return true;
  return classesOf(el).includes("noprint");
}

function drawImage(el, st, doc, boxX, boxY, boxW) {
  let img = config.logo;
  if (!img) {
    const rel = (el.attrs.src || "").replace(/^\/(preview|template)\//, "/");
    const p = path.join(PUBLIC_DIR, rel.replace(/^\//, ""));
    if (fs.existsSync(p)) img = fs.readFileSync(p);
  }
  if (!img) return;
  let op;
  try {
    op = doc.openImage(img);
  } catch {
    return;
  }
  const maxW = st.maxWidth != null ? st.maxWidth : boxW;
  const maxH = st.maxHeight != null ? st.maxHeight : boxW;
  const scale = Math.min(maxW / op.width, maxH / op.height, 1);
  const w = op.width * scale;
  const h = op.height * scale;
  const x = boxX + boxW - (st.right || 0) - w;
  doc.image(img, x, boxY + (st.top || 0), { width: w, height: h });
}

function flexItems(el) {
  const items = [];
  let buf = "";
  const flush = () => {
    if (buf.trim()) items.push({ tag: "#anon", value: buf });
    buf = "";
  };
  for (const c of el.children) {
    if (c.tag === "#text") {
      buf += c.value;
      continue;
    }
    if (skip(c)) continue;
    flush();
    items.push(c);
  }
  flush();
  return items;
}

function renderNode(el, ctx, x, y, width) {
  const anc = ctx.ancestors;
  const st = computedStyle(el, anc, ctx.inherit);
  const childCtx = { ...ctx, ancestors: [...anc, el], inherit: pickInherit(st) };

  for (const c of el.children) {
    if (c.tag === "#text" || skip(c)) continue;
    const cst = computedStyle(c, childCtx.ancestors, childCtx.inherit);
    if (cst.position === "absolute") drawImage(c, cst, ctx.doc, x, y, width);
  }

  if (st.display === "flex") return renderFlex(el, st, childCtx, x, y, width);

  if (isTextLeaf(el)) {
    const yy = y + st.margin.top;
    const h = drawLines(ctx.doc, textOf(el), x, yy, width, st, ctx.data);
    if (h === 0) return 0;
    return h + st.margin.top + st.margin.bottom;
  }

  let yy = y + st.margin.top;
  for (const c of el.children) {
    if (c.tag === "#text") {
      const s = fillText(c.value, ctx.data).replace(/\s+/g, " ").trim();
      if (s) yy += drawLines(ctx.doc, s, x, yy, width, st, ctx.data);
      continue;
    }
    if (skip(c)) continue;
    const cst = computedStyle(c, childCtx.ancestors, childCtx.inherit);
    if (cst.position === "absolute") continue;
    yy += renderNode(c, childCtx, x, yy, width);
  }
  return yy - y + st.margin.bottom;
}

function renderFlex(el, st, childCtx, x, y, width) {
  const items = flexItems(el);
  if (!items.length) return 0;
  const gap = st.gap || 0;
  const infos = items.map((it) => {
    if (it.tag === "#anon") return { it, flex: true };
    const ist = computedStyle(it, childCtx.ancestors, childCtx.inherit);
    const flexible = ist.flex != null || ist.width == null;
    return { it, style: ist, flex: flexible };
  });
  const fixedTotal = infos.reduce((a, i) => a + (i.flex ? 0 : i.style.width), 0);
  const flexCount = infos.filter((i) => i.flex).length;
  const flexW = flexCount ? Math.max(0, (width - gap * (items.length - 1) - fixedTotal) / flexCount) : 0;
  const baseY = y + st.margin.top;
  let cx = x;
  let maxH = 0;
  for (const info of infos) {
    const w = info.flex ? flexW : info.style.width;
    if (info.it.tag === "#anon") {
      maxH = Math.max(maxH, drawLines(childCtx.doc, info.it.value, cx, baseY, w, { ...st, margin: { top: 0 } }, childCtx.data));
    } else {
      maxH = Math.max(maxH, renderNode(info.it, childCtx, cx, baseY, w));
    }
    cx += w + gap;
  }
  return st.margin.top + maxH + st.margin.bottom;
}

// --------------------------------------------------------------- zone entry

function loadZone(name) {
  const html = fs.readFileSync(path.join(PUBLIC_DIR, name), "utf8");
  const css = [...html.matchAll(/<style[^>]*>([\s\S]*?)<\/style>/gi)].map((m) => m[1]).join("\n");
  const body = html.match(/<body[^>]*>([\s\S]*?)<\/body>/i);
  const bodyHtml = (body ? body[1] : html).replace(/<script[\s\S]*?<\/script>/gi, "");
  CSS.rules = parseRules(css);
  return parseHTML(bodyHtml);
}

function zoneRoot(root) {
  return root.children.find((c) => c.tag !== "#text") || root;
}

function renderZone(name, doc, data) {
  const root = loadZone(name);
  const zone = zoneRoot(root);
  const x = doc.page.margins.left;
  const width = doc.page.width - doc.page.margins.left - doc.page.margins.right;
  const y = doc.y;
  const inherit = { fontSize: config.fontSize || 11, fontWeight: 400, textAlign: "left" };
  const ctx = { doc, data: data || {}, ancestors: [root], inherit };
  const h = renderNode(zone, ctx, x, y, width);
  doc.x = x;
  doc.y = y + h;
  return h;
}

const renderHeader = (doc, data) => renderZone("header.html", doc, data);
const renderFooter = (doc, data) => renderZone("footer.html", doc, data);

module.exports = { renderHeader, renderFooter, renderZone };
