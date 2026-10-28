const { t, config, showMoney, docH, dt } = require("../globals");
const drawFlexTable = require("../flex-table");

const TABLE_MARGIN = 50 * 0.75; // 50px -> PDF points

// need docker compose up -d --build node
const COLUMN_WIDTHS = {
  id: 50,
  qty: 40,
  qtyShip: 40,
  price: 82,
  discount: 40,
  tax: 48,
  total: 92,
  return: 55,
};

const calcItems = (order) => {
  if (!order.items.length) return {};
  if (config.docType === dt.PACKING) return calcPacking(order);
  if (config.docType === dt.RETURN) return calcReturn(order);
  return calcPrices(order);
};

const calcPacking = (order) => {
  const firstItem = order.items[0];
  const header = [t("item_id"), t("item_desc")];
  const widths = [COLUMN_WIDTHS.id, undefined];
  if (!!firstItem.qty) {
    header.push(t("item_qty"));
    widths.push(COLUMN_WIDTHS.qty);
  }
  if (!!firstItem.qtyShip) {
    header.push(t("item_qtyShip"));
    widths.push(COLUMN_WIDTHS.qtyShip);
  }
  const table = [];
  for (const item of order.items) {
    const line = [item.id, item.desc];
    if (!!firstItem.qty) line.push(item.qty);
    if (!!firstItem.qtyShip) line.push(item.qtyShip);
    table.push(line);
  }
  return { header, table, widths };
};

const calcReturn = (order) => {
  const { header, table, widths } = calcPacking(order);
  header.push("Return");
  widths.push(COLUMN_WIDTHS.return);
  table.forEach((line) => line.push(""));
  const writeColumn = [];
  writeColumn[header.length - 1] = true;
  return { header, table, writeColumn, widths };
};

const calcPrices = (order) => {
  const hasDiscount = order.items.reduce((a, i) => a || !!i.discount, false);
  const hasTax = order.items.reduce((a, i) => a || !!i.tax, false);
  //
  const header = [t("item_id"), t("item_desc"), t("item_qty"), t("item_price")];
  const widths = [
    COLUMN_WIDTHS.id,
    undefined,
    COLUMN_WIDTHS.qty,
    COLUMN_WIDTHS.price,
  ];
  if (hasDiscount) {
    header.push(t("item_discount"));
    widths.push(COLUMN_WIDTHS.discount);
  }
  if (hasTax) {
    header.push(t("item_tax"));
    widths.push(COLUMN_WIDTHS.tax);
  }
  header.push(t("item_total"));
  widths.push(COLUMN_WIDTHS.total);
  //
  const table = [];
  const totals = [];
  for (const item of order.items) {
    const line = [item.id, item.desc, item.qty, showMoney(item.price)];
    if (hasDiscount) line.push(item.discount ? item.discount + "%" : "");
    if (hasTax) line.push(item.tax ? item.tax + "%" : "");
    const total = calcArticleTotal(item);
    totals.push(total);
    line.push(showMoney(total));
    table.push(line);
  }
  const subtotal = totals.reduce((a, t) => a + t, 0);
  return { header, table, subtotal, widths };
};

const calcArticleTotal = (item) => {
  let out = item.price;
  if (!isNaN(item.qty)) out *= item.qty;
  if (item.discount) out *= 1 - item.discount / 100;
  if (item.tax) out *= 1 + item.tax / 100;
  return out;
};

const Prices = (doc, order, pricesData) => {
  if (!order.items?.length || !pricesData) return docH.returnPos();
  const startPage = docH.getPageNumber();
  doc.y += TABLE_MARGIN; // 50px top margin
  const startY = doc.y;
  const { header, table, widths } = pricesData;
  const cellMarginX = 6;
  const onlyDescriptionColumn = Array(7).fill(false);
  onlyDescriptionColumn[1] = true;
  if (config.docType === dt.RETURN && !doc._acroform) doc.initForm();
  drawFlexTable({
    doc,
    fontName: config.fontName,
    fontNameBold: config.fontNameBold,
    header,
    table,
    footer: undefined,
    start: doc.page.margins.left,
    end: doc.page.width - doc.page.margins.right,
    allowWrap: onlyDescriptionColumn,
    allowGrow: onlyDescriptionColumn,
    cellMarginX,
    boldHeader: true,
    boldFooter: false,
    headerBackground: "#292EBA",
    headerColor: "#ffffff",
    alignColumns: onlyDescriptionColumn.map((v) => (v ? "left" : "right")),
    preferredColumnWidths: widths,
    flipGray: false,
    minCellHeight: 0,
    headerEveryPage: true,
    writeColumn: pricesData.writeColumn,
  });
  return docH.returnPos({ startPage, startY });
  doc.y += TABLE_MARGIN; // отстут после "Итого"
};

module.exports = { calcItems, Prices, COLUMN_WIDTHS };
