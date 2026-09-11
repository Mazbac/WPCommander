import { expect, test } from '@playwright/test'

test('desktop UI baseline remains stable', async ({ page }) => {
  await page.goto('/')
  await expect(page.getByRole('heading', { name: 'UI baseline' })).toBeVisible()
  await expect(page).toHaveScreenshot('ui-baseline-desktop.png', {
    fullPage: true,
  })
})

test('mobile UI baseline remains stable', async ({ page }) => {
  await page.setViewportSize({ width: 390, height: 844 })
  await page.goto('/')
  await expect(page.getByRole('heading', { name: 'UI baseline' })).toBeVisible()
  await expect(page).toHaveScreenshot('ui-baseline-mobile.png', {
    fullPage: true,
  })
})
