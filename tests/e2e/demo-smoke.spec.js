// @ts-check
const { test, expect } = require('@playwright/test');

const email = process.env.E2E_EMAIL || 'smoke.playwright@example.test';
const password = process.env.E2E_PASSWORD || 'smoke-password-123';
const workspaceName = process.env.E2E_WORKSPACE_NAME || 'Демо smoke КомУчёт';

async function login(page) {
  await page.goto('/login');
  await expect(page.getByRole('heading', { name: 'Вход' })).toBeVisible();

  await page.getByLabel('Email').fill(email);
  await page.getByLabel('Пароль').fill(password);
  await page.getByRole('button', { name: 'Войти' }).click();
}

async function ensureWorkspace(page, selectSelector) {
  const select = page.locator(selectSelector);
  if ((await select.count()) === 0) {
    await expect(page.locator('[data-current-workspace-summary]')).toContainText(workspaceName);
    return;
  }

  await expect(select).toContainText(workspaceName);

  const selectedOption = page.locator(`${selectSelector} option:checked`);
  const selectedText = (await selectedOption.textContent())?.trim();

  if (selectedText !== workspaceName) {
    await select.selectOption({ label: workspaceName });
    await page.getByRole('button', { name: 'Сменить' }).click();
  }

  await expect(page.locator(`${selectSelector} option:checked`)).toContainText(workspaceName);
}

async function openAdminWorkspace(page) {
  await page.goto('/admin');
  await expect(page.getByRole('heading', { name: 'Рабочий стол' })).toBeVisible();
  await ensureWorkspace(page, '#admin-current-workspace');
}

async function openPortalWorkspace(page) {
  await page.goto('/portal');
  await expect(page.getByRole('heading', { name: 'Личный кабинет' })).toBeVisible();
  await ensureWorkspace(page, '#portal-current-workspace');
}

