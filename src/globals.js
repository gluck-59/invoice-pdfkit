const config = { initialized: false, company: {}, languageData: {} };

const dt = {
  QUOTE: 0,
  INVOICE: 1,
  PACKING: 2,
  RETURN: 3,
  RECEIPT: 4,
  REFUND: 5,
};

const t = (key) => config.languageData[key];

const pad2 = (n) => String(n).padStart(2, "0");
const fmtDate = (d) => `${pad2(d.getDate())}.${pad2(d.getMonth() + 1)}.${d.getFullYear()}`;
const parseDate = (s) => {
  if (!s) return null;
  const str = String(s);
  let m = str.match(/^(\d{1,2})\.(\d{1,2})\.(\d{4})$/);
  if (m) return new Date(Number(m[3]), Number(m[2]) - 1, Number(m[1]));
  m = str.match(/^(\d{4})-(\d{1,2})-(\d{1,2})$/);
  if (m) return new Date(Number(m[1]), Number(m[2]) - 1, Number(m[3]));
  return null;
};
const showDate = (value) => {
  if (!value) return "";
  let date;
  if (value instanceof Date) date = value;
  else if (typeof value === "number") date = new Date(value);
  else date = parseDate(value);
  if (!date || isNaN(date)) return "";
  return fmtDate(date);
};

const NBSP = "\u00A0";

const showMoney = (num) => {
  const value = Number(num);
  if (config.currency === "EUR" && Number.isFinite(value)) {
    const sign = value < 0 ? "-" : "";
    const [whole, fraction] = Math.abs(value).toFixed(2).split(".");
    const grouped = whole.replace(/\B(?=(\d{3})+(?!\d))/g, NBSP);
    return `${sign}${grouped},${fraction}${NBSP}€`;
  }
  return value.toLocaleString(
    config.locale,
    config.currency
      ? {
          style: "currency",
          currency: config.currency,
        }
      : { minimumFractionDigits: "2", maximumFractionDigits: "2" }
  );
};

let docH = {};
const setDocHelper = (doc) => {
  docH.jumpStart = (position) => {
    if (!isNaN(position.startPage)) doc.switchToPage(position.startPage);
    if (position.startY) doc.y = position.startY;
  };
  docH.jumpBelow = (...positions) => {
    const last = positions.reduce(
      (a, pos) => {
        if (!pos) return a;
        else if (isNaN(pos.endPage) || pos.endPage === a.endPage)
          a.endY = Math.max(pos.endY, a.endY);
        else if (pos.endPage > a.endPage) {
          a.endY = pos.endY;
          a.endPage = pos.endPage;
        }
        return a;
      },
      { endPage: 0, endY: doc.page.margins.top }
    );
    doc.switchToPage(last.endPage);
    doc.y = last.endY;
  };
  docH.getPageNumber = () => doc._pageBuffer.indexOf(doc.page);
  docH.nextPage = () => {
    const pageNum = doc._pageBuffer.indexOf(doc.page);
    if (doc.options.bufferPages && doc._pageBuffer[pageNum + 1])
      doc.page = doc._pageBuffer[pageNum + 1];
    else doc.addPage();
  };
  docH.imageFixH = (img, x, y, opt) => {
    if (!img) return;
    if (y + opt.height > doc.page.height - doc.page.margins.bottom) {
      docH.nextPage();
      y = doc.page.margins.top;
    }
    doc.image(img, x, y, opt);
    doc.y = y + opt.height;
  };
  docH.pTextArgs = [];
  docH.pText = (...multiText) => {
    const printTxt = (txt) => {
      if (!txt) return;
      else if (typeof txt === "string") {
        doc.text(txt, ...docH.pTextArgs);
      } else if (Array.isArray(txt)) {
        txt.forEach((t) => printTxt(t));
      }
    };
    multiText.forEach(printTxt);
  };
  docH.widthOfStrings = (arr) => {
    const widths = arr.flatMap((s) => (s ? [doc.widthOfString(s)] : []));
    return Math.ceil(Math.max(...widths));
  };
  docH.returnPos = (start) => ({
    endPage: docH.getPageNumber(),
    endY: doc.y,
    ...start,
  });
  //docH.capitalize = (s) => s.charAt(0).toUpperCase() + s.slice(1).toLowerCase();
  docH.capitalize = (str) =>
    str
      .toLowerCase()
      .split(" ")
      .map((s) => s.charAt(0).toUpperCase() + s.substring(1))
      .join(" ");
  // Start constant variables
  docH.printWidth =
    doc.page.width - doc.page.margins.left - doc.page.margins.right;
  docH.margin = 4;
};

module.exports = { config, t, showDate, showMoney, setDocHelper, docH, dt };
