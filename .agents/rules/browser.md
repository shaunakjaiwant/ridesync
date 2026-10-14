# Browser Configuration: Chrome & Brave Browser

## Global Policy
- **Supported Browsers**:
  - Google Chrome: `C:\Program Files\Google\Chrome\Application\chrome.exe`
  - Brave Browser: `C:\Program Files\BraveSoftware\Brave-Browser\Application\brave.exe`

## Browser Operations & Testing
- **Opening URLs / Local Testing**:
  - Google Chrome: `Start-Process 'C:\Program Files\Google\Chrome\Application\chrome.exe' '<URL>'` or `npm run chrome:open`
  - Brave Browser: `Start-Process 'C:\Program Files\BraveSoftware\Brave-Browser\Application\brave.exe' '<URL>'` or `npm run brave:open`
- **Browser Automation & CDP Testing**:
  - Chrome automated test: `npm run test:chrome` (`tools/test_with_chrome.mjs`)
  - Brave automated test: `npm run test:brave` (`tools/test_with_brave.mjs`)
  - Universal test: `npm run test:browser`
