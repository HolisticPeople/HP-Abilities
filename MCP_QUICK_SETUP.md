# MCP Quick Setup Guide

## Using the Setup Script

The `setup-mcp.ps1` script automates the process of copying MCP configuration to another machine.

## Step-by-Step Process

### Step 1: Export on Current Machine

On your **current machine** (where MCP is already working):

1. Open PowerShell in the HP-Abilities folder
2. Run the export command:
   ```powershell
   .\setup-mcp.ps1 -Export
   ```

This will:
- Read your current MCP configuration
- Convert it to a portable format (using `npx` instead of hardcoded paths)
- Copy your SSH key (if it exists)
- Create an `mcp-export` folder with everything needed

### Step 2: Copy to New Machine

Transfer the `mcp-export` folder to the new machine:
- Via USB drive
- Via cloud storage (secure)
- Via network share
- Via email (if file sizes allow)

**⚠️ Security Note:** The SSH key is sensitive. Use secure transfer methods.

### Step 3: Setup on New Machine

On the **new machine**:

1. Copy the `mcp-export` folder to the HP-Abilities directory (or wherever you have the script)
2. Open PowerShell in that location
3. Run the setup command:
   ```powershell
   .\setup-mcp.ps1 -Setup
   ```

The script will:
- Prompt for your username (or use current user)
- Ask for SSH key path (or use default)
- Create the `.cursor` directory
- Copy the MCP config file
- Copy the SSH key (if included)
- Set proper file permissions

### Step 4: Install Prerequisites

On the new machine, ensure you have:

1. **Node.js** installed (required for MCP)
   - Download from: https://nodejs.org/
   - Version 18+ recommended
   - Verify: `node --version` and `npm --version`

2. **Cursor IDE** installed
   - Download from: https://cursor.sh/

3. **Browser Extension** (optional, for browser automation)
   - Install the Cursor browser extension if you use it

### Step 5: Restart and Verify

1. Restart Cursor IDE completely
2. Check MCP status:
   - Open Command Palette (Ctrl+Shift+P)
   - Search for "MCP" to see server status
3. Verify MCP tools are available in AI chat

## Command Line Options

### Export Command
```powershell
.\setup-mcp.ps1 -Export
```

### Setup Command (Interactive)
```powershell
.\setup-mcp.ps1 -Setup
```

### Setup Command (Non-Interactive)
```powershell
.\setup-mcp.ps1 -Setup -Username "myusername" -SshKeyPath "C:\Users\myusername\.ssh\kinsta_staging_key"
```

## Troubleshooting

### Script Won't Run
If you get an execution policy error:
```powershell
Set-ExecutionPolicy -ExecutionPolicy RemoteSigned -Scope CurrentUser
```

### SSH Key Permissions
If SSH key doesn't work, check permissions:
- Right-click the key file → Properties → Security
- Ensure only your user has access (remove inheritance if needed)

### MCP Servers Not Working
1. Verify Node.js is installed: `node --version`
2. Check Cursor's output panel for MCP errors
3. Verify paths in `%USERPROFILE%\.cursor\mcp.json`
4. Test npx manually: `npx -y @automattic/mcp-wordpress-remote`

### Port Already in Use
If browser extension port (13338) is in use:
- Close other instances of Cursor
- Restart the browser extension

## Manual Setup Alternative

If you prefer to set up manually, see `MCP_SETUP_GUIDE.md` for detailed instructions.

## What Gets Exported

The `mcp-export` folder contains:
- `mcp.json` - Portable MCP configuration
- `kinsta_staging_key` - SSH key (if it existed)

## Security Reminders

- ⚠️ The SSH key is sensitive - keep it secure
- ⚠️ The `mcp.json` file contains API keys - don't commit to version control
- ⚠️ Use secure transfer methods (encrypted USB, secure cloud storage)
- ⚠️ After setup, delete the `mcp-export` folder if no longer needed

