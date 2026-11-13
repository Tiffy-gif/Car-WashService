const { chromium } = require('playwright');

(async () => {
    const browser = await chromium.launch({ channel: 'chrome', headless: true });
    const page = await browser.newPage();
    await page.goto('http://127.0.0.1/Car-WashService/index.php?view=login');
    await page.fill('#username', 'dona');
    await page.fill('#pin', '123456');
    await Promise.all([page.waitForNavigation(), page.click('button[type="submit"]')]);
    await page.goto('http://127.0.0.1/Car-WashService/pos/index.html#mechanic');
    await page.waitForTimeout(1500);

    const data = await page.evaluate(async () => {
        const user = PosStorage.getUser();
        const res = await PosApi.getTechnicalJobs(user.id);
        return res;
    });
    console.log('API RESPONSE KEYS:', Object.keys(data));
    if (data.active_jobs && data.active_jobs.length > 0) {
        console.log('ACTIVE JOB ORDER:', JSON.stringify(data.active_jobs[0].order, null, 2));
        console.log('ACTIVE JOB TASKS:', JSON.stringify(data.active_jobs[0].tasks, null, 2));
        console.log('ACTIVE JOB CHECKLISTS COUNT:', data.active_jobs[0].checklists?.length);
    }
    if (data.waiting_handover) {
        console.log('WAITING HANDOVER COUNT:', data.waiting_handover.length);
    }
    await browser.close();
})();

