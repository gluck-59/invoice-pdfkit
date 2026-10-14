const tradePDF = require("./index");
const fs = require("fs");

const myCompany = {
  company: "Jopa with Artiacts",
  email: "jo@pa.com",
  web: "https://jopa.org",
};

const myOrder = {
  id: "S4TIX8GX",
  date: {
    created: 1659372135311,
    due: 1655118412147,
  },
  bill: {
    company: "Client",
    email: "client@client.com",
  },
  items: [
    {
      id: "cat1",
      desc: "Cat w/o tail",
      tax: "20",
      qty: "1",
      price: "1000",
    },
    {
      id: "tail1",
      desc: "Tail #1 for Cats",
      tax: "20",
      qty: "1",
      price: "2000",
    },
  ],
  total: {
    discount: 10,
    stateTax: 0,
    fedTax: 0,
    ship: 10,
  },
};

console.log("Language options: " + tradePDF.getLanguages());
tradePDF.init({
  logo: fs.existsSync("test/cat-white.png") ? fs.readFileSync("test/cat-white.png") : undefined,
  company: myCompany,
  currency: "USD",
});
(async () => {
const pdfData = await tradePDF.invoice(myOrder);
fs.writeFileSync("demo-invoice.pdf", pdfData);
console.log("Generated demo-invoice.pdf, bytes: " + pdfData.length);
})();
