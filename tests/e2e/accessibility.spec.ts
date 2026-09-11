import AxeBuilder from '@axe-core/playwright'
import { expect, test } from '@playwright/test'

test('overview is usable and has no detectable accessibility violations', async ({
  page,
}) => {
  await page.goto('/')

  await expect(page.getByRole('heading', { name: 'WPCommander' })).toBeVisible()
  await expect(
    page.getByRole('button', { name: 'Generate connection token' }),
  ).toBeEnabled()
  await expect(page.getByText('Connect Custom GPT')).toBeVisible()
  await expect(
    page.getByRole('button', { name: 'Copy Action schema' }),
  ).toBeEnabled()
  await expect(
    page.getByRole('button', { name: 'Copy GPT instructions' }),
  ).toBeEnabled()
  await page.getByRole('button', { name: 'Generate connection token' }).click()
  await expect(
    page.getByRole('button', { name: 'Copy Basic auth token' }),
  ).toBeVisible()
  await page.getByRole('button', { name: 'Run access test' }).click()
  await expect(page.getByText('Posts and pages').first()).toBeVisible()
  await expect(
    page.getByRole('button', { name: 'Copy diagnostic report' }),
  ).toBeVisible()

  const results = await new AxeBuilder({ page }).analyze()
  expect(results.violations).toEqual([])
})
