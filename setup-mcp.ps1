# MCP Setup Script for Cursor IDE
# This script helps set up MCP configuration on a new machine

param(
    [switch]$Export,
    [switch]$Setup,
    [string]$Username = "",
    [string]$SshKeyPath = ""
)

$ErrorActionPreference = "Stop"

function Export-MCPConfig {
    Write-Host "=== Exporting MCP Configuration ===" -ForegroundColor Cyan
    Write-Host ""
    
    $sourcePath = "$env:USERPROFILE\.cursor\mcp.json"
    
    if (-not (Test-Path $sourcePath)) {
        Write-Host "ERROR: MCP config file not found at: $sourcePath" -ForegroundColor Red
        Write-Host "Make sure MCP is configured on this machine first." -ForegroundColor Yellow
        exit 1
    }
    
    Write-Host "Found MCP config at: $sourcePath" -ForegroundColor Green
    
    # Read current config
    $config = Get-Content $sourcePath -Raw | ConvertFrom-Json
    
    # Convert to portable format (use npx instead of hardcoded paths)
    $portableConfig = @{
        mcpServers = @{}
    }
    
    foreach ($serverName in $config.mcpServers.PSObject.Properties.Name) {
        $server = $config.mcpServers.$serverName
        
        if ($serverName -eq "hp_abilities_staging" -or $serverName -eq "hp_abilities_production") {
            # Convert to portable npx format
            $portableConfig.mcpServers[$serverName] = @{
                type = "stdio"
                command = "npx"
                args = @(
                    "-y",
                    "@automattic/mcp-wordpress-remote",
                    "dist/proxy.js"
                )
                env = $server.env
            }
        }
        elseif ($serverName -eq "kinsta-staging") {
            # Keep npx format, but note SSH key path needs updating
            $newServer = $server.PSObject.Copy()
            $portableConfig.mcpServers[$serverName] = $newServer
        }
        elseif ($serverName -eq "cursor-browser-extension") {
            # Keep as-is
            $portableConfig.mcpServers[$serverName] = $server
        }
        elseif ($serverName -eq "GitKraken") {
            Write-Host "Skipping GitKraken (machine-specific path)" -ForegroundColor Yellow
        }
        else {
            # Keep other servers as-is
            $portableConfig.mcpServers[$serverName] = $server
        }
    }
    
    # Create export directory
    $exportDir = Join-Path $PSScriptRoot "mcp-export"
    New-Item -ItemType Directory -Force -Path $exportDir | Out-Null
    
    # Save portable config
    $portablePath = Join-Path $exportDir "mcp.json"
    $portableConfig | ConvertTo-Json -Depth 10 | Set-Content $portablePath -Encoding UTF8
    
    Write-Host ""
    Write-Host "Exported portable MCP config to: $portablePath" -ForegroundColor Green
    
    # Copy SSH key if it exists
    $sshKeySource = "$env:USERPROFILE\.ssh\kinsta_staging_key"
    if (Test-Path $sshKeySource) {
        $sshKeyDest = Join-Path $exportDir "kinsta_staging_key"
        Copy-Item $sshKeySource $sshKeyDest -Force
        Write-Host "Copied SSH key to: $sshKeyDest" -ForegroundColor Green
        Write-Host ""
        Write-Host "⚠️  SECURITY WARNING: SSH key contains sensitive data!" -ForegroundColor Red
        Write-Host "   Keep this file secure and don't commit it to version control." -ForegroundColor Yellow
    } else {
        Write-Host ""
        Write-Host "SSH key not found at: $sshKeySource" -ForegroundColor Yellow
        Write-Host "You'll need to copy it manually if needed." -ForegroundColor Yellow
    }
    
    Write-Host ""
    Write-Host "=== Export Complete ===" -ForegroundColor Cyan
    Write-Host ""
    Write-Host "Next steps:" -ForegroundColor Cyan
    Write-Host "1. Copy the 'mcp-export' folder to the new machine" -ForegroundColor White
    Write-Host "2. Run this script with -Setup parameter on the new machine" -ForegroundColor White
}

