param([switch]$Apply)

# Removes redundant manual-cleanup now that RefreshMongoDatabase resets the DB
# before every test. Only removes lines that are ENTIRELY cleanup statements, and
# collapses afterEach() blocks that only truncated collections. Assertions and any
# line mixing logic with cleanup are left untouched (reported for manual review).

$files = Get-ChildItem -Recurse "$PSScriptRoot\Feature" -Filter *.php

# A single cleanup statement, e.g.  $x->delete();  Model::truncate();  Foo::where(...)->delete();
$stmt = "(?:[A-Za-z0-9_\\\\]+::(?:truncate\(\)|where\([^;]*\)->delete\(\)|all\(\)->each\([^;]*\))|\`$[A-Za-z0-9_]+->delete\(\))\s*;"
# A whole line made of one or more such statements (space separated)
$lineOnlyCleanup = "^\s*(?:$stmt\s*)+$"
# afterEach block that contains only cleanup statements
$afterEachOnly = "(?ms)afterEach\(function\s*\(\)\s*\{\s*(?:$stmt\s*)+\}\);\s*"

$totalRemoved = 0
$touched = 0

foreach ($f in $files) {
    $orig = Get-Content $f.FullName -Raw
    $text = $orig

    # 1) Drop afterEach blocks that only truncate/delete
    $text = [regex]::Replace($text, $afterEachOnly, "")

    # 2) Drop standalone cleanup-only lines
    $newLines = @()
    $removed = 0
    foreach ($line in ($text -split "`r?`n")) {
        if ($line -match $lineOnlyCleanup) { $removed++; continue }
        $newLines += $line
    }
    $text = $newLines -join "`n"

    # 3) Collapse 3+ blank lines left behind into 2
    $text = [regex]::Replace($text, "(\n[ \t]*){3,}\n", "`n`n")

    if ($text -ne $orig) {
        $touched++
        $totalRemoved += $removed
        if ($Apply) {
            Set-Content -Path $f.FullName -Value $text -NoNewline
        } else {
            Write-Output ("{0,-50} -{1} lines" -f $f.Name, $removed)
        }
    }
}

Write-Output ""
Write-Output ("{0}: {1} files touched, ~{2} cleanup lines removed" -f ($(if($Apply){"APPLIED"}else{"DRY-RUN"})), $touched, $totalRemoved)
