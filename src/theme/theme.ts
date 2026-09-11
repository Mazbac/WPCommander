import { createTheme, type CSSVariablesResolver } from '@mantine/core'

const fontFamily =
  '-apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Oxygen-Sans, Ubuntu, Cantarell, "Helvetica Neue", sans-serif'

export const layoutTokens = {
  headerHeight: 56,
  navbarWidth: 224,
} as const

export const theme = createTheme({
  primaryColor: 'blue',
  primaryShade: { light: 8, dark: 6 },
  autoContrast: true,
  defaultRadius: 'xs',
  fontFamily,
  headings: {
    fontFamily,
    fontWeight: '600',
  },
})

export const cssVariablesResolver: CSSVariablesResolver = (resolvedTheme) => ({
  variables: {},
  light: { '--mantine-color-dimmed': resolvedTheme.colors.gray[7] },
  dark: { '--mantine-color-dimmed': resolvedTheme.colors.gray[4] },
})