test.describe('demo smoke', () => {
  test.beforeEach(async ({ page }) => {
    await login(page);
  });

  test('user can open admin workspace and subscriber portal', async ({ page }) => {
    await expect(page).toHaveURL('/');
    await expect(page.getByRole('heading', { name: 'КомУчёт' })).toBeVisible();
    await expect(page.getByText(email)).toBeVisible();
    await expect(page.getByRole('link', { name: 'Админка' })).toBeVisible();
    await expect(page.getByRole('link', { name: 'Личный кабинет' })).toBeVisible();

    await openAdminWorkspace(page);
    await expect(page.getByRole('link', { name: 'Участки' }).first()).toBeVisible();
    await expect(page.getByRole('link', { name: 'Квитанции', exact: true })).toBeVisible();

    await openPortalWorkspace(page);
    await expect(page.getByRole('heading', { name: 'Личный кабинет' })).toBeVisible();
    await expect(page.getByText('9-001')).toBeVisible();
    await expect(page.getByText('9-013')).toBeVisible();
    await expect(page.getByRole('link', { name: 'Админка' })).toBeVisible();
  });

  test('subscriber can inspect account details, statement and readings form', async ({ page }) => {
    await openPortalWorkspace(page);

    const accountRow = page.locator('tbody tr').filter({ hasText: '9-001' });
    await expect(accountRow).toContainText('DEMO-EL-0001');
    await accountRow.getByRole('link', { name: 'Открыть' }).click();

    await expect(page.getByRole('heading', { name: 'Участок 9-001' })).toBeVisible();
    const balanceCard = page.locator('section.card').filter({ has: page.getByRole('heading', { name: 'Баланс' }) });
    await expect(balanceCard).toContainText('К оплате');

    const activeMeterCard = page.locator('section.card').filter({ has: page.getByRole('heading', { name: 'Активный электросчетчик' }) });
    await expect(activeMeterCard).toContainText('DEMO-EL-0001');
    await expect(activeMeterCard).toContainText('Меркурий 201.8 DEMO');

    await expect(page.getByRole('heading', { name: 'Показания электросчетчиков' })).toBeVisible();
    await expect(page.locator('#readings')).toContainText('Однотарифная');
    await expect(page.locator('#readings')).toContainText('Активно');
    await expect(page.getByRole('heading', { name: 'Начисления' })).toBeVisible();
    await expect(page.locator('#accruals')).toContainText('сценарий debt');
    await expect(page.getByRole('heading', { name: 'Оплаты' })).toBeVisible();
    await expect(page.locator('#payments')).toContainText('сценарий debt');

    await page.getByRole('link', { name: 'Баланс и операции' }).click();
    await expect(page.getByRole('heading', { name: 'Баланс и операции по участку 9-001' })).toBeVisible();
    await expect(page.getByRole('heading', { name: 'Итог' })).toBeVisible();
    await expect(page.getByRole('heading', { name: 'Расчет электроэнергии' })).toBeVisible();
    await expect(page.getByRole('heading', { name: 'Оплаты' })).toBeVisible();

    await page.getByRole('link', { name: 'К участку' }).click();
    await page.getByRole('link', { name: 'Передать показания' }).click();
    await expect(page.getByRole('heading', { name: 'Передать показания' })).toBeVisible();
    await expect(page.getByText('Участок 9-001, счетчик DEMO-EL-0001')).toBeVisible();
    await expect(page.getByText('Последнее:')).toBeVisible();
  });

  test('admin can inspect payments, readings and a two-rate meter', async ({ page }) => {
    await openAdminWorkspace(page);

    await page.goto('/admin/payments?q=9-001');
    await expect(page.getByRole('heading', { name: 'Оплаты' })).toBeVisible();
    await ensureWorkspace(page, '#admin-current-workspace');

    const paymentRow = page.locator('tbody tr').filter({ hasText: '9-001' }).filter({ hasText: 'сценарий debt' });
    await expect(paymentRow).toContainText('Импорт');
    await expect(paymentRow).toContainText('Активно');

    await page.goto('/admin/electricity-meter-readings?q=9-005');
    await expect(page.getByRole('heading', { name: 'Показания электросчетчиков' })).toBeVisible();
    await expect(page.locator('tbody')).toContainText('9-005');
    await expect(page.locator('tbody')).toContainText('DEMO-EL-0005');
    await expect(page.locator('tbody')).toContainText('day');
    await expect(page.locator('tbody')).toContainText('night');
    await expect(page.locator('tbody')).toContainText('Активно');

    await page.goto('/admin/electricity-meters?q=9-005');
    await expect(page.getByRole('heading', { name: 'Электросчетчики' })).toBeVisible();

    const meterRow = page.locator('tbody tr').filter({ hasText: '9-005' }).filter({ hasText: 'DEMO-EL-0005' });
    await expect(meterRow).toContainText('Меркурий 200.02 DEMO');
    await expect(meterRow).toContainText('day');
    await expect(meterRow).toContainText('night');
    await meterRow.getByRole('link', { name: 'Открыть' }).click();

    await expect(page.getByRole('heading', { name: 'Электросчетчик участка 9-005' })).toBeVisible();
    const registersSection = page.locator('section.card').filter({ has: page.getByRole('heading', { name: 'Регистры счетчика' }) });
    await expect(registersSection).toContainText('day');
    await expect(registersSection).toContainText('night');
    await expect(registersSection).toContainText('Дневная зона');
    await expect(registersSection).toContainText('Ночная зона');
    await expect(page.getByRole('heading', { name: 'Показания' })).toBeVisible();
  });

  test('admin can inspect statement payment requisites, QR code and PDF', async ({ page }) => {
    await openAdminWorkspace(page);

    await page.goto('/admin/account-statements?q=9-001&status=active&amount_to_pay_from=1');
    await expect(page.getByRole('heading', { name: 'Квитанции' })).toBeVisible();
    await ensureWorkspace(page, '#admin-current-workspace');

    const statementRow = page.locator('tbody tr').filter({ hasText: '9-001' }).filter({ hasText: 'Активна' }).first();
    await expect(statementRow).toContainText('руб.');
    await statementRow.getByRole('link', { name: 'Открыть' }).click();

    await expect(page.getByRole('heading', { name: /Квитанция №/ })).toBeVisible();
    await expect(page.getByRole('heading', { name: 'Платежные реквизиты' })).toBeVisible();
    await expect(page.locator('body')).toContainText('НКО "Демо-хозяйство КомУчёт"');
    await expect(page.locator('body')).toContainText('40703810000000000001');

    const qr = page.locator('.statement-payment-qr img');
    await expect(qr).toBeVisible();
    const qrInfo = await qr.evaluate((img) => ({
      src: img.getAttribute('src') || '',
      naturalWidth: img.naturalWidth,
      naturalHeight: img.naturalHeight,
      width: img.getBoundingClientRect().width,
      height: img.getBoundingClientRect().height,
    }));
    expect(qrInfo.src).toContain('data:image/png;base64,');
    expect(qrInfo.naturalWidth).toBeGreaterThanOrEqual(390);
    expect(qrInfo.naturalHeight).toBeGreaterThanOrEqual(390);

    const printHref = await page.getByRole('link', { name: 'Печатная версия' }).getAttribute('href');
    expect(printHref).toBeTruthy();
    await page.goto(new URL(printHref || '', page.url()).toString());
    await expect(page.locator('.statement-print-document')).toBeVisible();
    await expect(page.locator('body')).toContainText('Сканируйте для оплаты');

    const printQr = page.locator('.statement-print-payment-qr img');
    await expect(printQr).toBeVisible();
    const printQrBox = await printQr.evaluate((img) => ({
      width: img.getBoundingClientRect().width,
      height: img.getBoundingClientRect().height,
    }));
    expect(printQrBox.width).toBeGreaterThanOrEqual(310);
    expect(printQrBox.height).toBeGreaterThanOrEqual(310);

    await page.goBack();
    const pdfHref = await page.getByRole('link', { name: 'PDF', exact: true }).getAttribute('href');
    expect(pdfHref).toBeTruthy();
    const pdfResponse = await page.context().request.get(new URL(pdfHref || '', page.url()).toString());
    expect(pdfResponse.status()).toBe(200);
    expect(pdfResponse.headers()['content-type'] || '').toContain('application/pdf');
    expect((await pdfResponse.body()).length).toBeGreaterThan(1000);
  });
});