function Setup-MCPConfig {
    Write-Host "=== Setting up MCP Configuration ===" -ForegroundColor Cyan
    Write-Host ""
    
    # Check if export directory exists
    $exportDir = Join-Path $PSScriptRoot "mcp-export"
    if (-not (Test-Path $exportDir)) {
        Write-Host "ERROR: Export directory not found: $exportDir" -ForegroundColor Red
        Write-Host ""
        Write-Host "Please either:" -ForegroundColor Yellow
        Write-Host "1. Run this script with -Export on the source machine first" -ForegroundColor White
        Write-Host "2. Or copy the 'mcp-export' folder to this location" -ForegroundColor White
        exit 1
    }
    
    $mcpJsonPath = Join-Path $exportDir "mcp.json"
    if (-not (Test-Path $mcpJsonPath)) {
        Write-Host "ERROR: mcp.json not found in export directory" -ForegroundColor Red
        exit 1
    }
    
    # Get or prompt for username
    if ([string]::IsNullOrEmpty($Username)) {
        $currentUser = $env:USERNAME
        Write-Host "Current username: $currentUser" -ForegroundColor Cyan
        $response = Read-Host "Use this username? (Y/n)"
        if ($response -eq "" -or $response -eq "Y" -or $response -eq "y") {
            $Username = $currentUser
        } else {
            $Username = Read-Host "Enter username"
        }
    }
    
    Write-Host ""
    Write-Host "Using username: $Username" -ForegroundColor Green
    
    # Read portable config
    $config = Get-Content $mcpJsonPath -Raw | ConvertFrom-Json
    
    # Update SSH key path in kinsta-staging if it exists
    if ($config.mcpServers.PSObject.Properties.Name -contains "kinsta-staging") {
        $kinstaServer = $config.mcpServers.'kinsta-staging'
        
        # Determine SSH key path
        $sshKeyPathToUse = ""
        if (-not [string]::IsNullOrEmpty($SshKeyPath)) {
            $sshKeyPathToUse = $SshKeyPath
        } else {
            $defaultSshPath = "C:\Users\$Username\.ssh\kinsta_staging_key"
            Write-Host ""
            Write-Host "SSH Key path (default: $defaultSshPath): " -NoNewline
            $inputPath = Read-Host
            if ([string]::IsNullOrEmpty($inputPath)) {
                $sshKeyPathToUse = $defaultSshPath
            } else {
                $sshKeyPathToUse = $inputPath
            }
        }
        
        # Update the key path in args
        $keyIndex = -1
        for ($i = 0; $i -lt $kinstaServer.args.Count; $i++) {
            if ($kinstaServer.args[$i] -eq "--key") {
                $keyIndex = $i + 1
                break
            }
        }
        
        if ($keyIndex -gt 0) {
            $kinstaServer.args[$keyIndex] = $sshKeyPathToUse.Replace("\", "\\")
            Write-Host "Updated SSH key path: $sshKeyPathToUse" -ForegroundColor Green
        }
    }
    
    # Create .cursor directory
    $cursorDir = "$env:USERPROFILE\.cursor"
    if (-not (Test-Path $cursorDir)) {
        New-Item -ItemType Directory -Force -Path $cursorDir | Out-Null
        Write-Host "Created directory: $cursorDir" -ForegroundColor Green
    }
    
    # Save config to .cursor directory
    $targetPath = Join-Path $cursorDir "mcp.json"
    $config | ConvertTo-Json -Depth 10 | Set-Content $targetPath -Encoding UTF8
    Write-Host "MCP config saved to: $targetPath" -ForegroundColor Green
    
    # Copy SSH key if it exists in export directory
    $sshKeySource = Join-Path $exportDir "kinsta_staging_key"
    if (Test-Path $sshKeySource) {
        $sshKeyDir = "$env:USERPROFILE\.ssh"
        if (-not (Test-Path $sshKeyDir)) {
            New-Item -ItemType Directory -Force -Path $sshKeyDir | Out-Null
            Write-Host "Created directory: $sshKeyDir" -ForegroundColor Green
        }
        
        $sshKeyDest = Join-Path $sshKeyDir "kinsta_staging_key"
        Copy-Item $sshKeySource $sshKeyDest -Force
        Write-Host "Copied SSH key to: $sshKeyDest" -ForegroundColor Green
        
        # Set file permissions (remove inheritance, keep current user)
        try {
            $acl = Get-Acl $sshKeyDest
            $acl.SetAccessRuleProtection($true, $false)
            $rule = New-Object System.Security.AccessControl.FileSystemAccessRule($env:USERNAME, "FullControl", "Allow")
            $acl.SetAccessRule($rule)
            Set-Acl $sshKeyDest $acl
            Write-Host "Set SSH key permissions" -ForegroundColor Green
        } catch {
            Write-Host "Warning: Could not set SSH key permissions (may need manual adjustment)" -ForegroundColor Yellow
        }
    } else {
        Write-Host ""
        Write-Host "⚠️  SSH key not found in export directory" -ForegroundColor Yellow
        Write-Host "   If you need the kinsta-staging MCP server, copy the SSH key manually" -ForegroundColor Yellow
    }
    
    Write-Host ""
    Write-Host "=== Setup Complete ===" -ForegroundColor Cyan
    Write-Host ""
    Write-Host "Next steps:" -ForegroundColor Cyan
    Write-Host "1. Install Node.js if not already installed (required for MCP)" -ForegroundColor White
    Write-Host "2. Restart Cursor IDE" -ForegroundColor White
    Write-Host "3. Verify MCP servers are working in Cursor" -ForegroundColor White
}

# Main execution
if ($Export) {
    Export-MCPConfig
}
elseif ($Setup) {
    Setup-MCPConfig
}
else {
    Write-Host "MCP Setup Script for Cursor IDE" -ForegroundColor Cyan
    Write-Host ""
    Write-Host "Usage:" -ForegroundColor Yellow
    Write-Host "  Export config from current machine:" -ForegroundColor White
    Write-Host "    .\setup-mcp.ps1 -Export" -ForegroundColor Gray
    Write-Host ""
    Write-Host "  Setup on new machine:" -ForegroundColor White
    Write-Host "    .\setup-mcp.ps1 -Setup" -ForegroundColor Gray
    Write-Host "    .\setup-mcp.ps1 -Setup -Username 'myusername' -SshKeyPath 'C:\path\to\key'" -ForegroundColor Gray
    Write-Host ""
    Write-Host "Options:" -ForegroundColor Yellow
    Write-Host "  -Export          Export current MCP config to portable format" -ForegroundColor White
    Write-Host "  -Setup           Set up MCP config on this machine" -ForegroundColor White
    Write-Host "  -Username        Username for paths (optional, will prompt)" -ForegroundColor White
    Write-Host "  -SshKeyPath      SSH key path (optional, will prompt)" -ForegroundColor White
}

