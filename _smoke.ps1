$base = 'http://localhost/cultour/api'
$body = @{ email = 'guide@example.com'; password = 'Password123!'; role_id = 3 } | ConvertTo-Json
$g = (Invoke-RestMethod -Method Post -Uri "$base/auth/login.php" -Body $body -ContentType 'application/json').token
$h = @{ Authorization = "Bearer $g" }
$days = @(1..5 | ForEach-Object { @{ day = $_; on = $true; start = '8:00 AM'; end = '5:00 PM' } })
$r = Invoke-RestMethod -Method Post -Uri "$base/guide/availability.php" -Headers $h -ContentType 'application/json' -Body (@{ action = 'weekly'; days = $days } | ConvertTo-Json -Depth 5)
Write-Output ("weekly POST: " + $r.message + " " + ($r.data.days | Where-Object { $_.on } | ForEach-Object { "$($_.label) $($_.start)-$($_.end)" }) -join ', ')
$r = Invoke-RestMethod -Method Post -Uri "$base/guide/availability.php" -Headers $h -ContentType 'application/json' -Body (@{ action = 'block'; block_date = '2027-01-15'; reason = 'Smoke' } | ConvertTo-Json)
Write-Output ("block: " + $r.message + " blocks=" + ($r.data.blocks | ConvertTo-Json -Compress))
