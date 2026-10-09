// Synthetic UI regression check: no login, real photos or Drive requests.
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const puppeteer = require('puppeteer');
const ts = require('typescript');

async function main() {
  const root = path.resolve(__dirname, '../../..');
  const source = fs.readFileSync(path.join(root, 'src/ts/frontend/shortcode/PhotoFilter.ts'), 'utf8');
  const js = ts.transpileModule(source, {
    compilerOptions: { target: ts.ScriptTarget.ES2022, module: ts.ModuleKind.CommonJS },
  }).outputText;
  const browser = await puppeteer.launch({
    executablePath: '/usr/bin/google-chrome', headless: true,
    args: ['--no-sandbox', '--disable-gpu'],
  });
  try {
    const page = await browser.newPage();
    await page.setContent('<div id="fixture"></div><button id="outside">Outside</button>');
    await page.addScriptTag({ content: 'window.exports = {};\n' + js });
    await page.evaluate(() => {
      window.changes = [];
      window.requests = [];
      window.fetch = async (url, options) => {
        window.requests.push(options.body);
        return { json: async () => ({ success: true, data: { recipient: 'test@example.invalid' } }) };
      };
      const conditions = [{ kind: 'tag', value: 'fixture', op: 'and', label: 'Example' }];
      const child = { id: 'child', name: 'Child', path: 'Parent/Child' };
      const bar = window.exports.buildFilterBar('/synthetic', conditions, 5, {
        selected: [child], onChange: (selected) => window.changes.push(selected),
        load: async (folder) => folder === ''
          ? [{ id: 'parent', name: 'Parent', path: 'Parent' }]
          : [child],
      }, { order: 'date', onChange() {} }, {
        enabled: true, captions: true, google: 'test@example.invalid',
        folders: [child], sort: 'date', nonce: 'synthetic',
      }, {
        filters: [], shared: [], recipients: async () => ({ users: [], groups: [] }),
        onShare: async () => [], onApply() {}, onDelete: async () => [], onSave: async () => [],
      }, () => {});
      document.getElementById('fixture').appendChild(bar);
    });
    await page.click('.avpvh-filter-share');
    const labels = await page.$$eval('.avpvh-filter-share-captions', (nodes) => nodes.map((node) => node.textContent));
    assert.ok(labels.some((label) => label.includes('jaar en plaats')));
    assert.ok(labels.some((label) => label.includes('A4-jpg') && label.includes('80 MB')));
    await page.$$eval('.avpvh-filter-share-captions input', (inputs) => inputs.forEach((input) => { input.checked = true; }));
    await page.click('.avpvh-filter-share');
    const request = new URLSearchParams(await page.evaluate(() => window.requests[0]));
    assert.equal(request.get('a4'), '1');
    assert.equal(request.get('captions'), '1');
    console.log('PASS A4 and year/place options are visible and sent to the server');

    await page.click('.avpvh-filter-folders summary');
    await page.waitForSelector('input[data-folder-id="child"]');
    const restored = await page.$eval('input[data-folder-id="child"]', (input) => input.checked);
    assert.equal(restored, true);
    assert.equal(await page.$eval('input[data-folder-id="parent"]', (input) => input.indeterminate), true);
    assert.equal(await page.$eval('input[data-folder-id="parent"]', (input) => input.closest('li').classList.contains('closed')), false);
    await page.click('input[data-folder-id="parent"]');
    assert.equal(await page.$eval('input[data-folder-id="child"]', (input) => input.checked), false);
    assert.equal(await page.evaluate(() => window.changes.length), 0);
    await page.click('.avpvh-filter-folder-apply');
    assert.deepEqual(await page.evaluate(() => window.changes[0].map((folder) => folder.id)), ['parent']);
    console.log('PASS restored checkbox state, auto-expansion, descendant state and Apply button');

    await page.click('.avpvh-filter-folders summary');
    await page.click('input[data-folder-id="parent"]');
    await new Promise((resolve) => setTimeout(resolve, 5200));
    assert.equal(await page.evaluate(() => window.changes.length), 2);
    console.log('PASS five-second folder debounce preserved');
  } finally {
    await browser.close();
  }
}
main().catch((error) => { console.error(error); process.exitCode = 1; });
