import puppeteer from 'puppeteer';

const folderName = process.argv[2];
const parentId = process.argv[3];
const browser = await puppeteer.connect({browserURL: 'http://127.0.0.1:9222'});
let page = (await browser.pages()).find(p => p.url().includes('drive.google.com/drive/folders/'));
if (!page) page = await browser.newPage();
await page.setViewport({width: 1800, height: 1400});
await page.goto(`https://drive.google.com/drive/folders/${parentId}`, {waitUntil: 'domcontentloaded', timeout: 60000});
await new Promise(r => setTimeout(r, 2500));

const findId = name => page.evaluate(n => [...document.querySelectorAll('tr[data-id]')].find(row => {
  const strong = row.querySelector('strong');
  return strong && strong.textContent.trim() === n;
})?.dataset.id || null, name);

const existing = await findId(folderName);
if (existing) {
  console.log(JSON.stringify({status: 'EXISTS', id: existing, name: folderName}));
  browser.disconnect();
  process.exit(0);
}

await page.click('button[guidedhelpid="new_menu_button"]');
await page.waitForFunction(() => [...document.querySelectorAll('[role=menuitem]')].some(el =>
  el.offsetParent !== null && el.innerText.trim().startsWith('New folder')
), {timeout: 10000});
const menuItems = await page.$$('[role=menuitem]');
let newFolderItem = null;
for (const item of menuItems) {
  const text = await item.evaluate(el => el.innerText.trim());
  if (text.startsWith('New folder')) { newFolderItem = item; break; }
}
if (!newFolderItem) throw new Error('New folder menu item not found');
await newFolderItem.click();
await page.waitForSelector('[role=dialog] input', {visible: true, timeout: 10000});
const input = await page.$('[role=dialog] input');
await input.click({clickCount: 3});
await input.type(folderName);
await page.keyboard.press('Enter');
await page.waitForFunction(name => [...document.querySelectorAll('tr[data-id]')].some(row => {
  const strong = row.querySelector('strong');
  return strong && strong.textContent.trim() === name;
}), {timeout: 30000}, folderName);
const id = await findId(folderName);
console.log(JSON.stringify({status: 'CREATED', id, name: folderName}));
browser.disconnect();
