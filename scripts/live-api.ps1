param(
    [ValidateSet('status', 'capture', 'request', 'clear')]
    [string]$Action = 'status',
    [string]$SiteUrl = '',
    [string]$Method = 'GET',
    [string]$Path = '/wp-json/wpcommander/v1/manifest',
    [string]$BodyJson = ''
)

$ErrorActionPreference = 'Stop'
$storeDirectory = Join-Path $env:LOCALAPPDATA 'WPCommander'
$storePath = Join-Path $storeDirectory 'live-connection.json'

function Get-StoredConnection {
    if (-not (Test-Path $storePath)) {
        throw 'No direct WPCommander connection is stored on this workstation.'
    }
    return Get-Content -Raw $storePath | ConvertFrom-Json
}

function Protect-BasicToken([string]$Token) {
    $secure = ConvertTo-SecureString $Token -AsPlainText -Force
    return ConvertFrom-SecureString $secure
}

function Unprotect-BasicToken([string]$ProtectedToken) {
    $secure = ConvertTo-SecureString $ProtectedToken
    $credential = [System.Net.NetworkCredential]::new('', $secure)
    return $credential.Password
}
function Invoke-WPCommanderRequest($Connection, [string]$RequestMethod, [string]$RequestPath, [string]$RequestBody) {
    $token = Unprotect-BasicToken $Connection.protectedBasicToken
    try {
        $headers = @{
            Authorization = "Basic $token"
            'User-Agent' = 'ChatGPT-User/1.0'
            Accept = 'application/json'
        }
        $uri = $Connection.siteUrl.TrimEnd('/') + '/' + $RequestPath.TrimStart('/')
        $parameters = @{
            Uri = $uri
            Method = $RequestMethod.ToUpperInvariant()
            Headers = $headers
            UseBasicParsing = $true
        }
        if ($RequestBody) {
            $parameters['ContentType'] = 'application/json'
            $parameters['Body'] = $RequestBody
        }
        $response = Invoke-WebRequest @parameters
        if ([string]::IsNullOrWhiteSpace($response.Content)) {
            return @{ status = [int]$response.StatusCode }
        }
        return $response.Content | ConvertFrom-Json
    }
    finally {
        $token = $null
    }
}

switch ($Action) {
    'status' {
        if (-not (Test-Path $storePath)) {
            [pscustomobject]@{ connected = $false; store = $storePath } | ConvertTo-Json
            exit 0
        }
        $connection = Get-StoredConnection
        [pscustomobject]@{
            connected = $true
            siteUrl = $connection.siteUrl
            createdAt = $connection.createdAt
            store = $storePath
        } | ConvertTo-Json
    }
    'capture' {
        if ([string]::IsNullOrWhiteSpace($SiteUrl)) {
            throw 'SiteUrl is required when capturing a connection.'
        }
        $clipboard = Get-Clipboard -Raw
        if ([string]::IsNullOrWhiteSpace($clipboard)) {
            throw 'Clipboard is empty. Copy the WPCommander Basic auth token first.'
        }
        try {
            $decoded = [Text.Encoding]::UTF8.GetString([Convert]::FromBase64String($clipboard.Trim()))
        }
        catch {
            throw 'Clipboard does not contain a valid WPCommander Basic auth token.'
        }
        if ($decoded -notmatch '^[^:]+:.+$') {
            throw 'Clipboard token is not a username:application-password Basic credential.'
        }
        New-Item -ItemType Directory -Force -Path $storeDirectory | Out-Null
        $payload = [pscustomobject]@{
            siteUrl = $SiteUrl.TrimEnd('/')
            protectedBasicToken = Protect-BasicToken $clipboard.Trim()
            createdAt = (Get-Date).ToUniversalTime().ToString('o')
        }
        $payload | ConvertTo-Json | Set-Content -Encoding UTF8 $storePath
        Add-Type -AssemblyName System.Windows.Forms
        [System.Windows.Forms.Clipboard]::Clear()
        $connection = Get-StoredConnection
        $manifest = Invoke-WPCommanderRequest $connection 'GET' '/wp-json/wpcommander/v1/manifest' ''
        [pscustomobject]@{
            connected = $true
            siteUrl = $connection.siteUrl
            pluginVersion = $manifest.pluginVersion
            connectionStatus = $manifest.connectionStatus
            createdAt = $connection.createdAt
        } | ConvertTo-Json
    }
    'request' {
        $connection = Get-StoredConnection
        $result = Invoke-WPCommanderRequest $connection $Method $Path $BodyJson
        $result | ConvertTo-Json -Depth 20
    }
    'clear' {
        if (Test-Path $storePath) {
            Remove-Item -Force $storePath
        }
        [pscustomobject]@{ connected = $false; cleared = $true } | ConvertTo-Json
    }
}
