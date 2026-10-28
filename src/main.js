const PDFDocument = require("pdfkit");
const fs = require("fs");
const path = require("path");
const { t, showDate, config, setDocHelper, docH, dt } = require("./globals");

const htmlZone = require("./htmlZone");
const { calcItems, Prices } = require("./sections/items");
const { calcTotals, Totals } = require("./sections/totals");
const Note = require("./sections/note");
const { PayQR, PayDetails, getDetails } = require("./sections/payment");

const i18nPath = __dirname + "/../i18n";
const defaultFont = path.join(__dirname, "../fonts/OpenSans-Regular.ttf");
const defaultFontBold = path.join(__dirname, "../fonts/OpenSans-Bold.ttf");
const getLanguages = () => {
  if (!config.languageOptions)
    config.languageOptions = fs.readdirSync(i18nPath);
  return config.languageOptions;
};

const init = (settings) => {
  config.company = settings.company;
  config.logo = settings.logo;
  const lang = settings.language || "en"; // Set fallback language
  config.languageData = require(`${i18nPath}/${lang}/translation.json`);
  config.locale = settings.locale || "en-US";
  config.currency = settings.currency;
  config.page = settings.page;
  config.fontName = settings.fontName || defaultFont;
  config.fontNameBold = settings.fontNameBold || defaultFontBold;
  config.fontSize = settings.fontSize || 11;
  config.defaultMargins = {
    top: 30,
    right: 30,
    bottom: 30,
    left: 54,
  };
  config.initialized = true;
};

const quote = (...args) => generate((docType = dt.QUOTE), ...args);
const invoice = (...args) => generate((docType = dt.INVOICE), ...args);
const packing = (...args) => generate((docType = dt.PACKING), ...args);
const ret = (...args) => generate((docType = dt.RETURN), ...args);
const receipt = (...args) => generate((docType = dt.RECEIPT), ...args);
const refund = (...args) => generate((docType = dt.REFUND), ...args);

const generate = (docType, order, payment) =>
  new Promise((resolve, reject) => {
    if (!order || typeof order !== "object") {
      reject(Error("Missing object parameter: order"));
      return;
    }
    if (!config.initialized) {
      reject(Error("Need to init() before generate()"));
      return;
    }
  config.docType = docType;
  const doc = new PDFDocument({
    bufferPages: true,
    margins: config.defaultMargins,
    font: config.fontName,
    ...config.page,
  });
  setDocHelper(doc);
  doc.font(config.fontName);
  //
  // Section: header — rendered from public/header.html (prototype) with
  // this client's cabinet data; the file is the layout source.
  htmlZone.renderHeader(doc, {
    company: config.company || {},
    bill: (order && order.bill) || {},
    ship: (order && order.ship) || {},
    order: {
      id: order.id,
      ref: order.ref,
      clientID: order.clientID,
      salesRep: order.salesRep,
      date: {
        created: showDate((order.date && order.date.created) || new Date()),
        due: showDate(order.date && order.date.due),
      },
    },
  });
  //
  // Section: body (pdfkit — items table + sums)
  const itemsData = calcItems(order);
  let totalsPos;
  if (order.items?.length) {
    const totalsData = calcTotals(doc, order, itemsData);
    Prices(doc, order, itemsData);
    totalsPos = Totals(doc, order, totalsData);
  } else {
    doc.font(config.fontNameBold);
    doc.fontSize(config.fontSize);
    doc.text(t("add_items_for_total"), doc.page.margins.left, doc.y, {
      width: docH.printWidth,
      align: "center",
    });
    doc.font(config.fontName);
    doc.moveDown(1);
    totalsPos = docH.returnPos({ width: 0 });
  }
  const roomTotals = docH.printWidth - totalsPos.width;
  if (roomTotals > docH.printWidth / 2) {
    docH.jumpStart(totalsPos);
    doc.moveDown(2);
  }
  //
  // Section
  const noteWidth =
    roomTotals > docH.printWidth / 2 ? roomTotals : docH.printWidth;
  Note(doc, order, noteWidth);
  const payQRpos = PayQR(doc, payment);
  const details = getDetails(doc, payment);
  if (details.table.length) {
    const isRoomDetails = details.width + docH.margin < docH.printWidth / 2;
    if (!isRoomDetails) docH.jumpBelow(totalsPos, payQRpos);
  }
  const payDetailsPos = PayDetails(doc, details);
  docH.jumpBelow(totalsPos, payQRpos, payDetailsPos);
  //
  // Section: footer — rendered from public/footer.html (prototype); starts
  // right where the body ended.
  const pages = String(doc._pageBuffer.length);
  htmlZone.renderFooter(doc, {
    company: config.company || {},
    document: order.bill || {},
    // documentJson: JSON.stringify(order.bill || {}, null, 2),
    page: pages,
    pages,
  });
  const chunks = [];
  doc.on("data", (chunk) => chunks.push(chunk));
  doc.on("end", () => resolve(Buffer.concat(chunks)));
  doc.on("error", reject);
  doc.end();
  });

module.exports = {
  getLanguages,
  init,
  quote,
  invoice,
  packing,
  return: ret,
  receipt,
  refund,
};
