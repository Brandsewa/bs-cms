$loginBody = '{"email":"owner@example.com","password":"TestUser2026!Secure"}'
$login = Invoke-WebRequest -Uri 'http://127.0.0.1:8081/api/v1/auth/login' -Method Post -ContentType 'application/json' -Body $loginBody -SessionVariable mySess
$audit = Invoke-RestMethod -Uri 'http://127.0.0.1:8081/api/v1/audit' -WebSession $mySess
$audit | ConvertTo-Json -Depth 6 | Out-File -FilePath 'admin/tests/fixtures/audit-fixtures.json' -Encoding utf8
Write-Output "Audit fixtures written. Item count: $($audit.items.Count)"
