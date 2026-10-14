const PDFDocument = require("pdfkit");
const fs = require("fs");
const path = require("path");
const { t, config, setDocHelper, docH, dt } = require("./globals");

const Title = require("./sections/title");
const Logo = require("./sections/logo");
const Company = require("./sections/company");
const Order = require("./sections/order");
const Destination = require("./sections/destination");
const { calcItems, Prices } = require("./sections/items");
const { calcTotals, Totals } = require("./sections/totals");
const Note = require("./sections/note");
const { PayQR, PayDetails, getDetails } = require("./sections/payment");
const {
  Terms,
  getPageIndicatorHeight,
  PageNumbers,
} = require("./sections/terms");

const localesPath = __dirname + "/../locales";
const defaultFont = path.join(__dirname, "../fonts/OpenSans-Regular.ttf");
const defaultFontBold = path.join(__dirname, "../fonts/OpenSans-ExtraBold.ttf");
const getLanguages = () => {
  if (!config.languageOptions)
    config.languageOptions = fs.readdirSync(localesPath);
  return config.languageOptions;
};

const init = (settings) => {
  config.company = settings.company;
  config.logo = settings.logo;
  const lang = settings.language || "en"; // Set fallback language
  config.languageData = require(`${localesPath}/${lang}/translation.json`);
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
  doc.page.margins.bottom += getPageIndicatorHeight(doc);
  //
  // Section
  Title(doc);
  Logo(doc);
  // Increased top margin for following pages (visually appealing)
  doc.page.margins.top *= 2;
  //doc.page.margins.top = doc.y / 2;
  //
  // Section
  const companyPos = Company(doc);
  docH.jumpStart(companyPos);
  let billPos, shipPos;
  if (config.docType === dt.PACKING) {
    shipPos = Destination(doc, order.ship, t("ship_to"), false);
    billPos = Destination(doc, order.bill, t("bill_to"), true);
  } else if (config.docType === dt.RETURN) {
    const { company } = config;
    if (company.ship) {
      shipPos = Destination(doc, company.ship, t("ship_to"), false);
      billPos = Destination(doc, company, t("bill_to"), true);
    } else {
      shipPos = Destination(doc, company, t("ship_to"), false);
    }
  } else if ([dt.QUOTE, dt.REFUND].includes(config.docType)) {
    billPos = Destination(doc, order.bill, t("bill_to"), false);
  } else {
    billPos = Destination(doc, order.bill, t("bill_to"), false);
    shipPos = Destination(doc, order.ship, t("ship_to"), true);
  }
  const orderPos = Order(doc, order);
  docH.jumpBelow(companyPos, billPos, shipPos, orderPos);
  //
  // Section
  const itemsData = calcItems(order);
  const totalsData = calcTotals(doc, order, itemsData);
  Prices(doc, order, itemsData, totalsData);
  const totalsPos = Totals(doc, order, totalsData);
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
  // Section
  Terms(doc, order);
  //
  // Section
  doc.page.margins.bottom -= getPageIndicatorHeight(doc);
  PageNumbers(doc);
  doc.page.margins.top /= 2;
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
