# One-off analysis of the test suite. Reports anti-patterns per file.
$root = Split-Path $PSScriptRoot -Parent
$files = Get-ChildItem -Recurse "$PSScriptRoot" -Filter *.php | Where-Object { $_.Name -ne 'Pest.php' -and $_.Name -ne 'TestCase.php' }

$report = foreach ($f in $files) {
    $c = Get-Content $f.FullName -Raw
    $lines = ($c -split "`n").Count
    $tests = ([regex]::Matches($c, '(?m)^\s*(test|it)\(')).Count

    $cleanup = ([regex]::Matches($c, '->delete\(\)|::truncate\(\)')).Count
    $localFns = ([regex]::Matches($c, '(?m)^function\s+\w+')).Count
    $debug = ([regex]::Matches($c, '\bdump\(|\bdd\(|\becho\s|->dump\(')).Count
    $httpReal = ([regex]::Matches($c, 'Http::fake|Storage::fake')).Count
    $manualUser = ([regex]::Matches($c, 'User::factory\(\)')).Count
    $authLogin = ([regex]::Matches($c, "auth\('api'\)->login")).Count
    $banner = if ($c -match 'DO NOT DELETE') { 'Y' } else { '' }
    $usesHelpers = ([regex]::Matches($c, '\b(userWithToken|tokenFor|createUser|createJob|createCompanyFor)\(')).Count

    [PSCustomObject]@{
        File        = $f.FullName.Replace("$PSScriptRoot\","")
        Lines       = $lines
        Tests       = $tests
        Cleanup     = $cleanup
        LocalFns    = $localFns
        Debug       = $debug
        Banner      = $banner
        FactoryUse  = $manualUser
        AuthLogin   = $authLogin
        HttpFake    = $httpReal
        NewHelpers  = $usesHelpers
    }
}

$report | Sort-Object Cleanup -Descending | Format-Table -AutoSize
Write-Output ""
Write-Output "TOTALS:"
Write-Output ("  files={0} tests={1} cleanup_calls={2} local_fns={3} debug={4}" -f `
    $report.Count, ($report | Measure-Object Tests -Sum).Sum, ($report | Measure-Object Cleanup -Sum).Sum, `
    ($report | Measure-Object LocalFns -Sum).Sum, ($report | Measure-Object Debug -Sum).Sum)
