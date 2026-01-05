# MCP Setup Guide for New Machine

This guide explains how to set up MCP (Model Context Protocol) on another machine with Cursor IDE.

## Overview

MCP configuration is stored in: `%USERPROFILE%\.cursor\mcp.json`

## What to Copy

### 1. MCP Configuration File (Required)
- **Source**: `%USERPROFILE%\.cursor\mcp.json` (on current machine)
- **Destination**: `%USERPROFILE%\.cursor\mcp.json` (on new machine)
- **Action**: Copy the entire file

### 2. SSH Key File (Required for `kinsta-staging`)
- **Source**: `C:\Users\user\.ssh\kinsta_staging_key` (on current machine)
- **Destination**: Same path or your preferred SSH directory on new machine
- **Action**: Copy the key file
- **Note**: Update the path in `mcp.json` if you place it in a different location

### 3. Browser Extension (Optional)
- The `cursor-browser-extension` MCP server runs on `http://127.0.0.1:13338/mcp`
- Ensure the browser extension is installed and running on the new machine

## Step-by-Step Setup Instructions

### Step 1: Create .cursor Directory
On the new machine, create the directory if it doesn't exist:
```powershell
# Windows PowerShell
New-Item -ItemType Directory -Force -Path "$env:USERPROFILE\.cursor"
```

### Step 2: Copy mcp.json File
1. On your current machine, locate: `%USERPROFILE%\.cursor\mcp.json`
2. Copy this file to the new machine at the same location: `%USERPROFILE%\.cursor\mcp.json`
3. If the username is different, the path will be: `C:\Users\{new-username}\.cursor\mcp.json`

### Step 3: Adjust Paths in mcp.json
After copying, you'll need to update machine-specific paths:

#### A. Node.js npx Cache Path (Critical)
The `args` for `hp_abilities_staging` and `hp_abilities_production` contain an npx cache path:
```
C:\\Users\\user\\AppData\\Local\\npm-cache\\_npx\\d036eb0573a6a23a\\...
```

**Solution Options:**

**Option 1: Use relative npx (Recommended)**
The first time npx runs, it will create the cache. You can update the config to just use the package name:

Change from:
```json
"args": [
  "C:\\Users\\user\\AppData\\Local\\npm-cache\\_npx\\d036eb0573a6a23a\\node_modules\\@automattic\\mcp-wordpress-remote\\dist\\proxy.js"
]
```

To:
```json
"args": [
  "-y",
  "@automattic/mcp-wordpress-remote",
  "dist/proxy.js"
]
```

**Option 2: Run npx once to generate the path**
1. On the new machine, run: `npx -y @automattic/mcp-wordpress-remote`
2. Note the cache path it creates (it will be different)
3. Update the `args` in `mcp.json` with the new path

**Option 3: Use global installation**
```powershell
npm install -g @automattic/mcp-wordpress-remote
```
Then use the global path in `mcp.json`

#### B. SSH Key Path (If Different Username)
If your username is different on the new machine, update the SSH key path:

From:
```
C:\\Users\\user\\.ssh\\kinsta_staging_key
```

To:
```
C:\\Users\\{new-username}\\.ssh\\kinsta_staging_key
```

#### C. GitKraken Path (Optional)
If you use GitKraken, the path in the config is machine-specific. You can:
- Remove this section if you don't use GitKraken
- Or update the path if GitKraken is installed in a different location

### Step 4: Copy SSH Key
1. Copy `C:\Users\user\.ssh\kinsta_staging_key` from current machine
2. Place it in `C:\Users\{your-username}\.ssh\` on the new machine
3. Ensure proper permissions (on Windows, right-click → Properties → Security)
4. Update the path in `mcp.json` if different

### Step 5: Install Prerequisites

#### Node.js (Required)
MCP servers require Node.js. Install from: https://nodejs.org/
- Minimum version: Node.js 18+ recommended
- Verify installation: `node --version` and `npm --version`

#### Browser Extension (Optional)
If using `cursor-browser-extension`:
- Install the Cursor browser extension
- Ensure it's running (should listen on port 13338)

### Step 6: Test MCP Configuration
1. Restart Cursor IDE on the new machine
2. Check MCP status in Cursor:
   - Open Command Palette (Ctrl+Shift+P)
   - Search for "MCP" to see status
3. Verify MCP tools are available (they should appear in the AI chat)

## Configuration Reference

Your current configuration includes:

1. **hp_abilities_staging** - WooCommerce MCP for staging environment
   - URL: `https://env-holisticpeoplecom-hpdevplus.kinsta.cloud/wp-json/woocommerce/mcp`
   - Requires: Node.js, API key in CUSTOM_HEADERS

