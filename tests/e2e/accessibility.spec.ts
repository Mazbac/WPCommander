import AxeBuilder from '@axe-core/playwright'
import { expect, test } from '@playwright/test'

test('baseline is usable and has no detectable accessibility violations', async ({
  page,
}) => {
  await page.goto('/')

  await expect(page.getByRole('heading', { name: 'UI baseline' })).toBeVisible()
  await expect(
    page.getByRole('button', { name: 'Primary action' }),
  ).toBeEnabled()

  const results = await new AxeBuilder({ page }).analyze()
  expect(results.violations).toEqual([])
})
