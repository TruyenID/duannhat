const { chromium } = require('/Users/alex/Documents/code_godx/tempo/admin-web/node_modules/.pnpm/playwright@1.61.1/node_modules/playwright');
(async () => {
  const TOKEN=process.env.POS_TOKEN, OUT=process.env.OUT_DIR, URL=process.env.URL, NAME=process.env.NAME, CLICK=process.env.CLICK;
  const browser = await chromium.launch({ executablePath: '/Users/alex/Library/Caches/ms-playwright/chromium_headless_shell-1217/chrome-headless-shell-mac-arm64/chrome-headless-shell' });
  const page = await (await browser.newContext({ viewport:{width:1440,height:1150} })).newPage();
  page.on('response', r => { if (r.url().includes('/pos/till')) console.log('RESP', r.status(), r.url().replace('http://localhost:5400','')); });
  await page.goto('http://localhost:5440', { waitUntil:'domcontentloaded' });
  await page.evaluate((token)=>{ localStorage.setItem('pos_device_token',token); localStorage.setItem('pos_device_info',JSON.stringify({id:'e2e',name:'E2E Terminal',type:'pos',branch_name:'sjk',branch_slug:'sjk'})); localStorage.setItem('pos_api_mode','cloud'); localStorage.setItem('pos_locale','vi'); }, TOKEN);
  await page.goto(URL, { waitUntil:'networkidle' });
  await page.waitForTimeout(4500);
  if (CLICK==='gapcheck') { const b=page.getByRole('checkbox'); if(await b.count()>0){ await b.first().click(); await page.waitForTimeout(1200);} }
  console.log('FINAL_URL', page.url());
  await page.screenshot({ path: OUT+'/'+NAME+'.png', fullPage:true });
  console.log('SHOT', NAME+'.png');
  const t=await page.evaluate(()=>document.body.innerText);
  console.log('KEYLINES', t.split('\n').filter(l=>/ca nào đang mở|Đối chiếu|giữ riêng|Tóm tắt|đã thanh toán|chưa thanh toán|khoản|Xác nhận/i.test(l)).slice(0,8).join(' | '));
  await browser.close();
})().catch(e=>{console.error('ERR',e.message);process.exit(1);});