2. **hp_abilities_production** - WooCommerce MCP for production environment
   - URL: `https://holisticpeople.com/wp-json/woocommerce/mcp`
   - Requires: Node.js, API key in CUSTOM_HEADERS

3. **kinsta-staging** - SSH MCP server
   - Requires: SSH key file, `mcp-remote-ssh` npm package

4. **cursor-browser-extension** - Browser automation
   - Requires: Browser extension installed and running

5. **GitKraken** (optional)
   - Requires: GitKraken installed

## Troubleshooting

### MCP Servers Not Appearing
- Check Cursor's output panel for MCP errors
- Verify Node.js is installed: `node --version`
- Check the npx cache path is correct (run npx once to generate it)
- Verify file paths use double backslashes in JSON: `C:\\\\Users\\\\...`

### SSH MCP Not Working
- Verify SSH key file exists and path is correct
- Check SSH key permissions
- Test SSH connection manually: `ssh -i path/to/key -p 12872 user@host`

### API Key Errors
- API keys are stored in `CUSTOM_HEADERS` environment variable
- Format: `{"X-MCP-API-Key":"consumer_key:consumer_secret"}`
- Verify keys are correct for staging/production

### Browser Extension Not Working
- Ensure browser extension is installed
- Check it's listening on port 13338
- Restart Cursor IDE after installing extension

## Quick Setup Checklist

- [ ] Create `.cursor` directory on new machine
- [ ] Copy `mcp.json` file
- [ ] Update npx cache paths or use relative paths
- [ ] Copy SSH key file
- [ ] Update SSH key path in `mcp.json` (if different username)
- [ ] Install Node.js
- [ ] Install browser extension (optional)
- [ ] Restart Cursor IDE
- [ ] Verify MCP servers are active

## Security Notes

⚠️ **Important Security Considerations:**

1. **API Keys**: The `mcp.json` file contains API keys. Keep it secure and don't commit it to version control.

2. **SSH Keys**: Treat SSH keys as sensitive. Use secure transfer methods (encrypted USB, secure cloud storage, etc.)

3. **File Permissions**: Ensure `.cursor` directory and `mcp.json` have appropriate permissions (not world-readable)

4. **Backup**: Keep a backup of your `mcp.json` in a secure location

## Alternative: Minimal Setup

If you only need the WooCommerce MCP servers, you can create a minimal `mcp.json`:

```json
{
  "mcpServers": {
    "hp_abilities_staging": {
      "type": "stdio",
      "command": "npx",
      "args": [
        "-y",
        "@automattic/mcp-wordpress-remote",
        "dist/proxy.js"
      ],
      "env": {
        "WP_API_URL": "https://env-holisticpeoplecom-hpdevplus.kinsta.cloud/wp-json/woocommerce/mcp",
        "CUSTOM_HEADERS": "{\"X-MCP-API-Key\":\"ck_500105377f30e4313faafdaf0e396dae83e96fea:cs_132e7bb84c8c1944687826b6f8ba2e787dde6095\"}",
        "TOOL_PREFIX": "hp"
      }
    },
    "hp_abilities_production": {
      "type": "stdio",
      "command": "npx",
      "args": [
        "-y",
        "@automattic/mcp-wordpress-remote",
        "dist/proxy.js"
      ],
      "env": {
        "WP_API_URL": "https://holisticpeople.com/wp-json/woocommerce/mcp",
        "CUSTOM_HEADERS": "{\"X-MCP-API-Key\":\"ck_607755c07ede34effa0a599780931fd5b750eebe:cs_4ed7859da0385dea30cc27f0587ea0df35c7fc8c\"}",
        "TOOL_PREFIX": "hp"
      }
    }
  }
}
```

This uses `npx` directly without hardcoded paths, making it more portable.

