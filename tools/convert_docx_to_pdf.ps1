param(
    [string]$DocxPath,
    [string]$PdfPath
)

$ErrorActionPreference = "Stop"

if (-not $DocxPath) {
    $DocxPath = Join-Path $PSScriptRoot "..\RideSync_Comprehensive_College_Project_Report.docx"
}
if (-not $PdfPath) {
    $PdfPath = Join-Path $PSScriptRoot "..\RideSync_Report.pdf"
}

$docxAbs = [System.IO.Path]::GetFullPath($DocxPath)
$pdfAbs = [System.IO.Path]::GetFullPath($PdfPath)

if (-not (Test-Path $docxAbs)) {
    Write-Error "DOCX file not found at: $docxAbs"
    exit 1
}

Write-Host "Converting DOCX to PDF..."
Write-Host "Input:  $docxAbs"
Write-Host "Output: $pdfAbs"

try {
    $word = New-Object -ComObject Word.Application
    $word.Visible = $false
    $doc = $word.Documents.Open($docxAbs)
    $doc.SaveAs([ref]$pdfAbs, [ref]17) # 17 = wdFormatPDF
    $doc.Close()
    $word.Quit()
    [System.Runtime.Interopservices.Marshal]::ReleaseComObject($word) | Out-Null
    Write-Host "SUCCESS: Report successfully exported to PDF at $pdfAbs"
} catch {
    Write-Error "Failed to convert DOCX to PDF via Word COM: $_"
    exit 1
}
