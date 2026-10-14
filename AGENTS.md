# RideSync Workspace Rules

## Browser Configuration: Chrome & Brave Browser
- **Supported Browsers**:
  - Google Chrome: `C:\Program Files\Google\Chrome\Application\chrome.exe`
  - Brave Browser: `C:\Program Files\BraveSoftware\Brave-Browser\Application\brave.exe`
- **Testing & Browser Interactions**:
  - Automated browser testing supports Google Chrome and Brave via CDP.
  - Commands available: `npm run test:browser` (auto-detects Chrome or Brave), `npm run test:chrome`, `npm run test:brave`.
  - For interactive launches:
    `Start-Process 'C:\Program Files\Google\Chrome\Application\chrome.exe' '<URL>'` or `npm run chrome:open`
    `Start-Process 'C:\Program Files\BraveSoftware\Brave-Browser\Application\brave.exe' '<URL>'` or `npm run brave:open`
