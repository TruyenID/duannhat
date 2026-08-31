const { chromium } = require('/Users/alex/Documents/code_godx/tempo/admin-web/node_modules/.pnpm/playwright@1.61.1/node_modules/playwright');
(async () => {
  const TOKEN = process.env.POS_TOKEN, OUT = process.env.OUT_DIR;
  const browser = await chromium.launch({ executablePath: '/Users/alex/Library/Caches/ms-playwright/chromium_headless_shell-1217/chrome-headless-shell-mac-arm64/chrome-headless-shell' });
  const page = await (await browser.newContext({ viewport: { width: 1440, height: 1100 } })).newPage();
  page.on('response', r => { if (r.url().includes('/pos/till')) console.log('RESP', r.status(), r.url().replace('http://localhost:5400','')); });
  await page.goto('http://localhost:5440', { waitUntil: 'domcontentloaded' });
  await page.evaluate((token) => {
    localStorage.setItem('pos_device_token', token);
    localStorage.setItem('pos_device_info', JSON.stringify({ id:'e2e', name:'E2E Terminal', type:'pos', branch_name:'sjk', branch_slug:'sjk' }));
    localStorage.setItem('pos_api_mode', 'cloud');   // force Cloud (skip the LAN workstation on :8080)
    localStorage.setItem('pos_locale', 'vi');
  }, TOKEN);
  await page.goto('http://localhost:5440/shop/sjk', { waitUntil: 'networkidle' });
  await page.waitForTimeout(5000);
  console.log('URL', page.url());
  await page.screenshot({ path: OUT + '/ui-01-shift-open.png', fullPage: true });
  console.log('SHOT ui-01-shift-open.png');
  const t = await page.evaluate(() => document.body.innerText.slice(0, 500));
  console.log('BODY>>>'); console.log(t); console.log('<<<');
  await browser.close();
})().catch(e => { console.error('ERR', e.message); process.exit(1); });
