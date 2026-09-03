Add-Type -AssemblyName System.Drawing
$bmp = [System.Drawing.Bitmap]::FromFile("C:\Users\MCK\.openclaw\media\inbound\image---3a42d879-595d-4223-a66d-3b22d952f1b2.png")

# Let's see what is drawn where
# Let's sample colors across the height at various X
Write-Output "Width: $($bmp.Width), Height: $($bmp.Height)"

# Find rows where there are borders or button backgrounds
for ($y = 0; $y -lt $bmp.Height; $y += 5) {
    $rowStr = "Y={0:D2}: " -f $y
    for ($x = 0; $x -lt $bmp.Width; $x += 15) {
        $c = $bmp.GetPixel($x, $y)
        if ($c.R -lt 50 -and $c.G -lt 50 -and $c.B -lt 50) { $rowStr += "D" } # dark
        elseif ($c.R -gt 240 -and $c.G -gt 240 -and $c.B -gt 240) { $rowStr += "." } # white/light
        elseif ($c.R -gt 200 -and $c.G -lt 100) { $rowStr += "R" } # red
        elseif ($c.B -gt 200 -and $c.R -lt 100) { $rowStr += "B" } # blue
        elseif ($c.G -gt 150 -and $c.R -lt 100) { $rowStr += "G" } # green
        else { $rowStr += "+" }
    }
    Write-Output $rowStr
}

$bmp.Dispose()
